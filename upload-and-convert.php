<?php

/*
	Name    : ffmpeg web gui
	Author  : Greg Colley
	Created : 03/12/2011
	Update  : 08/11/2026
	Version : 0.4 beta

	This is a upload and convert php script for ffmpeg. Allowing you to convert video files to html5 ready video via ffmpeg.

	Requires PHP 8.4+ and ffmpeg / ffprobe 4.3+.
	Settings live in config.php; put your own overrides in config.local.php
	(or environment variables, see src/jobs.php).
	This page checks uploads and queues them; the worker (src/worker.php)
	converts them. The page templates are in src/views/. Styles are Pico.css
	(assets/vendor/pico) plus assets/app.css, and assets/app.js adds upload
	progress and live job status.

	Pages:
		upload-and-convert.php                    form and recent conversions
		upload-and-convert.php?job=<id>           one conversion: progress, then results
		upload-and-convert.php?job=<id>&format=json   its status, polled by app.js
*/

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/src/functions.php';

try {
	$config = loadConfig(__DIR__);
} catch (RuntimeException $e) {
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	exit($e->getMessage());
}

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
$job    = null;

$loginConfigured = !$config['require_login'] || $config['password_hash'] !== '';
$loggedIn = !$config['require_login'] || !empty($_SESSION['logged_in']);
$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
// app.js uploads with XMLHttpRequest and asks for JSON instead of a page
$wantsJson = (isset($_GET['format']) && $_GET['format'] === 'json')
	|| (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

/** Send a JSON response and stop. */
function sendJson(array $data, $status = 200)
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode($data, JSON_UNESCAPED_SLASHES);
	exit;
}

/** Redirect (303, so a reload doesn't send the form again) to this page with $query. */
function redirectHere($query = '')
{
	header('Location: ' . ($query === '' ? '?' : '?' . $query), true, 303);
	exit;
}

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
		session_write_close();
		try {
			$queued = acceptUpload($config, $options, $_POST, $_FILES);
			if ($wantsJson) {
				sendJson(array('job' => $queued['id'], 'url' => '?job=' . $queued['id']));
			}
			redirectHere('job=' . $queued['id']);
		} catch (RuntimeException $e) {
			$errors[] = $e->getMessage();
		}
	} elseif ($action === 'delete_job' && $loginConfigured && $loggedIn) {
		session_write_close();
		$id = isset($_POST['job']) && is_string($_POST['job']) ? $_POST['job'] : '';
		try {
			if (!isJobId($id)) {
				throw new RuntimeException('That conversion was not found.');
			}
			deleteJob($config, $id);
			redirectHere();
		} catch (RuntimeException $e) {
			$errors[] = $e->getMessage();
		}
	}

	if ($wantsJson) {
		// Not logged in, or the upload was refused
		sendJson(array('errors' => $errors ?: array('Please log in again.')), $loggedIn ? 422 : 403);
	}
}

// One conversion: its progress, then its results
$jobId = isset($_GET['job']) && is_string($_GET['job']) ? $_GET['job'] : null;
if ($jobId !== null && $loginConfigured && $loggedIn) {
	session_write_close();
	$job = readJob($config, $jobId);
	if (!$job) {
		if ($wantsJson) {
			sendJson(array('errors' => array('That conversion was not found.')), 404);
		}
		http_response_code(404);
		$errors[] = 'That conversion was not found. It may have been deleted.';
	} else {
		$progressText = (string)@file_get_contents(jobProgressFile($config, $job['id']));
		$jobState = jobStatus($config, $job, $progressText, listJobs($config));
		if ($wantsJson) {
			sendJson($jobState);
		}
		if ($job['status'] === 'done' || $job['status'] === 'failed') {
			$result = jobResult($config, $job);
		}
	}
} elseif ($jobId !== null && $wantsJson) {
	sendJson(array('errors' => array('Please log in again.')), 403);
}

// Keep the user's choices after a refused upload, otherwise use the defaults
$formPost = $action === 'convert' ? $_POST : array();
$uploadLimit = uploadLimit($config['max_file_size'], ini_get('upload_max_filesize'), ini_get('post_max_size'));
$recentJobs = ($loginConfigured && $loggedIn && !$job) ? array_slice(listJobs($config), 0, 20) : array();

require __DIR__ . '/src/views/layout.php';
