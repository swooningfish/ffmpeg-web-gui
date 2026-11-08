<?php
/*
	Functions for ffmpeg web gui. Kept separate from upload-and-convert.php
	so they can be unit tested without handling a request. Job queueing and
	the worker's conversion code are in jobs.php.
*/

require_once __DIR__ . '/jobs.php';

// File extensions we accept. The real container is confirmed with ffprobe.
const ALLOWED_EXTENSIONS = array('mp4', 'm4v', 'mov', 'avi', 'mpg', 'mpeg', 'wmv', 'asf', 'webm', 'mkv', 'ogv', 'flv', '3gp');

// ffprobe container names we accept. Anything else (playlists such as hls or
// concat, image sequences, etc.) is rejected because it can make ffmpeg read
// other files on the server.
const ALLOWED_FORMATS = array('mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2', 'avi', 'mpeg', 'mpegts', 'mpegvideo', 'asf', 'matroska', 'webm', 'ogg', 'flv');

// Vorbis rejects many bitrate / sample rate combinations in bitrate mode, so
// each bitrate option maps to the nearest Vorbis quality level instead.
const VORBIS_QUALITY = array(320 => '9', 256 => '8', 192 => '6', 128 => '4', 96 => '2', 32 => '-1', 8 => '-1');


// ---------------------------------------------------------------------------
// Form definition
// ---------------------------------------------------------------------------

/**
 * Dropdown and radio options. These lists are used both to draw the form and
 * to validate what is posted, so only these exact values ever reach ffmpeg.
 */
function formOptions()
{
	return array(
		'video_size' => array(
			'label'   => 'Video size',
			'default' => '560x304',
			'values'  => array(
				'800x600' => '800x600 (4:3)',
				'704x576' => '704x576',
				'640x360' => '640x360 (16:9)',
				'560x304' => '560x304',
				'512x384' => '512x384',
				'400x300' => '400x300',
				'320x240' => '320x240',
			),
		),
		'video_bitrate' => array(
			'label'   => 'Video bitrate',
			'default' => '700',
			'values'  => array(
				'1600' => '1600 kbit/s',
				'1000' => '1000 kbit/s',
				'900'  => '900 kbit/s',
				'800'  => '800 kbit/s',
				'768'  => '768 kbit/s',
				'700'  => '700 kbit/s',
				'360'  => '360 kbit/s',
			),
		),
		'video_framerate' => array(
			'label'   => 'Frame rate',
			'default' => '30',
			'values'  => array(
				'30' => '30 fps',
				'25' => '25 fps',
				'15' => '15 fps',
				'12' => '12 fps',
			),
		),
		'encoding_audio_sampling_rate' => array(
			'label'   => 'Audio sampling rate',
			'default' => '44100',
			'values'  => array(
				'44100' => '44100 Hz',
				'22050' => '22050 Hz',
				'11025' => '11025 Hz',
			),
		),
		'encoding_audio_bitrate' => array(
			'label'   => 'Audio bitrate',
			'default' => '128',
			'values'  => array(
				'320' => '320 kbps',
				'256' => '256 kbps (DAB Quality)',
				'192' => '192 kbps (CD Quality)',
				'128' => '128 kbps',
				'96'  => '96 kbps',
				'32'  => '32 kbps',
				'8'   => '8 kbps (Telephone quality)',
			),
		),
		'encoding_audio_channels' => array(
			'label'   => 'Audio channels',
			'default' => 'stereo',
			'values'  => array(
				'stereo' => 'Stereo',
				'mono'   => 'Mono',
			),
		),
	);
}

