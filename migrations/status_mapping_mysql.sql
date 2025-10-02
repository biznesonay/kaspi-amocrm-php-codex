CREATE TABLE IF NOT EXISTS status_mapping (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kaspi_status VARCHAR(64) NOT NULL,
  amo_pipeline_id BIGINT NOT NULL,
  amo_status_id BIGINT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  amo_responsible_user_id BIGINT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY status_mapping_kaspi_pipeline_unique (kaspi_status, amo_pipeline_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'status_mapping'
    AND column_name = 'is_active'
);
SET @add_column_sql = IF(
  @column_exists = 0,
  'ALTER TABLE status_mapping ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER amo_responsible_user_id',
  'SELECT 1'
);
PREPARE stmt FROM @add_column_sql;
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

SET @old_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'status_mapping'
    AND index_name = 'status_mapping_kaspi_status_unique'
);
SET @drop_old_index_sql = IF(
  @old_index_exists > 0,
  'ALTER TABLE status_mapping DROP INDEX status_mapping_kaspi_status_unique',
  'SELECT 1'
);
PREPARE stmt FROM @drop_old_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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

SET @triple_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'status_mapping'
    AND index_name = 'status_mapping_kaspi_pipeline_status_unique'
);
SET @drop_triple_index_sql = IF(
  @triple_index_exists > 0,
  'ALTER TABLE status_mapping DROP INDEX status_mapping_kaspi_pipeline_status_unique',
  'SELECT 1'
);
PREPARE stmt FROM @drop_triple_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @pair_index_columns = (
  SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'status_mapping'
    AND index_name = 'status_mapping_kaspi_pipeline_unique'
);
SET @needs_pair_index_update = IF(
  @pair_index_columns IS NULL OR @pair_index_columns <> 'kaspi_status,amo_pipeline_id',
  1,
  0
);
SET @drop_existing_pair_index_sql = IF(
  @needs_pair_index_update = 1 AND @pair_index_columns IS NOT NULL,
  'ALTER TABLE status_mapping DROP INDEX status_mapping_kaspi_pipeline_unique',
  'SELECT 1'
);
PREPARE stmt FROM @drop_existing_pair_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_pair_index_sql = IF(
  @needs_pair_index_update = 1,
  'ALTER TABLE status_mapping ADD UNIQUE INDEX status_mapping_kaspi_pipeline_unique (kaspi_status, amo_pipeline_id)',
  'SELECT 1'
);
PREPARE stmt FROM @add_pair_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
