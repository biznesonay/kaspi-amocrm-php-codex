CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(64) PRIMARY KEY,
  `value` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders_map (
  order_code VARCHAR(64) PRIMARY KEY,
  kaspi_order_id VARCHAR(64) NOT NULL,
  kaspi_status VARCHAR(64) DEFAULT NULL,
  lead_id BIGINT NOT NULL,
  total_price BIGINT,
  processing_token VARCHAR(64),
  processing_at DATETIME NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders_map'
    AND COLUMN_NAME = 'kaspi_status'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `orders_map` ADD COLUMN `kaspi_status` VARCHAR(64) NULL DEFAULT NULL;',
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

CREATE TABLE IF NOT EXISTS oauth_tokens (
  service VARCHAR(32) PRIMARY KEY,
  access_token TEXT NOT NULL,
  refresh_token TEXT NOT NULL,
  expires_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
