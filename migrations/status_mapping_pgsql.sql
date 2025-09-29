CREATE TABLE IF NOT EXISTS status_mapping (
  id BIGSERIAL PRIMARY KEY,
  kaspi_status TEXT NOT NULL,
  amo_pipeline_id BIGINT NOT NULL,
  amo_status_id BIGINT NOT NULL,
  amo_responsible_user_id BIGINT,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS status_mapping_kaspi_status_unique
  ON status_mapping (kaspi_status);

CREATE OR REPLACE FUNCTION set_status_mapping_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at := NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'status_mapping_set_updated_at'
  ) THEN
    CREATE TRIGGER status_mapping_set_updated_at
    BEFORE UPDATE ON status_mapping
    FOR EACH ROW
    EXECUTE PROCEDURE set_status_mapping_updated_at();
  END IF;
END;
$$;
