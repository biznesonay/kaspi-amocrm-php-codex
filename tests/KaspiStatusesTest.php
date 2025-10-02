<?php
declare(strict_types=1);

require_once __DIR__.'/../lib/StatusMappingManager.php';
require_once __DIR__.'/../lib/Logger.php';

/**
 * PDO-заглушка, которая запрещает вызовы query() после инициализации менеджера статусов.
 */
final class NoQueryPDO extends PDO {
    public function __construct() {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        throw new RuntimeException('Unexpected database query: '.$query);
    }
}

$pdo = new NoQueryPDO();
$manager = new StatusMappingManager($pdo);

$statuses = $manager->getKaspiStatuses();

$expected = StatusMappingManager::KASPI_STATUSES;

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
