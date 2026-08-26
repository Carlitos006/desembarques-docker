-- Fase 7F - Administrative Soft Delete
-- Migración aditiva para hosting compartido.
-- No consulta information_schema y no requiere privilegios administrativos.
-- Ejecutar una sola vez, después de confirmar que la comprobación previa no devuelve filas.

-- COMPROBACIÓN PREVIA
-- En una instalación sin Fase 7F, las dos primeras consultas no devuelven filas.
-- SHOW CREATE TABLE permite confirmar que tampoco existe una FK previa para deleted_by.
SHOW COLUMNS FROM desembarques
WHERE Field IN ('deleted_at', 'deleted_by', 'delete_reason');

SHOW INDEX FROM desembarques
WHERE Key_name = 'idx_desembarques_deleted_at';

SHOW CREATE TABLE desembarques;

-- CAMBIO ADITIVO
-- MySQL ejecuta las cinco adiciones como una sola alteración de la tabla padre.
-- No contiene UPDATE, DELETE, DROP ni cambios en tablas hijas.
ALTER TABLE desembarques
    ADD COLUMN deleted_at DATETIME NULL AFTER created_at,
    ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at,
    ADD COLUMN delete_reason VARCHAR(500) NULL AFTER deleted_by,
    ADD INDEX idx_desembarques_deleted_at (deleted_at),
    ADD CONSTRAINT fk_desembarques_deleted_by
        FOREIGN KEY (deleted_by)
        REFERENCES users (id)
        ON DELETE SET NULL;

-- COMPROBACIÓN POSTERIOR
-- Debe devolver exactamente las tres columnas, un índice y una definición
-- fk_desembarques_deleted_by con ON DELETE SET NULL.
SHOW COLUMNS FROM desembarques
WHERE Field IN ('deleted_at', 'deleted_by', 'delete_reason');

SHOW INDEX FROM desembarques
WHERE Key_name = 'idx_desembarques_deleted_at';

SHOW CREATE TABLE desembarques;
