<?php /* Login form. */ ?>
<article class="narrow-card">
	<header><h1>Log in</h1></header>
	<?php require __DIR__ . '/messages.php'; ?>
	<form action="" method="post">
		<input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
		<input type="hidden" name="action" value="login">
		<label for="password">Password</label>
		<input type="password" name="password" id="password" autocomplete="current-password" required autofocus<?php echo $errors ? ' aria-invalid="true"' : ''; ?>>
		<button type="submit">Log in</button>
	</form>
</article>
