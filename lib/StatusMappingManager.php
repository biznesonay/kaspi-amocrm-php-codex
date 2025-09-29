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
     * @return array<string, mixed>|null
     */
    public function getMapping(string $kaspiStatus): ?array {
        try {
            $mapping = $this->fetchMappingByStatus($kaspiStatus);
            if ($mapping === null) {
                Logger::info('Kaspi status mapping not found', ['kaspi_status' => $kaspiStatus]);
            } else {
                Logger::info('Kaspi status mapping fetched', ['kaspi_status' => $kaspiStatus]);
            }
            return $mapping;
        } catch (PDOException $e) {
            Logger::error('Failed to fetch mapping by kaspi status', [
                'kaspi_status' => $kaspiStatus,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Возвращает ID статуса amoCRM по статусу Kaspi (только активные сопоставления).
     *
     * @param string $kaspiStatus Статус Kaspi.
     * @return int|null
     */
    public function getAmoStatusId(string $kaspiStatus): ?int {
        try {
            $sql = 'SELECT amo_status_id FROM status_mapping WHERE kaspi_status = :kaspi_status';
            $params = [':kaspi_status' => $kaspiStatus];
            if ($this->hasActiveColumn) {
                $sql .= ' AND is_active = :is_active';
                $params[':is_active'] = true;
            }
            $stmt = $this->pdo->prepare($sql.' LIMIT 1');
            foreach ($params as $key => $value) {
                if ($key === ':is_active') {
                    $stmt->bindValue($key, $value, PDO::PARAM_BOOL);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $amoStatusId = $row['amo_status_id'] ?? null;
            if ($amoStatusId !== null) {
                Logger::info('Fetched amo status id for Kaspi status', [
                    'kaspi_status' => $kaspiStatus,
                    'amo_status_id' => (int) $amoStatusId,
                ]);
                return (int) $amoStatusId;
            }
            Logger::info('No amo status id found for Kaspi status', ['kaspi_status' => $kaspiStatus]);
            return null;
        } catch (PDOException $e) {
            Logger::error('Failed to fetch amo status id', [
                'kaspi_status' => $kaspiStatus,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Создаёт или обновляет сопоставление.
     *
     * @param string   $kaspiStatus            Статус Kaspi.
     * @param int      $amoPipelineId         ID воронки amoCRM.
     * @param int      $amoStatusId           ID статуса amoCRM.
     * @param int|null $amoResponsibleUserId  ID ответственного amoCRM.
     * @return array<string, mixed>
     */
    public function upsertMapping(string $kaspiStatus, int $amoPipelineId, int $amoStatusId, ?int $amoResponsibleUserId = null): array {
        try {
            $this->pdo->beginTransaction();
            $existing = $this->fetchMappingByStatus($kaspiStatus);
            if ($existing === null) {
                $this->insertMapping($kaspiStatus, $amoPipelineId, $amoStatusId, $amoResponsibleUserId);
                $action = 'inserted';
            } else {
                $this->updateMapping($kaspiStatus, $amoPipelineId, $amoStatusId, $amoResponsibleUserId);
                $action = 'updated';
            }
            $this->pdo->commit();
            $mapping = $this->fetchMappingByStatus($kaspiStatus) ?? [];
            Logger::info('Status mapping upserted', [
                'kaspi_status' => $kaspiStatus,
                'action' => $action,
            ]);
            return $mapping;
        } catch (PDOException $e) {
            $this->rollbackSafely();
            Logger::error('Failed to upsert status mapping', [
                'kaspi_status' => $kaspiStatus,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Удаляет сопоставление по ID.
     *
     * @param int $id Идентификатор записи.
     * @return array{deleted: bool}
     */
    public function deleteMapping(int $id): array {
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare('DELETE FROM status_mapping WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $deleted = $stmt->rowCount() > 0;
            $this->pdo->commit();
            Logger::info('Status mapping deleted', ['id' => $id, 'deleted' => $deleted]);
            return ['deleted' => $deleted];
        } catch (PDOException $e) {
            $this->rollbackSafely();
            Logger::error('Failed to delete status mapping', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Деактивирует сопоставление.
     *
     * @param int $id Идентификатор записи.
     * @return array{updated: bool}
     */
    public function deactivateMapping(int $id): array {
        if (!$this->hasActiveColumn) {
            Logger::error('Cannot deactivate mapping: is_active column missing', ['id' => $id]);
            return ['updated' => false];
        }
        return $this->toggleActive($id, false);
    }

    /**
     * Активирует сопоставление.
     *
     * @param int $id Идентификатор записи.
     * @return array{updated: bool}
     */
    public function activateMapping(int $id): array {
        if (!$this->hasActiveColumn) {
            Logger::error('Cannot activate mapping: is_active column missing', ['id' => $id]);
            return ['updated' => false];
        }
        return $this->toggleActive($id, true);
    }

    /**
     * Возвращает список статусов Kaspi (только активные, если колонка активности есть).
     *
     * @return array<int, string>
     */
    public function getKaspiStatuses(): array {
        try {
            $sql = 'SELECT kaspi_status FROM status_mapping';
            if ($this->hasActiveColumn) {
                $sql .= ' WHERE is_active = :is_active';
            }
            $sql .= ' ORDER BY kaspi_status';
            $stmt = $this->pdo->prepare($sql);
            if ($this->hasActiveColumn) {
                $stmt->bindValue(':is_active', true, PDO::PARAM_BOOL);
            }
            $stmt->execute();
            $statuses = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $statuses[] = (string) $row['kaspi_status'];
            }
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
                $stmt = $this->pdo->query($sql);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $stats = [
                    'total' => isset($row['total']) ? (int) $row['total'] : 0,
                    'active' => isset($row['active']) ? (int) $row['active'] : 0,
                    'inactive' => isset($row['inactive']) ? (int) $row['inactive'] : 0,
                ];
            } else {
                $stmt = $this->pdo->query('SELECT COUNT(*) AS total FROM status_mapping');
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
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
    private function fetchMappingByStatus(string $kaspiStatus): ?array {
        $sql = 'SELECT '.$this->baseColumns().' FROM status_mapping WHERE kaspi_status = :kaspi_status LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':kaspi_status', $kaspiStatus);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row === null) {
            return null;
        }
        return $this->mapRow($row);
    }

    /**
     * Вставляет новую запись.
     */
    private function insertMapping(string $kaspiStatus, int $amoPipelineId, int $amoStatusId, ?int $amoResponsibleUserId): void {
        $columns = ['kaspi_status', 'amo_pipeline_id', 'amo_status_id', 'amo_responsible_user_id'];
        $placeholders = [':kaspi_status', ':amo_pipeline_id', ':amo_status_id', ':amo_responsible_user_id'];
        if ($this->hasActiveColumn) {
            $columns[] = 'is_active';
            $placeholders[] = ':is_active';
        }
        $sql = sprintf(
            'INSERT INTO status_mapping (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':kaspi_status', $kaspiStatus);
        $stmt->bindValue(':amo_pipeline_id', $amoPipelineId, PDO::PARAM_INT);
        $stmt->bindValue(':amo_status_id', $amoStatusId, PDO::PARAM_INT);
        if ($amoResponsibleUserId === null) {
            $stmt->bindValue(':amo_responsible_user_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':amo_responsible_user_id', $amoResponsibleUserId, PDO::PARAM_INT);
        }
        if ($this->hasActiveColumn) {
            $stmt->bindValue(':is_active', true, PDO::PARAM_BOOL);
        }
        $stmt->execute();
    }

    /**
     * Обновляет существующую запись.
     */
    private function updateMapping(string $kaspiStatus, int $amoPipelineId, int $amoStatusId, ?int $amoResponsibleUserId): void {
        $setParts = [
            'amo_pipeline_id = :amo_pipeline_id',
            'amo_status_id = :amo_status_id',
            'amo_responsible_user_id = :amo_responsible_user_id',
        ];
        if ($this->hasActiveColumn) {
            $setParts[] = 'is_active = :is_active';
        }
        $sql = 'UPDATE status_mapping SET '.implode(', ', $setParts).' WHERE kaspi_status = :kaspi_status';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':kaspi_status', $kaspiStatus);
        $stmt->bindValue(':amo_pipeline_id', $amoPipelineId, PDO::PARAM_INT);
        $stmt->bindValue(':amo_status_id', $amoStatusId, PDO::PARAM_INT);
        if ($amoResponsibleUserId === null) {
            $stmt->bindValue(':amo_responsible_user_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':amo_responsible_user_id', $amoResponsibleUserId, PDO::PARAM_INT);
        }
        if ($this->hasActiveColumn) {
            $stmt->bindValue(':is_active', true, PDO::PARAM_BOOL);
        }
        $stmt->execute();
    }

    /**
     * Активирует или деактивирует запись.
     */
    private function toggleActive(int $id, bool $active): array {
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare('UPDATE status_mapping SET is_active = :is_active WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':is_active', $active, PDO::PARAM_BOOL);
            $stmt->execute();
            $updated = $stmt->rowCount() > 0;
            $this->pdo->commit();
            Logger::info($active ? 'Status mapping activated' : 'Status mapping deactivated', [
                'id' => $id,
                'updated' => $updated,
            ]);
            return ['updated' => $updated];
        } catch (PDOException $e) {
            $this->rollbackSafely();
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
     * Откатывает транзакцию, если она открыта.
     */
    private function rollbackSafely(): void {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
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
