-- Desembarques · Fase Excel-first 1
-- Compatibiliza una instalación existente con el alta simplificada y con el generador de Aviso.
-- MySQL 8.0.29+ (el proyecto local usa MySQL 8.0.46).
-- No borra tablas ni datos existentes.

ALTER TABLE desembarque_aviso_details
    ADD COLUMN IF NOT EXISTS manifiesto VARCHAR(100) NULL AFTER desembarque_id,
    MODIFY COLUMN imo_transporte VARCHAR(40) NULL,
    MODIFY COLUMN rig_name VARCHAR(180) NULL,
    MODIFY COLUMN rig_imo VARCHAR(40) NULL,
    ADD COLUMN IF NOT EXISTS rig_field VARCHAR(120) NULL AFTER rig_imo,
    ADD COLUMN IF NOT EXISTS rig_area VARCHAR(120) NULL AFTER rig_field,
    ADD COLUMN IF NOT EXISTS document_code VARCHAR(100) NULL AFTER comitente,
    ADD COLUMN IF NOT EXISTS document_title TEXT NULL AFTER document_code,
    ADD COLUMN IF NOT EXISTS notice_number VARCHAR(100) NULL AFTER document_title,
    ADD COLUMN IF NOT EXISTS location_date_text VARCHAR(200) NULL AFTER notice_number,
    ADD COLUMN IF NOT EXISTS recipient_text TEXT NULL AFTER location_date_text,
    ADD COLUMN IF NOT EXISTS introduction LONGTEXT NULL AFTER recipient_text,
    ADD COLUMN IF NOT EXISTS body LONGTEXT NULL AFTER introduction,
    ADD COLUMN IF NOT EXISTS operations LONGTEXT NULL AFTER body,
    ADD COLUMN IF NOT EXISTS documentation TEXT NULL AFTER operations,
    ADD COLUMN IF NOT EXISTS closing_text TEXT NULL AFTER documentation,
    ADD COLUMN IF NOT EXISTS signer_name VARCHAR(200) NULL AFTER closing_text,
    ADD COLUMN IF NOT EXISTS signer_title VARCHAR(200) NULL AFTER signer_name,
    ADD COLUMN IF NOT EXISTS footer_text TEXT NULL AFTER signer_title;

-- Conserva el valor del esquema anterior si existía `campo`.
SET @has_legacy_campo := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'desembarque_aviso_details'
      AND column_name = 'campo'
);
SET @copy_legacy_campo_sql := IF(
    @has_legacy_campo > 0,
    'UPDATE desembarque_aviso_details SET rig_field = campo WHERE rig_field IS NULL AND campo IS NOT NULL AND LENGTH(TRIM(campo)) > 0',
    'SELECT 1'
);
PREPARE copy_legacy_campo_stmt FROM @copy_legacy_campo_sql;
EXECUTE copy_legacy_campo_stmt;
DEALLOCATE PREPARE copy_legacy_campo_stmt;

ALTER TABLE desembarque_aviso_items
    MODIFY COLUMN unidad VARCHAR(40) NULL,
    MODIFY COLUMN marca VARCHAR(180) NULL,
    MODIFY COLUMN clave VARCHAR(30) NULL,
    MODIFY COLUMN num_pedimento VARCHAR(120) NULL,
    MODIFY COLUMN cantidad DECIMAL(14,3) NULL,
    ADD COLUMN IF NOT EXISTS importer_name VARCHAR(255) NULL AFTER cantidad;

-- Verificación rápida al terminar.
SELECT 'desembarque_aviso_details' AS tabla, COUNT(*) AS columnas
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'desembarque_aviso_details';

SELECT 'desembarque_aviso_items' AS tabla, COUNT(*) AS columnas
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'desembarque_aviso_items';
