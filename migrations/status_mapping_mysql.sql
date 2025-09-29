CREATE TABLE IF NOT EXISTS status_mapping (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kaspi_status VARCHAR(64) NOT NULL,
  amo_pipeline_id BIGINT NOT NULL,
  amo_status_id BIGINT NOT NULL,
  amo_responsible_user_id BIGINT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY status_mapping_kaspi_status_pipeline_unique (kaspi_status, amo_pipeline_id)
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

SET @old_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'status_mapping'
    AND index_name = 'status_mapping_kaspi_status_unique'
);
SET @drop_index_sql = IF(
  @old_index_exists > 0,
  'ALTER TABLE status_mapping DROP INDEX status_mapping_kaspi_status_unique',
  'SELECT 1'
);
PREPARE stmt FROM @drop_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @new_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'status_mapping'
    AND index_name = 'status_mapping_kaspi_status_pipeline_unique'
);
SET @add_index_sql = IF(
  @new_index_exists = 0,
  'ALTER TABLE status_mapping ADD UNIQUE INDEX status_mapping_kaspi_status_pipeline_unique (kaspi_status, amo_pipeline_id)',
  'SELECT 1'
);
PREPARE stmt FROM @add_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
