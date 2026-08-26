-- Desembarques · Fase 5E1/5E2
-- Foundation para importación histórica múltiple de Avisos.
-- Esta fase SOLO crea staging. No inserta desembarques ni versiones definitivas.

CREATE TABLE IF NOT EXISTS aviso_import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) COLLATE utf8mb4_unicode_ci NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    default_aviso_status VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'presented',
    status VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'review',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    ready_rows INT UNSIGNED NOT NULL DEFAULT 0,
    review_rows INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_rows INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aviso_import_batches_public (public_id),
    KEY idx_aviso_import_batches_client (client_id),
    KEY idx_aviso_import_batches_created_by (created_by),
    KEY idx_aviso_import_batches_created_at (created_at),
    CONSTRAINT fk_aviso_import_batches_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_aviso_import_batches_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aviso_import_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    source_pdf_name VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    source_pdf_stored_name VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    source_pdf_sha256 CHAR(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    source_pdf_size BIGINT UNSIGNED DEFAULT NULL,
    source_pdf_page_count INT UNSIGNED DEFAULT NULL,
    source_docx_name VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    source_docx_stored_name VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    source_docx_sha256 CHAR(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    source_docx_size BIGINT UNSIGNED DEFAULT NULL,
    detected_notice_number VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    detected_document_code VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    extraction_source VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
    extraction_status VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'review_required',
    is_scanned_pdf TINYINT(1) NOT NULL DEFAULT 0,
    raw_text LONGTEXT COLLATE utf8mb4_unicode_ci,
    normalized_data JSON DEFAULT NULL,
    confidence_json JSON DEFAULT NULL,
    critical_score DECIMAL(6,3) DEFAULT NULL,
    overall_confidence DECIMAL(6,3) DEFAULT NULL,
    duplicate_desembarque_id INT UNSIGNED DEFAULT NULL,
    duplicate_reason VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    error_message TEXT COLLATE utf8mb4_unicode_ci,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aviso_import_rows_batch (batch_id, id),
    KEY idx_aviso_import_rows_notice (detected_notice_number),
    KEY idx_aviso_import_rows_status (extraction_status),
    KEY idx_aviso_import_rows_pdf_sha (source_pdf_sha256),
    KEY idx_aviso_import_rows_duplicate (duplicate_desembarque_id),
    CONSTRAINT fk_aviso_import_rows_batch FOREIGN KEY (batch_id) REFERENCES aviso_import_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_import_rows_duplicate FOREIGN KEY (duplicate_desembarque_id) REFERENCES desembarques(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'aviso_import_batches' AS tabla, COUNT(*) AS registros FROM aviso_import_batches;
SELECT 'aviso_import_rows' AS tabla, COUNT(*) AS registros FROM aviso_import_rows;
