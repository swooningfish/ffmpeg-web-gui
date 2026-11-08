<?php
/*
	Default settings for ffmpeg web gui.

	Don't edit this file. Create config.local.php (ignored by git) and return
	only the settings you want to change, for example:

		<?php
		return array(
			'password_hash' => '$2y$10$....',
			'ffmpeg'        => '/usr/bin/ffmpeg',
			'ffprobe'       => '/usr/bin/ffprobe',
		);
*/

return array(
	// Login. The app refuses to run until a password hash is set, unless
	// require_login is false (only do that behind your own authentication).
	// Generate a hash with:
	//   php -r "echo password_hash('your password', PASSWORD_DEFAULT), PHP_EOL;"
	'require_login' => true,
	'password_hash' => '',
	// After this many wrong passwords in a row from one IP address, each
	// further try has to wait login_lockout seconds (0 = no limit).
	'max_login_failures' => 5,
	'login_lockout'      => 300,
	// Send the login cookie over HTTPS only. null = when PHP sees an HTTPS
	// request; set true if a proxy or load balancer handles HTTPS for you.
	'secure_cookie' => null,

	// Binaries (ffmpeg / ffprobe 4.3 or later)
	'ffmpeg'  => '/usr/local/bin/ffmpeg',
	'ffprobe' => '/usr/local/bin/ffprobe',

	// Folders. upload/ and logs/ are blocked from web access by their own
	// .htaccess files; converted/ only serves the generated media files.
	'upload_dir'    => __DIR__ . '/upload/',
	'converted_dir' => __DIR__ . '/converted/',
	'log_dir'       => __DIR__ . '/logs/',
	// Queued and finished conversions (one JSON file each). Blocked from web
	// access by its .htaccess file.
	'jobs_dir'      => __DIR__ . '/jobs/',
	// URL of converted_dir, relative to upload-and-convert.php
	'converted_url' => 'converted/',

	// Limits. Keep max_file_size in line with upload_max_filesize and
	// post_max_size in .htaccess / .user.ini.
	'max_file_size'      => 104857600, // 100MB
	// Seconds the worker may spend converting one video. ffmpeg is stopped
	// when this runs out.
	'max_execution_time' => 3600,
	'max_duration'       => 3600,      // only convert the first N seconds of a video (0 = no limit)
	'max_output_size'    => 2147483648, // stop an output file at 2GB (0 = no limit)

	// Delete the original upload once conversion has finished.
	'delete_upload_after_convert' => true,
	// The worker deletes finished conversions (their files and their entry in
	// the list) this many days after they finished. 0 = keep them.
	'job_retention_days' => 7,
);
