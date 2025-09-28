-- Добавляет недостающие колонки в orders_map на существующей базе (MySQL 5.7+).
-- Выполните файл командой `mysql -u USER -p DB_NAME < migrations/add_orders_map_columns.sql`.

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders_map'
    AND COLUMN_NAME = 'total_price'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `orders_map` ADD COLUMN `total_price` BIGINT;',
  'SELECT 0;'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders_map'
    AND COLUMN_NAME = 'processing_token'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `orders_map` ADD COLUMN `processing_token` VARCHAR(64);',
  'SELECT 0;'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders_map'
    AND COLUMN_NAME = 'processing_at'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `orders_map` ADD COLUMN `processing_at` DATETIME NULL;',
  'SELECT 0;'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
