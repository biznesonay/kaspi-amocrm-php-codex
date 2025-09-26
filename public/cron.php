<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';

$token = $_GET['token'] ?? '';
if ($token !== env('CRON_SECRET')) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}
$task = $_GET['task'] ?? '';
if ($task === 'new') {
    require __DIR__.'/../bin/fetch_new.php';
    echo "OK new";
} elseif ($task === 'reconcile') {
    require __DIR__.'/../bin/reconcile.php';
    echo "OK reconcile";
} else {
    echo "Unknown task";
}
