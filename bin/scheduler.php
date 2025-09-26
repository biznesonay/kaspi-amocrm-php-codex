<?php
declare(strict_types=1);

require_once __DIR__.'/../lib/Scheduler.php';
require_once __DIR__.'/../lib/Logger.php';

$options = getopt('', ['once', 'loop', 'daemon']);
$loop = isset($options['loop']) || isset($options['daemon']);
$runOnce = isset($options['once']) || !$loop;

$lockDir = __DIR__.'/../storage';
if (!is_dir($lockDir)) {
    if (!mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
        fwrite(STDERR, "Cannot create storage directory for scheduler lock: {$lockDir}\n");
        exit(1);
    }
}
$lockPath = $lockDir.'/cron.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false) {
    fwrite(STDERR, "Cannot open scheduler lock file: {$lockPath}\n");
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Scheduler already running (lock held by another process).\n");
    exit(0);
}

$scheduler = new Scheduler();
$scheduler->register('fetch_new', 60, static function (): void {
    require __DIR__.'/fetch_new.php';
});
$scheduler->register('reconcile', 600, static function (): void {
    require __DIR__.'/reconcile.php';
});

Logger::info('Scheduler started', [
    'mode' => $loop ? 'loop' : 'once',
]);

$delayUs = 500000;

do {
    $scheduler->runDueJobs(time());
    if ($runOnce) {
        break;
    }
    usleep($delayUs);
} while (true);

Logger::info('Scheduler finished', [
    'mode' => $loop ? 'loop' : 'once',
]);

// Keep the lock file handle open until the script exits.
if (is_resource($lockHandle)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
