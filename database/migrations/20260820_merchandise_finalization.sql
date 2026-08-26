-- Fase 5F — Finalización / salida de mercancías del expediente
-- Registra eventos de salida sin eliminar mercancías del Aviso.
-- Permite salidas completas o parciales y conserva historial auditable.

SET @schema_name := DATABASE();

CREATE TABLE IF NOT EXISTS desembarque_item_finalizations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    desembarque_id INT UNSIGNED NOT NULL,
    export_date DATE NOT NULL,
    pedimento_r1_agregar VARCHAR(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    pedimento_retorno_parcial_h1 VARCHAR(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    mercancia_pedimento_h1 TEXT COLLATE utf8mb4_unicode_ci,
    pedimento_r1_desagregado VARCHAR(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    pedimento_a3 VARCHAR(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    notes VARCHAR(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_item_finalizations_desembarque (desembarque_id, export_date, id),
    KEY idx_item_finalizations_created_by (created_by),
    CONSTRAINT fk_item_finalizations_desembarque
        FOREIGN KEY (desembarque_id) REFERENCES desembarques(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_finalizations_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_item_finalization_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    finalization_id BIGINT UNSIGNED NOT NULL,
    aviso_item_id BIGINT UNSIGNED NOT NULL,
    quantity_exported DECIMAL(14,3) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_item_finalization_line (finalization_id, aviso_item_id),
    KEY idx_item_finalization_lines_item (aviso_item_id),
    CONSTRAINT fk_item_finalization_lines_finalization
        FOREIGN KEY (finalization_id) REFERENCES desembarque_item_finalizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_finalization_lines_item
        FOREIGN KEY (aviso_item_id) REFERENCES desembarque_aviso_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT table_name
FROM information_schema.tables
WHERE table_schema = @schema_name
  AND table_name IN ('desembarque_item_finalizations', 'desembarque_item_finalization_lines')
ORDER BY table_name;
