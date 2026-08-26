CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'usuario', 'cliente') NOT NULL DEFAULT 'usuario',
    last_login_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (name, email, password_hash, role)
SELECT 'Administrador', 'admin@example.com', '$2y$12$sqhQGAUFppmQPOrympAkNuf/b0gsoDFI5q8HRtvSFyCUdKTPVe6nm', 'admin'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@example.com')
LIMIT 1;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_password_resets_user_id (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL UNIQUE,
    token_prefix VARCHAR(16) NOT NULL,
    name VARCHAR(100) NULL,
    scopes JSON NOT NULL,
    last_used DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_api_tokens_user_id (user_id),
    INDEX idx_api_tokens_prefix (token_prefix),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_clients_user_id (user_id),
    CONSTRAINT fk_clients_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_statuses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name_es VARCHAR(100) NOT NULL,
    name_en VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO desembarque_statuses (slug, name_es, name_en, is_default)
SELECT 'pending', 'Pendiente', 'Pending', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM desembarque_statuses WHERE slug = 'pending')
LIMIT 1;

INSERT INTO desembarque_statuses (slug, name_es, name_en)
SELECT 'in_progress', 'En progreso', 'In progress'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM desembarque_statuses WHERE slug = 'in_progress')
LIMIT 1;

INSERT INTO desembarque_statuses (slug, name_es, name_en)
SELECT 'completed', 'Completado', 'Completed'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM desembarque_statuses WHERE slug = 'completed')
LIMIT 1;

INSERT INTO desembarque_statuses (slug, name_es, name_en)
SELECT 'cancelled', 'Cancelado', 'Cancelled'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM desembarque_statuses WHERE slug = 'cancelled')
LIMIT 1;

INSERT INTO desembarque_statuses (slug, name_es, name_en)
SELECT 'on_hold', 'En espera', 'On hold'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM desembarque_statuses WHERE slug = 'on_hold')
LIMIT 1;

