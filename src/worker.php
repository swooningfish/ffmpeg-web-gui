<?php
/*
	ffmpeg web gui worker. Converts queued videos one at a time, and deletes
	finished conversions older than job_retention_days.

	Run it as the web server user, from the command line only:
		php src/worker.php          keep running (Docker, systemd)
		php src/worker.php --once   convert everything queued, then exit (cron)

	In Docker the worker service in docker-compose.yml runs it for you.
*/

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}

require __DIR__ . '/functions.php';

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);
set_time_limit(0);

$once = in_array('--once', array_slice($argv, 1), true);
try {
	$config = loadConfig(dirname(__DIR__));
} catch (RuntimeException $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}

// Stop cleanly on docker stop / systemctl stop when the pcntl extension is
// there. Without it the process just ends, and the next start marks any job
// it was converting as interrupted.
$stopping = false;
if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
	foreach (array(SIGTERM, SIGINT) as $signal) {
		pcntl_signal($signal, function () use (&$stopping) {
			$stopping = true;
		});
	}
}

function workerLog(array $config, $message)
{
	writeLog($config, 'worker', $message);
	fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
}

workerLog($config, 'Worker started' . ($once ? ' (--once)' : ''));
$lastCleanup = 0;
while (!$stopping) {
	try {
		if (time() - $lastCleanup >= 600) {
			$lastCleanup = time();
			$deleted = deleteOldJobs($config);
			if ($deleted) {
				workerLog($config, 'Deleted ' . $deleted . ' old conversion(s)');
			}
		}
		$ran = runNextJob($config);
	} catch (RuntimeException $e) {
		workerLog($config, 'Error: ' . $e->getMessage());
		$ran = false;
		sleep(5);
	}
	if ($ran) {
		continue;
	}
	if ($once) {
		break;
	}
	sleep(1);
}
workerLog($config, 'Worker stopped');
