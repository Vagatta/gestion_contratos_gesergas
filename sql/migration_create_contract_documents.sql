-- Migration: Create missing contract_documents table

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
