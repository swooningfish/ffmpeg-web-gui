<?php

/*
	Name    : ffmpeg web gui
	Author  : Greg Colley
	Created : 03/12/2011
	Update  : 07/11/2026
	Version : 0.3 beta

	This is a upload and convert php script for ffmpeg. Allowing you to convert video files to html5 ready video via ffmpeg.

	Requires PHP 8.4+ and ffmpeg / ffprobe 4.3+.
	Settings live in config.php; put your own overrides in config.local.php.
	The conversion logic lives in src/functions.php.
*/

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/src/functions.php';

$config = require __DIR__ . '/config.php';
if (is_file(__DIR__ . '/config.local.php')) {
	$localConfig = require __DIR__ . '/config.local.php';
	if (!is_array($localConfig)) {
		http_response_code(500);
		exit('config.local.php must return an array of settings, e.g. <?php return array(\'password_hash\' => \'...\');');
	}
	$config = array_replace($config, $localConfig);
}
// A little longer than the conversion deadline, so ffmpeg is always stopped
// and cleaned up by convertUpload() before PHP gives up
set_time_limit($config['max_execution_time'] > 0 ? (int)$config['max_execution_time'] + 60 : 0);

$options    = formOptions();
$checkboxes = formCheckboxes();


// ---------------------------------------------------------------------------
// Request handling
// ---------------------------------------------------------------------------

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; form-action 'self'");

session_set_cookie_params(array(
	'httponly' => true,
	'samesite' => 'Lax',
	'secure'   => $config['secure_cookie'] !== null
		? (bool)$config['secure_cookie']
		: !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
));
session_start();
if (empty($_SESSION['csrf'])) {
	$_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf'];

$errors = array();
$result = null;

$loginConfigured = !$config['require_login'] || $config['password_hash'] !== '';
$loggedIn = !$config['require_login'] || !empty($_SESSION['logged_in']);
$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfOk = isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals($csrfToken, $_POST['csrf']);

	if ($action === '' && !empty($_SERVER['CONTENT_LENGTH'])) {
		// PHP drops the whole request body when it is bigger than post_max_size
		$errors[] = 'The file is larger than the server upload limit.';
	} elseif (!$csrfOk) {
		$errors[] = 'Your session expired. Please try again.';
	} elseif ($action === 'login' && $loginConfigured && $config['require_login']) {
		$password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
		$ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
		try {
			$wait = loginAttemptStart($config, $ip);
			if ($wait > 0) {
				$errors[] = 'Too many incorrect passwords. Please try again in ' . ceil($wait / 60) . ' minute(s).';
			} elseif (password_verify($password, $config['password_hash'])) {
				loginSucceeded($config, $ip);
				session_regenerate_id(true);
				$_SESSION['logged_in'] = true;
				$loggedIn = true;
			} else {
				sleep(1); // slow down password guessing
				$errors[] = 'Incorrect password.';
			}
		} catch (RuntimeException $e) {
			$errors[] = $e->getMessage();
		}
	} elseif ($action === 'logout') {
		$_SESSION = array();
		session_regenerate_id(true);
		$_SESSION['csrf'] = $csrfToken = bin2hex(random_bytes(32));
		$loggedIn = !$config['require_login'];
	} elseif ($action === 'convert' && $loginConfigured && $loggedIn) {
		// Release the session lock so other tabs aren't blocked while ffmpeg runs
		session_write_close();
		try {
			$result = convertUpload($config, $options, $_POST, $_FILES);
		} catch (RuntimeException $e) {
			$errors[] = $e->getMessage();
		}
	}
}

// Keep the user's choices after a conversion, otherwise use the defaults
$formPost = $action === 'convert' ? $_POST : array();

function isChecked(array $checkboxes, $key, array $formPost)
{
	return $formPost ? !empty($formPost[$key]) : $checkboxes[$key]['default'];
}

function renderSelect(array $options, $key, array $formPost)
{
	$current = formValue($options, $key, $formPost);
	$html = '<select name="' . h($key) . '" id="' . h($key) . '">';
	foreach ($options[$key]['values'] as $value => $label) {
		$selected = ((string)$value === $current) ? ' selected' : '';
		$html .= '<option value="' . h($value) . '"' . $selected . '>' . h($label) . '</option>';
	}
	return $html . '</select>';
}

function renderCheckbox(array $checkboxes, $key, array $formPost)
{
	$checked = isChecked($checkboxes, $key, $formPost) ? ' checked' : '';
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
		<input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>" />
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
		<input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>" />
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
		<input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>" />
		<input type="hidden" name="action" value="convert" />

		<div class="fieldset">
			<label for="file">Video file:</label>
			<input type="file" name="file" id="file" accept="video/*,<?php echo h('.' . implode(',.', ALLOWED_EXTENSIONS)); ?>" required />
		</div>
		<div class="fieldset">
			<label for="video_size">Video size (max):</label>
			<?php echo renderSelect($options, 'video_size', $formPost); ?>
		</div>
		<div class="fieldset">
			<label for="video_bitrate">Video bitrate:</label>
			<?php echo renderSelect($options, 'video_bitrate', $formPost); ?>
		</div>
		<div class="fieldset">
			<label for="video_framerate">Frame rate:</label>
			<?php echo renderSelect($options, 'video_framerate', $formPost); ?>
		</div>
		<div class="fieldset">
			<?php echo renderCheckbox($checkboxes, 'encoding_video_deinterlace', $formPost); ?>
		</div>
		<div class="fieldset">
			<?php echo renderCheckbox($checkboxes, 'encoding_enable_audio', $formPost); ?>
		</div>
		<div class="fieldset">
			<label for="encoding_audio_sampling_rate">Audio sampling rate:</label>
			<?php echo renderSelect($options, 'encoding_audio_sampling_rate', $formPost); ?>
		</div>
		<div class="fieldset">
			<label for="encoding_audio_bitrate">Audio bitrate:</label>
			<?php echo renderSelect($options, 'encoding_audio_bitrate', $formPost); ?>
		</div>
		<?php foreach ($options['encoding_audio_channels']['values'] as $value => $label): ?>
		<div class="fieldset">
			<label for="channels_<?php echo h($value); ?>"><?php echo h($label); ?></label>
			<input type="radio" name="encoding_audio_channels" id="channels_<?php echo h($value); ?>" value="<?php echo h($value); ?>" class="checkbox"<?php echo formValue($options, 'encoding_audio_channels', $formPost) === $value ? ' checked' : ''; ?> />
		</div>
		<?php endforeach; ?>
		<div class="clear"></div>

		<h2>HTML5 Video Compatible</h2>
		<div class="fieldset">
			<?php echo renderCheckbox($checkboxes, 'encoding_x264', $formPost); ?>
		</div>
		<div class="fieldset">
			<?php echo renderCheckbox($checkboxes, 'encoding_webm', $formPost); ?>
		</div>
		<div class="fieldset">
			<?php echo renderCheckbox($checkboxes, 'encoding_ogv', $formPost); ?>
		</div>
		<div class="fieldset">
			<?php echo renderCheckbox($checkboxes, 'encoding_stills', $formPost); ?>
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