/** Checkboxes, whether they are ticked by default, and a short hint shown under each. */
function formCheckboxes()
{
	return array(
		'encoding_video_deinterlace' => array('label' => 'Deinterlace', 'default' => true, 'hint' => 'Only changes frames that are marked as interlaced.'),
		'encoding_enable_audio'      => array('label' => 'Enable audio', 'default' => true, 'hint' => 'Turn off to make silent videos.'),
		'encoding_x264'              => array('label' => 'MP4 (H.264 video, AAC audio)', 'default' => true, 'hint' => 'Plays in every browser and on every device.'),
		'encoding_webm'              => array('label' => 'WebM (VP9 video, Opus audio)', 'default' => true, 'hint' => 'Smaller files at the same quality.'),
		'encoding_ogv'               => array('label' => 'Ogg (Theora video, Vorbis audio)', 'default' => false, 'hint' => 'Only needed for very old browsers.'),
		'encoding_stills'            => array('label' => 'Capture stills', 'default' => true, 'hint' => 'Up to five PNG frames, one a second from 0:01. The third is used as the player poster.'),
	);
}

/** Value of a form option: the posted one if it is valid, otherwise the default. */
function formValue(array $options, $key, array $post)
{
	$posted = isset($post[$key]) && is_string($post[$key]) ? $post[$key] : null;
	if ($posted !== null && array_key_exists($posted, $options[$key]['values'])) {
		return $posted;
	}
	return (string)$options[$key]['default'];
}


// ---------------------------------------------------------------------------
// Form rendering
// ---------------------------------------------------------------------------

/** Whether a checkbox is ticked: as posted after a conversion, otherwise its default. */
function isChecked(array $checkboxes, $key, array $formPost)
{
	return $formPost ? !empty($formPost[$key]) : (bool)$checkboxes[$key]['default'];
}

/** Label and dropdown for one of formOptions(). $label overrides the option's own label. */
function renderSelect(array $options, $key, array $formPost, $label = null)
{
	$current = formValue($options, $key, $formPost);
	$html = '<label for="' . h($key) . '">' . h($label !== null ? $label : $options[$key]['label']) . '</label>'
		. '<select name="' . h($key) . '" id="' . h($key) . '">';
	foreach ($options[$key]['values'] as $value => $text) {
		$selected = ((string)$value === $current) ? ' selected' : '';
		$html .= '<option value="' . h($value) . '"' . $selected . '>' . h($text) . '</option>';
	}
	return $html . '</select>';
}

/** Checkbox (or switch) with its label, and its hint linked through aria-describedby. */
function renderCheckbox(array $checkboxes, $key, array $formPost, $switch = false)
{
	$checked = isChecked($checkboxes, $key, $formPost) ? ' checked' : '';
	$hint    = isset($checkboxes[$key]['hint']) ? $checkboxes[$key]['hint'] : '';
	$html = '<label for="' . h($key) . '">'
		. '<input type="checkbox" name="' . h($key) . '" id="' . h($key) . '" value="1"'
		. ($switch ? ' role="switch"' : '')
		. ($hint !== '' ? ' aria-describedby="' . h($key) . '_hint"' : '')
		. $checked . '> ' . h($checkboxes[$key]['label']) . '</label>';
	if ($hint !== '') {
		$html .= '<small class="hint" id="' . h($key) . '_hint">' . h($hint) . '</small>';
	}
	return $html;
}

/** Radio buttons for one of formOptions(), grouped under a legend. */
function renderRadios(array $options, $key, array $formPost)
{
	$current = formValue($options, $key, $formPost);
	$html = '<fieldset class="inline"><legend>' . h($options[$key]['label']) . '</legend>';
	foreach ($options[$key]['values'] as $value => $text) {
		$id = $key . '_' . $value;
		$checked = ((string)$value === $current) ? ' checked' : '';
		$html .= '<label for="' . h($id) . '"><input type="radio" name="' . h($key) . '" id="' . h($id) . '" value="' . h($value) . '"' . $checked . '> ' . h($text) . '</label>';
	}
	return $html . '</fieldset>';
}

