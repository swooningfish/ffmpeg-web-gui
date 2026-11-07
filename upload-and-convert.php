<?php

/*
	Name    : ffmpeg web gui
	Author  : Greg Colley
	Created : 03/12/2011
	Update  : 07/11/2026
	Version : 0.3 beta

	This is a upload and convert php script for ffmpeg. Allowing you to convert video files to html5 ready video via ffmpeg.

	Requires PHP 7.4+ and ffmpeg / ffprobe 4.3+.
	Settings live in config.php; put your own overrides in config.local.php.
*/

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$config = require __DIR__ . '/config.php';
if (is_file(__DIR__ . '/config.local.php')) {
	$localConfig = require __DIR__ . '/config.local.php';
	if (!is_array($localConfig)) {
		http_response_code(500);
		exit('config.local.php must return an array of settings, e.g. <?php return array(\'password_hash\' => \'...\');');
	}
	$config = array_replace($config, $localConfig);
}
set_time_limit((int)$config['max_execution_time']);

// File extensions we accept. The real container is confirmed with ffprobe.
const ALLOWED_EXTENSIONS = array('mp4', 'm4v', 'mov', 'avi', 'mpg', 'mpeg', 'wmv', 'asf', 'webm', 'mkv', 'ogv', 'flv', '3gp');

// ffprobe container names we accept. Anything else (playlists such as hls or
// concat, image sequences, etc.) is rejected because it can make ffmpeg read
// other files on the server.
const ALLOWED_FORMATS = array('mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2', 'avi', 'mpeg', 'mpegts', 'mpegvideo', 'asf', 'matroska', 'webm', 'ogg', 'flv');

// Form options. These lists are used both to draw the form and to validate
// what is posted, so only these exact values ever reach ffmpeg.
$options = array(
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

// Checkboxes and whether they are ticked by default
$checkboxes = array(
	'encoding_video_deinterlace' => array('label' => 'Deinterlace',             'default' => true),
	'encoding_enable_audio'      => array('label' => 'Enable audio',            'default' => true),
	'encoding_x264'              => array('label' => 'MP4 (H.264 video, AAC audio)', 'default' => true),
	'encoding_webm'              => array('label' => 'WebM (VP9 video, Opus audio)', 'default' => true),
	'encoding_ogv'               => array('label' => 'Ogg (Theora video, Vorbis audio)', 'default' => false),
	'encoding_stills'            => array('label' => 'Capture stills',          'default' => true),
);


// ---------------------------------------------------------------------------
// Helpers
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
	return $slug !== '' ? $slug : 'video';
}

/**
 * Run a program without a shell. Every argument is passed to the program
 * as-is, so nothing in them can be read as a shell command or split into
 * extra arguments. Returns the exit code. stderr goes to a temporary file
 * so a chatty program can never block on a full pipe.
 */
function runCommand(array $args, &$stdout, &$stderr)
{
	$stdout = '';
	$stderr = '';
	$errorFile = tempnam(sys_get_temp_dir(), 'ffgui');
	$descriptors = array(
		0 => array('file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'),
		1 => array('pipe', 'w'),
		2 => array('file', $errorFile, 'w'),
	);
	$process = proc_open($args, $descriptors, $pipes);
	if (!is_resource($process)) {
		@unlink($errorFile);
		$stderr = 'Could not start ' . $args[0];
		return -1;
	}
	$stdout = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$code = proc_close($process);
	$stderr = (string)file_get_contents($errorFile);
	@unlink($errorFile);
	return $code;
}

