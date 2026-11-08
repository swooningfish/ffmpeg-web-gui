<?php
/*
	Conversion jobs for ffmpeg web gui.

	The web request only checks the upload and queues a job (acceptUpload).
	The worker (src/worker.php) converts queued jobs one at a time (runJob),
	so a long conversion never ties up a web request or hits its timeouts.

	Each job is a JSON file in jobs_dir named <id>.json. Changes that read
	and then write a job (claiming, deleting) hold jobs.lock; every write
	replaces the file in one rename, so readers never see half a file.

	Job status: queued -> running -> done | failed
*/

const JOB_ID_PATTERN = '/^[0-9a-f]{16}$/';

/** Settings from environment variables, e.g. for Docker. Empty variables are ignored. */
const CONFIG_ENV = array(
	'FFMPEG_GUI_PASSWORD_HASH'      => 'password_hash',
	'FFMPEG_GUI_FFMPEG'             => 'ffmpeg',
	'FFMPEG_GUI_FFPROBE'            => 'ffprobe',
	'FFMPEG_GUI_SECURE_COOKIE'      => 'secure_cookie',
	'FFMPEG_GUI_JOB_RETENTION_DAYS' => 'job_retention_days',
);


// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/**
 * config.php, then config.local.php, then environment variables.
 * FFMPEG_GUI_PASSWORD_HASH_FILE names a file holding the hash (a Docker secret).
 * Throws RuntimeException if config.local.php is not an array.
 */
function loadConfig($appDir, ?callable $getenv = null)
{
	$getenv = $getenv ?: 'getenv';
	$config = require $appDir . '/config.php';
	if (is_file($appDir . '/config.local.php')) {
		$localConfig = require $appDir . '/config.local.php';
		if (!is_array($localConfig)) {
			throw new RuntimeException('config.local.php must return an array of settings, e.g. <?php return array(\'password_hash\' => \'...\');');
		}
		$config = array_replace($config, $localConfig);
	}
	return array_replace($config, envConfig($getenv));
}

/** Settings found in the environment variables listed in CONFIG_ENV. */
function envConfig(callable $getenv)
{
	$settings = array();
	foreach (CONFIG_ENV as $name => $key) {
		$value = $getenv($name);
		if (is_string($value) && trim($value) !== '') {
			$settings[$key] = trim($value);
		}
	}
	$hashFile = $getenv('FFMPEG_GUI_PASSWORD_HASH_FILE');
	if (!isset($settings['password_hash']) && is_string($hashFile) && $hashFile !== '') {
		$hash = is_readable($hashFile) ? trim((string)file_get_contents($hashFile)) : '';
		if ($hash === '') {
			throw new RuntimeException('FFMPEG_GUI_PASSWORD_HASH_FILE is set, but ' . $hashFile . ' is missing, empty or not readable.');
		}
		$settings['password_hash'] = $hash;
	}
	if (isset($settings['secure_cookie'])) {
		$settings['secure_cookie'] = filter_var($settings['secure_cookie'], FILTER_VALIDATE_BOOLEAN);
	}
	if (isset($settings['job_retention_days'])) {
		$settings['job_retention_days'] = max(0, (int)$settings['job_retention_days']);
	}
	return $settings;
}


// ---------------------------------------------------------------------------
// Job files
// ---------------------------------------------------------------------------

function isJobId($id)
{
	return is_string($id) && preg_match(JOB_ID_PATTERN, $id) === 1;
}

function jobFile(array $config, $id)
{
	if (!isJobId($id)) {
		throw new InvalidArgumentException('Bad job id.');
	}
	return $config['jobs_dir'] . $id . '.json';
}

/** ffmpeg writes its -progress output here while a job runs. */
function jobProgressFile(array $config, $id)
{
	return $config['jobs_dir'] . $id . '.progress';
}

