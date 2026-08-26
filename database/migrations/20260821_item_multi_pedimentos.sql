-- Fase transversal: múltiples pedimentos por mercancía.
-- No elimina ni modifica datos existentes; agrega una relación 1:N y hace backfill de los campos legacy.

CREATE TABLE IF NOT EXISTS desembarque_aviso_item_pedimentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    aviso_item_id BIGINT UNSIGNED NOT NULL,
    clave VARCHAR(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    num_pedimento VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL,
    importer_name VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_item_pedimento (aviso_item_id, num_pedimento),
    KEY idx_item_pedimentos_item (aviso_item_id),
    KEY idx_item_pedimentos_number (num_pedimento),
    CONSTRAINT fk_item_pedimentos_item
        FOREIGN KEY (aviso_item_id) REFERENCES desembarque_aviso_items(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO desembarque_aviso_item_pedimentos
    (aviso_item_id, clave, num_pedimento, importer_name, sort_order)
SELECT
    i.id,
    NULLIF(TRIM(i.clave), ''),
    TRIM(i.num_pedimento),
    NULLIF(TRIM(i.importer_name), ''),
    1
FROM desembarque_aviso_items i
WHERE TRIM(COALESCE(i.num_pedimento, '')) <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM desembarque_aviso_item_pedimentos ip
      WHERE ip.aviso_item_id = i.id
        AND REPLACE(ip.num_pedimento, ' ', '') = REPLACE(TRIM(i.num_pedimento), ' ', '')
  );

SELECT COUNT(*) AS relaciones_item_pedimento
FROM desembarque_aviso_item_pedimentos;

-- Remediación conservadora de históricos: si el Aviso tiene exactamente un renglón de mercancía,
-- todos sus pedimentos globales pertenecen necesariamente a ese único renglón.
INSERT INTO desembarque_aviso_item_pedimentos
    (aviso_item_id, clave, num_pedimento, importer_name, sort_order)
SELECT
    i.id,
    NULLIF(TRIM(ph.cve_pedimento), ''),
    TRIM(ph.num_pedimento),
    NULLIF(TRIM(ph.razon_social), ''),
    ROW_NUMBER() OVER (PARTITION BY i.id ORDER BY ph.id)
FROM desembarque_aviso_items i
INNER JOIN (
    SELECT desembarque_id
    FROM desembarque_aviso_items
    GROUP BY desembarque_id
    HAVING COUNT(*) = 1
) one_item ON one_item.desembarque_id = i.desembarque_id
INNER JOIN desembarque_pedimento_headers ph ON ph.desembarque_id = i.desembarque_id
WHERE TRIM(COALESCE(ph.num_pedimento, '')) <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM desembarque_aviso_item_pedimentos ip
      WHERE ip.aviso_item_id = i.id
        AND REPLACE(ip.num_pedimento, ' ', '') = REPLACE(TRIM(ph.num_pedimento), ' ', '')
  );

SELECT COUNT(*) AS relaciones_item_pedimento_final
FROM desembarque_aviso_item_pedimentos;
