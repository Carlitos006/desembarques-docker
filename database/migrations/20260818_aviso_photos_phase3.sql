-- Desembarques - Fase 3: anexo fotográfico del Aviso de Desembarque
-- Compatible con MySQL 8.0.x y con instalaciones donde desembarque_aviso_images ya existe.

CREATE TABLE IF NOT EXISTS `desembarque_aviso_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `desembarque_id` int unsigned NOT NULL,
  `file_id` bigint unsigned NOT NULL,
  `aviso_item_id` bigint unsigned DEFAULT NULL,
  `caption` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_aviso_images_desembarque` (`desembarque_id`),
  KEY `idx_aviso_images_file` (`file_id`),
  KEY `idx_aviso_images_item` (`aviso_item_id`),
  CONSTRAINT `fk_aviso_images_desembarque` FOREIGN KEY (`desembarque_id`) REFERENCES `desembarques` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aviso_images_file` FOREIGN KEY (`file_id`) REFERENCES `desembarque_files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aviso_images_item` FOREIGN KEY (`aviso_item_id`) REFERENCES `desembarque_aviso_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limpieza defensiva previa a crear FKs en bases donde la tabla ya existía sin restricciones.
DELETE ai
FROM desembarque_aviso_images ai
LEFT JOIN desembarques d ON d.id = ai.desembarque_id
WHERE d.id IS NULL;

DELETE ai
FROM desembarque_aviso_images ai
LEFT JOIN desembarque_files f ON f.id = ai.file_id AND f.desembarque_id = ai.desembarque_id
WHERE f.id IS NULL;

UPDATE desembarque_aviso_images ai
LEFT JOIN desembarque_aviso_items i ON i.id = ai.aviso_item_id AND i.desembarque_id = ai.desembarque_id
SET ai.aviso_item_id = NULL
WHERE ai.aviso_item_id IS NOT NULL
  AND i.id IS NULL;

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'desembarque_aviso_images' AND index_name = 'idx_aviso_images_desembarque'
  ),
  'SELECT 1',
  'ALTER TABLE desembarque_aviso_images ADD KEY idx_aviso_images_desembarque (desembarque_id)'
);
PREPARE phase3_stmt FROM @sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'desembarque_aviso_images' AND index_name = 'idx_aviso_images_file'
  ),
  'SELECT 1',
  'ALTER TABLE desembarque_aviso_images ADD KEY idx_aviso_images_file (file_id)'
);
PREPARE phase3_stmt FROM @sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'desembarque_aviso_images' AND index_name = 'idx_aviso_images_item'
  ),
  'SELECT 1',
  'ALTER TABLE desembarque_aviso_images ADD KEY idx_aviso_images_item (aviso_item_id)'
);
PREPARE phase3_stmt FROM @sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.key_column_usage
    WHERE table_schema = DATABASE()
      AND table_name = 'desembarque_aviso_images'
      AND column_name = 'desembarque_id'
      AND referenced_table_name = 'desembarques'
      AND referenced_column_name = 'id'
  ),
  'SELECT 1',
  'ALTER TABLE desembarque_aviso_images ADD CONSTRAINT fk_aviso_images_desembarque FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE'
);
PREPARE phase3_stmt FROM @sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.key_column_usage
    WHERE table_schema = DATABASE()
      AND table_name = 'desembarque_aviso_images'
      AND column_name = 'file_id'
      AND referenced_table_name = 'desembarque_files'
      AND referenced_column_name = 'id'
  ),
  'SELECT 1',
  'ALTER TABLE desembarque_aviso_images ADD CONSTRAINT fk_aviso_images_file FOREIGN KEY (file_id) REFERENCES desembarque_files (id) ON DELETE CASCADE'
);
PREPARE phase3_stmt FROM @sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.key_column_usage
    WHERE table_schema = DATABASE()
      AND table_name = 'desembarque_aviso_images'
      AND column_name = 'aviso_item_id'
      AND referenced_table_name = 'desembarque_aviso_items'
      AND referenced_column_name = 'id'
  ),
  'SELECT 1',
  'ALTER TABLE desembarque_aviso_images ADD CONSTRAINT fk_aviso_images_item FOREIGN KEY (aviso_item_id) REFERENCES desembarque_aviso_items (id) ON DELETE SET NULL'
);
PREPARE phase3_stmt FROM @sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

-- Mejora de acceso para reconciliar mercancías sin destruir sus IDs.
SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'desembarque_aviso_items' AND index_name = 'idx_aviso_items_source_row'
  ),
  'SELECT 1',
  'ALTER TABLE desembarque_aviso_items ADD KEY idx_aviso_items_source_row (desembarque_id, source_row)'
);
PREPARE phase3_stmt FROM @sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

SELECT 'desembarque_aviso_images' AS tabla, COUNT(*) AS asociaciones FROM desembarque_aviso_images;
SHOW INDEX FROM desembarque_aviso_images;
