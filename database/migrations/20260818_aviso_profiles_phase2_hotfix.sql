-- Desembarques · Fase 2 HOTFIX de esquema
-- Compatible con la BD real observada el 2026-08-18.
-- Corrige una ejecución parcial de 20260818_aviso_profiles_phase2.sql.
-- No elimina tablas ni registros.

-- 1) Contrato base de perfiles. Si la tabla ya existe, no la recrea.
CREATE TABLE IF NOT EXISTS aviso_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NULL,
    name VARCHAR(180) COLLATE utf8mb4_unicode_ci NOT NULL,
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
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aviso_profiles_client (client_id),
    KEY idx_aviso_profiles_active (is_active),
    KEY idx_aviso_profiles_default (client_id, is_default),
    KEY idx_aviso_profiles_recipient (recipient_contact_id),
    KEY idx_aviso_profiles_signer (signer_contact_id),
    KEY idx_aviso_profiles_created_by (created_by),
    CONSTRAINT fk_aviso_profiles_client
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_profiles_recipient
        FOREIGN KEY (recipient_contact_id) REFERENCES aviso_contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_aviso_profiles_signer
        FOREIGN KEY (signer_contact_id) REFERENCES aviso_contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_aviso_profiles_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) La versión previa de aviso_profiles usa `campo`; el contrato Fase 2 usa `rig_field`.
SET @has_rig_field := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'aviso_profiles' AND column_name = 'rig_field'
);
SET @has_campo := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'aviso_profiles' AND column_name = 'campo'
);
SET @rename_field_sql := IF(
    @has_rig_field = 0 AND @has_campo = 1,
    'ALTER TABLE aviso_profiles CHANGE COLUMN campo rig_field VARCHAR(120) COLLATE utf8mb4_unicode_ci NULL',
    'SELECT 1'
);
PREPARE rename_field_stmt FROM @rename_field_sql;
EXECUTE rename_field_stmt;
DEALLOCATE PREPARE rename_field_stmt;

