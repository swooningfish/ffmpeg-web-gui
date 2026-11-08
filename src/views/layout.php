<?php
/*
	Page layout. Included by upload-and-convert.php, which sets every variable
	used here and in the other views. All output goes through h().
	src/.htaccess stops these files being opened directly.
*/

if (!$loginConfigured) {
	$pageTitle = 'Set up';
	$view      = 'setup.php';
} elseif (!$loggedIn) {
	$pageTitle = 'Log in';
	$view      = 'login.php';
} elseif ($jobId !== null) {
	$pageTitle = $job ? $job['name'] : 'Conversion not found';
	$view      = 'job.php';
} else {
	$pageTitle = 'Convert a video';
	$view      = 'convert.php';
}
// Without JavaScript, a job page reloads itself until the conversion finishes
$autoRefresh  = $job && in_array($job['status'], array('queued', 'running'), true);
$assetVersion = '0.5';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="color-scheme" content="light dark">
	<title><?php echo h($pageTitle); ?> · ffmpeg web gui</title>
	<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
	<link rel="stylesheet" href="assets/vendor/pico/pico.min.css?v=2.0.6">
	<link rel="stylesheet" href="assets/app.css?v=<?php echo h($assetVersion); ?>">
	<script src="assets/app.js?v=<?php echo h($assetVersion); ?>" defer></script>
	<?php if ($autoRefresh): ?>
	<noscript><meta http-equiv="refresh" content="5"></noscript>
	<?php endif; ?>
</head>
<body>
	<header class="app-header">
		<nav class="container">
			<ul>
				<li><span class="brand"><img src="assets/icon.svg" alt="" width="28" height="28"> ffmpeg web gui</span></li>
			</ul>
			<?php if ($loginConfigured && $loggedIn && $config['require_login']): ?>
			<ul>
				<li>
					<form action="" method="post" class="logout">
						<input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
						<input type="hidden" name="action" value="logout">
						<button type="submit" class="secondary outline">Log out</button>
					</form>
				</li>
			</ul>
			<?php endif; ?>
		</nav>
	</header>

	<main class="container">
		<?php require __DIR__ . '/' . $view; ?>
	</main>

	<footer class="app-footer container">
		<small>Converts with <a href="https://ffmpeg.org/">FFmpeg</a> · <a href="https://github.com/swooningfish/ffmpeg-web-gui">ffmpeg web gui on GitHub</a></small>
	</footer>
</body>
</html>
