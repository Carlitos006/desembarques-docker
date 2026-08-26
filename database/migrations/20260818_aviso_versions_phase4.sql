-- Desembarques - Fase 4: historial inmutable de emisiones PDF del Aviso de Desembarque
-- Compatible con MySQL 8.0.x. No elimina ni modifica versiones existentes.

CREATE TABLE IF NOT EXISTS `desembarque_aviso_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `desembarque_id` int unsigned NOT NULL,
  `version_no` int unsigned NOT NULL,
  `aviso_profile_id` bigint unsigned DEFAULT NULL,
  `document_code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notice_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_excel_sha256` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_count` int unsigned NOT NULL DEFAULT '0',
  `photo_count` int unsigned NOT NULL DEFAULT '0',
  `page_count` int unsigned DEFAULT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'application/pdf',
  `size` bigint unsigned NOT NULL,
  `pdf_sha256` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot_json` json NOT NULL,
  `snapshot_sha256` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `generated_by` int unsigned DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aviso_versions_number` (`desembarque_id`,`version_no`),
  UNIQUE KEY `uq_aviso_versions_stored_name` (`stored_name`),
  KEY `idx_aviso_versions_generated_at` (`generated_at`),
  KEY `idx_aviso_versions_profile` (`aviso_profile_id`),
  KEY `idx_aviso_versions_generated_by` (`generated_by`),
  CONSTRAINT `fk_aviso_versions_desembarque` FOREIGN KEY (`desembarque_id`) REFERENCES `desembarques` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aviso_versions_profile` FOREIGN KEY (`aviso_profile_id`) REFERENCES `aviso_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_aviso_versions_generated_by` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'desembarque_aviso_versions' AS tabla, COUNT(*) AS versiones FROM desembarque_aviso_versions;
SHOW INDEX FROM desembarque_aviso_versions;
