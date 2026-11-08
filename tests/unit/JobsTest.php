<?php

use PHPUnit\Framework\TestCase;

final class JobsTest extends TestCase
{
	private $root;
	private $config;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/ffgui_jobs_' . getmypid() . '_' . mt_rand() . '/';
		foreach (array('', 'jobs', 'upload', 'converted', 'logs') as $dir) {
			mkdir($this->root . $dir);
		}
		$this->config = array(
			'jobs_dir'                    => $this->root . 'jobs/',
			'upload_dir'                  => $this->root . 'upload/',
			'converted_dir'               => $this->root . 'converted/',
			'log_dir'                     => $this->root . 'logs/',
			'converted_url'               => 'converted/',
			'max_duration'                => 0,
			'job_retention_days'          => 7,
			'delete_upload_after_convert' => true,
		);
	}

	protected function tearDown(): void
	{
		$this->removeTree($this->root);
	}

	private function removeTree($path)
	{
		if (is_dir($path)) {
			foreach (scandir($path) as $name) {
				if ($name !== '.' && $name !== '..') {
					$this->removeTree($path . '/' . $name);
				}
			}
			rmdir($path);
		} elseif (file_exists($path)) {
			unlink($path);
		}
	}

	/** A job as acceptUpload() would queue it, with $extra on top. */
	private function job($id, $status = 'queued', $created = 1000, array $extra = array())
	{
		$settings = readSettings(formOptions(), array('encoding_enable_audio' => '1'));
		return $extra + array(
			'id'          => $id,
			'status'      => $status,
			'created'     => $created,
			'name'        => 'clip.mp4',
			'size'        => 1234,
			'ext'         => 'mp4',
			'base'        => 'clip',
			'settings'    => $settings,
			'formats'     => array('encoding_x264', 'encoding_webm'),
			'want_stills' => true,
			'duration'    => 100.0,
			'step'        => 0,
		);
	}

	private function id($n)
	{
		return str_pad(dechex($n), 16, '0', STR_PAD_LEFT);
	}

	// --- settings ----------------------------------------------------------

	private function env(array $vars)
	{
		return function ($name) use ($vars) {
			return isset($vars[$name]) ? $vars[$name] : false;
		};
	}

	public function testEnvConfigReadsKnownVariablesAndIgnoresEmptyOnes()
	{
		$settings = envConfig($this->env(array(
			'FFMPEG_GUI_FFMPEG'             => '/usr/bin/ffmpeg',
			'FFMPEG_GUI_FFPROBE'            => '  ',
			'FFMPEG_GUI_SECURE_COOKIE'      => 'true',
			'FFMPEG_GUI_JOB_RETENTION_DAYS' => '14',
			'FFMPEG_GUI_SOMETHING_ELSE'     => 'x',
		)));
		$this->assertSame(array('ffmpeg' => '/usr/bin/ffmpeg', 'secure_cookie' => true, 'job_retention_days' => 14), $settings);
		$this->assertFalse(envConfig($this->env(array('FFMPEG_GUI_SECURE_COOKIE' => 'false')))['secure_cookie']);
	}

	public function testEnvConfigReadsThePasswordHashFromAFile()
	{
		file_put_contents($this->root . 'hash', "\$2y\$10\$abc\n");
		$settings = envConfig($this->env(array('FFMPEG_GUI_PASSWORD_HASH_FILE' => $this->root . 'hash')));
		$this->assertSame('$2y$10$abc', $settings['password_hash']);

		$this->expectException(RuntimeException::class);
		envConfig($this->env(array('FFMPEG_GUI_PASSWORD_HASH_FILE' => $this->root . 'missing')));
	}

	public function testLoadConfigLayersLocalConfigThenEnvironment()
	{
		file_put_contents($this->root . 'config.php', "<?php return array('ffmpeg' => 'a', 'ffprobe' => 'a', 'password_hash' => '');");
		file_put_contents($this->root . 'config.local.php', "<?php return array('ffmpeg' => 'b', 'ffprobe' => 'b');");
		$config = loadConfig(rtrim($this->root, '/'), $this->env(array('FFMPEG_GUI_FFPROBE' => 'c')));
		$this->assertSame(array('ffmpeg' => 'b', 'ffprobe' => 'c', 'password_hash' => ''), $config);
	}

	public function testLoadConfigRejectsALocalConfigThatIsNotAnArray()
	{
		file_put_contents($this->root . 'config.php', "<?php return array();");
		file_put_contents($this->root . 'config.local.php', "<?php \$password_hash = 'x';");
		$this->expectException(RuntimeException::class);
		loadConfig(rtrim($this->root, '/'), $this->env(array()));
	}

	// --- job files ---------------------------------------------------------

	public function testJobIdsAreSixteenHexDigits()
	{
		$this->assertTrue(isJobId('0123456789abcdef'));
		foreach (array('0123456789ABCDEF', '0123456789abcde', '../../config.php', '0123456789abcdef/', '', null, array()) as $bad) {
			$this->assertFalse(isJobId($bad), var_export($bad, true));
		}
		$this->assertNull(readJob($this->config, '../jobs/x'));
	}

	public function testWriteAndReadAJob()
	{
		$job = $this->job($this->id(1));
		writeJob($this->config, $job);
		$this->assertSame($job, readJob($this->config, $this->id(1)));
		$this->assertNull(readJob($this->config, $this->id(2)));
		$this->assertSame(array($this->id(1) . '.json'), array_values(array_diff(scandir($this->config['jobs_dir']), array('.', '..'))), 'no temp files left');
	}

	public function testAJobFileWithAnotherIdIsIgnored()
	{
		file_put_contents($this->config['jobs_dir'] . $this->id(1) . '.json', json_encode($this->job($this->id(2))));
		$this->assertNull(readJob($this->config, $this->id(1)));
	}

	public function testListJobsIsNewestFirst()
	{
		writeJob($this->config, $this->job($this->id(1), 'done', 1000));
		writeJob($this->config, $this->job($this->id(2), 'queued', 3000));
		writeJob($this->config, $this->job($this->id(3), 'queued', 2000));
		$this->assertSame(array($this->id(2), $this->id(3), $this->id(1)), array_column(listJobs($this->config), 'id'));
	}

	public function testClaimNextJobTakesTheOldestQueuedJob()
	{
		writeJob($this->config, $this->job($this->id(1), 'done', 1000));
		writeJob($this->config, $this->job($this->id(2), 'queued', 3000));
		writeJob($this->config, $this->job($this->id(3), 'queued', 2000));

		$job = claimNextJob($this->config, 5000);
		$this->assertSame($this->id(3), $job['id']);
		$this->assertSame('running', readJob($this->config, $this->id(3))['status']);
		$this->assertSame(5000, readJob($this->config, $this->id(3))['started']);

		claimNextJob($this->config);
		$this->assertNull(claimNextJob($this->config), 'queue is empty');
	}

	public function testJobsAhead()
	{
		$jobs = array(
			$this->job($this->id(1), 'running', 1000),
			$this->job($this->id(2), 'queued', 2000),
			$this->job($this->id(3), 'queued', 3000),
			$this->job($this->id(4), 'done', 1500),
		);
		$this->assertSame(1, jobsAhead($jobs, $jobs[1]));
		$this->assertSame(2, jobsAhead($jobs, $jobs[2]));
	}

	public function testDeleteJobRemovesItsFiles()
	{
		$id = $this->id(1);
		writeJob($this->config, $this->job($id, 'done'));
		mkdir($this->config['converted_dir'] . $id);
		touch($this->config['converted_dir'] . $id . '/clip.mp4');
		touch($this->config['upload_dir'] . $id . '.mp4');
		touch(jobProgressFile($this->config, $id));
		writeJob($this->config, $this->job($this->id(2), 'done'));

		deleteJob($this->config, $id);
		$this->assertNull(readJob($this->config, $id));
		$this->assertDirectoryDoesNotExist($this->config['converted_dir'] . $id);
		$this->assertFileDoesNotExist($this->config['upload_dir'] . $id . '.mp4');
		$this->assertFileDoesNotExist(jobProgressFile($this->config, $id));
		$this->assertNotNull(readJob($this->config, $this->id(2)), 'other jobs are kept');
	}

	public function testARunningJobCannotBeDeleted()
	{
		writeJob($this->config, $this->job($this->id(1), 'running'));
		try {
			deleteJob($this->config, $this->id(1));
			$this->fail('Expected RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertNotNull(readJob($this->config, $this->id(1)));
		}
	}

	public function testDeleteOldJobsOnlyDeletesFinishedJobsPastTheRetention()
	{
		$now = 100 * 86400;
		writeJob($this->config, $this->job($this->id(1), 'done', 0, array('finished' => $now - 8 * 86400)));
		writeJob($this->config, $this->job($this->id(2), 'failed', 0, array('finished' => $now - 8 * 86400)));
		writeJob($this->config, $this->job($this->id(3), 'done', 0, array('finished' => $now - 6 * 86400)));
		writeJob($this->config, $this->job($this->id(4), 'queued', 0));

		$this->assertSame(2, deleteOldJobs($this->config, $now));
		$this->assertSame(array($this->id(4), $this->id(3)), array_column(listJobs($this->config), 'id'));

		$this->assertSame(0, deleteOldJobs(array('job_retention_days' => 0) + $this->config, $now * 2), '0 keeps everything');
	}

	public function testFailInterruptedJobs()
	{
		writeJob($this->config, $this->job($this->id(1), 'running'));
		writeJob($this->config, $this->job($this->id(2), 'queued'));
		touch($this->config['upload_dir'] . $this->id(1) . '.mp4');

		failInterruptedJobs($this->config, 9000);
		$job = readJob($this->config, $this->id(1));
		$this->assertSame('failed', $job['status']);
		$this->assertSame(9000, $job['finished']);
		$this->assertStringContainsString('interrupted', $job['error']);
		$this->assertFileDoesNotExist($this->config['upload_dir'] . $this->id(1) . '.mp4');
		$this->assertSame('queued', readJob($this->config, $this->id(2))['status']);
	}

	// --- progress ----------------------------------------------------------

	public function testParseProgressUsesTheLastBlock()
	{
		$text = "frame=10\nout_time_us=1500000\nout_time_ms=1500000\nprogress=continue\n"
			. "frame=50\nout_time_us=12250000\nout_time_ms=12250000\nprogress=continue\n";
		$this->assertSame(12.25, parseProgress($text));
		$this->assertSame(3.0, parseProgress("out_time_ms=3000000\n"), 'older ffmpeg only has out_time_ms, which is microseconds');
		$this->assertNull(parseProgress("out_time_us=N/A\n"));
		$this->assertNull(parseProgress(''));
	}

	public function testJobSteps()
	{
		$this->assertSame(array('MP4', 'WebM', 'Stills'), jobSteps($this->job($this->id(1))));
		$this->assertSame(array('Ogg'), jobSteps($this->job($this->id(1), 'queued', 0, array('formats' => array('encoding_ogv'), 'want_stills' => false))));
	}

	public function testStatusOfAQueuedJob()
	{
		$first  = $this->job($this->id(1), 'running', 1000, array('started' => 1000));
		$second = $this->job($this->id(2), 'queued', 2000);
		$third  = $this->job($this->id(3), 'queued', 3000);
		$status = jobStatus($this->config, $third, '', array($first, $second, $third));
		$this->assertSame(2, $status['ahead']);
		$this->assertSame(0, $status['percent']);
		$this->assertSame('Waiting: 2 conversions ahead of this one.', $status['text']);

		$alone = $this->job($this->id(4), 'queued', 4000);
		$this->assertSame('Waiting to start…', jobStatus($this->config, $alone, '', array($alone))['text']);
	}

	public function testStatusOfARunningJob()
	{
		// Step 2 of 3 (WebM), halfway through a 100 second video, 60 seconds in
		$job = $this->job($this->id(1), 'running', 1000, array('started' => 1000, 'step' => 1));
		$status = jobStatus($this->config, $job, "out_time_us=50000000\nprogress=continue\n", array($job), 1060);
		$this->assertSame(50, $status['percent']);
		$this->assertSame('WebM (2 of 3)', $status['step']);
		$this->assertSame(60, $status['eta']);
		$this->assertSame('Converting WebM (2 of 3): 50%, about 1 min left.', $status['text']);
	}

	public function testRunningProgressUsesMaxDurationAndNeverReaches100()
	{
		$config = array('max_duration' => 10) + $this->config;
		$job = $this->job($this->id(1), 'running', 1000, array('started' => 1000, 'step' => 2, 'formats' => array('encoding_x264', 'encoding_webm'), 'want_stills' => false));
		$status = jobStatus($config, $job, "out_time_us=999000000\n", array($job), 1001);
		$this->assertSame(99, $status['percent'], 'step is capped to the last one and the percentage below 100');
		$this->assertNull($status['eta'], 'no estimate in the first few seconds');
	}

	public function testStatusOfFinishedJobs()
	{
		$done = $this->job($this->id(1), 'done');
		$this->assertSame(100, jobStatus($this->config, $done, '', array($done))['percent']);
		$failed = $this->job($this->id(2), 'failed', 0, array('error' => 'Oops.'));
		$this->assertSame('Failed. Oops.', jobStatus($this->config, $failed, '', array($failed))['text']);
	}

	public function testFormatDuration()
	{
		$this->assertSame('45 s', formatDuration(45));
		$this->assertSame('3 min', formatDuration(170));
		$this->assertSame('1 h 5 min', formatDuration(3900));
		$this->assertSame('0 s', formatDuration(-5));
	}

	public function testJobResultForTheResultsCard()
	{
		$job = $this->job($this->id(1), 'done', 0, array('made' => array(array('label' => 'MP4', 'file' => 'clip.mp4', 'type' => 'video/mp4', 'size' => 10)), 'stills' => array('clip_still_1.png'), 'player' => 'clip_player.html', 'failed' => array()));
		$result = jobResult($this->config, $job);
		$this->assertSame('converted/' . $this->id(1) . '/', $result['url']);
		$this->assertSame(array('clip_still_1.png'), $result['stills']);
		$this->assertSame('clip_player.html', $result['player']);
	}
}
