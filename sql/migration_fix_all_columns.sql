-- Migration: Add ALL missing columns (run this entire script on production)
-- For MySQL that doesn't support IF NOT EXISTS in ALTER TABLE

-- Clients table columns
ALTER TABLE clients ADD COLUMN client_type ENUM('particular','empresa') NOT NULL DEFAULT 'particular' AFTER contratista;
ALTER TABLE clients ADD COLUMN tax_id VARCHAR(20) DEFAULT NULL AFTER client_type;
ALTER TABLE clients ADD COLUMN birth_date DATE DEFAULT NULL AFTER tax_id;
ALTER TABLE clients ADD COLUMN fiscal_address VARCHAR(300) DEFAULT NULL AFTER birth_date;
ALTER TABLE clients ADD COLUMN comms_address VARCHAR(300) DEFAULT NULL AFTER fiscal_address;
ALTER TABLE clients ADD COLUMN iban VARCHAR(34) DEFAULT NULL AFTER comms_address;
ALTER TABLE clients ADD COLUMN agent_name VARCHAR(200) DEFAULT NULL AFTER iban;
ALTER TABLE clients ADD COLUMN agent_email VARCHAR(200) DEFAULT NULL AFTER agent_name;

-- Contracts table columns
ALTER TABLE contracts ADD COLUMN energy_type ENUM('gas','electricidad') DEFAULT NULL AFTER contract_date;
ALTER TABLE contracts ADD COLUMN tariff_type VARCHAR(20) DEFAULT NULL AFTER energy_type;
ALTER TABLE contracts ADD COLUMN company VARCHAR(200) DEFAULT NULL AFTER tariff_type;
ALTER TABLE contracts ADD COLUMN cups VARCHAR(100) DEFAULT NULL AFTER company;
ALTER TABLE contracts ADD COLUMN annual_consumption VARCHAR(50) DEFAULT NULL AFTER cups;
ALTER TABLE contracts ADD COLUMN power_p1 VARCHAR(50) DEFAULT NULL AFTER annual_consumption;
ALTER TABLE contracts ADD COLUMN power_p2 VARCHAR(50) DEFAULT NULL AFTER power_p1;
ALTER TABLE contracts ADD COLUMN product VARCHAR(200) DEFAULT NULL AFTER power_p2;
ALTER TABLE contracts ADD COLUMN sale_date DATE DEFAULT NULL AFTER product;
ALTER TABLE contracts ADD COLUMN activation_date DATE DEFAULT NULL AFTER sale_date;
ALTER TABLE contracts ADD COLUMN start_date DATE DEFAULT NULL AFTER activation_date;
ALTER TABLE contracts ADD COLUMN end_date DATE DEFAULT NULL AFTER start_date;
ALTER TABLE contracts ADD COLUMN invoice_paper TINYINT(1) NOT NULL DEFAULT 0 AFTER end_date;
ALTER TABLE contracts ADD COLUMN contract_paper TINYINT(1) NOT NULL DEFAULT 0 AFTER invoice_paper;
