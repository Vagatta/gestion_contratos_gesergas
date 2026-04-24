-- Esquema BD para gestión de contratos
-- MariaDB / MySQL 5.7+
-- Ejecutar: mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS contratos_db
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE contratos_db;

-- Clientes
CREATE TABLE IF NOT EXISTS clients (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(200) NOT NULL,
    address      VARCHAR(300) DEFAULT NULL,
    contratista  VARCHAR(200) DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_clients_name (name)
) ENGINE=InnoDB;

-- Métodos de contacto (N por cliente, extensible vía "type")
CREATE TABLE IF NOT EXISTS contact_methods (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id  INT UNSIGNED NOT NULL,
    type       VARCHAR(30) NOT NULL,           -- phone | email | other | whatsapp | ...
    label      VARCHAR(100) DEFAULT NULL,      -- etiqueta libre, p.ej. "móvil personal"
    value      VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_contact_client
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_contact_client (client_id),
    INDEX idx_contact_type (type)
) ENGINE=InnoDB;

-- Contratos
CREATE TABLE IF NOT EXISTS contracts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id      INT UNSIGNED NOT NULL,
    contract_date  DATE NOT NULL,
    document_path  VARCHAR(500) DEFAULT NULL,
    document_name  VARCHAR(255) DEFAULT NULL,
    notes          TEXT DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_contract_client
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_contract_date (contract_date),
    INDEX idx_contract_client (client_id)
) ENGINE=InnoDB;

-- Notificaciones (generadas automáticamente al crear contrato)
CREATE TABLE IF NOT EXISTS notifications (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id   INT UNSIGNED NOT NULL,
    notify_date   DATE NOT NULL,
    message       VARCHAR(500) NOT NULL,
    status        ENUM('pending','completed') NOT NULL DEFAULT 'pending',
    completed_at  DATETIME DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_contract
        FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    INDEX idx_notif_date (notify_date),
    INDEX idx_notif_status (status)
) ENGINE=InnoDB;

-- Configuración de la app (clave/valor)
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Valor por defecto: solo notificar 1 mes antes del vencimiento
INSERT INTO app_settings (setting_key, setting_value)
VALUES ('notification_schedule', '["1_month"]')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Historial de acciones (audit trail simple)
CREATE TABLE IF NOT EXISTS action_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity      VARCHAR(50) NOT NULL,   -- contract | notification | client
    entity_id   INT UNSIGNED NOT NULL,
    action      VARCHAR(50) NOT NULL,   -- created | updated | deleted | completed
    details     TEXT DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_entity (entity, entity_id)
) ENGINE=InnoDB;
