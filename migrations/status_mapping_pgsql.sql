CREATE TABLE IF NOT EXISTS status_mapping (
  id BIGSERIAL PRIMARY KEY,
  kaspi_status TEXT NOT NULL,
  amo_pipeline_id BIGINT NOT NULL,
  amo_status_id BIGINT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  amo_responsible_user_id BIGINT,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS status_mapping_kaspi_pipeline_unique
  ON status_mapping (kaspi_status, amo_pipeline_id);

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
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'status_mapping'
      AND column_name = 'is_active'
  ) THEN
    ALTER TABLE status_mapping
      ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE;
  END IF;

  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'status_mapping'
      AND column_name = 'sort_order'
  ) THEN
    ALTER TABLE status_mapping
      ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0;
  END IF;

  IF EXISTS (
    SELECT 1
    FROM pg_indexes
    WHERE schemaname = current_schema()
      AND tablename = 'status_mapping'
      AND indexname = 'status_mapping_kaspi_status_unique'
  ) THEN
    EXECUTE 'DROP INDEX ' || quote_ident(current_schema()) || '.status_mapping_kaspi_status_unique';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM pg_indexes
    WHERE schemaname = current_schema()
      AND tablename = 'status_mapping'
      AND indexname = 'status_mapping_kaspi_status_pipeline_unique'
  ) THEN
    EXECUTE 'DROP INDEX ' || quote_ident(current_schema()) || '.status_mapping_kaspi_status_pipeline_unique';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM pg_indexes
    WHERE schemaname = current_schema()
      AND tablename = 'status_mapping'
      AND indexname = 'status_mapping_kaspi_pipeline_status_unique'
  ) THEN
    EXECUTE 'DROP INDEX ' || quote_ident(current_schema()) || '.status_mapping_kaspi_pipeline_status_unique';
  END IF;

  PERFORM 1
  FROM pg_indexes
  WHERE schemaname = current_schema()
    AND tablename = 'status_mapping'
    AND indexname = 'status_mapping_kaspi_pipeline_unique'
    AND indexdef LIKE 'CREATE UNIQUE INDEX % ON ' || quote_ident(current_schema()) || '.status_mapping USING btree (kaspi_status, amo_pipeline_id%';

  IF NOT FOUND THEN
    IF EXISTS (
      SELECT 1
      FROM pg_indexes
      WHERE schemaname = current_schema()
        AND tablename = 'status_mapping'
        AND indexname = 'status_mapping_kaspi_pipeline_unique'
    ) THEN
      EXECUTE 'DROP INDEX ' || quote_ident(current_schema()) || '.status_mapping_kaspi_pipeline_unique';
    END IF;

    EXECUTE 'CREATE UNIQUE INDEX status_mapping_kaspi_pipeline_unique ON '
      || quote_ident(current_schema())
      || '.status_mapping (kaspi_status, amo_pipeline_id)';
  END IF;
END;
$$;

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
