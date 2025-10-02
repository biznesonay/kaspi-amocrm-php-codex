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
    /** @var array<int, string>|null */
    private ?array $kaspiStatusesCache = null;

    /**
     * Статусы заказов Kaspi в ожидаемом порядке.
     *
     * @var array<int, string>
     */
    public const KASPI_STATUSES = [
        'NEW',
        'APPROVED_BY_BANK',
        'APPROVED_BY_MERCHANT',
        'COLLECTED',
        'ON_DELIVERY',
        'DELIVERED',
        'COMPLETED',
        'RETURNED_TO_STORE',
        'RETURNED_TO_BANK',
        'RETURNED_TO_MERCHANT',
        'CANCELLED_BY_BANK',
        'CANCELLED_BY_MERCHANT',
        'CANCELLED_BY_CUSTOMER',
    ];

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
     * Возвращает сопоставление по статусу Kaspi и ID воронки amoCRM.
     */
    public function getMapping(string $kaspiStatus, int $pipelineId): ?array {
        try {
            $mapping = $this->fetchMapping($kaspiStatus, $pipelineId, true);
            Logger::info('Kaspi status mapping resolved', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $pipelineId,
                'found' => $mapping !== null,
            ]);
            return $mapping;
        } catch (PDOException $e) {
            Logger::error('Failed to resolve Kaspi status mapping', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $pipelineId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Возвращает ID статуса amoCRM для статуса Kaspi.
     */
    public function getAmoStatusId(string $kaspiStatus, int $pipelineId): ?int {
        $mapping = $this->getMapping($kaspiStatus, $pipelineId);
        if ($mapping === null || !isset($mapping['amo_status_id'])) {
            Logger::info('Kaspi status mapping amo status id not found', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $pipelineId,
            ]);
            return null;
        }

        $amoStatusId = (int) $mapping['amo_status_id'];
        if ($amoStatusId <= 0) {
            Logger::info('Kaspi status mapping amo status id is not positive', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $pipelineId,
                'amo_status_id' => $amoStatusId,
            ]);
            return null;
        }

        Logger::info('Kaspi status mapping amo status id resolved', [
            'kaspi_status' => $kaspiStatus,
            'amo_pipeline_id' => $pipelineId,
            'amo_status_id' => $amoStatusId,
        ]);

        return $amoStatusId;
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
        if ($onlyActive) {
            $amoStatusId = $this->getAmoStatusId($kaspiStatus, $pipelineId);
            return $amoStatusId !== null ? [$amoStatusId] : [];
        }

        $mappings = $this->fetchMappings($kaspiStatus, $pipelineId, false);
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
     * @return int ID сохранённой записи.
     */
    public function upsertMapping(string $kaspiStatus, int $amoPipelineId, int $amoStatusId, bool $isActive = true): int {
        try {
            $id = $this->performUpsert($kaspiStatus, $amoPipelineId, $amoStatusId, $isActive);
            Logger::info('Status mapping upserted', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $amoPipelineId,
                'amo_status_id' => $amoStatusId,
                'is_active' => $isActive,
                'id' => $id,
            ]);
            return $id;
        } catch (PDOException $e) {
            Logger::error('Failed to upsert status mapping', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $amoPipelineId,
                'amo_status_id' => $amoStatusId,
                'is_active' => $isActive,
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
        if ($this->kaspiStatusesCache === null) {
            $this->kaspiStatusesCache = self::KASPI_STATUSES;
            Logger::info('Kaspi statuses loaded from constant', ['count' => count($this->kaspiStatusesCache)]);
        } else {
            Logger::info('Kaspi statuses fetched from cache', ['count' => count($this->kaspiStatusesCache)]);
        }

        return $this->kaspiStatusesCache;
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

    private function fetchMapping(string $kaspiStatus, int $pipelineId, bool $onlyActive): ?array {
        $sql = 'SELECT '.$this->baseColumns().' FROM status_mapping'
            .' WHERE kaspi_status = :kaspi_status AND amo_pipeline_id = :amo_pipeline_id';
        if ($onlyActive && $this->hasActiveColumn) {
            $sql .= ' AND is_active = :is_active';
        }
        $sql .= ' ORDER BY id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':kaspi_status', $kaspiStatus);
        $stmt->bindValue(':amo_pipeline_id', $pipelineId, PDO::PARAM_INT);
        if ($onlyActive && $this->hasActiveColumn) {
            $stmt->bindValue(':is_active', true, PDO::PARAM_BOOL);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return $this->mapRow($row);
    }

    private function performUpsert(string $kaspiStatus, int $amoPipelineId, int $amoStatusId, bool $isActive): int {
        $columns = ['kaspi_status', 'amo_pipeline_id', 'amo_status_id'];
        $placeholders = [':kaspi_status', ':amo_pipeline_id', ':amo_status_id'];
        $parameters = [
            ':kaspi_status' => [$kaspiStatus, PDO::PARAM_STR],
            ':amo_pipeline_id' => [$amoPipelineId, PDO::PARAM_INT],
            ':amo_status_id' => [$amoStatusId, PDO::PARAM_INT],
        ];
        $updatableColumns = ['amo_status_id'];
        if ($this->hasSortOrderColumn) {
            $columns[] = 'sort_order';
            $placeholders[] = ':sort_order';
            $parameters[':sort_order'] = [0, PDO::PARAM_INT];
        }
        if ($this->hasActiveColumn) {
            $columns[] = 'is_active';
            $placeholders[] = ':is_active';
            $parameters[':is_active'] = [$isActive, PDO::PARAM_BOOL];
            $updatableColumns[] = 'is_active';
        }

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $updates = array_map(
                static fn(string $column): string => $column.' = EXCLUDED.'.$column,
                $updatableColumns
            );
            $updates[] = 'updated_at = NOW()';
            $sql = 'INSERT INTO status_mapping ('.implode(', ', $columns).') VALUES ('.implode(', ', $placeholders).')'
                .' ON CONFLICT (kaspi_status, amo_pipeline_id) DO UPDATE SET '
                .implode(', ', $updates)
                .' RETURNING id';
            $stmt = $this->pdo->prepare($sql);
            foreach ($parameters as $placeholder => [$value, $type]) {
                $stmt->bindValue($placeholder, $value, $type);
            }
            $stmt->execute();
            $id = $stmt->fetchColumn();
            if ($id === false || $id === null) {
                throw new \RuntimeException('Upsert did not return an ID');
            }
            return (int) $id;
        }

        $updates = array_map(
            static fn(string $column): string => $column.' = VALUES('.$column.')',
            $updatableColumns
        );
        $updates[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql = 'INSERT INTO status_mapping ('.implode(', ', $columns).') VALUES ('.implode(', ', $placeholders).')'
            .' ON DUPLICATE KEY UPDATE '.implode(', ', $updates);
        $stmt = $this->pdo->prepare($sql);
        foreach ($parameters as $placeholder => [$value, $type]) {
            $stmt->bindValue($placeholder, $value, $type);
        }
        $stmt->execute();
        $lastInsertId = (int) $this->pdo->lastInsertId();
        if ($lastInsertId > 0) {
            return $lastInsertId;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM status_mapping WHERE kaspi_status = :kaspi_status'
            .' AND amo_pipeline_id = :amo_pipeline_id'
            .' ORDER BY id LIMIT 1'
        );
        $stmt->bindValue(':kaspi_status', $kaspiStatus, PDO::PARAM_STR);
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
