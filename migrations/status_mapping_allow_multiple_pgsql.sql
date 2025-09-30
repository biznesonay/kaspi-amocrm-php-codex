-- Обновляет таблицу status_mapping для поддержки нескольких статусов amoCRM на один статус Kaspi.
-- Выполните файл командой `psql -f migrations/status_mapping_allow_multiple_pgsql.sql`.

DO $$
BEGIN
  IF EXISTS (
    SELECT 1
    FROM pg_indexes
    WHERE schemaname = current_schema()
      AND tablename = 'status_mapping'
      AND indexname = 'status_mapping_kaspi_status_pipeline_unique'
  ) THEN
    EXECUTE 'DROP INDEX ' || quote_ident(current_schema()) || '.status_mapping_kaspi_status_pipeline_unique';
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

  IF NOT EXISTS (
    SELECT 1
    FROM pg_indexes
    WHERE schemaname = current_schema()
      AND tablename = 'status_mapping'
      AND indexname = 'status_mapping_kaspi_pipeline_status_unique'
  ) THEN
    EXECUTE 'CREATE UNIQUE INDEX status_mapping_kaspi_pipeline_status_unique ON '
      || quote_ident(current_schema())
      || '.status_mapping (kaspi_status, amo_pipeline_id, amo_status_id)';
  END IF;
END;
$$;
