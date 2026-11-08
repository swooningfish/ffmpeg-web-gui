<?php /* Results of a finished conversion. $result comes from jobResult(). */ ?>
<?php if ($result['failed']): ?>
<p class="notice notice-error" role="alert">
	Failed: <?php echo h(implode(', ', $result['failed'])); ?>. See the ffmpeg log in the logs folder for details.
</p>
<?php endif; ?>

<?php if ($result['made'] || $result['stills']): ?>
<article class="result" aria-labelledby="result_title">
	<header>
		<h2 id="result_title">Converted <?php echo h($result['name']); ?></h2>
		<small><?php echo h(formatBytes($result['size'])); ?> uploaded</small>
	</header>

	<?php if ($result['player'] || $result['made']): ?>
	<div class="actions">
		<?php if ($result['player']): ?>
		<a href="<?php echo h($result['url'] . $result['player']); ?>" role="button">Open the HTML5 player</a>
		<?php endif; ?>
		<?php foreach ($result['made'] as $video): ?>
		<a href="<?php echo h($result['url'] . $video['file']); ?>" role="button" class="secondary outline" download>Download <?php echo h($video['label']); ?><?php if (isset($video['size'])): ?> <small class="download-size">(<?php echo h(formatBytes($video['size'])); ?>)</small><?php endif; ?></a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<?php if ($result['stills']): ?>
	<h3>Stills</h3>
	<ul class="stills">
		<?php foreach ($result['stills'] as $i => $still): ?>
		<li><a href="<?php echo h($result['url'] . $still); ?>"><img src="<?php echo h($result['url'] . $still); ?>" alt="Still <?php echo $i + 1; ?> of <?php echo count($result['stills']); ?>" loading="lazy"></a></li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
</article>
<?php endif; ?>
