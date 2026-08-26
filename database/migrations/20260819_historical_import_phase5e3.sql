-- Desembarques · Fase 5E3
-- Review editable + commit transaccional de avisos históricos.
-- Compatible con MySQL 8.0.x y con la 5E1/5E2 ya aplicada.
-- No elimina expedientes, PDFs, documentos ni filas de staging existentes.

SET @schema_name := DATABASE();

-- -----------------------------------------------------------------------------
-- 1) Configuración/resultado del lote histórico.
-- -----------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_batches' AND column_name = 'default_operational_status_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_batches ADD COLUMN default_operational_status_id INT UNSIGNED NULL AFTER default_aviso_status',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_batches' AND column_name = 'imported_rows'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_batches ADD COLUMN imported_rows INT UNSIGNED NOT NULL DEFAULT 0 AFTER duplicate_rows',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_batches' AND column_name = 'skipped_rows'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_batches ADD COLUMN skipped_rows INT UNSIGNED NOT NULL DEFAULT 0 AFTER imported_rows',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_batches' AND column_name = 'failed_rows'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_batches ADD COLUMN failed_rows INT UNSIGNED NOT NULL DEFAULT 0 AFTER skipped_rows',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_batches' AND column_name = 'committed_by'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_batches ADD COLUMN committed_by INT UNSIGNED NULL AFTER created_by',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_batches' AND column_name = 'committed_at'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_batches ADD COLUMN committed_at DATETIME NULL AFTER committed_by',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2) Revisión humana y resultado de commit por fila.
-- -----------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'review_status'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN review_status VARCHAR(30) NOT NULL DEFAULT ''pending'' AFTER extraction_status',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'review_data'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN review_data JSON NULL AFTER normalized_data',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'review_aviso_status'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN review_aviso_status VARCHAR(30) NULL AFTER review_data',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'review_status_effective_at'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN review_status_effective_at DATETIME NULL AFTER review_aviso_status',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'review_reason'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN review_reason VARCHAR(1000) NULL AFTER review_status_effective_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'review_action'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN review_action VARCHAR(30) NOT NULL DEFAULT ''import_new'' AFTER review_reason',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'duplicate_kind'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN duplicate_kind VARCHAR(30) NULL AFTER duplicate_reason',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'reviewed_by'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN reviewed_by INT UNSIGNED NULL AFTER review_action',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'reviewed_at'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'commit_status'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN commit_status VARCHAR(30) NOT NULL DEFAULT ''pending'' AFTER reviewed_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'imported_desembarque_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN imported_desembarque_id INT UNSIGNED NULL AFTER commit_status',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'imported_version_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN imported_version_id BIGINT UNSIGNED NULL AFTER imported_desembarque_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'import_error'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN import_error TEXT NULL AFTER imported_version_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND column_name = 'imported_at'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD COLUMN imported_at DATETIME NULL AFTER import_error',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 3) Trazabilidad de origen en el expediente y en cada PDF histórico.
-- -----------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_aviso_details' AND column_name = 'office_date'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_details ADD COLUMN office_date DATE NULL AFTER aviso_status_changed_by',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_aviso_details' AND column_name = 'source_type'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_details ADD COLUMN source_type VARCHAR(30) NOT NULL DEFAULT ''system'' AFTER source_excel_sha256',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_aviso_details' AND column_name = 'historical_import_row_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_details ADD COLUMN historical_import_row_id BIGINT UNSIGNED NULL AFTER source_type',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_aviso_versions' AND column_name = 'piece_count'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_versions ADD COLUMN piece_count DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER item_count',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_aviso_versions' AND column_name = 'document_date'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_versions ADD COLUMN document_date DATE NULL AFTER notice_number',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_aviso_versions' AND column_name = 'source_type'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_versions ADD COLUMN source_type VARCHAR(30) NOT NULL DEFAULT ''system_generated'' AFTER document_date',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'desembarque_aviso_versions' AND column_name = 'source_import_row_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_versions ADD COLUMN source_import_row_id BIGINT UNSIGNED NULL AFTER source_type',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 4) Índices y FKs idempotentes.
-- -----------------------------------------------------------------------------
SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND index_name = 'idx_aviso_import_rows_review'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD INDEX idx_aviso_import_rows_review (batch_id, review_status, commit_status)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND index_name = 'idx_aviso_import_rows_imported_desembarque'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD INDEX idx_aviso_import_rows_imported_desembarque (imported_desembarque_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = @schema_name AND table_name = 'aviso_import_rows' AND index_name = 'idx_aviso_import_rows_imported_version'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD INDEX idx_aviso_import_rows_imported_version (imported_version_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = @schema_name AND table_name = 'desembarque_aviso_details' AND index_name = 'idx_aviso_details_source_type'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_details ADD INDEX idx_aviso_details_source_type (source_type, office_date)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = @schema_name AND table_name = 'desembarque_aviso_details' AND index_name = 'idx_aviso_details_historical_row'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_details ADD INDEX idx_aviso_details_historical_row (historical_import_row_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = @schema_name AND table_name = 'desembarque_aviso_versions' AND index_name = 'idx_aviso_versions_source_type'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_versions ADD INDEX idx_aviso_versions_source_type (source_type, document_date)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = @schema_name AND table_name = 'desembarque_aviso_versions' AND index_name = 'idx_aviso_versions_source_import_row'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_versions ADD INDEX idx_aviso_versions_source_import_row (source_import_row_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Batch operational status.
SET @exists := (
  SELECT COUNT(*) FROM information_schema.referential_constraints
  WHERE constraint_schema = @schema_name AND table_name = 'aviso_import_batches' AND constraint_name = 'fk_aviso_import_batches_operational_status'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_batches ADD CONSTRAINT fk_aviso_import_batches_operational_status FOREIGN KEY (default_operational_status_id) REFERENCES desembarque_statuses(id) ON DELETE RESTRICT',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.referential_constraints
  WHERE constraint_schema = @schema_name AND table_name = 'aviso_import_batches' AND constraint_name = 'fk_aviso_import_batches_committed_by'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_batches ADD CONSTRAINT fk_aviso_import_batches_committed_by FOREIGN KEY (committed_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.referential_constraints
  WHERE constraint_schema = @schema_name AND table_name = 'aviso_import_rows' AND constraint_name = 'fk_aviso_import_rows_reviewed_by'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD CONSTRAINT fk_aviso_import_rows_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.referential_constraints
  WHERE constraint_schema = @schema_name AND table_name = 'aviso_import_rows' AND constraint_name = 'fk_aviso_import_rows_imported_desembarque'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD CONSTRAINT fk_aviso_import_rows_imported_desembarque FOREIGN KEY (imported_desembarque_id) REFERENCES desembarques(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.referential_constraints
  WHERE constraint_schema = @schema_name AND table_name = 'aviso_import_rows' AND constraint_name = 'fk_aviso_import_rows_imported_version'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE aviso_import_rows ADD CONSTRAINT fk_aviso_import_rows_imported_version FOREIGN KEY (imported_version_id) REFERENCES desembarque_aviso_versions(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.referential_constraints
  WHERE constraint_schema = @schema_name AND table_name = 'desembarque_aviso_details' AND constraint_name = 'fk_aviso_details_historical_import_row'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_details ADD CONSTRAINT fk_aviso_details_historical_import_row FOREIGN KEY (historical_import_row_id) REFERENCES aviso_import_rows(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.referential_constraints
  WHERE constraint_schema = @schema_name AND table_name = 'desembarque_aviso_versions' AND constraint_name = 'fk_aviso_versions_source_import_row'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE desembarque_aviso_versions ADD CONSTRAINT fk_aviso_versions_source_import_row FOREIGN KEY (source_import_row_id) REFERENCES aviso_import_rows(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 5) Backfill conservador para lotes 5E1/5E2 ya creados.
-- -----------------------------------------------------------------------------
SET @default_operational_status_id := (
  SELECT id FROM desembarque_statuses
  WHERE is_active = 1
  ORDER BY (slug = 'completed') DESC, is_default DESC, id ASC
  LIMIT 1
);
UPDATE aviso_import_batches
SET default_operational_status_id = @default_operational_status_id
WHERE default_operational_status_id IS NULL
  AND @default_operational_status_id IS NOT NULL;

UPDATE aviso_import_rows
SET review_action = 'skip'
WHERE extraction_status = 'duplicate'
  AND review_action = 'import_new';

UPDATE aviso_import_rows r
INNER JOIN aviso_import_batches b ON b.id = r.batch_id
SET r.review_aviso_status = b.default_aviso_status
WHERE r.review_aviso_status IS NULL OR TRIM(r.review_aviso_status) = '';

UPDATE aviso_import_rows
SET duplicate_kind = CASE
    WHEN duplicate_reason LIKE '%mismo PDF%' THEN 'pdf_sha'
    WHEN duplicate_desembarque_id IS NOT NULL THEN 'notice'
    ELSE duplicate_kind
END
WHERE duplicate_kind IS NULL AND duplicate_desembarque_id IS NOT NULL;

UPDATE desembarque_aviso_details
SET source_type = 'system'
WHERE source_type IS NULL OR TRIM(source_type) = '';

UPDATE desembarque_aviso_versions
SET source_type = 'system_generated'
WHERE source_type IS NULL OR TRIM(source_type) = '';

-- -----------------------------------------------------------------------------
-- Verificación final.
-- -----------------------------------------------------------------------------
SELECT column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = @schema_name
  AND table_name = 'aviso_import_rows'
  AND column_name IN (
    'review_status','review_data','review_aviso_status','review_status_effective_at','review_reason',
    'review_action','reviewed_by','reviewed_at','commit_status','imported_desembarque_id',
    'imported_version_id','import_error','imported_at','duplicate_kind'
  )
ORDER BY ordinal_position;

SELECT column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = @schema_name
  AND table_name = 'desembarque_aviso_versions'
  AND column_name IN ('piece_count','document_date','source_type','source_import_row_id')
ORDER BY ordinal_position;

SELECT id, public_id, status, total_rows, ready_rows, review_rows, duplicate_rows,
       imported_rows, skipped_rows, failed_rows, default_operational_status_id
FROM aviso_import_batches
ORDER BY id DESC
LIMIT 10;
