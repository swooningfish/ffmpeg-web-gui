<?php
/*
	One conversion. While it is queued or running this shows its progress,
	which assets/app.js keeps up to date from the JSON status URL. When it
	has finished it shows the results.
*/
$headings = array(
	'queued'  => 'Waiting to convert',
	'running' => 'Converting',
	'done'    => 'Your video is ready',
	'failed'  => 'Conversion failed',
);
?>
<p class="back"><a href="?">← Convert another video</a></p>

<?php if (!$job): ?>
	<h1>Conversion not found</h1>
	<?php require __DIR__ . '/messages.php'; ?>
<?php else: ?>
	<hgroup>
		<h1 data-job-heading><?php echo h($headings[$job['status']]); ?></h1>
		<p><?php echo h($job['name']); ?> · <?php echo h(formatBytes($job['size'])); ?></p>
	</hgroup>
	<?php require __DIR__ . '/messages.php'; ?>

	<?php if ($job['status'] === 'queued' || $job['status'] === 'running'): ?>
	<article class="job-progress" data-job-status="?job=<?php echo h($job['id']); ?>&amp;format=json">
		<progress max="100" aria-label="Conversion progress" aria-describedby="job_text"<?php echo $job['status'] === 'running' ? ' value="' . (int)$jobState['percent'] . '"' : ''; ?>></progress>
		<p id="job_text" class="job-text" aria-live="polite" data-job-text><?php echo h($jobState['text']); ?></p>
		<p class="job-note"><small>You can leave this page; the conversion carries on and stays under Recent conversions.<noscript> This page refreshes every 5 seconds.</noscript></small></p>
	</article>
	<?php endif; ?>

	<?php if ($job['status'] === 'failed' && !empty($job['error'])): ?>
	<p class="notice notice-error" role="alert"><?php echo h($job['error']); ?></p>
	<?php endif; ?>

	<?php if ($result) { require __DIR__ . '/results.php'; } ?>
<?php endif; ?>
