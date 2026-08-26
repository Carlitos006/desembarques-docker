-- Fase 5F.2 — Finalización de mercancías V2
-- Mueve los datos aduanales al nivel de cada mercancía/movimiento,
-- preserva compatibilidad con finalizaciones V1 y añade anulación auditada.

SET @schema_name := DATABASE();

-- -------------------------------------------------------------------------
-- Nuevos campos aduanales por línea de mercancía
-- -------------------------------------------------------------------------

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='desembarque_item_finalization_lines' AND column_name='pedimento_r1_agregar') = 0,
    'ALTER TABLE desembarque_item_finalization_lines ADD COLUMN pedimento_r1_agregar VARCHAR(150) COLLATE utf8mb4_unicode_ci NULL AFTER quantity_exported',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='desembarque_item_finalization_lines' AND column_name='pedimento_retorno_parcial_h1') = 0,
    'ALTER TABLE desembarque_item_finalization_lines ADD COLUMN pedimento_retorno_parcial_h1 VARCHAR(150) COLLATE utf8mb4_unicode_ci NULL AFTER pedimento_r1_agregar',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='desembarque_item_finalization_lines' AND column_name='mercancia_pedimento_h1') = 0,
    'ALTER TABLE desembarque_item_finalization_lines ADD COLUMN mercancia_pedimento_h1 TEXT COLLATE utf8mb4_unicode_ci NULL AFTER pedimento_retorno_parcial_h1',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='desembarque_item_finalization_lines' AND column_name='pedimento_r1_desagregado') = 0,
    'ALTER TABLE desembarque_item_finalization_lines ADD COLUMN pedimento_r1_desagregado VARCHAR(150) COLLATE utf8mb4_unicode_ci NULL AFTER mercancia_pedimento_h1',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='desembarque_item_finalization_lines' AND column_name='pedimento_a3') = 0,
    'ALTER TABLE desembarque_item_finalization_lines ADD COLUMN pedimento_a3 VARCHAR(150) COLLATE utf8mb4_unicode_ci NULL AFTER pedimento_r1_desagregado',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------
-- Anulación auditada de eventos (nunca DELETE)
-- -------------------------------------------------------------------------

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='desembarque_item_finalizations' AND column_name='voided_at') = 0,
    'ALTER TABLE desembarque_item_finalizations ADD COLUMN voided_at DATETIME NULL AFTER notes',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='desembarque_item_finalizations' AND column_name='void_reason') = 0,
    'ALTER TABLE desembarque_item_finalizations ADD COLUMN void_reason VARCHAR(1000) COLLATE utf8mb4_unicode_ci NULL AFTER voided_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='desembarque_item_finalizations' AND column_name='voided_by') = 0,
    'ALTER TABLE desembarque_item_finalizations ADD COLUMN voided_by INT UNSIGNED NULL AFTER void_reason',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='desembarque_item_finalizations' AND index_name='idx_item_finalizations_voided_by') = 0,
    'ALTER TABLE desembarque_item_finalizations ADD KEY idx_item_finalizations_voided_by (voided_by)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=@schema_name AND table_name='desembarque_item_finalizations' AND constraint_name='fk_item_finalizations_voided_by') = 0,
    'ALTER TABLE desembarque_item_finalizations ADD CONSTRAINT fk_item_finalizations_voided_by FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------
-- Compatibilidad V1 -> V2
-- Los eventos creados con la V1 tenían los 5 datos aduanales a nivel evento.
-- Se copian a cada línea existente para conservar exactamente lo capturado.
-- Las columnas V1 NO se eliminan todavía.
-- -------------------------------------------------------------------------

UPDATE desembarque_item_finalization_lines l
INNER JOIN desembarque_item_finalizations f ON f.id = l.finalization_id
SET
    l.pedimento_r1_agregar = COALESCE(l.pedimento_r1_agregar, f.pedimento_r1_agregar),
    l.pedimento_retorno_parcial_h1 = COALESCE(l.pedimento_retorno_parcial_h1, f.pedimento_retorno_parcial_h1),
    l.mercancia_pedimento_h1 = COALESCE(l.mercancia_pedimento_h1, f.mercancia_pedimento_h1),
    l.pedimento_r1_desagregado = COALESCE(l.pedimento_r1_desagregado, f.pedimento_r1_desagregado),
    l.pedimento_a3 = COALESCE(l.pedimento_a3, f.pedimento_a3)
WHERE
    l.pedimento_r1_agregar IS NULL
    OR l.pedimento_retorno_parcial_h1 IS NULL
    OR l.mercancia_pedimento_h1 IS NULL
    OR l.pedimento_r1_desagregado IS NULL
    OR l.pedimento_a3 IS NULL;

-- -------------------------------------------------------------------------
-- Evidencia de cierre
-- -------------------------------------------------------------------------

SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = @schema_name
  AND table_name = 'desembarque_item_finalization_lines'
  AND column_name IN (
      'pedimento_r1_agregar',
      'pedimento_retorno_parcial_h1',
      'mercancia_pedimento_h1',
      'pedimento_r1_desagregado',
      'pedimento_a3'
  )
ORDER BY ordinal_position;

SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = @schema_name
  AND table_name = 'desembarque_item_finalizations'
  AND column_name IN ('voided_at', 'void_reason', 'voided_by')
ORDER BY ordinal_position;
