-- Обновляет таблицу status_mapping для поддержки нескольких статусов amoCRM на один статус Kaspi.
-- Выполните файл командой `mysql -u USER -p DB_NAME < migrations/status_mapping_allow_multiple_mysql.sql`.

SET @pair_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'status_mapping'
    AND index_name = 'status_mapping_kaspi_status_pipeline_unique'
);
SET @drop_pair_index_sql = IF(
  @pair_index_exists > 0,
  'ALTER TABLE status_mapping DROP INDEX status_mapping_kaspi_status_pipeline_unique',
  'SELECT 1'
);
PREPARE stmt FROM @drop_pair_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sort_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'status_mapping'
    AND column_name = 'sort_order'
);
SET @add_sort_column_sql = IF(
  @sort_column_exists = 0,
  'ALTER TABLE status_mapping ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER amo_status_id',
  'SELECT 1'
);
PREPARE stmt FROM @add_sort_column_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @triple_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'status_mapping'
    AND index_name = 'status_mapping_kaspi_pipeline_status_unique'
);
SET @add_triple_index_sql = IF(
  @triple_index_exists = 0,
  'ALTER TABLE status_mapping ADD UNIQUE INDEX status_mapping_kaspi_pipeline_status_unique (kaspi_status, amo_pipeline_id, amo_status_id)',
  'SELECT 1'
);
PREPARE stmt FROM @add_triple_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