/** Bytes in a php.ini size such as "100M" or "2G". 0 means empty or no limit. */
function iniBytes($value)
{
	$value = strtolower(trim((string)$value));
	if ($value === '' || !is_numeric(rtrim($value, 'kmg'))) {
		return 0;
	}
	$multipliers = array('k' => 1024, 'm' => 1048576, 'g' => 1073741824);
	$unit = substr($value, -1);
	$bytes = (float)rtrim($value, 'kmg') * (isset($multipliers[$unit]) ? $multipliers[$unit] : 1);
	return max(0, (int)$bytes);
}

/**
 * Largest upload that will actually get through: the smallest of the app's
 * max_file_size and PHP's upload_max_filesize and post_max_size. 0 = no limit.
 */
function uploadLimit($maxFileSize, $uploadMaxFilesize, $postMaxSize)
{
	$limits = array_filter(array((int)$maxFileSize, iniBytes($uploadMaxFilesize), iniBytes($postMaxSize)));
	return $limits ? min($limits) : 0;
}


// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

/** Escape text for HTML output. */
function h($text)
{
	return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function formatBytes($bytes)
{
	$units = array('bytes', 'KB', 'MB', 'GB');
	$i = 0;
	while ($bytes >= 1024 && $i < count($units) - 1) {
		$bytes /= 1024;
		$i++;
	}
	return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
}

/** Turn an uploaded filename into a safe base name for the output files. */
function slugify($name)
{
	$slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
	$slug = substr($slug, 0, 60);
	$slug = rtrim($slug, '-');
	return $slug !== '' ? $slug : 'video';
}

/** Exit code runCommand() returns when it stopped a program that ran out of time. */
const EXIT_TIMED_OUT = -2;

/**
 * Run a program without a shell. Every argument is passed to the program
 * as-is, so nothing in them can be read as a shell command or split into
 * extra arguments. Returns the exit code, -1 if the program could not be
 * started, or EXIT_TIMED_OUT if it was stopped after $timeout seconds
 * (0 = no limit). stdout and stderr go to temporary files so a chatty
 * program can never block on a full pipe.
 */
function runCommand(array $args, &$stdout, &$stderr, $timeout = 0)
{
	$stdout = '';
	$stderr = '';
	$outFile = tempnam(sys_get_temp_dir(), 'ffgui');
	$errFile = tempnam(sys_get_temp_dir(), 'ffgui');
	if ($outFile === false || $errFile === false) {
		$outFile && @unlink($outFile);
		$errFile && @unlink($errFile);
		$stderr = 'Could not create temporary files in ' . sys_get_temp_dir();
		return -1;
	}
	$descriptors = array(
		0 => array('file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'),
		1 => array('file', $outFile, 'w'),
		2 => array('file', $errFile, 'w'),
	);
	$process = proc_open($args, $descriptors, $pipes);
	if (!is_resource($process)) {
		@unlink($outFile);
		@unlink($errFile);
		$stderr = 'Could not start ' . $args[0];
		return -1;
	}

	// Wait for the program, stopping it if it runs out of time. SIGTERM first
	// so ffmpeg can close its files, then SIGKILL if it hasn't stopped in 5s.
	$start      = microtime(true);
	$stoppingAt = null;
	$killed     = false;
	while (true) {
		$status = proc_get_status($process);
		if (!$status['running']) {
			$code = $status['exitcode'];
			break;
		}
		$now = microtime(true);
		if ($stoppingAt === null && $timeout > 0 && $now - $start >= $timeout) {
			$stoppingAt = $now;
			proc_terminate($process);
		} elseif ($stoppingAt !== null && !$killed && $now - $stoppingAt >= 5) {
			$killed = true;
			proc_terminate($process, 9);
		}
		usleep($now - $start < 1 ? 10000 : 100000);
	}
	proc_close($process);

	$stdout = (string)file_get_contents($outFile);
	$stderr = (string)file_get_contents($errFile);
	@unlink($outFile);
	@unlink($errFile);
	if ($stoppingAt !== null) {
		$stderr .= "\nStopped after the time limit of " . $timeout . ' seconds.';
		return EXIT_TIMED_OUT;
	}
	return $code;
}

function writeLog(array $config, $name, $text)
{
	$line = '[' . date('Y-m-d H:i:s') . '] ' . rtrim($text) . "\n";
	@file_put_contents($config['log_dir'] . $name . '_' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/** Run ffmpeg/ffprobe, log the command and its messages, and return the exit code. */
function runLogged(array $config, $logName, array $args, &$stdout = null, $timeout = 0)
{
	$code = runCommand($args, $stdout, $stderr, $timeout);
	writeLog($config, $logName, implode(' ', array_map('escapeshellarg', $args)) . "\nExit code: " . $code . "\n" . $stderr);
	return $code;
}


// ---------------------------------------------------------------------------
// Login throttling
// ---------------------------------------------------------------------------

/**
 * Seconds $ip has to wait before it may try another password. $failures
 * maps each IP address to array('count' => wrong passwords in a row,
 * 'last' => time of the last one). After $maxFailures wrong passwords, each
 * further try has to wait $lockout seconds after the previous one.
 */
function loginWaitSeconds(array $failures, $ip, $now, $maxFailures, $lockout)
{
	if (!isset($failures[$ip]['count'], $failures[$ip]['last']) || $failures[$ip]['count'] < $maxFailures) {
		return 0;
	}
	return max(0, (int)$failures[$ip]['last'] + (int)$lockout - (int)$now);
}

/**
 * Read and update the stored login failures while holding a lock, so
 * parallel requests can't slip extra guesses in. $update receives the
 * failures and returns array(new failures, result); the result is returned.
 * The file lives in log_dir, so the limit covers every session.
 */
function updateLoginFailures(array $config, callable $update)
{
	$handle = @fopen($config['log_dir'] . 'login_failures.json', 'c+');
	if (!$handle) {
		throw new RuntimeException('The logs folder needs write permission for the web server user.');
	}
	try {
		flock($handle, LOCK_EX);
		$failures = json_decode((string)stream_get_contents($handle), true);
		list($failures, $result) = $update(is_array($failures) ? $failures : array());
		ftruncate($handle, 0);
		rewind($handle);
		fwrite($handle, json_encode($failures));
		fflush($handle);
		return $result;
	} finally {
		flock($handle, LOCK_UN);
		fclose($handle);
	}
}

/**
 * Call before checking a password. Returns 0 and counts the attempt as a
 * failure (until loginSucceeded() clears it), or returns the seconds to wait
 * if $ip has to wait first. Counting up front means a burst of parallel
 * guesses can't get past the limit.
 */
function loginAttemptStart(array $config, $ip, $now = null)
{
	if ($config['max_login_failures'] <= 0) {
		return 0; // no limit
	}
	$now = $now === null ? time() : $now;
	return updateLoginFailures($config, function (array $failures) use ($config, $ip, $now) {
		// Forget addresses that haven't failed for a day
		foreach ($failures as $key => $entry) {
			if (!isset($entry['last']) || $entry['last'] < $now - 86400) {
				unset($failures[$key]);
			}
		}
		$wait = loginWaitSeconds($failures, $ip, $now, $config['max_login_failures'], $config['login_lockout']);
		if ($wait === 0) {
			$count = isset($failures[$ip]['count']) ? (int)$failures[$ip]['count'] : 0;
			$failures[$ip] = array('count' => $count + 1, 'last' => $now);
		}
		return array($failures, $wait);
	});
}

/** Clear the failures for $ip after a correct password. */
function loginSucceeded(array $config, $ip)
{
	updateLoginFailures($config, function (array $failures) use ($ip) {
		unset($failures[$ip]);
		return array($failures, null);
	});
}


// ---------------------------------------------------------------------------
// Validation and ffmpeg arguments (no side effects, unit tested)
// ---------------------------------------------------------------------------

/**
 * Check an entry from $_FILES and return its lower-case extension.
 * Throws RuntimeException with a message that is safe to show the user.
 */
function validateUpload($file, $maxFileSize)
{
	if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
		throw new RuntimeException('Please choose a video file.');
	}
	switch ($file['error']) {
		case UPLOAD_ERR_OK:
			break;
		case UPLOAD_ERR_INI_SIZE:
		case UPLOAD_ERR_FORM_SIZE:
			throw new RuntimeException('The file is larger than the server upload limit.');
		case UPLOAD_ERR_NO_FILE:
			throw new RuntimeException('Please choose a video file.');
		default:
			throw new RuntimeException('The upload failed (error code ' . (int)$file['error'] . ').');
	}
	if ($maxFileSize > 0 && $file['size'] > $maxFileSize) {
		throw new RuntimeException('The file is ' . formatBytes($file['size']) . '; the limit is ' . formatBytes($maxFileSize) . '.');
	}
	$extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
	if (!in_array($extension, ALLOWED_EXTENSIONS, true)) {
		throw new RuntimeException('Unsupported file type. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS) . '.');
	}
	return $extension;
}

/**
 * Read the posted settings. Only values from the option lists are accepted.
 * Throws RuntimeException when a value is not in its list.
 */
function readSettings(array $options, array $post)
{
	$settings = array();
	foreach ($options as $key => $option) {
		$value = isset($post[$key]) && is_string($post[$key]) ? $post[$key] : (string)$option['default'];
		if (!array_key_exists($value, $option['values'])) {
			throw new RuntimeException('Invalid value for ' . $option['label'] . '.');
		}
		$settings[$key] = (string)$value;
	}
	$settings['deinterlace'] = !empty($post['encoding_video_deinterlace']);
	$settings['audio']       = !empty($post['encoding_enable_audio']);
	return $settings;
}

/** Video filter that fits the video inside the chosen size, keeps the aspect ratio and never upscales. */
function scaleFilter($videoSize)
{
	list($width, $height) = array_map('intval', explode('x', $videoSize));
	return "scale=w='min(iw," . $width . ")':h='min(ih," . $height . ")':force_original_aspect_ratio=decrease:force_divisible_by=2";
}

/**
 * ffmpeg output arguments for each format, in the order the player should
 * try them. Expects settings from readSettings().
 */
function outputFormats(array $settings)
{
	$scale = scaleFilter($settings['video_size']);
	// Only deinterlace frames that are actually marked as interlaced
	$videoFilter = ($settings['deinterlace'] ? 'yadif=deint=interlaced,' : '') . $scale;
	$videoArgs = array('-vf', $videoFilter, '-pix_fmt', 'yuv420p', '-r', $settings['video_framerate'], '-b:v', $settings['video_bitrate'] . 'k');

	$channels       = $settings['encoding_audio_channels'] === 'stereo' ? 2 : 1;
	$audioKbps      = (int)$settings['encoding_audio_bitrate'];
	$sampleRateArgs = array('-ar', $settings['encoding_audio_sampling_rate']);
	$channelArgs    = array('-ac', (string)$channels);

	$noAudio    = array('-an');
	$aacArgs    = array_merge(array('-c:a', 'aac', '-b:a', $audioKbps . 'k'), $sampleRateArgs, $channelArgs);
	// Opus always runs at 48kHz and allows at most 256 kbps per channel
	$opusArgs   = array_merge(array('-c:a', 'libopus', '-b:a', min($audioKbps, 256 * $channels) . 'k'), $channelArgs);
	$vorbisArgs = array_merge(array('-c:a', 'libvorbis', '-q:a', VORBIS_QUALITY[$audioKbps]), $sampleRateArgs, $channelArgs);

	return array(
		'encoding_x264' => array(
			'label' => 'MP4',
			'ext'   => 'mp4',
			'type'  => 'video/mp4',
			'args'  => array_merge(
				array('-c:v', 'libx264', '-preset', 'medium'),
				$videoArgs,
				$settings['audio'] ? $aacArgs : $noAudio,
				array('-movflags', '+faststart') // lets playback start before the whole file has downloaded
			),
		),
		'encoding_webm' => array(
			'label' => 'WebM',
			'ext'   => 'webm',
			'type'  => 'video/webm',
			'args'  => array_merge(
				array('-c:v', 'libvpx-vp9', '-deadline', 'good', '-cpu-used', '4', '-row-mt', '1'),
				$videoArgs,
				$settings['audio'] ? $opusArgs : $noAudio
			),
		),
		'encoding_ogv' => array(
			'label' => 'Ogg',
			'ext'   => 'ogv',
			'type'  => 'video/ogg',
			'args'  => array_merge(
				array('-c:v', 'libtheora'),
				$videoArgs,
				$settings['audio'] ? $vorbisArgs : $noAudio
			),
		),
	);
}

/** True if ffprobe's JSON output describes a real video in a container we accept. */
function isAllowedVideo($probeJson)
{
	$probe = json_decode((string)$probeJson, true);
	if (!is_array($probe) || !isset($probe['format']['format_name']) || !is_string($probe['format']['format_name'])) {
		return false;
	}
	$containers = explode(',', $probe['format']['format_name']);
	if (array_diff($containers, ALLOWED_FORMATS)) {
		return false;
	}
	$streams = isset($probe['streams']) && is_array($probe['streams']) ? $probe['streams'] : array();
	foreach ($streams as $stream) {
		// Cover art in an audio file shows up as a video stream; ignore it
		$isCoverArt = !empty($stream['disposition']['attached_pic']);
		if (isset($stream['codec_type']) && $stream['codec_type'] === 'video' && !$isCoverArt) {
			return true;
		}
	}
	return false;
}

/** HTML page with an HTML5 player for the converted videos. */
function buildPlayerPage($title, $width, $height, array $videos, $poster)
{
	$html  = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
	$html .= "<meta charset=\"utf-8\">\n";
	$html .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
	$html .= '<title>' . h($title) . "</title>\n";
	$html .= "<style>body{font-family:sans-serif;margin:1rem}video{max-width:100%;height:auto;background:#000}</style>\n";
	$html .= "</head>\n<body>\n";
	$html .= '<video controls preload="metadata" width="' . (int)$width . '" height="' . (int)$height . '"';
	if ($poster) {
		$html .= ' poster="' . h(rawurlencode($poster)) . '"';
	}
	$html .= ">\n";
	foreach ($videos as $video) {
		$html .= "\t<source src=\"" . h(rawurlencode($video['file'])) . '" type="' . h($video['type']) . "\">\n";
	}
	$html .= "\tYour browser can't play this video. Please use a download link below.\n";
	$html .= "</video>\n";

	$links = array();
	foreach ($videos as $video) {
		$links[] = '<a href="' . h(rawurlencode($video['file'])) . '" download>' . h($video['label']) . '</a>';
	}
	$html .= '<p><strong>Download video:</strong> ' . implode(' | ', $links) . "</p>\n";
	$html .= "</body>\n</html>\n";
	return $html;
}


/*
	TODO

	iPod / iPhone
	-acodec aac -ab 128kb -vcodec mpeg4 -b 1200kb -mbd 2 -flags +4mv+trell -aic 2 -cmp 2 -subcmp 2 -s 320x180

	PSP
	-b:v 300-s 320x240 -vcodec xvid -ab 32 -ar 24000 -acodec aac fin

	// Extracting sound from a video, and save it as Mp3
	ffmpeg -i source_video.avi -vn -ar 44100 -ac 2 -ab 192 -f mp3 sound.mp3

	Convert to animated gif(uncompressed)
	ffmpeg -i video_origine.avi gif_anime.gif

	Convert .avi to .flv
	ffmpeg -i video_origine.avi -ab 56 -ar 44100 -b 200 -r 15 -s 320x240 -f flv

	Convert .avi to mpeg for dvd players
	ffmpeg -i temp.mp4 -target pal-dvd -ps 2000000000 -aspect 16:9 finale_video.mpeg

	Compress .avi to divx
	ffmpeg -i temp.avi -y -vcodec msmpeg4v2 video_finale.avi
*/
