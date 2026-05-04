USE contratos_db;

ALTER TABLE clients
    ADD COLUMN client_type     ENUM('particular','empresa') NOT NULL DEFAULT 'particular' AFTER contratista,
    ADD COLUMN tax_id          VARCHAR(20)  DEFAULT NULL AFTER client_type,
    ADD COLUMN birth_date      DATE         DEFAULT NULL AFTER tax_id,
    ADD COLUMN fiscal_address  VARCHAR(300) DEFAULT NULL AFTER birth_date,
    ADD COLUMN comms_address   VARCHAR(300) DEFAULT NULL AFTER fiscal_address,
    ADD COLUMN iban            VARCHAR(34)  DEFAULT NULL AFTER comms_address,
    ADD COLUMN agent_name      VARCHAR(200) DEFAULT NULL AFTER iban,
    ADD COLUMN agent_email     VARCHAR(200) DEFAULT NULL AFTER agent_name;

ALTER TABLE contracts
    ADD COLUMN energy_type        ENUM('gas','electricidad') DEFAULT NULL AFTER contract_date,
    ADD COLUMN tariff_type        VARCHAR(20)  DEFAULT NULL AFTER energy_type,
    ADD COLUMN company            VARCHAR(200) DEFAULT NULL AFTER tariff_type,
    ADD COLUMN cups               VARCHAR(100) DEFAULT NULL AFTER company,
    ADD COLUMN annual_consumption VARCHAR(50)  DEFAULT NULL AFTER cups,
    ADD COLUMN power_p1           VARCHAR(50)  DEFAULT NULL AFTER annual_consumption,
    ADD COLUMN power_p2           VARCHAR(50)  DEFAULT NULL AFTER power_p1,
    ADD COLUMN product            VARCHAR(200) DEFAULT NULL AFTER power_p2,
    ADD COLUMN sale_date          DATE DEFAULT NULL AFTER product,
    ADD COLUMN activation_date    DATE DEFAULT NULL AFTER sale_date,
    ADD COLUMN start_date         DATE DEFAULT NULL AFTER activation_date,
    ADD COLUMN end_date           DATE DEFAULT NULL AFTER start_date,
    ADD COLUMN invoice_paper      TINYINT(1) NOT NULL DEFAULT 0 AFTER end_date,
    ADD COLUMN contract_paper     TINYINT(1) NOT NULL DEFAULT 0 AFTER invoice_paper;

CREATE TABLE IF NOT EXISTS contract_documents (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id    INT UNSIGNED NOT NULL,
    document_type  VARCHAR(50) NOT NULL,
    document_path  VARCHAR(500) NOT NULL,
    document_name  VARCHAR(255) NOT NULL,
    uploaded_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_contract_document_contract
        FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    INDEX idx_contract_documents_contract (contract_id),
    INDEX idx_contract_documents_type (document_type)
) ENGINE=InnoDB;
