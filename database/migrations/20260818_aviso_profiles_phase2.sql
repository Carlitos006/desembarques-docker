-- Desembarques · Fase 2 Aviso simplificado + perfiles Rig/Proyecto
-- Requiere la Fase Excel-first 1.
-- MySQL 8.0.29+.
-- No elimina datos existentes.

CREATE TABLE IF NOT EXISTS aviso_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(180) COLLATE utf8mb4_unicode_ci NOT NULL,
    client_id INT UNSIGNED NULL,
    rig_name VARCHAR(180) COLLATE utf8mb4_unicode_ci NOT NULL,
    rig_imo VARCHAR(40) COLLATE utf8mb4_unicode_ci NULL,
    rig_field VARCHAR(120) COLLATE utf8mb4_unicode_ci NULL,
    rig_area VARCHAR(120) COLLATE utf8mb4_unicode_ci NULL,
    comitente VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
    recipient_contact_id INT UNSIGNED NULL,
    signer_contact_id INT UNSIGNED NULL,
    document_prefix VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'MADE',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aviso_profiles_client (client_id),
    KEY idx_aviso_profiles_active (is_active),
    KEY idx_aviso_profiles_default (client_id, is_default),
    KEY idx_aviso_profiles_recipient (recipient_contact_id),
    KEY idx_aviso_profiles_signer (signer_contact_id),
    KEY idx_aviso_profiles_created_by (created_by),
    KEY idx_aviso_profiles_updated_by (updated_by),
    CONSTRAINT fk_aviso_profiles_client
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_profiles_recipient
        FOREIGN KEY (recipient_contact_id) REFERENCES aviso_contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_aviso_profiles_signer
        FOREIGN KEY (signer_contact_id) REFERENCES aviso_contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_aviso_profiles_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_aviso_profiles_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE desembarque_aviso_details
    ADD COLUMN IF NOT EXISTS profile_id BIGINT UNSIGNED NULL AFTER desembarque_id;

SET @has_profile_idx := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'desembarque_aviso_details'
      AND index_name = 'idx_desembarque_aviso_profile'
);
SET @profile_idx_sql := IF(
    @has_profile_idx = 0,
    'ALTER TABLE desembarque_aviso_details ADD INDEX idx_desembarque_aviso_profile (profile_id)',
    'SELECT 1'
);
PREPARE profile_idx_stmt FROM @profile_idx_sql;
EXECUTE profile_idx_stmt;
DEALLOCATE PREPARE profile_idx_stmt;

SET @has_profile_fk := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'desembarque_aviso_details'
      AND constraint_name = 'fk_desembarque_aviso_profile'
      AND constraint_type = 'FOREIGN KEY'
);
SET @profile_fk_sql := IF(
    @has_profile_fk = 0,
    'ALTER TABLE desembarque_aviso_details ADD CONSTRAINT fk_desembarque_aviso_profile FOREIGN KEY (profile_id) REFERENCES aviso_profiles(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE profile_fk_stmt FROM @profile_fk_sql;
EXECUTE profile_fk_stmt;
DEALLOCATE PREPARE profile_fk_stmt;

-- Perfil de referencia tomado del Aviso 060-26 compartido para QA.
-- Se deja global y NO predeterminado para evitar aplicarlo automáticamente a clientes no relacionados.
INSERT INTO aviso_profiles (
    name, client_id, rig_name, rig_imo, rig_field, rig_area, comitente,
    recipient_contact_id, signer_contact_id, document_prefix,
    is_default, is_active, created_by, updated_by
)
SELECT
    'Deepwater Thalassa · Trion', NULL,
    'DEEPWATER THALASSA', '9675169', 'TRION', 'CUBIERTA',
    'GLOBALSANTAFE DRILLING MEXICO, S. DE R.L. DE C.V.',
    (SELECT id FROM aviso_contacts WHERE contact_type = 'recipient' AND is_default = 1 ORDER BY id ASC LIMIT 1),
    (SELECT id FROM aviso_contacts WHERE contact_type = 'signer' AND is_default = 1 ORDER BY id ASC LIMIT 1),
    'MADE', 0, 1,
    (SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1),
    (SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1)
WHERE NOT EXISTS (
    SELECT 1 FROM aviso_profiles
    WHERE client_id IS NULL
      AND rig_name = 'DEEPWATER THALASSA'
      AND rig_imo = '9675169'
      AND rig_field = 'TRION'
);

SELECT 'aviso_profiles' AS tabla, COUNT(*) AS registros FROM aviso_profiles;
SELECT 'desembarque_aviso_details.profile_id' AS columna, COUNT(*) AS existe
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'desembarque_aviso_details'
  AND column_name = 'profile_id';
