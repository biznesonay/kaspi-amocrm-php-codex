CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(64) PRIMARY KEY,
  `value` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders_map (
  order_code VARCHAR(64) PRIMARY KEY,
  kaspi_order_id VARCHAR(64) NOT NULL,
  lead_id BIGINT NOT NULL,
  total_price BIGINT,
  processing_token VARCHAR(64),
  processing_at DATETIME NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders_map
  ADD COLUMN IF NOT EXISTS total_price BIGINT;
ALTER TABLE orders_map
  ADD COLUMN IF NOT EXISTS processing_token VARCHAR(64);
ALTER TABLE orders_map
  ADD COLUMN IF NOT EXISTS processing_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS oauth_tokens (
  service VARCHAR(32) PRIMARY KEY,
  access_token TEXT NOT NULL,
  refresh_token TEXT NOT NULL,
  expires_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
