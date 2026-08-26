-- Aviso de desembarque: snapshot del oficio + datos importados desde Excel.
-- MySQL 8.x. Seguro para ejecutar más de una vez.

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
    CONSTRAINT fk_desembarque_aviso_details_desembarque
        FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE,
    CONSTRAINT fk_desembarque_aviso_details_imported_by
        FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE SET NULL
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
    CONSTRAINT fk_desembarque_aviso_items_desembarque
        FOREIGN KEY (desembarque_id) REFERENCES desembarques (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El PDF de referencia 060-26 usa Aduana de Tampico y patente 1948.
-- Solo migramos los defaults heredados conocidos; si el proyecto ya fue personalizado,
-- estas sentencias no alteran el perfil del usuario.
UPDATE aviso_contacts
SET name_es = 'MTRO. ANTONIO MORALES HERNÁNDEZ',
    name_en = 'ANTONIO MORALES HERNÁNDEZ',
    title_es = 'TITULAR DE LA ADUANA DE TAMPICO',
    title_en = 'Head of the Tampico Customs Office'
WHERE contact_type = 'recipient'
  AND is_default = 1
  AND UPPER(title_es) = 'TITULAR DE LA ADUANA DE ALTAMIRA';

UPDATE aviso_contacts
SET name_es = 'Javier Gerez Bazan',
    name_en = 'Javier Gerez Bazan',
    title_es = 'AGENTE ADUANAL PATENTE 1948',
    title_en = 'Customs Broker License 1948'
WHERE contact_type = 'signer'
  AND is_default = 1
  AND UPPER(title_es) = 'AGENTE ADUANAL PATENTE 3908';
