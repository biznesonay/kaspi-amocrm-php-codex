<?php
declare(strict_types=1);

require_once __DIR__.'/../lib/StatusMappingManager.php';
require_once __DIR__.'/../lib/Logger.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE status_mapping (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kaspi_status TEXT,
    amo_pipeline_id INTEGER,
    amo_status_id INTEGER
)');

$pdo->exec('CREATE TABLE orders_map (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kaspi_status TEXT
)');

$pdo->exec("INSERT INTO orders_map (kaspi_status) VALUES
    (' new'),
    ('COMPLETED'),
    (NULL),
    ('')");

$pdo->exec("INSERT INTO status_mapping (kaspi_status, amo_pipeline_id, amo_status_id) VALUES
    ('approved_by_bank', 1, 111),
    ('completed', 1, 112)");

$manager = new StatusMappingManager($pdo);

$statuses = $manager->getKaspiStatuses();

$expected = ['APPROVED_BY_BANK', 'COMPLETED', 'NEW'];

if ($statuses !== $expected) {
    fwrite(STDERR, 'Kaspi statuses do not match expected list'.PHP_EOL);
    fwrite(STDERR, 'Expected: '.json_encode($expected).PHP_EOL);
    fwrite(STDERR, 'Actual:   '.json_encode($statuses).PHP_EOL);
    exit(1);
}

$cached = $manager->getKaspiStatuses();
if ($cached !== $expected) {
    fwrite(STDERR, 'Kaspi statuses cache returned different result'.PHP_EOL);
    exit(1);
}

echo "KaspiStatusesTest: OK\n";