/** The job, or null if there is no such job. */
function readJob(array $config, $id)
{
	if (!isJobId($id)) {
		return null;
	}
	$json = @file_get_contents(jobFile($config, $id));
	$job = $json === false ? null : json_decode($json, true);
	return is_array($job) && isset($job['id']) && $job['id'] === $id ? $job : null;
}

function writeJob(array $config, array $job)
{
	$file = jobFile($config, $job['id']);
	$temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
	if (file_put_contents($temp, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false || !rename($temp, $file)) {
		@unlink($temp);
		throw new RuntimeException('Could not save the job in the jobs folder.');
	}
}

/** Change a job with $update (job => job). Returns the new job, or null if it is gone. */
function updateJob(array $config, $id, callable $update)
{
	return withJobsLock($config, function () use ($config, $id, $update) {
		$job = readJob($config, $id);
		if (!$job) {
			return null;
		}
		$job = $update($job);
		writeJob($config, $job);
		return $job;
	});
}

/** Run $fn while holding jobs.lock, and return what it returns. */
function withJobsLock(array $config, callable $fn)
{
	$lock = @fopen($config['jobs_dir'] . 'jobs.lock', 'c');
	if (!$lock || !flock($lock, LOCK_EX)) {
		$lock && fclose($lock);
		throw new RuntimeException('Could not lock the jobs folder. It needs write permission for the web server user.');
	}
	try {
		return $fn();
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

/** All jobs, newest first. */
function listJobs(array $config)
{
	$jobs = array();
	foreach (glob($config['jobs_dir'] . '*.json') ?: array() as $file) {
		$job = readJob($config, basename($file, '.json'));
		if ($job) {
			$jobs[] = $job;
		}
	}
	usort($jobs, function ($a, $b) {
		return array($b['created'], $b['id']) <=> array($a['created'], $a['id']);
	});
	return $jobs;
}

/** Number of jobs that will run before $job: queued earlier, or running now. */
function jobsAhead(array $jobs, array $job)
{
	$ahead = 0;
	foreach ($jobs as $other) {
		if ($other['id'] === $job['id']) {
			continue;
		}
		if ($other['status'] === 'running'
			|| ($other['status'] === 'queued' && array($other['created'], $other['id']) < array($job['created'], $job['id']))) {
			$ahead++;
		}
	}
	return $ahead;
}

/**
 * Delete a job with its converted files, upload and progress file.
 * Throws RuntimeException for a running job.
 */
function deleteJob(array $config, $id)
{
	withJobsLock($config, function () use ($config, $id) {
		$job = readJob($config, $id);
		if (!$job) {
			return;
		}
		if ($job['status'] === 'running') {
			throw new RuntimeException('A conversion that is running can\'t be deleted. Wait for it to finish.');
		}
		removeJobFiles($config, $job);
		@unlink(jobFile($config, $id));
	});
}

/** Remove what a job left on disk (not the job file itself). */
function removeJobFiles(array $config, array $job)
{
	$jobDir = $config['converted_dir'] . $job['id'] . '/';
	if (is_dir($jobDir)) {
		foreach (scandir($jobDir) ?: array() as $name) {
			if ($name !== '.' && $name !== '..' && is_file($jobDir . $name)) {
				@unlink($jobDir . $name);
			}
		}
		@rmdir($jobDir);
	}
	@unlink($config['upload_dir'] . $job['id'] . '.' . $job['ext']);
	@unlink(jobProgressFile($config, $job['id']));
}

/** Delete finished jobs older than job_retention_days (0 = keep them). Returns how many were deleted. */
function deleteOldJobs(array $config, $now = null)
{
	$days = isset($config['job_retention_days']) ? (int)$config['job_retention_days'] : 0;
	if ($days <= 0) {
		return 0;
	}
	$now = $now === null ? time() : $now;
	$deleted = 0;
	foreach (listJobs($config) as $job) {
		$finished = isset($job['finished']) ? (int)$job['finished'] : 0;
		if (in_array($job['status'], array('done', 'failed'), true) && $finished && $finished < $now - $days * 86400) {
			deleteJob($config, $job['id']);
			$deleted++;
		}
	}
	return $deleted;
}


// ---------------------------------------------------------------------------
// Queueing an upload (web request)
// ---------------------------------------------------------------------------

/**
 * Check the upload and settings, confirm with ffprobe that it is a video we
 * accept, and queue a job for it. Returns the job.
 * Throws RuntimeException with a message that is safe to show the user.
 */
function acceptUpload(array $config, array $options, array $post, array $files, $now = null)
{
	// 1. Check the upload and the settings
	$file      = isset($files['file']) ? $files['file'] : null;
	$extension = validateUpload($file, $config['max_file_size']);
	$settings  = readSettings($options, $post);
	$formats   = array();
	foreach (outputFormats($settings) as $key => $format) {
		if (!empty($post[$key])) {
			$formats[] = $key;
		}
	}
	$wantStills = !empty($post['encoding_stills']);
	if (!$formats && !$wantStills) {
		throw new RuntimeException('Please choose at least one output format.');
	}

	// 2. Check the setup
	checkSetup($config);

	// 3. Save the upload under a random name and confirm it is a video
	$id         = bin2hex(random_bytes(8));
	$uploadPath = $config['upload_dir'] . $id . '.' . $extension;
	if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
		throw new RuntimeException('Could not save the uploaded file.');
	}
	try {
		$duration = probeVideo($config, $uploadPath);
		$job = array(
			'id'       => $id,
			'status'   => 'queued',
			'created'  => $now === null ? time() : $now,
			'name'     => (string)$file['name'],
			'size'     => (int)$file['size'],
			'ext'      => $extension,
			'base'     => slugify(pathinfo((string)$file['name'], PATHINFO_FILENAME)),
			'settings' => $settings,
			'formats'  => $formats,
			'want_stills' => $wantStills,
			'duration' => $duration,
			'step'     => 0,
		);
		writeJob($config, $job);
		return $job;
	} catch (Exception $e) {
		@unlink($uploadPath);
		throw $e;
	}
}

/** Throws RuntimeException if ffmpeg/ffprobe are missing or a folder isn't writable. */
function checkSetup(array $config)
{
	foreach (array('ffmpeg', 'ffprobe') as $binary) {
		if (!is_file($config[$binary]) || !is_executable($config[$binary])) {
			throw new RuntimeException($binary . ' was not found at the path set in the config. Set \'' . $binary . '\' in config.local.php.');
		}
	}
	foreach (array('upload_dir', 'converted_dir', 'log_dir', 'jobs_dir') as $dirKey) {
		if (!is_dir($config[$dirKey]) || !is_writable($config[$dirKey])) {
			throw new RuntimeException('The ' . basename($config[$dirKey]) . ' folder needs write permission for the web server user.');
		}
	}
}

/**
 * Run ffprobe on an upload. Returns its duration in seconds (null if unknown).
 * Throws RuntimeException if it isn't a video in a container we accept.
 */
function probeVideo(array $config, $path)
{
	$args = array($config['ffprobe'], '-v', 'error', '-protocol_whitelist', 'file',
		'-show_entries', 'format=format_name,duration:stream=codec_type:stream_disposition=attached_pic', '-of', 'json', $path);
	$code = runLogged($config, 'ffprobe', $args, $output, 60);
	if ($code !== 0 || !isAllowedVideo($output)) {
		throw new RuntimeException('That file is not a supported video.');
	}
	$probe = json_decode($output, true);
	$duration = isset($probe['format']['duration']) && is_numeric($probe['format']['duration']) ? (float)$probe['format']['duration'] : 0.0;
	return $duration > 0 ? $duration : null;
}


// ---------------------------------------------------------------------------
// Converting (worker)
// ---------------------------------------------------------------------------

/**
 * Convert the oldest queued job, if there is one. Holds convert.lock while
 * it runs, so only one conversion runs at a time even with several workers.
 * Returns true if a job was run.
 */
function runNextJob(array $config)
{
	$lock = @fopen($config['log_dir'] . 'convert.lock', 'c');
	if (!$lock) {
		throw new RuntimeException('Could not create the lock file in the logs folder.');
	}
	if (!flock($lock, LOCK_EX | LOCK_NB)) {
		fclose($lock);
		return false;
	}
	try {
		// Nothing else is converting, so a job still marked running was interrupted
		failInterruptedJobs($config);
		$job = claimNextJob($config);
		if (!$job) {
			return false;
		}
		runJob($config, $job);
		return true;
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

/** Mark the oldest queued job as running and return it, or null if the queue is empty. */
function claimNextJob(array $config, $now = null)
{
	return withJobsLock($config, function () use ($config, $now) {
		$queued = array_filter(listJobs($config), function ($job) {
			return $job['status'] === 'queued';
		});
		if (!$queued) {
			return null;
		}
		$job = end($queued); // listJobs() is newest first
		$job['status']  = 'running';
		$job['started'] = $now === null ? time() : $now;
		writeJob($config, $job);
		return $job;
	});
}

/** Fail jobs left marked running by a worker that stopped part way through. */
function failInterruptedJobs(array $config, $now = null)
{
	withJobsLock($config, function () use ($config, $now) {
		foreach (listJobs($config) as $job) {
			if ($job['status'] === 'running') {
				$job['status']   = 'failed';
				$job['error']    = 'The conversion was interrupted (the worker stopped). Please convert the video again.';
				$job['finished'] = $now === null ? time() : $now;
				writeJob($config, $job);
				if ($config['delete_upload_after_convert']) {
					@unlink($config['upload_dir'] . $job['id'] . '.' . $job['ext']);
				}
				@unlink(jobProgressFile($config, $job['id']));
			}
		}
	});
}

/** Labels of a job's steps, in the order they run. */
function jobSteps(array $job)
{
	$formats = outputFormats($job['settings']);
	$steps = array();
	foreach ($job['formats'] as $key) {
		$steps[] = $formats[$key]['label'];
	}
	if (!empty($job['want_stills'])) {
		$steps[] = 'Stills';
	}
	return $steps;
}

/** Convert a claimed job and record the result in its job file. */
function runJob(array $config, array $job)
{
	$id         = $job['id'];
	$uploadPath = $config['upload_dir'] . $id . '.' . $job['ext'];
	$jobDir     = $config['converted_dir'] . $id . '/';
	$progress   = jobProgressFile($config, $id);
	$baseName   = $job['base'];
	$formats    = outputFormats($job['settings']);
	$made       = array();
	$stills     = array();
	$failed     = array();
	$player     = null;
	$error      = null;

	// Every ffmpeg run in this job shares one deadline
	$deadline  = $config['max_execution_time'] > 0 ? time() + (int)$config['max_execution_time'] : 0;
	$timeLeft  = function () use ($deadline) {
		return $deadline ? max(1, $deadline - time()) : 0;
	};
	$outOfTime = function () use ($deadline) {
		return $deadline && time() >= $deadline;
	};
	$setStep = function ($step) use ($config, $id, $progress) {
		@unlink($progress);
		updateJob($config, $id, function ($job) use ($step) {
			$job['step'] = $step;
			return $job;
		});
	};

	try {
		if (!is_file($uploadPath)) {
			throw new RuntimeException('The uploaded file is missing.');
		}
		if (!is_dir($jobDir) && !mkdir($jobDir, 0755)) {
			throw new RuntimeException('Could not create the output folder.');
		}

		$inputArgs = array($config['ffmpeg'], '-hide_banner', '-nostdin', '-nostats', '-y', '-protocol_whitelist', 'file', '-progress', $progress);
		if ($config['max_duration'] > 0) {
			$inputArgs[] = '-t'; // only read this many seconds of the input
			$inputArgs[] = (string)(int)$config['max_duration'];
		}
		$inputArgs[] = '-i';
		$inputArgs[] = $uploadPath;
		// Stop runaway output (e.g. a crafted file that makes ffmpeg duplicate frames forever)
		$sizeLimitArgs = $config['max_output_size'] > 0 ? array('-fs', (string)(int)$config['max_output_size']) : array();

		$step = 0;
		foreach ($job['formats'] as $key) {
			$format = $formats[$key];
			$setStep($step++);
			if ($outOfTime()) {
				$failed[] = $format['label'] . ' (time limit reached)';
				continue;
			}
			$outName = $baseName . '.' . $format['ext'];
			$code = runLogged($config, 'ffmpeg', array_merge($inputArgs, $format['args'], $sizeLimitArgs, array($jobDir . $outName)), $unused, $timeLeft());
			if ($code === 0 && is_file($jobDir . $outName) && filesize($jobDir . $outName) > 0) {
				$made[] = array('label' => $format['label'], 'file' => $outName, 'type' => $format['type'], 'size' => filesize($jobDir . $outName));
			} else {
				@unlink($jobDir . $outName);
				$failed[] = $format['label'] . ($code === EXIT_TIMED_OUT ? ' (time limit reached)' : ' (exit code ' . $code . ')');
			}
		}

		// Up to five stills, one a second starting one second in
		if (!empty($job['want_stills'])) {
			$setStep($step++);
			if ($outOfTime()) {
				$failed[] = 'Stills (time limit reached)';
			} else {
				$stillArgs = array('-ss', '1', '-vf', 'fps=1,' . scaleFilter($job['settings']['video_size']), '-frames:v', '5', $jobDir . $baseName . '_still_%d.png');
				runLogged($config, 'ffmpeg', array_merge($inputArgs, $stillArgs), $unused, $timeLeft());
				for ($i = 1; $i <= 5; $i++) {
					if (is_file($jobDir . $baseName . '_still_' . $i . '.png')) {
						$stills[] = $baseName . '_still_' . $i . '.png';
					}
				}
				if (!$stills) {
					$failed[] = 'Stills';
				}
			}
		}

		// Player page for whatever was made
		if ($made) {
			list($width, $height) = array_map('intval', explode('x', $job['settings']['video_size']));
			$poster = $stills ? $stills[min(2, count($stills) - 1)] : null;
			$player = $baseName . '_player.html';
			file_put_contents($jobDir . $player, buildPlayerPage($job['name'], $width, $height, $made, $poster));
		}
		if (!$made && !$stills) {
			@rmdir($jobDir);
		}
	} catch (Throwable $e) {
		$error = $e instanceof RuntimeException ? $e->getMessage() : 'Unexpected error: ' . $e->getMessage();
	} finally {
		if ($config['delete_upload_after_convert']) {
			@unlink($uploadPath);
		}
		@unlink($progress);
	}

	return updateJob($config, $id, function ($job) use ($made, $stills, $failed, $player, $error) {
		$job['status']   = ($made || $stills) ? 'done' : 'failed';
		$job['made']     = $made;
		$job['stills']   = $stills;
		$job['player']   = $player;
		$job['failed']   = $failed;
		$job['finished'] = time();
		if ($error !== null) {
			$job['error'] = $error;
		} elseif (!$made && !$stills) {
			$job['error'] = 'Nothing could be made from this video.';
		}
		return $job;
	});
}


// ---------------------------------------------------------------------------
// Progress
// ---------------------------------------------------------------------------

/** Seconds of video ffmpeg has written so far, from its -progress output (null if unknown). */
function parseProgress($text)
{
	$seconds = null;
	// ffmpeg writes a block of key=value lines about twice a second; use the last one.
	// out_time_ms is in microseconds too (an old ffmpeg naming mistake).
	if (preg_match_all('/^out_time_(?:us|ms)=(\d+)\s*$/m', (string)$text, $matches)) {
		$seconds = (float)end($matches[1]) / 1000000;
	}
	return $seconds;
}

/**
 * What to show for a job: status, overall percent, current step, ETA in
 * seconds and queue position. $progressText is the job's -progress file.
 */
function jobStatus(array $config, array $job, $progressText, array $allJobs, $now = null)
{
	$now    = $now === null ? time() : $now;
	$steps  = jobSteps($job);
	$status = array(
		'id'      => $job['id'],
		'status'  => $job['status'],
		'percent' => $job['status'] === 'done' ? 100 : 0,
		'step'    => null,
		'eta'     => null,
		'ahead'   => $job['status'] === 'queued' ? jobsAhead($allJobs, $job) : 0,
		'error'   => isset($job['error']) ? $job['error'] : null,
	);
	if ($job['status'] !== 'running' || !$steps) {
		$status['text'] = jobStatusText($status);
		return $status;
	}

	$step = min((int)$job['step'], count($steps) - 1);
	$length = $job['duration'];
	if ($config['max_duration'] > 0) {
		$length = $length ? min($length, (float)$config['max_duration']) : (float)$config['max_duration'];
	}
	$done = parseProgress($progressText);
	$fraction = ($length && $done !== null) ? min(1, $done / $length) : 0;

	$percent = ($step + $fraction) / count($steps) * 100;
	$status['percent'] = (int)min(99, floor($percent));
	$status['step']    = $steps[$step] . ' (' . ($step + 1) . ' of ' . count($steps) . ')';
	$elapsed = $now - (isset($job['started']) ? (int)$job['started'] : $now);
	if ($percent >= 2 && $elapsed >= 3) {
		$status['eta'] = (int)round($elapsed * (100 - $percent) / $percent);
	}
	$status['text'] = jobStatusText($status);
	return $status;
}

/** One line describing a status from jobStatus(), e.g. "Converting WebM (2 of 3): 40%, about 2 min left". */
function jobStatusText(array $status)
{
	switch ($status['status']) {
		case 'queued':
			return $status['ahead'] > 0
				? 'Waiting: ' . $status['ahead'] . ' conversion' . ($status['ahead'] === 1 ? '' : 's') . ' ahead of this one.'
				: 'Waiting to start…';
		case 'running':
			return 'Converting ' . $status['step'] . ': ' . $status['percent'] . '%'
				. ($status['eta'] !== null ? ', about ' . formatDuration($status['eta']) . ' left' : '') . '.';
		case 'done':
			return 'Finished.';
		default:
			return 'Failed.' . ($status['error'] ? ' ' . $status['error'] : '');
	}
}

/** Short label for a job status, for the Recent conversions list. */
function jobStatusLabel($status)
{
	$labels = array('queued' => 'Waiting', 'running' => 'Converting', 'done' => 'Ready', 'failed' => 'Failed');
	return isset($labels[$status]) ? $labels[$status] : $status;
}

/** "45 s", "3 min", "1 h 5 min" */
function formatDuration($seconds)
{
	$seconds = max(0, (int)$seconds);
	if ($seconds < 60) {
		return $seconds . ' s';
	}
	if ($seconds < 3600) {
		return (int)round($seconds / 60) . ' min';
	}
	return (int)floor($seconds / 3600) . ' h ' . (int)round(($seconds % 3600) / 60) . ' min';
}

/** The page's result card data for a finished job (see src/views/results.php). */
function jobResult(array $config, array $job)
{
	return array(
		'name'   => $job['name'],
		'size'   => $job['size'],
		'url'    => $config['converted_url'] . $job['id'] . '/',
		'made'   => isset($job['made']) ? $job['made'] : array(),
		'stills' => isset($job['stills']) && is_array($job['stills']) ? $job['stills'] : array(),
		'player' => isset($job['player']) ? $job['player'] : null,
		'failed' => isset($job['failed']) ? $job['failed'] : array(),
	);
}
