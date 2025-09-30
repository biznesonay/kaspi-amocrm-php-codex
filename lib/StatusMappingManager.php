<?php
declare(strict_types=1);

require_once __DIR__.'/Db.php';
require_once __DIR__.'/Logger.php';

/**
 * Управляет сопоставлениями статусов Kaspi и amoCRM.
 */
final class StatusMappingManager {
    private PDO $pdo;
    private bool $hasActiveColumn;
    private bool $hasSortOrderColumn;
    /** @var array<string, bool> */
    private array $columnExistsCache = [];
    /** @var array<string, bool> */
    private array $tableExistsCache = [];
    /** @var array<int, string>|null */
    private ?array $kaspiStatusesCache = null;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Db::pdo();
        $this->hasActiveColumn = $this->columnExists('is_active');
        $this->hasSortOrderColumn = $this->columnExists('sort_order');
    }

    /**
     * Получает список всех сопоставлений.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllMappings(): array {
        try {
            $columns = $this->baseColumns();
            $order = $this->hasSortOrderColumn
                ? 'kaspi_status, amo_pipeline_id, sort_order, id'
                : 'kaspi_status, amo_pipeline_id, id';
            $sql = "SELECT {$columns} FROM status_mapping ORDER BY {$order}";
            $stmt = $this->pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $mappings = array_map(fn(array $row) => $this->mapRow($row), $rows);
            Logger::info('Fetched status mappings', ['count' => count($mappings)]);
            return $mappings;
        } catch (PDOException $e) {
            Logger::error('Failed to fetch status mappings', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Возвращает список сопоставлений по статусу Kaspi и ID воронки amoCRM.
     *
     * @param string $kaspiStatus Статус Kaspi.
     * @param int    $pipelineId  ID воронки amoCRM.
     * @param bool   $onlyActive  Ограничить выборку только активными сопоставлениями.
     * @return array<int, array<string, mixed>>
     */
    public function getMappings(string $kaspiStatus, int $pipelineId, bool $onlyActive = false): array {
        try {
            $mappings = $this->fetchMappings($kaspiStatus, $pipelineId, $onlyActive);
            Logger::info('Kaspi status mappings fetched', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $pipelineId,
                'only_active' => $onlyActive,
                'count' => count($mappings),
            ]);
            return $mappings;
        } catch (PDOException $e) {
            Logger::error('Failed to fetch mappings by kaspi status', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $pipelineId,
                'only_active' => $onlyActive,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Возвращает список ID статусов amoCRM для статуса Kaspi.
     *
     * @param string $kaspiStatus Статус Kaspi.
     * @param int    $pipelineId  ID воронки amoCRM.
     * @param bool   $onlyActive  Возвращать только активные сопоставления.
     * @return array<int, int>
     */
    public function getAmoStatusIds(string $kaspiStatus, int $pipelineId, bool $onlyActive = true): array {
        $mappings = $this->getMappings($kaspiStatus, $pipelineId, $onlyActive);
        $ids = [];
        foreach ($mappings as $mapping) {
            if (isset($mapping['amo_status_id'])) {
                $ids[] = (int) $mapping['amo_status_id'];
            }
        }
        Logger::info('Resolved amo status ids for Kaspi status', [
            'kaspi_status' => $kaspiStatus,
            'amo_pipeline_id' => $pipelineId,
            'only_active' => $onlyActive,
            'count' => count($ids),
        ]);
        return $ids;
    }

    /**
     * Создаёт или обновляет сопоставление.
     *
     * @param string $kaspiStatus    Статус Kaspi.
     * @param int    $amoPipelineId  ID воронки amoCRM.
     * @param int    $amoStatusId    ID статуса amoCRM.
     * @param bool   $isActive       Флаг активности сопоставления.
     * @param int    $sortOrder      Порядок сортировки внутри статуса Kaspi.
     * @return int ID сохранённой записи.
     */
    public function upsertMapping(string $kaspiStatus, int $amoPipelineId, int $amoStatusId, bool $isActive = true, int $sortOrder = 0): int {
        try {
            $id = $this->performUpsert($kaspiStatus, $amoPipelineId, $amoStatusId, $isActive, $sortOrder);
            Logger::info('Status mapping upserted', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $amoPipelineId,
                'amo_status_id' => $amoStatusId,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
                'id' => $id,
            ]);
            return $id;
        } catch (PDOException $e) {
            Logger::error('Failed to upsert status mapping', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $amoPipelineId,
                'amo_status_id' => $amoStatusId,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Удаляет сопоставление по ID.
     */
    public function deleteMapping(int $id): bool {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM status_mapping WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $deleted = $stmt->rowCount() > 0;
            Logger::info('Status mapping deleted', ['id' => $id, 'deleted' => $deleted]);
            return $deleted;
        } catch (PDOException $e) {
            Logger::error('Failed to delete status mapping', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Деактивирует сопоставление.
     */
    public function deactivateMapping(int $id): bool {
        if (!$this->hasActiveColumn) {
            Logger::error('Cannot deactivate mapping: is_active column missing', ['id' => $id]);
            return false;
        }
        return $this->setActive($id, false);
    }

    /**
     * Активирует сопоставление.
     */
    public function activateMapping(int $id): bool {
        if (!$this->hasActiveColumn) {
            Logger::error('Cannot activate mapping: is_active column missing', ['id' => $id]);
            return false;
        }
        return $this->setActive($id, true);
    }

    /**
     * Возвращает список статусов Kaspi (только активные, если колонка активности есть).
     *
     * @return array<int, string>
     */
    public function getKaspiStatuses(): array {
        if ($this->kaspiStatusesCache !== null) {
            Logger::info('Kaspi statuses fetched from cache', ['count' => count($this->kaspiStatusesCache)]);
            return $this->kaspiStatusesCache;
        }

        try {
            $statuses = $this->fetchKaspiStatuses();
            $this->kaspiStatusesCache = $statuses;
            Logger::info('Kaspi statuses fetched', ['count' => count($statuses)]);
            return $statuses;
        } catch (PDOException $e) {
            Logger::error('Failed to fetch Kaspi statuses', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Возвращает статистику по сопоставлениям.
     *
     * @return array<string, int>
     */
    public function getMappingStats(): array {
        try {
            if ($this->hasActiveColumn) {
                $sql = 'SELECT COUNT(*) AS total,'
                    .' SUM(CASE WHEN is_active THEN 1 ELSE 0 END) AS active,'
                    .' SUM(CASE WHEN NOT is_active THEN 1 ELSE 0 END) AS inactive'
                    .' FROM status_mapping';
            } else {
                $sql = 'SELECT COUNT(*) AS total FROM status_mapping';
            }
            $stmt = $this->pdo->query($sql);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($this->hasActiveColumn) {
                $stats = [
                    'total' => isset($row['total']) ? (int) $row['total'] : 0,
                    'active' => isset($row['active']) ? (int) $row['active'] : 0,
                    'inactive' => isset($row['inactive']) ? (int) $row['inactive'] : 0,
                ];
            } else {
                $total = isset($row['total']) ? (int) $row['total'] : 0;
                $stats = [
                    'total' => $total,
                    'active' => $total,
                    'inactive' => 0,
                ];
            }
            Logger::info('Status mapping stats fetched', $stats);
            return $stats;
        } catch (PDOException $e) {
            Logger::error('Failed to fetch mapping stats', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Получает список колонок для выборки.
     */
    private function baseColumns(): string {
        $columns = [
            'id',
            'kaspi_status',
            'amo_pipeline_id',
            'amo_status_id',
        ];
        if ($this->hasSortOrderColumn) {
            $columns[] = 'sort_order';
        }
        $columns = array_merge($columns, [
            'amo_responsible_user_id',
            'created_at',
            'updated_at',
        ]);
        if ($this->hasActiveColumn) {
            $columns[] = 'is_active';
        }
        return implode(', ', $columns);
    }

    /**
     * Преобразует строку БД в массив с нужными типами.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'kaspi_status' => (string) ($row['kaspi_status'] ?? ''),
            'amo_pipeline_id' => isset($row['amo_pipeline_id']) ? (int) $row['amo_pipeline_id'] : null,
            'amo_status_id' => isset($row['amo_status_id']) ? (int) $row['amo_status_id'] : null,
            'sort_order' => $this->hasSortOrderColumn
                ? (int) ($row['sort_order'] ?? 0)
                : null,
            'amo_responsible_user_id' => array_key_exists('amo_responsible_user_id', $row) && $row['amo_responsible_user_id'] !== null
                ? (int) $row['amo_responsible_user_id']
                : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'is_active' => $this->hasActiveColumn
                ? $this->normalizeBool($row['is_active'] ?? null)
                : true,
        ];
    }

    /**
     * Получает список сопоставлений по статусу без дополнительного логирования.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchMappings(string $kaspiStatus, int $pipelineId, bool $onlyActive): array {
        $sql = 'SELECT '.$this->baseColumns().' FROM status_mapping'
            .' WHERE kaspi_status = :kaspi_status AND amo_pipeline_id = :amo_pipeline_id';
        if ($onlyActive && $this->hasActiveColumn) {
            $sql .= ' AND is_active = :is_active';
        }
        $orderBy = $this->hasSortOrderColumn ? ' ORDER BY sort_order, id' : ' ORDER BY id';
        $sql .= $orderBy;
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':kaspi_status', $kaspiStatus);
        $stmt->bindValue(':amo_pipeline_id', $pipelineId, PDO::PARAM_INT);
        if ($onlyActive && $this->hasActiveColumn) {
            $stmt->bindValue(':is_active', true, PDO::PARAM_BOOL);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        return array_map(fn(array $row) => $this->mapRow($row), $rows);
    }

    private function performUpsert(string $kaspiStatus, int $amoPipelineId, int $amoStatusId, bool $isActive, int $sortOrder): int {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $columns = 'kaspi_status, amo_pipeline_id, amo_status_id';
            $values = ':kaspi_status, :amo_pipeline_id, :amo_status_id';
            $updates = ['amo_status_id = EXCLUDED.amo_status_id'];
            if ($this->hasSortOrderColumn) {
                $columns .= ', sort_order';
                $values .= ', :sort_order';
                $updates[] = 'sort_order = EXCLUDED.sort_order';
            }
            if ($this->hasActiveColumn) {
                $columns .= ', is_active';
                $values .= ', :is_active';
                $updates[] = 'is_active = EXCLUDED.is_active';
            }
            $updates[] = 'updated_at = NOW()';
            $sql = 'INSERT INTO status_mapping ('.$columns.') VALUES ('.$values.')'
                .' ON CONFLICT (kaspi_status, amo_pipeline_id) DO UPDATE SET '
                .implode(', ', $updates)
                .' RETURNING id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':kaspi_status', $kaspiStatus);
            $stmt->bindValue(':amo_pipeline_id', $amoPipelineId, PDO::PARAM_INT);
            $stmt->bindValue(':amo_status_id', $amoStatusId, PDO::PARAM_INT);
            if ($this->hasSortOrderColumn) {
                $stmt->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
            }
            if ($this->hasActiveColumn) {
                $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
            }
            $stmt->execute();
            $id = $stmt->fetchColumn();
            if ($id === false || $id === null) {
                throw new \RuntimeException('Upsert did not return an ID');
            }
            return (int) $id;
        }

        $columns = 'kaspi_status, amo_pipeline_id, amo_status_id';
        $values = ':kaspi_status, :amo_pipeline_id, :amo_status_id';
        $updates = [
            'amo_status_id = VALUES(amo_status_id)',
            'updated_at = CURRENT_TIMESTAMP',
        ];
        if ($this->hasSortOrderColumn) {
            $columns .= ', sort_order';
            $values .= ', :sort_order';
            $updates[] = 'sort_order = VALUES(sort_order)';
        }
        if ($this->hasActiveColumn) {
            $columns .= ', is_active';
            $values .= ', :is_active';
            $updates[] = 'is_active = VALUES(is_active)';
        }
        $sql = 'INSERT INTO status_mapping ('.$columns.') VALUES ('.$values.')'
            .' ON DUPLICATE KEY UPDATE '.implode(', ', $updates);
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':kaspi_status', $kaspiStatus);
        $stmt->bindValue(':amo_pipeline_id', $amoPipelineId, PDO::PARAM_INT);
        $stmt->bindValue(':amo_status_id', $amoStatusId, PDO::PARAM_INT);
        if ($this->hasSortOrderColumn) {
            $stmt->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
        }
        if ($this->hasActiveColumn) {
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
        }
        $stmt->execute();
        $lastInsertId = (int) $this->pdo->lastInsertId();
        if ($lastInsertId > 0) {
            return $lastInsertId;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM status_mapping WHERE kaspi_status = :kaspi_status'
            .' AND amo_pipeline_id = :amo_pipeline_id'
            .' ORDER BY id'
        );
        $stmt->bindValue(':kaspi_status', $kaspiStatus);
        $stmt->bindValue(':amo_pipeline_id', $amoPipelineId, PDO::PARAM_INT);
        $stmt->execute();
        $id = $stmt->fetchColumn();
        if ($id === false || $id === null) {
            throw new \RuntimeException('Failed to fetch status mapping after upsert');
        }
        return (int) $id;
    }

    /**
     * Активирует или деактивирует запись.
     */
    private function setActive(int $id, bool $active): bool {
        try {
            $stmt = $this->pdo->prepare('UPDATE status_mapping SET is_active = :is_active WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':is_active', $active, PDO::PARAM_BOOL);
            $stmt->execute();
            $updated = $stmt->rowCount() > 0;
            Logger::info($active ? 'Status mapping activated' : 'Status mapping deactivated', [
                'id' => $id,
                'updated' => $updated,
            ]);
            return $updated;
        } catch (PDOException $e) {
            Logger::error('Failed to change mapping activity', [
                'id' => $id,
                'active' => $active,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Проверяет существование колонки в таблице.
     */
    private function columnExists(string $column): bool {
        if (array_key_exists($column, $this->columnExistsCache)) {
            return $this->columnExistsCache[$column];
        }
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $sql = 'SELECT 1 FROM information_schema.columns'
                    .' WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column';
            } else {
                $sql = 'SELECT 1 FROM information_schema.columns'
                    .' WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column';
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':table', 'status_mapping');
            $stmt->bindValue(':column', $column);
            $stmt->execute();
            $exists = (bool) $stmt->fetchColumn();
            $this->columnExistsCache[$column] = $exists;
            return $exists;
        } catch (PDOException $e) {
            Logger::error('Failed to inspect table structure', [
                'column' => $column,
                'error' => $e->getMessage(),
            ]);
            $this->columnExistsCache[$column] = false;
            return false;
        }
    }

    private function fetchKaspiStatuses(): array {
        $statuses = [];
        $sources = [
            [
                'table' => 'orders_map',
                'sql' => "SELECT DISTINCT kaspi_status FROM orders_map WHERE kaspi_status IS NOT NULL AND TRIM(kaspi_status) <> ''",
            ],
            [
                'table' => 'status_mapping',
                'sql' => "SELECT DISTINCT kaspi_status FROM status_mapping WHERE kaspi_status IS NOT NULL AND TRIM(kaspi_status) <> ''",
            ],
        ];

        foreach ($sources as $source) {
            if (!$this->tableExists($source['table'])) {
                Logger::info('Skipping Kaspi status source table because it does not exist', ['table' => $source['table']]);
                continue;
            }

            try {
                $stmt = $this->pdo->query($source['sql']);
            } catch (PDOException $e) {
                Logger::error('Failed to query Kaspi statuses from source table', [
                    'table' => $source['table'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (!$stmt) {
                continue;
            }

            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $value) {
                $normalized = $this->normalizeKaspiStatus($value);
                if ($normalized === '') {
                    continue;
                }
                $statuses[$normalized] = true;
            }
        }

        $result = array_keys($statuses);
        sort($result, SORT_STRING);

        if ($result === []) {
            Logger::info('Kaspi statuses list is empty after querying sources');
        }

        return $result;
    }

    private function tableExists(string $table): bool {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        try {
            $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $sql = 'SELECT 1 FROM information_schema.tables'
                    .' WHERE table_schema = current_schema() AND table_name = :table';
            } elseif ($driver === 'sqlite') {
                $sql = "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table";
            } else {
                $sql = 'SELECT 1 FROM information_schema.tables'
                    .' WHERE table_schema = DATABASE() AND table_name = :table';
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':table', $table);
            $stmt->execute();
            $exists = (bool) $stmt->fetchColumn();
            $this->tableExistsCache[$table] = $exists;
            return $exists;
        } catch (PDOException $e) {
            Logger::error('Failed to check table existence', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
            $this->tableExistsCache[$table] = false;
            return false;
        }
    }

    private function normalizeKaspiStatus(mixed $value): string {
        if ($value === null) {
            return '';
        }
        $status = trim((string) $value);
        if ($status === '') {
            return '';
        }
        return strtoupper($status);
    }

    /**
     * Нормализует булево значение, считанное из БД.
     */
    private function normalizeBool(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 't', 'true', 'y', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'f', 'false', 'n', 'no'], true)) {
                return false;
            }
        }
        return (bool) $value;
    }
}
