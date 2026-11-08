<?php
/* Convert form, with the result of the last conversion above it. */

$extensions = strtoupper(implode(', ', ALLOWED_EXTENSIONS));
$fileHint   = ($uploadLimit > 0 ? 'Up to ' . formatBytes($uploadLimit) . '. ' : '')
	. 'Accepted: ' . $extensions . '.';
if ($config['max_duration'] > 0) {
	$minutes   = $config['max_duration'] / 60;
	$fileHint .= ' Only the first ' . ($minutes >= 1 ? round($minutes, 1) . ' minutes' : (int)$config['max_duration'] . ' seconds') . ' are converted.';
}
?>
<hgroup>
	<h1>Convert a video</h1>
	<p>Upload a video to get web-ready video files, still images and an HTML5 player page.</p>
</hgroup>

<div class="form-messages"><?php require __DIR__ . '/messages.php'; ?></div>

<form action="" method="post" enctype="multipart/form-data" class="convert-form">
	<input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
	<input type="hidden" name="action" value="convert">

	<fieldset class="card">
		<legend>1. Choose a video</legend>
		<label for="file">Video file</label>
		<input type="file" name="file" id="file" required aria-describedby="file_hint"
			accept="video/*,<?php echo h('.' . implode(',.', ALLOWED_EXTENSIONS)); ?>"
			data-max-bytes="<?php echo (int)$uploadLimit; ?>">
		<small id="file_hint"><?php echo h($fileHint); ?></small>
	</fieldset>

	<fieldset class="card">
		<legend>2. Choose what to make</legend>
		<?php echo renderCheckbox($checkboxes, 'encoding_x264', $formPost); ?>
		<?php echo renderCheckbox($checkboxes, 'encoding_webm', $formPost); ?>
		<?php echo renderCheckbox($checkboxes, 'encoding_ogv', $formPost); ?>
		<hr>
		<?php echo renderCheckbox($checkboxes, 'encoding_stills', $formPost); ?>
	</fieldset>

	<div class="grid settings">
		<fieldset class="card">
			<legend>3. Video</legend>
			<?php echo renderSelect($options, 'video_size', $formPost, 'Maximum size'); ?>
			<small class="field-hint">Smaller videos are never enlarged, and the shape is kept.</small>
			<?php echo renderSelect($options, 'video_bitrate', $formPost); ?>
			<?php echo renderSelect($options, 'video_framerate', $formPost); ?>
			<?php echo renderCheckbox($checkboxes, 'encoding_video_deinterlace', $formPost, true); ?>
		</fieldset>

		<fieldset class="card audio">
			<legend>4. Audio</legend>
			<?php echo renderCheckbox($checkboxes, 'encoding_enable_audio', $formPost, true); ?>
			<div class="audio-settings">
				<?php echo renderSelect($options, 'encoding_audio_sampling_rate', $formPost, 'Sampling rate'); ?>
				<?php echo renderSelect($options, 'encoding_audio_bitrate', $formPost, 'Bitrate'); ?>
				<?php echo renderRadios($options, 'encoding_audio_channels', $formPost); ?>
			</div>
		</fieldset>
	</div>

	<div class="submit-row">
		<button type="submit">Upload and convert</button>
		<small>Videos are converted one at a time in the background. You can close this page once the upload has finished and come back to the result under Recent conversions.</small>
	</div>
	<div class="upload-status" hidden>
		<progress value="0" max="100" aria-label="Upload progress"></progress>
		<small aria-live="polite" data-upload-text></small>
	</div>
</form>

<?php require __DIR__ . '/recent.php'; ?>
