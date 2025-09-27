<?php
declare(strict_types=1);

$output = shell_exec('php '.escapeshellarg(__DIR__.'/../bin/cron_paths.php'));
if ($output === null) {
    fwrite(STDERR, "Не удалось выполнить bin/cron_paths.php\n");
    exit(1);
}

$expected = [
    realpath(__DIR__.'/../bin/fetch_new.php'),
    realpath(__DIR__.'/../bin/reconcile.php'),
    realpath(__DIR__.'/../bin/scheduler.php'),
];

foreach ($expected as $path) {
    if ($path === false) {
        fwrite(STDERR, "Ожидался существующий путь, но realpath вернул false\n");
        exit(1);
    }
    if (strpos($output, (string) $path) === false) {
        fwrite(STDERR, "В выводе отсутствует путь: {$path}\n");
        exit(1);
    }
}

echo "CronPathsTest: OK\n";
