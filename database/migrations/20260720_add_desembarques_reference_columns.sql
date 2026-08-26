-- Desembarques: agrega columnas de compatibilidad usadas por store/list/update.
-- Compatible con MySQL 8 y seguro para ejecutar más de una vez.

SET @current_schema = DATABASE();

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @current_schema
          AND TABLE_NAME = 'desembarques'
          AND COLUMN_NAME = 'pedimento'
    ),
    'SELECT ''pedimento already exists'' AS migration_status',
    'ALTER TABLE desembarques ADD COLUMN pedimento VARCHAR(100) NULL AFTER folio_aviso'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @current_schema
          AND TABLE_NAME = 'desembarques'
          AND COLUMN_NAME = 'cipl'
    ),
    'SELECT ''cipl already exists'' AS migration_status',
    'ALTER TABLE desembarques ADD COLUMN cipl VARCHAR(100) NULL AFTER pedimento'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @current_schema
          AND TABLE_NAME = 'desembarques'
          AND COLUMN_NAME = 'manifiesto'
    ),
    'SELECT ''manifiesto already exists'' AS migration_status',
    'ALTER TABLE desembarques ADD COLUMN manifiesto VARCHAR(100) NULL AFTER cipl'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Recupera el primer valor normalizado para registros existentes, cuando aplique.
UPDATE desembarques d
SET d.pedimento = (
    SELECT p.reference
    FROM desembarque_pedimentos p
    WHERE p.desembarque_id = d.id
    ORDER BY p.id ASC
    LIMIT 1
)
WHERE d.pedimento IS NULL
  AND EXISTS (
      SELECT 1 FROM desembarque_pedimentos p2 WHERE p2.desembarque_id = d.id
  );

UPDATE desembarques d
SET d.cipl = (
    SELECT c.reference
    FROM desembarque_cipls c
    WHERE c.desembarque_id = d.id
    ORDER BY c.id ASC
    LIMIT 1
)
WHERE d.cipl IS NULL
  AND EXISTS (
      SELECT 1 FROM desembarque_cipls c2 WHERE c2.desembarque_id = d.id
  );

UPDATE desembarques d
SET d.manifiesto = (
    SELECT m.reference
    FROM desembarque_manifests m
    WHERE m.desembarque_id = d.id
    ORDER BY m.id ASC
    LIMIT 1
)
WHERE d.manifiesto IS NULL
  AND EXISTS (
      SELECT 1 FROM desembarque_manifests m2 WHERE m2.desembarque_id = d.id
  );

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'desembarques'
  AND COLUMN_NAME IN ('pedimento', 'cipl', 'manifiesto')
ORDER BY ORDINAL_POSITION;