-- Si por alguna instalación coexistieran ambos campos, conserva el valor legacy cuando rig_field esté vacío.
SET @has_rig_field := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'aviso_profiles' AND column_name = 'rig_field'
);
SET @has_campo := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'aviso_profiles' AND column_name = 'campo'
);
SET @copy_field_sql := IF(
    @has_rig_field = 1 AND @has_campo = 1,
    'UPDATE aviso_profiles SET rig_field = campo WHERE (rig_field IS NULL OR rig_field = '''') AND campo IS NOT NULL',
    'SELECT 1'
);
PREPARE copy_field_stmt FROM @copy_field_sql;
EXECUTE copy_field_stmt;
DEALLOCATE PREPARE copy_field_stmt;

-- 3) Permite perfiles globales (client_id NULL) y alinea longitudes con el backend.
ALTER TABLE aviso_profiles
    MODIFY COLUMN client_id INT UNSIGNED NULL,
    MODIFY COLUMN name VARCHAR(180) COLLATE utf8mb4_unicode_ci NOT NULL;

-- 4) Índice de selección de perfil predeterminado, sólo si falta.
SET @has_default_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'aviso_profiles'
      AND index_name = 'idx_aviso_profiles_default'
);
SET @default_idx_sql := IF(
    @has_default_idx = 0,
    'ALTER TABLE aviso_profiles ADD INDEX idx_aviso_profiles_default (client_id, is_default)',
    'SELECT 1'
);
PREPARE default_idx_stmt FROM @default_idx_sql;
EXECUTE default_idx_stmt;
DEALLOCATE PREPARE default_idx_stmt;

-- 5) La BD real ya usa `aviso_profile_id`. Lo crea sólo donde todavía no exista.
SET @has_aviso_profile_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'desembarque_aviso_details'
      AND column_name = 'aviso_profile_id'
);
SET @add_aviso_profile_id_sql := IF(
    @has_aviso_profile_id = 0,
    'ALTER TABLE desembarque_aviso_details ADD COLUMN aviso_profile_id BIGINT UNSIGNED NULL AFTER desembarque_id',
    'SELECT 1'
);
PREPARE add_aviso_profile_id_stmt FROM @add_aviso_profile_id_sql;
EXECUTE add_aviso_profile_id_stmt;
DEALLOCATE PREPARE add_aviso_profile_id_stmt;

-- Si una ejecución parcial hubiera llegado a crear `profile_id`, migra su valor sin borrar la columna legacy.
SET @has_profile_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'desembarque_aviso_details'
      AND column_name = 'profile_id'
);
SET @migrate_profile_id_sql := IF(
    @has_profile_id = 1,
    'UPDATE desembarque_aviso_details SET aviso_profile_id = profile_id WHERE aviso_profile_id IS NULL AND profile_id IS NOT NULL',
    'SELECT 1'
);
PREPARE migrate_profile_id_stmt FROM @migrate_profile_id_sql;
EXECUTE migrate_profile_id_stmt;
DEALLOCATE PREPARE migrate_profile_id_stmt;

SET @has_profile_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'desembarque_aviso_details'
      AND column_name = 'aviso_profile_id'
);
SET @profile_idx_sql := IF(
    @has_profile_idx = 0,
    'ALTER TABLE desembarque_aviso_details ADD INDEX idx_aviso_details_profile (aviso_profile_id)',
    'SELECT 1'
);
PREPARE profile_idx_stmt FROM @profile_idx_sql;
EXECUTE profile_idx_stmt;
DEALLOCATE PREPARE profile_idx_stmt;

SET @has_profile_fk := (
    SELECT COUNT(*)
    FROM information_schema.key_column_usage
    WHERE table_schema = DATABASE()
      AND table_name = 'desembarque_aviso_details'
      AND column_name = 'aviso_profile_id'
      AND referenced_table_name = 'aviso_profiles'
      AND referenced_column_name = 'id'
);
SET @profile_fk_sql := IF(
    @has_profile_fk = 0,
    'ALTER TABLE desembarque_aviso_details ADD CONSTRAINT fk_aviso_details_profile FOREIGN KEY (aviso_profile_id) REFERENCES aviso_profiles(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE profile_fk_stmt FROM @profile_fk_sql;
EXECUTE profile_fk_stmt;
DEALLOCATE PREPARE profile_fk_stmt;

-- 6) Perfil global de referencia del Aviso 060-26 para QA.
-- No queda predeterminado para ningún cliente.
INSERT INTO aviso_profiles (
    name, client_id, rig_name, rig_imo, rig_field, rig_area, comitente,
    recipient_contact_id, signer_contact_id, document_prefix,
    is_default, is_active, created_by
)
SELECT
    'Deepwater Thalassa · Trion', NULL,
    'DEEPWATER THALASSA', '9675169', 'TRION', 'CUBIERTA',
    'GLOBALSANTAFE DRILLING MEXICO, S. DE R.L. DE C.V.',
    (SELECT id FROM aviso_contacts WHERE contact_type = 'recipient' AND is_default = 1 ORDER BY id ASC LIMIT 1),
    (SELECT id FROM aviso_contacts WHERE contact_type = 'signer' AND is_default = 1 ORDER BY id ASC LIMIT 1),
    'MADE', 0, 1,
    (SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1)
WHERE NOT EXISTS (
    SELECT 1 FROM aviso_profiles
    WHERE client_id IS NULL
      AND rig_name = 'DEEPWATER THALASSA'
      AND COALESCE(rig_imo, '') = '9675169'
      AND COALESCE(rig_field, '') = 'TRION'
);

-- Verificación final.
SELECT
    id, name, client_id, rig_name, rig_imo, rig_field, rig_area, comitente,
    is_default, is_active
FROM aviso_profiles
ORDER BY client_id IS NULL DESC, client_id, id;

SELECT
    column_name, is_nullable, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'desembarque_aviso_details'
  AND column_name IN ('aviso_profile_id', 'profile_id')
ORDER BY ordinal_position;
