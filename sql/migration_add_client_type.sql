-- Migration: Add missing columns to clients and contracts tables
-- Run this on production database if columns are missing

-- Add missing columns to clients table
ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS client_type ENUM('particular','empresa') NOT NULL DEFAULT 'particular' AFTER contratista,
    ADD COLUMN IF NOT EXISTS tax_id VARCHAR(20) DEFAULT NULL AFTER client_type,
    ADD COLUMN IF NOT EXISTS birth_date DATE DEFAULT NULL AFTER tax_id,
    ADD COLUMN IF NOT EXISTS fiscal_address VARCHAR(300) DEFAULT NULL AFTER birth_date,
    ADD COLUMN IF NOT EXISTS comms_address VARCHAR(300) DEFAULT NULL AFTER fiscal_address,
    ADD COLUMN IF NOT EXISTS iban VARCHAR(34) DEFAULT NULL AFTER comms_address,
    ADD COLUMN IF NOT EXISTS agent_name VARCHAR(200) DEFAULT NULL AFTER iban,
    ADD COLUMN IF NOT EXISTS agent_email VARCHAR(200) DEFAULT NULL AFTER agent_name;

-- Add missing columns to contracts table
ALTER TABLE contracts
    ADD COLUMN IF NOT EXISTS energy_type ENUM('gas','electricidad') DEFAULT NULL AFTER contract_date,
    ADD COLUMN IF NOT EXISTS tariff_type VARCHAR(20) DEFAULT NULL AFTER energy_type,
    ADD COLUMN IF NOT EXISTS company VARCHAR(200) DEFAULT NULL AFTER tariff_type,
    ADD COLUMN IF NOT EXISTS cups VARCHAR(100) DEFAULT NULL AFTER company,
    ADD COLUMN IF NOT EXISTS annual_consumption VARCHAR(50) DEFAULT NULL AFTER cups,
    ADD COLUMN IF NOT EXISTS power_p1 VARCHAR(50) DEFAULT NULL AFTER annual_consumption,
    ADD COLUMN IF NOT EXISTS power_p2 VARCHAR(50) DEFAULT NULL AFTER power_p1,
    ADD COLUMN IF NOT EXISTS product VARCHAR(200) DEFAULT NULL AFTER power_p2,
    ADD COLUMN IF NOT EXISTS sale_date DATE DEFAULT NULL AFTER product,
    ADD COLUMN IF NOT EXISTS activation_date DATE DEFAULT NULL AFTER sale_date,
    ADD COLUMN IF NOT EXISTS start_date DATE DEFAULT NULL AFTER activation_date,
    ADD COLUMN IF NOT EXISTS end_date DATE DEFAULT NULL AFTER start_date,
    ADD COLUMN IF NOT EXISTS invoice_paper TINYINT(1) NOT NULL DEFAULT 0 AFTER end_date,
    ADD COLUMN IF NOT EXISTS contract_paper TINYINT(1) NOT NULL DEFAULT 0 AFTER invoice_paper;
