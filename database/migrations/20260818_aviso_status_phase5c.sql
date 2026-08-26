-- Desembarques · Fase 5C
-- Estado documental propio del Aviso de Desembarque.
-- Independiente del estado operativo de desembarques y de la gestión documental 5B.

SET @schema_name = DATABASE();

-- 1) Columnas de estado actual en desembarque_aviso_details.
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE desembarque_aviso_details ADD COLUMN aviso_status VARCHAR(30) NOT NULL DEFAULT ''draft'' AFTER aviso_profile_id',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'desembarque_aviso_details' AND COLUMN_NAME = 'aviso_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE desembarque_aviso_details ADD COLUMN aviso_status_effective_at DATETIME NULL AFTER aviso_status',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'desembarque_aviso_details' AND COLUMN_NAME = 'aviso_status_effective_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE desembarque_aviso_details ADD COLUMN aviso_status_changed_at DATETIME NULL AFTER aviso_status_effective_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'desembarque_aviso_details' AND COLUMN_NAME = 'aviso_status_changed_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE desembarque_aviso_details ADD COLUMN aviso_status_changed_by INT UNSIGNED NULL AFTER aviso_status_changed_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'desembarque_aviso_details' AND COLUMN_NAME = 'aviso_status_changed_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE desembarque_aviso_details ADD INDEX idx_aviso_details_status (aviso_status)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'desembarque_aviso_details' AND INDEX_NAME = 'idx_aviso_details_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE desembarque_aviso_details ADD INDEX idx_aviso_details_status_changed_by (aviso_status_changed_by)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'desembarque_aviso_details' AND INDEX_NAME = 'idx_aviso_details_status_changed_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE desembarque_aviso_details ADD CONSTRAINT fk_aviso_details_status_changed_by FOREIGN KEY (aviso_status_changed_by) REFERENCES users(id) ON DELETE SET NULL',
        'SELECT 1')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @schema_name AND TABLE_NAME = 'desembarque_aviso_details' AND CONSTRAINT_NAME = 'fk_aviso_details_status_changed_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Historial autoritativo de transiciones.
CREATE TABLE IF NOT EXISTS desembarque_aviso_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    desembarque_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(30) NOT NULL,
    to_status VARCHAR(30) NOT NULL,
    reason VARCHAR(1000) NULL,
    effective_at DATETIME NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'manual',
    aviso_version_id BIGINT UNSIGNED NULL,
    changed_by INT UNSIGNED NULL,
    changed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aviso_status_history_desembarque (desembarque_id, changed_at),
    KEY idx_aviso_status_history_version (aviso_version_id),
    KEY idx_aviso_status_history_changed_by (changed_by),
    CONSTRAINT fk_aviso_status_history_desembarque FOREIGN KEY (desembarque_id) REFERENCES desembarques(id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_status_history_version FOREIGN KEY (aviso_version_id) REFERENCES desembarque_aviso_versions(id) ON DELETE SET NULL,
    CONSTRAINT fk_aviso_status_history_changed_by FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Normaliza cualquier valor inesperado a Borrador.
UPDATE desembarque_aviso_details
SET aviso_status = 'draft'
WHERE aviso_status IS NULL
   OR aviso_status NOT IN ('draft','issued','presented','replaced','cancelled');

-- 4) Los avisos que ya tienen al menos una versión histórica pasan a EMITIDO.
UPDATE desembarque_aviso_details d
JOIN (
    SELECT v.desembarque_id, MIN(v.generated_at) AS first_generated_at
    FROM desembarque_aviso_versions v
    GROUP BY v.desembarque_id
) x ON x.desembarque_id = d.desembarque_id
SET d.aviso_status = 'issued',
    d.aviso_status_effective_at = COALESCE(d.aviso_status_effective_at, x.first_generated_at),
    d.aviso_status_changed_at = COALESCE(d.aviso_status_changed_at, x.first_generated_at)
WHERE d.aviso_status = 'draft';

-- Usuario de la primera emisión para el backfill, cuando exista.
UPDATE desembarque_aviso_details d
JOIN desembarque_aviso_versions v
  ON v.desembarque_id = d.desembarque_id
 AND v.version_no = (
     SELECT MIN(v2.version_no)
     FROM desembarque_aviso_versions v2
     WHERE v2.desembarque_id = d.desembarque_id
 )
SET d.aviso_status_changed_by = COALESCE(d.aviso_status_changed_by, v.generated_by)
WHERE d.aviso_status = 'issued';

-- 5) Historial inicial de avisos emitidos antes de Fase 5C. Idempotente.
INSERT INTO desembarque_aviso_status_history (
    desembarque_id, from_status, to_status, reason, effective_at, source, aviso_version_id, changed_by, changed_at
)
SELECT
    d.desembarque_id,
    'draft',
    'issued',
    'Backfill Fase 5C: el aviso ya contaba con una versión PDF emitida.',
    v.generated_at,
    'migration',
    v.id,
    v.generated_by,
    v.generated_at
FROM desembarque_aviso_details d
JOIN desembarque_aviso_versions v
  ON v.desembarque_id = d.desembarque_id
 AND v.version_no = (
     SELECT MIN(v2.version_no)
     FROM desembarque_aviso_versions v2
     WHERE v2.desembarque_id = d.desembarque_id
 )
WHERE d.aviso_status = 'issued'
  AND NOT EXISTS (
      SELECT 1 FROM desembarque_aviso_status_history h WHERE h.desembarque_id = d.desembarque_id
  );

-- Verificación final.
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @schema_name
  AND TABLE_NAME = 'desembarque_aviso_details'
  AND COLUMN_NAME IN ('aviso_status','aviso_status_effective_at','aviso_status_changed_at','aviso_status_changed_by')
ORDER BY ORDINAL_POSITION;

SELECT aviso_status, COUNT(*) AS registros
FROM desembarque_aviso_details
GROUP BY aviso_status
ORDER BY aviso_status;

SELECT COUNT(*) AS transiciones_historicas
FROM desembarque_aviso_status_history;