CREATE TABLE IF NOT EXISTS desembarques (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referencia VARCHAR(100) NOT NULL,
    fecha_desembarque DATE NOT NULL,
    descripcion TEXT NOT NULL,
    observaciones TEXT NULL,
    destino VARCHAR(150) NOT NULL,
    folio_aviso VARCHAR(100) NOT NULL,
    pedimento VARCHAR(100) NULL,
    cipl VARCHAR(100) NULL,
    manifiesto VARCHAR(100) NULL,
    fecha_embarque DATE NOT NULL,
    barco VARCHAR(150) NOT NULL,
    cliente VARCHAR(150) NOT NULL,
    status_id INT UNSIGNED NOT NULL,
    client_id INT UNSIGNED NULL,
    dias_transcurridos INT NOT NULL,
    dias_fuera INT NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    deleted_by INT UNSIGNED NULL,
    delete_reason VARCHAR(500) NULL,
    INDEX idx_desembarques_deleted_at (deleted_at),
    CONSTRAINT fk_desembarques_users FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_desembarques_deleted_by FOREIGN KEY (deleted_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_desembarques_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL,
    CONSTRAINT fk_desembarques_status FOREIGN KEY (status_id) REFERENCES desembarque_statuses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_pedimentos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desembarque_id INT UNSIGNED NOT NULL,
    reference VARCHAR(191) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_desembarque_pedimentos_desembarque (desembarque_id),
    CONSTRAINT fk_desembarque_pedimentos_desembarque FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_pedimento_headers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desembarque_id INT UNSIGNED NOT NULL,
    num_pedimento VARCHAR(100) NULL,
    cve_pedimento VARCHAR(100) NULL,
    razon_social VARCHAR(255) NULL,
    fecha_entrada VARCHAR(50) NULL,
    fecha_pago VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_desembarque_pedimento_headers_desembarque (desembarque_id),
    CONSTRAINT fk_desembarque_pedimento_headers_desembarque FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_pedimento_header_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    header_id BIGINT UNSIGNED NOT NULL,
    reference VARCHAR(191) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_desembarque_pedimento_links_header (header_id),
    CONSTRAINT fk_desembarque_pedimento_links_header FOREIGN KEY (header_id) REFERENCES desembarque_pedimento_headers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_manifests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desembarque_id INT UNSIGNED NOT NULL,
    reference VARCHAR(191) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_desembarque_manifests_desembarque (desembarque_id),
    CONSTRAINT fk_desembarque_manifests_desembarque FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_cipls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desembarque_id INT UNSIGNED NOT NULL,
    reference VARCHAR(191) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_desembarque_cipls_desembarque (desembarque_id),
    CONSTRAINT fk_desembarque_cipls_desembarque FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_observaciones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desembarque_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_desembarque_observaciones_desembarque (desembarque_id),
    INDEX idx_desembarque_observaciones_created_by (created_by),
    CONSTRAINT fk_desembarque_observaciones_desembarque FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE,
    CONSTRAINT fk_desembarque_observaciones_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desembarque_id INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    extension VARCHAR(20) NOT NULL,
    size BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(50) NULL,
    document_type VARCHAR(50) NULL,
    description VARCHAR(500) NULL,
    document_date DATE NULL,
    sha256 CHAR(64) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    replaces_file_id BIGINT UNSIGNED NULL,
    replaced_at DATETIME NULL,
    replaced_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_desembarque_files_desembarque (desembarque_id),
    INDEX idx_desembarque_files_uploaded_by (uploaded_by),
    INDEX idx_desembarque_files_purpose (desembarque_id, purpose, is_active),
    INDEX idx_desembarque_files_document_type (document_type),
    INDEX idx_desembarque_files_replaces (replaces_file_id),
    INDEX idx_desembarque_files_replaced_by (replaced_by),
    UNIQUE KEY uq_desembarque_files_stored_name (stored_name),
    CONSTRAINT fk_desembarque_files_desembarque FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE,
    CONSTRAINT fk_desembarque_files_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_desembarque_files_replaces FOREIGN KEY (replaces_file_id) REFERENCES desembarque_files (id) ON DELETE SET NULL,
    CONSTRAINT fk_desembarque_files_replaced_by FOREIGN KEY (replaced_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_desembarque_files_size CHECK (size > 0 AND size <= 10485760),
    CONSTRAINT chk_desembarque_files_extension CHECK (extension IN ('pdf','doc','docx','xls','xlsx','csv','txt','jpg','jpeg','png','gif'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_portal_preferences (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    widgets JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_client_portal_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_portal_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_user_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_portal_messages_client (client_user_id),
    INDEX idx_client_portal_messages_sender (sender_id),
    CONSTRAINT fk_client_portal_messages_client FOREIGN KEY (client_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_client_portal_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_milestone_confirmations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desembarque_id INT UNSIGNED NOT NULL,
    confirmed_by INT UNSIGNED NOT NULL,
    confirmed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    comment VARCHAR(255) NULL,
    UNIQUE KEY uq_desembarque_milestone_confirmations (desembarque_id, confirmed_by),
    INDEX idx_desembarque_milestone_confirmations_desembarque (desembarque_id),
    INDEX idx_desembarque_milestone_confirmations_user (confirmed_by),
    CONSTRAINT fk_desembarque_milestone_confirmations_desembarque FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE,
    CONSTRAINT fk_desembarque_milestone_confirmations_user FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS desembarque_aviso_details (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desembarque_id INT UNSIGNED NOT NULL,
    manifiesto VARCHAR(100) NULL,
    medio_transporte VARCHAR(255) NULL,
    imo_transporte VARCHAR(40) NULL,
    consignataria VARCHAR(255) NULL,
    fecha_embarque DATETIME NULL,
    lugar_desembarque TEXT NULL,
    fecha_desembarque_eta DATETIME NULL,
    domicilio_almacenamiento TEXT NULL,
    domicilio_reparacion TEXT NULL,
    rig_name VARCHAR(180) NULL,
    rig_imo VARCHAR(40) NULL,
    rig_field VARCHAR(120) NULL,
    rig_area VARCHAR(120) NULL,
    comitente VARCHAR(255) NULL,
    document_code VARCHAR(100) NULL,
    document_title TEXT NULL,
    notice_number VARCHAR(100) NULL,
    location_date_text VARCHAR(200) NULL,
    recipient_text TEXT NULL,
    introduction LONGTEXT NULL,
    body LONGTEXT NULL,
    operations LONGTEXT NULL,
    documentation TEXT NULL,
    closing_text TEXT NULL,
    signer_name VARCHAR(200) NULL,
    signer_title VARCHAR(200) NULL,
    footer_text TEXT NULL,
    source_excel_name VARCHAR(255) NULL,
    source_excel_sha256 CHAR(64) NULL,
    imported_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_desembarque_aviso_details_desembarque (desembarque_id),
    INDEX idx_desembarque_aviso_details_imported_by (imported_by),
    CONSTRAINT fk_desembarque_aviso_details_desembarque FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE,
    CONSTRAINT fk_desembarque_aviso_details_imported_by FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_aviso_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desembarque_id INT UNSIGNED NOT NULL,
    source_row INT UNSIGNED NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    item_no VARCHAR(50) NULL,
    unidad VARCHAR(40) NULL,
    descripcion TEXT NOT NULL,
    serial_number TEXT NULL,
    marca VARCHAR(180) NULL,
    clave VARCHAR(30) NULL,
    num_pedimento VARCHAR(120) NULL,
    partida VARCHAR(50) NULL,
    cantidad DECIMAL(14,3) NULL,
    importer_name VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_desembarque_aviso_items_desembarque (desembarque_id, sort_order),
    INDEX idx_desembarque_aviso_items_pedimento (desembarque_id, clave, num_pedimento),
    INDEX idx_desembarque_aviso_items_source_row (desembarque_id, source_row),
    CONSTRAINT fk_desembarque_aviso_items_desembarque FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desembarque_aviso_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desembarque_id INT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    aviso_item_id BIGINT UNSIGNED NULL,
    caption VARCHAR(255) NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_aviso_images_desembarque (desembarque_id),
    INDEX idx_aviso_images_file (file_id),
    INDEX idx_aviso_images_item (aviso_item_id),
    CONSTRAINT fk_aviso_images_desembarque FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_images_file FOREIGN KEY (file_id) REFERENCES desembarque_files (id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_images_item FOREIGN KEY (aviso_item_id) REFERENCES desembarque_aviso_items (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id VARCHAR(100) NULL,
    payload JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_logs_user_id (user_id),
    INDEX idx_audit_logs_entity (entity_type, entity_id),
    INDEX idx_audit_logs_action (action),
    INDEX idx_audit_logs_created_at (created_at),
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aviso_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_type ENUM('recipient', 'signer') NOT NULL,
    name_es VARCHAR(200) NOT NULL,
    name_en VARCHAR(200) NOT NULL,
    title_es VARCHAR(200) NOT NULL,
    title_en VARCHAR(200) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_aviso_contacts_type (contact_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO aviso_contacts (contact_type, name_es, name_en, title_es, title_en, is_default)
SELECT 'recipient', 'MTRO. ANTONIO MORALES HERNÁNDEZ', 'ANTONIO MORALES HERNÁNDEZ',
    'TITULAR DE LA ADUANA DE TAMPICO', 'Head of the Tampico Customs Office', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM aviso_contacts
    WHERE contact_type = 'recipient'
        AND is_default = 1
)
LIMIT 1;

INSERT INTO aviso_contacts (contact_type, name_es, name_en, title_es, title_en, is_default)
SELECT 'signer', 'Javier Gerez Bazan', 'Javier Gerez Bazan',
    'AGENTE ADUANAL PATENTE 1948', 'Customs Broker License 1948', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM aviso_contacts
    WHERE contact_type = 'signer'
        AND is_default = 1
)
LIMIT 1;
