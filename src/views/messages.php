<?php /* Error messages for the current request. role="alert" makes screen readers announce them. */ ?>
<?php foreach ($errors as $error): ?>
<p class="notice notice-error" role="alert"><?php echo h($error); ?></p>
<?php endforeach; ?>
