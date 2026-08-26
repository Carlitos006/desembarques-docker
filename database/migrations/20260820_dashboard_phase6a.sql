-- Fase 6A — Dashboard Data Contract & KPI Foundation
-- Cambio no destructivo. No elimina ni reescribe datos de negocio.
-- Añade un scope explícito para excluir fixtures/QA de KPIs directivos.

SET @schema_name := DATABASE();

SET @exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = @schema_name
    AND table_name = 'desembarques'
    AND column_name = 'record_scope'
);

SET @sql := IF(
  @exists = 0,
  "ALTER TABLE desembarques ADD COLUMN record_scope VARCHAR(20) NOT NULL DEFAULT 'production' AFTER client_id",
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Normalización defensiva únicamente para valores vacíos existentes.
UPDATE desembarques
SET record_scope = 'production'
WHERE record_scope IS NULL OR TRIM(record_scope) = '';

SET @exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = @schema_name
    AND table_name = 'desembarques'
    AND index_name = 'idx_desembarques_dashboard_scope_date'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE desembarques ADD INDEX idx_desembarques_dashboard_scope_date (record_scope, fecha_desembarque)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificación.
SELECT
  COLUMN_NAME,
  COLUMN_TYPE,
  IS_NULLABLE,
  COLUMN_DEFAULT
FROM information_schema.columns
WHERE table_schema = @schema_name
  AND table_name = 'desembarques'
  AND column_name = 'record_scope';

SELECT record_scope, COUNT(*) AS registros
FROM desembarques
GROUP BY record_scope
ORDER BY record_scope;
