<?php

use PHPUnit\Framework\TestCase;

final class FunctionsTest extends TestCase
{
	/** Settings as readSettings() returns them for the form defaults. */
	private function defaultSettings(array $post = array())
	{
		return readSettings(formOptions(), $post + array(
			'encoding_video_deinterlace' => '1',
			'encoding_enable_audio'      => '1',
		));
	}

	/** Value that follows $option in an ffmpeg argument list. */
	private function argValue(array $args, $option)
	{
		$i = array_search($option, $args, true);
		$this->assertNotFalse($i, "Expected $option in: " . implode(' ', $args));
		return $args[$i + 1];
	}

	// --- h() / slugify() ---------------------------------------------------

	public function testHEscapesHtml()
	{
		$this->assertSame('&lt;img src=x onerror=&quot;a()&quot;&gt; &#039;', h('<img src=x onerror="a()"> \''));
	}

	/** @dataProvider slugProvider */
	public function testSlugifyOnlyProducesSafeCharacters($input, $expected)
	{
		$this->assertSame($expected, slugify($input));
		$this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', slugify($input));
	}

	public function slugProvider()
	{
		return array(
			'plain'          => array('My Holiday', 'my-holiday'),
			'shell chars'    => array('a;rm -rf /;$(id)`x`|b', 'a-rm-rf-id-x-b'),
			'html'           => array('<img src=x onerror=alert(1)>', 'img-src-x-onerror-alert-1'),
			'ffmpeg pattern' => array('clip%d%03d', 'clip-d-03d'),
			'dots'           => array('../../etc/passwd', 'etc-passwd'),
			'empty'          => array('', 'video'),
			'only symbols'   => array('!!!', 'video'),
			'unicode'        => array('vidéo été', 'vid-o-t'),
		);
	}

	public function testSlugifyLimitsLengthWithoutTrailingDash()
	{
		$slug = slugify(str_repeat('ab ', 40));
		$this->assertLessThanOrEqual(60, strlen($slug));
		$this->assertStringEndsNotWith('-', $slug);
	}

	// --- validateUpload() --------------------------------------------------

	private function upload($name, $error = UPLOAD_ERR_OK, $size = 1000)
	{
		return array('name' => $name, 'type' => 'video/mp4', 'tmp_name' => '/tmp/x', 'error' => $error, 'size' => $size);
	}

	public function testValidateUploadAcceptsVideoExtensions()
	{
		$this->assertSame('mp4', validateUpload($this->upload('Clip.MP4'), 0));
		$this->assertSame('mkv', validateUpload($this->upload('a.b.mkv'), 0));
	}

