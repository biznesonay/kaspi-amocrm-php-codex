<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../lib/Db.php';
require_once __DIR__.'/../lib/Logger.php';
require_once __DIR__.'/../lib/Phone.php';
require_once __DIR__.'/../lib/KaspiClient.php';
require_once __DIR__.'/../lib/AmoClient.php';

function normalizePhone(string $raw): string {
    $def = env('DEFAULT_COUNTRY', 'KZ');
    return Phone::toE164($raw, $def);
}

function getOrderCode(array $order): string {
    return (string)($order['attributes']['code'] ?? '');
}
