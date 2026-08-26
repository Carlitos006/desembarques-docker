-- Fase 5F.3 — Cierre documental parcial de salidas de mercancía
-- Ejecutar UNA sola vez, después de 20260820_merchandise_finalization_v2.sql.
-- No consulta information_schema y no elimina ni modifica cantidades históricas.

ALTER TABLE desembarque_item_finalizations
    ADD COLUMN closure_status VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'complete' AFTER notes,
    ADD COLUMN closure_completed_at DATETIME NULL AFTER closure_status,
    ADD COLUMN closure_completed_by INT UNSIGNED NULL AFTER closure_completed_at,
    ADD KEY idx_item_finalizations_closure (desembarque_id, closure_status, voided_at),
    ADD KEY idx_item_finalizations_completed_by (closure_completed_by),
    ADD CONSTRAINT fk_item_finalizations_completed_by
        FOREIGN KEY (closure_completed_by) REFERENCES users(id) ON DELETE SET NULL;

-- Los movimientos anteriores a esta fase ya eran cierres completos.
UPDATE desembarque_item_finalizations
SET
    closure_status = 'complete',
    closure_completed_at = COALESCE(closure_completed_at, created_at),
    closure_completed_by = COALESCE(closure_completed_by, created_by)
WHERE closure_status = 'complete';

-- Validación compatible con cuentas sin acceso a information_schema.
SHOW COLUMNS FROM desembarque_item_finalizations LIKE 'closure_status';
SHOW COLUMNS FROM desembarque_item_finalizations LIKE 'closure_completed_at';
SHOW COLUMNS FROM desembarque_item_finalizations LIKE 'closure_completed_by';
SHOW INDEX FROM desembarque_item_finalizations WHERE Key_name = 'idx_item_finalizations_closure';