function writeLog(array $config, $name, $text)
{
	$line = '[' . date('Y-m-d H:i:s') . '] ' . rtrim($text) . "\n";
	@file_put_contents($config['log_dir'] . $name . '_' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/** Run ffmpeg/ffprobe, log the command and its messages, and return the exit code. */
function runLogged(array $config, $logName, array $args, &$stdout = null)
{
	$code = runCommand($args, $stdout, $stderr);
	writeLog($config, $logName, implode(' ', array_map('escapeshellarg', $args)) . "\nExit code: " . $code . "\n" . $stderr);
	return $code;
}

/** Value of a form option: the posted one if it is valid, otherwise the default. */
function formValue(array $options, $key)
{
	$posted = isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : null;
	if ($posted !== null && array_key_exists($posted, $options[$key]['values'])) {
		return $posted;
	}
	return $options[$key]['default'];
}

function isChecked(array $checkboxes, $key)
{
	if (isset($_POST['action']) && $_POST['action'] === 'convert') {
		return !empty($_POST[$key]);
	}
	return $checkboxes[$key]['default'];
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


// ---------------------------------------------------------------------------
// Upload and convert
// ---------------------------------------------------------------------------

/**
 * Validate the upload, convert it and return a summary of what was made.
 * Throws RuntimeException with a message that is safe to show the user.
 */
function convertUpload(array $config, array $options, array $checkboxes)
{
	// 1. Check the upload itself
	if (!isset($_FILES['file']) || !is_array($_FILES['file']) || is_array($_FILES['file']['error'])) {
		throw new RuntimeException('Please choose a video file.');
	}
	$file = $_FILES['file'];
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
	if ($config['max_file_size'] > 0 && $file['size'] > $config['max_file_size']) {
		throw new RuntimeException('The file is ' . formatBytes($file['size']) . '; the limit is ' . formatBytes($config['max_file_size']) . '.');
	}
	$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
	if (!in_array($extension, ALLOWED_EXTENSIONS, true)) {
		throw new RuntimeException('Unsupported file type. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS) . '.');
	}

	// 2. Read the settings. Only values from the option lists are accepted.
	$settings = array();
	foreach ($options as $key => $option) {
		$value = isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : (string)$option['default'];
		if (!array_key_exists($value, $option['values'])) {
			throw new RuntimeException('Invalid value for ' . $option['label'] . '.');
		}
		$settings[$key] = (string)$value;
	}
	$deinterlace  = !empty($_POST['encoding_video_deinterlace']);
	$audioEnabled = !empty($_POST['encoding_enable_audio']);
	$wantStills   = !empty($_POST['encoding_stills']);

	list($width, $height) = array_map('intval', explode('x', $settings['video_size']));
	// Fit inside the chosen size, keep the aspect ratio, never upscale
	$scale = "scale=w='min(iw," . $width . ")':h='min(ih," . $height . ")':force_original_aspect_ratio=decrease:force_divisible_by=2";
	// Only deinterlace frames that are actually marked as interlaced
	$videoFilter = ($deinterlace ? 'yadif=deint=interlaced,' : '') . $scale;
	$videoArgs = array('-vf', $videoFilter, '-pix_fmt', 'yuv420p', '-r', $settings['video_framerate'], '-b:v', $settings['video_bitrate'] . 'k');
	$channels  = $settings['encoding_audio_channels'] === 'stereo' ? 2 : 1;
	$audioKbps = (int)$settings['encoding_audio_bitrate'];
	$sampleRateArgs = array('-ar', $settings['encoding_audio_sampling_rate']);
	$channelArgs = array('-ac', (string)$channels);
	// Opus allows at most 256 kbps per channel
	$opusArgs = array_merge(array('-c:a', 'libopus', '-b:a', min($audioKbps, 256 * $channels) . 'k'), $channelArgs);
	// Vorbis rejects many bitrate / sample rate combinations in bitrate mode,
	// so map the chosen bitrate to the nearest Vorbis quality level instead
	$vorbisQuality = array(320 => '9', 256 => '8', 192 => '6', 128 => '4', 96 => '2', 32 => '-1', 8 => '-1');
	$vorbisArgs = array_merge(array('-c:a', 'libvorbis', '-q:a', $vorbisQuality[$audioKbps]), $sampleRateArgs, $channelArgs);
	$aacArgs = array_merge(array('-c:a', 'aac', '-b:a', $audioKbps . 'k'), $sampleRateArgs, $channelArgs);

	// Output formats, in the order the player should try them
	$formats = array(
		'encoding_x264' => array(
			'label' => 'MP4',
			'ext'   => 'mp4',
			'type'  => 'video/mp4',
			'args'  => array_merge(
				array('-c:v', 'libx264', '-preset', 'medium'),
				$videoArgs,
				$audioEnabled ? $aacArgs : array('-an'),
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
				// Opus always runs at 48kHz, so the sample rate setting is not passed
				$audioEnabled ? $opusArgs : array('-an')
			),
		),
		'encoding_ogv' => array(
			'label' => 'Ogg',
			'ext'   => 'ogv',
			'type'  => 'video/ogg',
			'args'  => array_merge(
				array('-c:v', 'libtheora'),
				$videoArgs,
				$audioEnabled ? $vorbisArgs : array('-an')
			),
		),
	);
	$selected = array();
	foreach ($formats as $key => $format) {
		if (!empty($_POST[$key])) {
			$selected[$key] = $format;
		}
	}
	if (!$selected && !$wantStills) {
		throw new RuntimeException('Please choose at least one output format.');
	}

	// 3. Check the setup and make sure only one conversion runs at a time
	foreach (array('ffmpeg', 'ffprobe') as $binary) {
		if (!is_file($config[$binary]) || !is_executable($config[$binary])) {
			throw new RuntimeException($binary . ' was not found at the path set in the config. Set \'' . $binary . '\' in config.local.php.');
		}
	}
	foreach (array('upload_dir', 'converted_dir', 'log_dir') as $dirKey) {
		if (!is_dir($config[$dirKey]) || !is_writable($config[$dirKey])) {
			throw new RuntimeException('The ' . basename($config[$dirKey]) . ' folder needs write permission for the web server user.');
		}
	}
	$lock = fopen($config['log_dir'] . 'convert.lock', 'c');
	if (!$lock) {
		throw new RuntimeException('Could not create the lock file in the logs folder.');
	}
	if (!flock($lock, LOCK_EX | LOCK_NB)) {
		fclose($lock);
		throw new RuntimeException('Another conversion is running. Please try again in a few minutes.');
	}

	// Files are stored under random names; the original name is never used in a path
	$jobId      = bin2hex(random_bytes(8));
	$baseName   = slugify(pathinfo($file['name'], PATHINFO_FILENAME));
	$uploadPath = $config['upload_dir'] . $jobId . '.' . $extension;
	$jobDir     = $config['converted_dir'] . $jobId . '/';

	try {
		if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
			throw new RuntimeException('Could not save the uploaded file.');
		}

		// 4. Confirm it really is a video in a container we accept
		$probeArgs = array($config['ffprobe'], '-v', 'error', '-protocol_whitelist', 'file',
			'-show_entries', 'format=format_name:stream=codec_type:stream_disposition=attached_pic', '-of', 'json', $uploadPath);
		$code  = runLogged($config, 'ffprobe', $probeArgs, $probeOutput);
		$probe = $code === 0 ? json_decode($probeOutput, true) : null;
		$containers = isset($probe['format']['format_name']) ? explode(',', $probe['format']['format_name']) : array();
		$hasVideo = false;
		foreach (isset($probe['streams']) ? $probe['streams'] : array() as $stream) {
			// Cover art in an audio file shows up as a video stream; ignore it
			$isCoverArt = !empty($stream['disposition']['attached_pic']);
			if (isset($stream['codec_type']) && $stream['codec_type'] === 'video' && !$isCoverArt) {
				$hasVideo = true;
			}
		}
		if (!$containers || array_diff($containers, ALLOWED_FORMATS) || !$hasVideo) {
			throw new RuntimeException('That file is not a supported video.');
		}

		if (!mkdir($jobDir, 0755)) {
			throw new RuntimeException('Could not create the output folder.');
		}

		// 5. Convert
		$inputArgs = array($config['ffmpeg'], '-hide_banner', '-nostdin', '-nostats', '-y', '-protocol_whitelist', 'file');
		if ($config['max_duration'] > 0) {
			$inputArgs[] = '-t'; // only read this many seconds of the input
			$inputArgs[] = (string)(int)$config['max_duration'];
		}
		$inputArgs[] = '-i';
		$inputArgs[] = $uploadPath;
		// Stop runaway output (e.g. a crafted file that makes ffmpeg duplicate frames forever)
		$sizeLimitArgs = $config['max_output_size'] > 0 ? array('-fs', (string)(int)$config['max_output_size']) : array();
		$made   = array();
		$failed = array();
		foreach ($selected as $format) {
			$outName = $baseName . '.' . $format['ext'];
			$code = runLogged($config, 'ffmpeg', array_merge($inputArgs, $format['args'], $sizeLimitArgs, array($jobDir . $outName)));
			if ($code === 0 && is_file($jobDir . $outName) && filesize($jobDir . $outName) > 0) {
				$made[] = array('label' => $format['label'], 'file' => $outName, 'type' => $format['type']);
			} else {
				@unlink($jobDir . $outName);
				$failed[] = $format['label'] . ' (exit code ' . $code . ')';
			}
		}

		// Up to five stills, one a second starting one second in
		$stills = array();
		if ($wantStills) {
			$stillArgs = array('-ss', '1', '-vf', 'fps=1,' . $scale, '-frames:v', '5', $jobDir . $baseName . '_still_%d.png');
			runLogged($config, 'ffmpeg', array_merge($inputArgs, $stillArgs));
			for ($i = 1; $i <= 5; $i++) {
				if (is_file($jobDir . $baseName . '_still_' . $i . '.png')) {
					$stills[] = $baseName . '_still_' . $i . '.png';
				}
			}
			if (!$stills) {
				$failed[] = 'Stills';
			}
		}

		// 6. Player page for whatever was made
		$player = null;
		if ($made) {
			$poster = $stills ? $stills[min(2, count($stills) - 1)] : null;
			$player = $baseName . '_player.html';
			file_put_contents($jobDir . $player, buildPlayerPage($file['name'], $width, $height, $made, $poster));
		}

		if (!$made && !$stills) {
			@rmdir($jobDir);
		}

		return array(
			'name'   => $file['name'],
			'size'   => $file['size'],
			'url'    => $config['converted_url'] . $jobId . '/',
			'made'   => $made,
			'stills' => $stills,
			'player' => $player,
			'failed' => $failed,
		);
	} finally {
		if ($config['delete_upload_after_convert'] && is_file($uploadPath)) {
			@unlink($uploadPath);
		}
		flock($lock, LOCK_UN);
		fclose($lock);
	}
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


// ---------------------------------------------------------------------------
// Request handling
// ---------------------------------------------------------------------------

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; form-action 'self'");

session_set_cookie_params(array(
	'httponly' => true,
	'samesite' => 'Lax',
	'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
));
session_start();
if (empty($_SESSION['csrf'])) {
	$_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$errors = array();
$result = null;

$loginConfigured = !$config['require_login'] || $config['password_hash'] !== '';
$loggedIn = !$config['require_login'] || !empty($_SESSION['logged_in']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
	$csrfOk = isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);

	if ($action === '' && !empty($_SERVER['CONTENT_LENGTH'])) {
		// PHP drops the whole request body when it is bigger than post_max_size
		$errors[] = 'The file is larger than the server upload limit.';
	} elseif (!$csrfOk) {
		$errors[] = 'Your session expired. Please try again.';
	} elseif ($action === 'login' && $loginConfigured && $config['require_login']) {
		$password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
		if (password_verify($password, $config['password_hash'])) {
			session_regenerate_id(true);
			$_SESSION['logged_in'] = true;
			$loggedIn = true;
		} else {
			sleep(1); // slow down password guessing
			$errors[] = 'Incorrect password.';
		}
	} elseif ($action === 'logout') {
		$_SESSION = array();
		session_regenerate_id(true);
		$_SESSION['csrf'] = bin2hex(random_bytes(32));
		$loggedIn = !$config['require_login'];
	} elseif ($action === 'convert' && $loginConfigured && $loggedIn) {
		// Release the session lock so other tabs aren't blocked while ffmpeg runs
		session_write_close();
		try {
			$result = convertUpload($config, $options, $checkboxes);
		} catch (RuntimeException $e) {
			$errors[] = $e->getMessage();
		}
	}
}

function renderSelect(array $options, $key)
{
	$current = formValue($options, $key);
	$html = '<select name="' . h($key) . '" id="' . h($key) . '">';
	foreach ($options[$key]['values'] as $value => $label) {
		$selected = ((string)$value === (string)$current) ? ' selected' : '';
		$html .= '<option value="' . h($value) . '"' . $selected . '>' . h($label) . '</option>';
	}
	return $html . '</select>';
}

function renderCheckbox(array $checkboxes, $key)
{
	$checked = isChecked($checkboxes, $key) ? ' checked' : '';
	return '<label for="' . h($key) . '">' . h($checkboxes[$key]['label']) . '</label>'
		. '<input type="checkbox" name="' . h($key) . '" id="' . h($key) . '" value="1" class="checkbox"' . $checked . ' />';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>FFmpeg GUI</title>
	<style type="text/css">
	body {
		font-family: sans-serif;
	}
	.clear {
		clear:both;
	}
	.error {
		color:#c00;
		font-weight:bold;
		padding:10px;
	}
	.uploadSuccess {
		color:#45862d;
		padding:10px;
	}
	.uploadSuccess img {
		margin:4px 4px 0 0;
	}
	.fieldset {
		clear:both;
		padding:5px;
	}
	.fieldset label {
		width:250px;
		float:left;
	}
	.fieldset input {
		width:120px;
		float:left;
	}
	.fieldset input.checkbox {
		width:auto;
		float:left;
	}
	.logout {
		float:right;
	}
	</style>
</head>
<body>

	<h1>ffmpeg web gui</h1>

<?php if (!$loginConfigured): ?>
	<div class="error">
		Set a password before using ffmpeg web gui. Create <code>config.local.php</code> next to this file containing:
		<pre>&lt;?php
return array('password_hash' =&gt; 'PASTE HASH HERE');</pre>
		Generate the hash with: <code>php -r "echo password_hash('your password', PASSWORD_DEFAULT), PHP_EOL;"</code>
	</div>

<?php elseif (!$loggedIn): ?>
	<?php foreach ($errors as $error): ?>
		<div class="error"><?php echo h($error); ?></div>
	<?php endforeach; ?>
	<form action="" method="post">
		<input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>" />
		<input type="hidden" name="action" value="login" />
		<div class="fieldset">
			<label for="password">Password:</label>
			<input type="password" name="password" id="password" autocomplete="current-password" autofocus />
		</div>
		<div class="fieldset">
			<input type="submit" value="Log in" />
		</div>
	</form>

<?php else: ?>
	<?php if ($config['require_login']): ?>
	<form action="" method="post" class="logout">
		<input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>" />
		<input type="hidden" name="action" value="logout" />
		<input type="submit" value="Log out" />
	</form>
	<?php endif; ?>

	<?php foreach ($errors as $error): ?>
		<div class="error"><?php echo h($error); ?></div>
	<?php endforeach; ?>

	<?php if ($result): ?>
		<?php if ($result['failed']): ?>
		<div class="error">
			Failed: <?php echo h(implode(', ', $result['failed'])); ?>. See the ffmpeg log in the logs folder for details.
		</div>
		<?php endif; ?>
		<?php if ($result['made'] || $result['stills']): ?>
		<div class="uploadSuccess">
			Converted <strong><?php echo h($result['name']); ?></strong> (<?php echo h(formatBytes($result['size'])); ?>).
			<?php if ($result['player']): ?>
				<br /><a href="<?php echo h($result['url'] . $result['player']); ?>">Open the HTML5 player</a>
			<?php endif; ?>
			<?php if ($result['made']): ?>
				<br />Download:
				<?php foreach ($result['made'] as $i => $video): ?>
					<?php echo $i ? ' | ' : ''; ?><a href="<?php echo h($result['url'] . $video['file']); ?>" download><?php echo h($video['label']); ?></a>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php if ($result['stills']): ?>
				<br />
				<?php foreach ($result['stills'] as $still): ?>
					<a href="<?php echo h($result['url'] . $still); ?>"><img src="<?php echo h($result['url'] . $still); ?>" width="100" alt="Still" /></a>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	<?php endif; ?>

	<form action="" method="post" enctype="multipart/form-data">
		<input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>" />
		<input type="hidden" name="action" value="convert" />

		<div class="fieldset">
			<label for="file">Video file:</label>
			<input type="file" name="file" id="file" accept="video/*,<?php echo h('.' . implode(',.', ALLOWED_EXTENSIONS)); ?>" required />
		</div>
		<div class="fieldset">
			<label for="video_size">Video size (max):</label>
			<?php echo renderSelect($options, 'video_size'); ?>
		</div>
		<div class="fieldset">
			<label for="video_bitrate">Video bitrate:</label>
			<?php echo renderSelect($options, 'video_bitrate'); ?>
		</div>
		<div class="fieldset">
			<label for="video_framerate">Frame rate:</label>
			<?php echo renderSelect($options, 'video_framerate'); ?>
		</div>
		<div class="fieldset">
			<?php echo renderCheckbox($checkboxes, 'encoding_video_deinterlace'); ?>
		</div>
		<div class="fieldset">
			<?php echo renderCheckbox($checkboxes, 'encoding_enable_audio'); ?>
		</div>
		<div class="fieldset">
			<label for="encoding_audio_sampling_rate">Audio sampling rate:</label>
			<?php echo renderSelect($options, 'encoding_audio_sampling_rate'); ?>
		</div>
		<div class="fieldset">
			<label for="encoding_audio_bitrate">Audio bitrate:</label>
			<?php echo renderSelect($options, 'encoding_audio_bitrate'); ?>
		</div>
		<?php foreach ($options['encoding_audio_channels']['values'] as $value => $label): ?>
		<div class="fieldset">
			<label for="channels_<?php echo h($value); ?>"><?php echo h($label); ?></label>
			<input type="radio" name="encoding_audio_channels" id="channels_<?php echo h($value); ?>" value="<?php echo h($value); ?>" class="checkbox"<?php echo formValue($options, 'encoding_audio_channels') === $value ? ' checked' : ''; ?> />
		</div>
		<?php endforeach; ?>
		<div class="clear"></div>

		<h2>HTML5 Video Compatible</h2>
		<div class="fieldset">
			<?php echo renderCheckbox($checkboxes, 'encoding_x264'); ?>
		</div>
		<div class="fieldset">
			<?php echo renderCheckbox($checkboxes, 'encoding_webm'); ?>
		</div>
		<div class="fieldset">
			<?php echo renderCheckbox($checkboxes, 'encoding_ogv'); ?>
		</div>
		<div class="fieldset">
			<?php echo renderCheckbox($checkboxes, 'encoding_stills'); ?>
		</div>
		<div class="clear"></div>

		<!-- Todo
		<h2>Other Encodings</h2>
		iPod / iPhone (320x180), PSP (320x240), animated gif, extract mp3,
		flv, mpeg for dvd players, divx
		-->

		<p>Conversion runs while you wait and can take several minutes for large files.</p>
		<input type="submit" value="Upload and convert" />
	</form>
<?php endif; ?>

</body>
</html>
