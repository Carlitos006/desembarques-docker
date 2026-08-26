-- Fase 7 - Alcances del Aviso de Desembarque
-- Crea únicamente estructuras nuevas. No elimina ni reescribe datos existentes.

SET @schema_name = DATABASE();

CREATE TABLE IF NOT EXISTS desembarque_aviso_alcances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    desembarque_id INT UNSIGNED NOT NULL,
    alcance_no INT UNSIGNED NOT NULL,
    document_code VARCHAR(100) NULL,
    notice_number VARCHAR(100) NULL,
    alcance_date DATE NOT NULL,
    notes TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'issued',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aviso_alcances_public_id (public_id),
    UNIQUE KEY uq_aviso_alcances_number (desembarque_id, alcance_no),
    KEY idx_aviso_alcances_status (desembarque_id, status),
    KEY idx_aviso_alcances_created_by (created_by),
    CONSTRAINT fk_aviso_alcances_desembarque FOREIGN KEY (desembarque_id)
        REFERENCES desembarques (id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_alcances_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_aviso_alcance_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alcance_id BIGINT UNSIGNED NOT NULL,
    aviso_item_id BIGINT UNSIGNED NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aviso_alcance_item (alcance_id, aviso_item_id),
    KEY idx_aviso_alcance_items_item (aviso_item_id),
    CONSTRAINT fk_aviso_alcance_items_alcance FOREIGN KEY (alcance_id)
        REFERENCES desembarque_aviso_alcances (id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_alcance_items_item FOREIGN KEY (aviso_item_id)
        REFERENCES desembarque_aviso_items (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_aviso_alcance_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alcance_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    item_count INT UNSIGNED NOT NULL DEFAULT 0,
    photo_count INT UNSIGNED NOT NULL DEFAULT 0,
    page_count INT UNSIGNED NOT NULL DEFAULT 1,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    size BIGINT UNSIGNED NOT NULL,
    pdf_sha256 CHAR(64) NOT NULL,
    snapshot_json JSON NULL,
    snapshot_sha256 CHAR(64) NULL,
    snapshot_hash_version TINYINT UNSIGNED NULL DEFAULT 1,
    generated_by INT UNSIGNED NULL,
    generated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aviso_alcance_version (alcance_id, version_no),
    UNIQUE KEY uq_aviso_alcance_version_stored (stored_name),
    KEY idx_aviso_alcance_versions_generated_by (generated_by),
    KEY idx_aviso_alcance_versions_hash (pdf_sha256),
    CONSTRAINT fk_aviso_alcance_versions_alcance FOREIGN KEY (alcance_id)
        REFERENCES desembarque_aviso_alcances (id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_alcance_versions_generated_by FOREIGN KEY (generated_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_aviso_alcance_receipts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alcance_id BIGINT UNSIGNED NOT NULL,
    alcance_version_id BIGINT UNSIGNED NOT NULL,
    folio VARCHAR(100) NOT NULL,
    received_at DATETIME NOT NULL,
    notes VARCHAR(1000) NULL,
    evidence_file_id BIGINT UNSIGNED NULL,
    recorded_by INT UNSIGNED NULL,
    recorded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aviso_alcance_receipt_version (alcance_version_id),
    KEY idx_aviso_alcance_receipts_alcance (alcance_id),
    KEY idx_aviso_alcance_receipts_evidence (evidence_file_id),
    KEY idx_aviso_alcance_receipts_recorded_by (recorded_by),
    KEY idx_aviso_alcance_receipts_updated_by (updated_by),
    CONSTRAINT fk_aviso_alcance_receipts_alcance FOREIGN KEY (alcance_id)
        REFERENCES desembarque_aviso_alcances (id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_alcance_receipts_version FOREIGN KEY (alcance_version_id)
        REFERENCES desembarque_aviso_alcance_versions (id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_alcance_receipts_evidence FOREIGN KEY (evidence_file_id)
        REFERENCES desembarque_files (id) ON DELETE SET NULL,
    CONSTRAINT fk_aviso_alcance_receipts_recorded_by FOREIGN KEY (recorded_by)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_aviso_alcance_receipts_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT table_name
FROM information_schema.tables
WHERE table_schema = @schema_name
  AND table_name LIKE 'desembarque_aviso_alcance%'
ORDER BY table_name;
