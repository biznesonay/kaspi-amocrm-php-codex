CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT
);

CREATE TABLE IF NOT EXISTS orders_map (
  order_code TEXT PRIMARY KEY,
  kaspi_order_id TEXT NOT NULL,
  kaspi_status TEXT,
  lead_id BIGINT NOT NULL,
  total_price NUMERIC(20,0),
  processing_token TEXT,
  processing_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

ALTER TABLE IF EXISTS orders_map
  ADD COLUMN IF NOT EXISTS kaspi_status TEXT;
ALTER TABLE IF EXISTS orders_map
  ADD COLUMN IF NOT EXISTS total_price NUMERIC(20,0);
ALTER TABLE IF EXISTS orders_map
  ADD COLUMN IF NOT EXISTS processing_token TEXT;
ALTER TABLE IF EXISTS orders_map
  ADD COLUMN IF NOT EXISTS processing_at TIMESTAMP NULL;

CREATE TABLE IF NOT EXISTS oauth_tokens (
  service TEXT PRIMARY KEY,
  access_token TEXT NOT NULL,
  refresh_token TEXT NOT NULL,
  expires_at BIGINT NOT NULL
);
