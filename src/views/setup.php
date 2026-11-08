<?php /* Shown until a password hash is set (or require_login is turned off). */ ?>
<article class="narrow-card">
	<header><h1>Set a password first</h1></header>
	<p>ffmpeg web gui won't run until it has a password. Create <code>config.local.php</code> next to <code>upload-and-convert.php</code> containing:</p>
	<pre><code>&lt;?php
return array('password_hash' =&gt; 'PASTE HASH HERE');</code></pre>
	<p>Generate the hash with:</p>
	<pre><code>php -r "echo password_hash('your password', PASSWORD_DEFAULT), PHP_EOL;"</code></pre>
	<p>Then reload this page.</p>
</article>
