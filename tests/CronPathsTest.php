<?php
declare(strict_types=1);

$output = shell_exec('php '.escapeshellarg(__DIR__.'/../bin/cron_paths.php'));
if ($output === null) {
    fwrite(STDERR, "Не удалось выполнить bin/cron_paths.php\n");
    exit(1);
}

$cliPhp = getenv('CLI_PHP');
if ($cliPhp === false || $cliPhp === '') {
    $cliPhp = PHP_BINARY;
}
$php = escapeshellarg($cliPhp);

$expected = [
    'fetch_new' => realpath(__DIR__.'/../bin/fetch_new.php'),
    'reconcile' => realpath(__DIR__.'/../bin/reconcile.php'),
    'scheduler' => realpath(__DIR__.'/../bin/scheduler.php'),
];

foreach ($expected as $name => $path) {
    if ($path === false) {
        fwrite(STDERR, "Ожидался существующий путь, но realpath вернул false\n");
        exit(1);
    }
    $quotedPath = escapeshellarg($path);
    if (strpos($output, $quotedPath) === false) {
        fwrite(STDERR, "В выводе отсутствует путь: {$quotedPath}\n");
        exit(1);
    }

    $expectedPrefix = $php.' '.$quotedPath;
    if ($name === 'scheduler') {
        $expectedPrefix .= ' --loop';
    }

    if (strpos($output, $expectedPrefix) === false) {
        fwrite(STDERR, "В выводе отсутствует ожидаемый префикс команды: {$expectedPrefix}\n");
        exit(1);
    }
}

echo "CronPathsTest: OK\n";
