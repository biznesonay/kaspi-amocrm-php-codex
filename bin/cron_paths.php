#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectRoot = realpath(__DIR__.'/..');
if ($projectRoot === false) {
    fwrite(STDERR, "Не удалось определить корень проекта.\n");
    exit(1);
}

$logDir = $projectRoot.'/logs';
if (!is_dir($logDir)) {
    if (!mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        fwrite(STDERR, "Не удалось создать каталог логов: {$logDir}\n");
        exit(1);
    }
}

$logPath = $logDir.'/scheduler.log';

$paths = [
    'fetch_new' => realpath(__DIR__.'/fetch_new.php'),
    'reconcile' => realpath(__DIR__.'/reconcile.php'),
    'scheduler' => realpath(__DIR__.'/scheduler.php'),
];

foreach ($paths as $name => $path) {
    if ($path === false) {
        fwrite(STDERR, "Не удалось определить путь до {$name}.\n");
        exit(1);
    }
}

echo "Рекомендуемые команды для cron:\n";
echo "\n";
echo "[Supervisor/systemd] Один процесс-планировщик:\n";
printf("  php %s --loop >> %s 2>&1\n", $paths['scheduler'], $logPath);
echo "\n";
echo "[Cron на shared-хостинге] Отдельные задания:\n";
printf("  * * * * * php %s >> %s 2>&1\n", $paths['fetch_new'], $logPath);
printf("  */10 * * * * php %s >> %s 2>&1\n", $paths['reconcile'], $logPath);
echo "\n";
echo "Логи будут сохраняться в: {$logPath}\n";
