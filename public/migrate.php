<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
$token = $_GET['token'] ?? '';
if ($token !== env('CRON_SECRET')) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}
$driver = env('DB_DRIVER', 'mysql');
$sqlFile = __DIR__.'/../migrations/'.($driver === 'pgsql' ? 'pgsql.sql' : 'mysql.sql');
$sql = file_get_contents($sqlFile);
$pdo = (require __DIR__.'/../lib/Db.php')::pdo();
$pdo->exec($sql);
echo "Migration OK for {$driver}";
