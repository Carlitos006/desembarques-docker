-- Fase 5B — Gestión documental del expediente
-- Compatible con MySQL 8.0 sin ADD COLUMN IF NOT EXISTS.
-- No elimina documentos ni afecta el estado operativo/estado futuro del Aviso.

SET @schema_name := DATABASE();

-- purpose
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND column_name = 'purpose'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD COLUMN purpose VARCHAR(50) NULL AFTER size',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- document_type
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND column_name = 'document_type'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD COLUMN document_type VARCHAR(50) NULL AFTER purpose',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- description
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND column_name = 'description'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD COLUMN description VARCHAR(500) NULL AFTER document_type',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- document_date
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND column_name = 'document_date'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD COLUMN document_date DATE NULL AFTER description',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sha256
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND column_name = 'sha256'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD COLUMN sha256 CHAR(64) NULL AFTER document_date',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- is_active
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND column_name = 'is_active'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER sha256',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- replaces_file_id
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND column_name = 'replaces_file_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD COLUMN replaces_file_id BIGINT UNSIGNED NULL AFTER is_active',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- replaced_at
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND column_name = 'replaced_at'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD COLUMN replaced_at DATETIME NULL AFTER replaces_file_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- replaced_by
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND column_name = 'replaced_by'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD COLUMN replaced_by INT UNSIGNED NULL AFTER replaced_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indexes
SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND index_name = 'idx_desembarque_files_purpose'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD INDEX idx_desembarque_files_purpose (desembarque_id, purpose, is_active)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND index_name = 'idx_desembarque_files_document_type'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD INDEX idx_desembarque_files_document_type (document_type)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND index_name = 'idx_desembarque_files_replaces'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD INDEX idx_desembarque_files_replaces (replaces_file_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = @schema_name AND table_name = 'desembarque_files' AND index_name = 'idx_desembarque_files_replaced_by'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD INDEX idx_desembarque_files_replaced_by (replaced_by)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Foreign keys (only if missing)
SET @exists := (
  SELECT COUNT(*) FROM information_schema.referential_constraints
  WHERE constraint_schema = @schema_name AND table_name = 'desembarque_files' AND constraint_name = 'fk_desembarque_files_replaces'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD CONSTRAINT fk_desembarque_files_replaces FOREIGN KEY (replaces_file_id) REFERENCES desembarque_files(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.referential_constraints
  WHERE constraint_schema = @schema_name AND table_name = 'desembarque_files' AND constraint_name = 'fk_desembarque_files_replaced_by'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_files ADD CONSTRAINT fk_desembarque_files_replaced_by FOREIGN KEY (replaced_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill conservador: el Excel fuente nunca se convierte en documento del expediente.
UPDATE desembarque_files f
INNER JOIN desembarque_aviso_details d ON d.desembarque_id = f.desembarque_id
SET f.purpose = 'source_excel'
WHERE d.source_excel_name IS NOT NULL
  AND TRIM(d.source_excel_name) <> ''
  AND f.original_name = d.source_excel_name;

-- Fotografías actualmente usadas en el anexo.
UPDATE desembarque_files f
INNER JOIN desembarque_aviso_images ai ON ai.file_id = f.id AND ai.desembarque_id = f.desembarque_id
SET f.purpose = 'photo'
WHERE COALESCE(f.purpose, '') <> 'source_excel';

-- PDFs y Word legacy pasan a gestión documental controlada.
UPDATE desembarque_files
SET purpose = 'case_document',
    document_type = COALESCE(NULLIF(document_type, ''), 'other'),
    is_active = COALESCE(is_active, 1)
WHERE LOWER(extension) IN ('pdf', 'doc', 'docx')
  AND COALESCE(purpose, '') NOT IN ('source_excel', 'photo');

-- Otros adjuntos conservan su naturaleza legacy.
UPDATE desembarque_files
SET purpose = 'attachment'
WHERE purpose IS NULL OR TRIM(purpose) = '';

-- Verificación final.
SELECT column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = @schema_name
  AND table_name = 'desembarque_files'
  AND column_name IN (
    'purpose','document_type','description','document_date','sha256','is_active',
    'replaces_file_id','replaced_at','replaced_by'
  )
ORDER BY ordinal_position;

SELECT purpose, COUNT(*) AS registros
FROM desembarque_files
GROUP BY purpose
ORDER BY purpose;
