<?php
declare(strict_types=1);

require_once __DIR__.'/Db.php';
require_once __DIR__.'/Logger.php';

/**
 * Управляет сопоставлениями статусов Kaspi и amoCRM.
 */
final class StatusMappingManager {
    private const SUPPORTED_KASPI_STATUSES = [
        'NEW',
        'APPROVED_BY_BANK',
        'ACCEPTED_BY_MERCHANT',
        'COLLECTED',
        'DELIVERY',
        'DELIVERED',
        'COMPLETED',
        'RETURNED',
        'CANCELLED_BY_CUSTOMER',
        'CANCELLED_BY_MERCHANT',
        'CANCELLED_BY_BANK',
    ];
    private PDO $pdo;
    private bool $hasActiveColumn;
    /** @var array<string, bool> */
    private array $columnExistsCache = [];

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Db::pdo();
        $this->hasActiveColumn = $this->columnExists('is_active');
    }

    /**
     * Получает список всех сопоставлений.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllMappings(): array {
        try {
            $columns = $this->baseColumns();
            $sql = "SELECT {$columns} FROM status_mapping ORDER BY kaspi_status";
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
     * Возвращает сопоставление по статусу Kaspi.
     *
     * @param string $kaspiStatus Статус Kaspi.
     * @param int    $pipelineId  ID воронки amoCRM.
     * @return array<string, mixed>|null
     */
    public function getMapping(string $kaspiStatus, int $pipelineId): ?array {
        try {
            $mapping = $this->fetchMapping($kaspiStatus, $pipelineId, false);
            if ($mapping === null) {
                Logger::info('Kaspi status mapping not found', [
                    'kaspi_status' => $kaspiStatus,
                    'amo_pipeline_id' => $pipelineId,
                ]);
            } else {
                Logger::info('Kaspi status mapping fetched', [
                    'kaspi_status' => $kaspiStatus,
                    'amo_pipeline_id' => $pipelineId,
                ]);
            }
            return $mapping;
        } catch (PDOException $e) {
            Logger::error('Failed to fetch mapping by kaspi status', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $pipelineId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Возвращает ID статуса amoCRM по статусу Kaspi (только активные сопоставления).
     *
     * @param string $kaspiStatus Статус Kaspi.
     * @param int    $pipelineId  ID воронки amoCRM.
     * @return int|null
     */
    public function getAmoStatusId(string $kaspiStatus, int $pipelineId): ?int {
        try {
            $mapping = $this->fetchMapping($kaspiStatus, $pipelineId, true);
            if ($mapping !== null && isset($mapping['amo_status_id'])) {
                $amoStatusId = (int) $mapping['amo_status_id'];
                Logger::info('Fetched amo status id for Kaspi status', [
                    'kaspi_status' => $kaspiStatus,
                    'amo_pipeline_id' => $pipelineId,
                    'amo_status_id' => $amoStatusId,
                ]);
                return $amoStatusId;
            }
            Logger::info('No amo status id found for Kaspi status', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $pipelineId,
            ]);
            return null;
        } catch (PDOException $e) {
            Logger::error('Failed to fetch amo status id', [
                'kaspi_status' => $kaspiStatus,
                'amo_pipeline_id' => $pipelineId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
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
        Logger::info('Kaspi statuses fetched', ['count' => count(self::SUPPORTED_KASPI_STATUSES)]);
        return self::SUPPORTED_KASPI_STATUSES;
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
            'amo_responsible_user_id',
            'created_at',
            'updated_at',
        ];
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
     * Получает сопоставление по статусу без логирования.
     */
    private function fetchMapping(string $kaspiStatus, int $pipelineId, bool $onlyActive): ?array {
        $sql = 'SELECT '.$this->baseColumns().' FROM status_mapping'
            .' WHERE kaspi_status = :kaspi_status AND amo_pipeline_id = :amo_pipeline_id';
        if ($onlyActive && $this->hasActiveColumn) {
            $sql .= ' AND is_active = :is_active';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':kaspi_status', $kaspiStatus);
        $stmt->bindValue(':amo_pipeline_id', $pipelineId, PDO::PARAM_INT);
        if ($onlyActive && $this->hasActiveColumn) {
            $stmt->bindValue(':is_active', true, PDO::PARAM_BOOL);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row === null) {
            return null;
        }
        return $this->mapRow($row);
    }

    private function performUpsert(string $kaspiStatus, int $amoPipelineId, int $amoStatusId, bool $isActive): int {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $sql = 'INSERT INTO status_mapping (kaspi_status, amo_pipeline_id, amo_status_id'.($this->hasActiveColumn ? ', is_active' : '').')'
                .' VALUES (:kaspi_status, :amo_pipeline_id, :amo_status_id'.($this->hasActiveColumn ? ', :is_active' : '').')'
                .' ON CONFLICT (kaspi_status, amo_pipeline_id) DO UPDATE SET '
                .'amo_status_id = EXCLUDED.amo_status_id'
                .($this->hasActiveColumn ? ', is_active = EXCLUDED.is_active' : '')
                .', updated_at = NOW()'
                .' RETURNING id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':kaspi_status', $kaspiStatus);
            $stmt->bindValue(':amo_pipeline_id', $amoPipelineId, PDO::PARAM_INT);
            $stmt->bindValue(':amo_status_id', $amoStatusId, PDO::PARAM_INT);
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

        // Default to MySQL/MariaDB syntax.
        $columns = 'kaspi_status, amo_pipeline_id, amo_status_id';
        $values = ':kaspi_status, :amo_pipeline_id, :amo_status_id';
        $update = 'amo_status_id = VALUES(amo_status_id), updated_at = CURRENT_TIMESTAMP';
        if ($this->hasActiveColumn) {
            $columns .= ', is_active';
            $values .= ', :is_active';
            $update = 'amo_status_id = VALUES(amo_status_id), is_active = VALUES(is_active), updated_at = CURRENT_TIMESTAMP';
        }
        $sql = 'INSERT INTO status_mapping ('.$columns.') VALUES ('.$values.')'
            .' ON DUPLICATE KEY UPDATE '.$update;
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':kaspi_status', $kaspiStatus);
        $stmt->bindValue(':amo_pipeline_id', $amoPipelineId, PDO::PARAM_INT);
        $stmt->bindValue(':amo_status_id', $amoStatusId, PDO::PARAM_INT);
        if ($this->hasActiveColumn) {
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
        }
        $stmt->execute();
        $lastInsertId = (int) $this->pdo->lastInsertId();
        if ($lastInsertId > 0) {
            return $lastInsertId;
        }
        $mapping = $this->fetchMapping($kaspiStatus, $amoPipelineId, false);
        if ($mapping === null || !isset($mapping['id'])) {
            throw new \RuntimeException('Failed to fetch status mapping after upsert');
        }
        return (int) $mapping['id'];
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