	/** @dataProvider badExtensionProvider */
	public function testValidateUploadRejectsOtherExtensions($name)
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unsupported file type');
		validateUpload($this->upload($name), 0);
	}

	public function badExtensionProvider()
	{
		return array(array('shell.php'), array('shell.phtml'), array('x.mp4.php'), array('.htaccess'), array('noextension'), array('page.html'));
	}

	public function testValidateUploadChecksErrorBeforeType()
	{
		$this->expectExceptionMessage('larger than the server upload limit');
		validateUpload($this->upload('', UPLOAD_ERR_INI_SIZE, 0), 0);
	}

	public function testValidateUploadRejectsMissingFile()
	{
		$this->expectExceptionMessage('Please choose a video file');
		validateUpload(null, 0);
	}

	public function testValidateUploadRejectsArrayUpload()
	{
		$this->expectExceptionMessage('Please choose a video file');
		validateUpload(array('name' => array('a.mp4'), 'error' => array(0)), 0);
	}

	public function testValidateUploadEnforcesMaxSize()
	{
		$this->expectExceptionMessage('the limit is');
		validateUpload($this->upload('a.mp4', UPLOAD_ERR_OK, 2000), 1000);
	}

	// --- readSettings() ----------------------------------------------------

	public function testReadSettingsUsesDefaults()
	{
		$settings = readSettings(formOptions(), array());
		$this->assertSame('560x304', $settings['video_size']);
		$this->assertSame('700', $settings['video_bitrate']);
		$this->assertFalse($settings['audio']);
	}

	public function testReadSettingsAcceptsEveryListedValue()
	{
		foreach (formOptions() as $key => $option) {
			foreach (array_keys($option['values']) as $value) {
				$settings = readSettings(formOptions(), array($key => (string)$value));
				$this->assertSame((string)$value, $settings[$key]);
			}
		}
	}

	/** @dataProvider injectedValueProvider */
	public function testReadSettingsRejectsValuesNotInTheList($key, $value)
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invalid value');
		readSettings(formOptions(), array($key => $value));
	}

	public function injectedValueProvider()
	{
		return array(
			'argument injection' => array('video_size', '640x360 -f data -i /etc/passwd'),
			'shell injection'    => array('video_size', '640x360;id'),
			'filter injection'   => array('video_size', "640x360',movie=/etc/passwd"),
			'leading space'      => array('video_bitrate', ' 700'),
			'leading zero'       => array('video_bitrate', '0700'),
			'float'              => array('video_framerate', '30.0'),
			'unlisted number'    => array('encoding_audio_bitrate', '64'),
			'channels'           => array('encoding_audio_channels', '5.1'),
		);
	}

	public function testReadSettingsIgnoresArrayValues()
	{
		$settings = readSettings(formOptions(), array('video_size' => array('800x600')));
		$this->assertSame('560x304', $settings['video_size']);
	}

	public function testFormValueFallsBackToDefaultForBadInput()
	{
		$this->assertSame('560x304', formValue(formOptions(), 'video_size', array('video_size' => 'evil')));
		$this->assertSame('800x600', formValue(formOptions(), 'video_size', array('video_size' => '800x600')));
	}

	// --- outputFormats() ---------------------------------------------------

	public function testEveryArgumentIsAStringWithoutShellOrFilterSurprises()
	{
		foreach (formOptions()['encoding_audio_bitrate']['values'] as $bitrate => $label) {
			foreach (array('stereo', 'mono') as $channels) {
				$settings = $this->defaultSettings(array('encoding_audio_bitrate' => (string)$bitrate, 'encoding_audio_channels' => $channels));
				foreach (outputFormats($settings) as $format) {
					foreach ($format['args'] as $arg) {
						$this->assertIsString($arg);
						$this->assertDoesNotMatchRegularExpression('/[;&|`$<>\n]/', $arg);
					}
				}
			}
		}
	}

	public function testMp4UsesAacAndFaststart()
	{
		$args = outputFormats($this->defaultSettings())['encoding_x264']['args'];
		$this->assertSame('libx264', $this->argValue($args, '-c:v'));
		$this->assertSame('aac', $this->argValue($args, '-c:a'));
		$this->assertSame('+faststart', $this->argValue($args, '-movflags'));
		$this->assertSame('yuv420p', $this->argValue($args, '-pix_fmt'));
		$this->assertNotContains('libfaac', $args);
		$this->assertNotContains('-deinterlace', $args);
	}

	public function testOpusBitrateIsCappedPerChannel()
	{
		$mono = outputFormats($this->defaultSettings(array('encoding_audio_bitrate' => '320', 'encoding_audio_channels' => 'mono')));
		$this->assertSame('256k', $this->argValue($mono['encoding_webm']['args'], '-b:a'));

		$stereo = outputFormats($this->defaultSettings(array('encoding_audio_bitrate' => '320', 'encoding_audio_channels' => 'stereo')));
		$this->assertSame('320k', $this->argValue($stereo['encoding_webm']['args'], '-b:a'));
	}

	public function testOpusDoesNotGetASampleRate()
	{
		$args = outputFormats($this->defaultSettings(array('encoding_audio_sampling_rate' => '44100')))['encoding_webm']['args'];
		$this->assertNotContains('-ar', $args);
	}

	public function testVorbisUsesQualityForEveryBitrate()
	{
		foreach (array_keys(formOptions()['encoding_audio_bitrate']['values']) as $bitrate) {
			$args = outputFormats($this->defaultSettings(array('encoding_audio_bitrate' => (string)$bitrate)))['encoding_ogv']['args'];
			$this->assertContains('-q:a', $args);
			$this->assertNotContains('-b:a', $args);
		}
	}

	public function testAudioOffDisablesAudioForEveryFormat()
	{
		$settings = $this->defaultSettings();
		$settings['audio'] = false;
		foreach (outputFormats($settings) as $format) {
			$this->assertContains('-an', $format['args']);
			$this->assertNotContains('-c:a', $format['args']);
		}
	}

	public function testDeinterlaceOnlyWhenTicked()
	{
		$settings = $this->defaultSettings();
		$this->assertStringStartsWith('yadif=deint=interlaced,', $this->argValue(outputFormats($settings)['encoding_x264']['args'], '-vf'));
		$settings['deinterlace'] = false;
		$this->assertStringStartsWith('scale=', $this->argValue(outputFormats($settings)['encoding_x264']['args'], '-vf'));
	}

	public function testScaleFilterNeverUpscalesAndKeepsAspectRatio()
	{
		$this->assertSame(
			"scale=w='min(iw,640)':h='min(ih,360)':force_original_aspect_ratio=decrease:force_divisible_by=2",
			scaleFilter('640x360')
		);
	}

	// --- isAllowedVideo() --------------------------------------------------

	private function probe($formatName, array $streams)
	{
		return json_encode(array('streams' => $streams, 'format' => array('format_name' => $formatName)));
	}

	public function testAllowsRealVideos()
	{
		$this->assertTrue(isAllowedVideo($this->probe('mov,mp4,m4a,3gp,3g2,mj2', array(array('codec_type' => 'video'), array('codec_type' => 'audio')))));
		$this->assertTrue(isAllowedVideo($this->probe('matroska,webm', array(array('codec_type' => 'video')))));
		$this->assertTrue(isAllowedVideo($this->probe('avi', array(array('codec_type' => 'video', 'disposition' => array('attached_pic' => 0))))));
	}

	/** @dataProvider rejectedProbeProvider */
	public function testRejectsPlaylistsImagesAndAudio($json)
	{
		$this->assertFalse(isAllowedVideo($json));
	}

	public function rejectedProbeProvider()
	{
		$probe = function ($formatName, array $streams) {
			return json_encode(array('streams' => $streams, 'format' => array('format_name' => $formatName)));
		};
		return array(
			'hls playlist'   => array($probe('hls', array(array('codec_type' => 'video')))),
			'concat list'    => array($probe('concat', array(array('codec_type' => 'video')))),
			'image sequence' => array($probe('image2', array(array('codec_type' => 'video')))),
			'mixed formats'  => array($probe('mp4,hls', array(array('codec_type' => 'video')))),
			'audio only'     => array($probe('mp3', array(array('codec_type' => 'audio')))),
			'audio in mp4'   => array($probe('mov,mp4,m4a,3gp,3g2,mj2', array(array('codec_type' => 'audio')))),
			'cover art only' => array($probe('mov,mp4,m4a,3gp,3g2,mj2', array(
				array('codec_type' => 'audio'),
				array('codec_type' => 'video', 'disposition' => array('attached_pic' => 1)),
			))),
			'no streams'     => array($probe('mp4', array())),
			'not json'       => array('[mov @ 0x1] error before json {"format":{}}'),
			'empty'          => array(''),
			'format array'   => array(json_encode(array('format' => array('format_name' => array('mp4'))))),
		);
	}

	// --- buildPlayerPage() -------------------------------------------------

	public function testPlayerPageEscapesTheTitleAndUsesRelativeUrls()
	{
		$html = buildPlayerPage(
			'<img src=x onerror=alert(1)>.mp4',
			640,
			360,
			array(array('label' => 'MP4', 'file' => 'clip.mp4', 'type' => 'video/mp4')),
			'clip_still_3.png'
		);
		$this->assertStringNotContainsString('<img src=x', $html);
		$this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;.mp4', $html);
		$this->assertStringContainsString('<source src="clip.mp4" type="video/mp4">', $html);
		$this->assertStringContainsString('poster="clip_still_3.png"', $html);
		$this->assertStringNotContainsString('http', $html);
		$this->assertStringNotContainsString('shockwave', $html);
	}

	public function testPlayerPageWithoutPoster()
	{
		$html = buildPlayerPage('t', 1, 1, array(array('label' => 'WebM', 'file' => 'a.webm', 'type' => 'video/webm')), null);
		$this->assertStringNotContainsString('poster=', $html);
	}

	// --- runCommand() ------------------------------------------------------

	public function testRunCommandPassesArgumentsLiterally()
	{
		$marker = sys_get_temp_dir() . '/ffgui_injection_' . getmypid();
		@unlink($marker);
		$code = runCommand(array(PHP_BINARY, '-r', 'echo $argv[1];', '; touch ' . $marker), $stdout, $stderr);
		$this->assertSame(0, $code);
		$this->assertSame('; touch ' . $marker, $stdout);
		$this->assertFileDoesNotExist($marker);
	}

	public function testRunCommandSeparatesStderrAndReturnsExitCode()
	{
		$code = runCommand(array(PHP_BINARY, '-r', 'fwrite(STDERR, "warn"); echo "{}"; exit(3);'), $stdout, $stderr);
		$this->assertSame(3, $code);
		$this->assertSame('{}', $stdout);
		$this->assertSame('warn', $stderr);
	}
}
