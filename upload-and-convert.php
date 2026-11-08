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
	The conversion logic lives in src/functions.php and the page templates in
	src/views/. Styles are Pico.css (assets/vendor/pico) plus assets/app.css.
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
// No inline styles or scripts. data: images are the small SVG icons inside Pico.css.
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

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
$uploadLimit = uploadLimit($config['max_file_size'], ini_get('upload_max_filesize'), ini_get('post_max_size'));

require __DIR__ . '/src/views/layout.php';
