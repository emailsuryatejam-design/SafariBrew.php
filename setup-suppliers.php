<?php
require_once __DIR__ . '/config.php';

$pdo = getDB();

$tables = [
    // Master suppliers (brands/groups like "Serena Hotels", "andBeyond", "Singita")
    "CREATE TABLE IF NOT EXISTS supplier_brands (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        brand_name VARCHAR(200) NOT NULL,
        brand_code VARCHAR(30),
        description TEXT,
        logo_url VARCHAR(500),
        website VARCHAR(300),
        headquarters_country VARCHAR(60),
        headquarters_address TEXT,
        primary_contact_name VARCHAR(150),
        primary_contact_email VARCHAR(150),
        primary_contact_phone VARCHAR(50),
        payment_terms TEXT,
        bank_details JSON,
        tax_id VARCHAR(60),
        notes TEXT,
        is_active TINYINT(1) DEFAULT 1,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_branch (branch_id),
        INDEX idx_name (brand_name)
    )",

    // Individual suppliers (properties) - each property is a supplier
    "CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        brand_id INT,
        accommodation_id INT,
        supplier_name VARCHAR(200) NOT NULL,
        supplier_code VARCHAR(30),
        supplier_type ENUM('accommodation','activity_provider','transport','ground_handler','other') DEFAULT 'accommodation',
        description TEXT,
        country VARCHAR(60),
        region VARCHAR(80),
        address TEXT,
        contact_name VARCHAR(150),
        contact_email VARCHAR(150),
        contact_phone VARCHAR(50),
        reservations_email VARCHAR(150),
        reservations_phone VARCHAR(50),
        finance_email VARCHAR(150),
        finance_phone VARCHAR(50),
        website VARCHAR(300),
        payment_terms TEXT,
        bank_details JSON,
        tax_id VARCHAR(60),
        preferred_currency VARCHAR(10) DEFAULT 'USD',
        commission_rate DECIMAL(5,2) DEFAULT 0,
        credit_limit DECIMAL(12,2) DEFAULT 0,
        rating TINYINT,
        tags JSON,
        notes TEXT,
        is_active TINYINT(1) DEFAULT 1,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_branch (branch_id),
        INDEX idx_brand (brand_id),
        INDEX idx_accommodation (accommodation_id),
        INDEX idx_type (supplier_type)
    )",

    // Supplier contacts (multiple contacts per supplier)
    "CREATE TABLE IF NOT EXISTS supplier_contact_persons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        brand_id INT,
        full_name VARCHAR(150) NOT NULL,
        role VARCHAR(80),
        department VARCHAR(80),
        email VARCHAR(150),
        phone VARCHAR(50),
        is_primary TINYINT(1) DEFAULT 0,
        notes TEXT,
        INDEX idx_supplier (supplier_id),
        INDEX idx_brand (brand_id)
    )",

    // Supplier documents (contracts, agreements, etc.)
    "CREATE TABLE IF NOT EXISTS supplier_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT,
        brand_id INT,
        contract_id INT,
        document_type VARCHAR(60),
        document_name VARCHAR(200),
        file_url VARCHAR(500),
        file_size INT,
        valid_from DATE,
        valid_to DATE,
        notes TEXT,
        uploaded_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_supplier (supplier_id),
        INDEX idx_brand (brand_id)
    )",
];

// Add supplier linkage to existing tables
$alterations = [
    "ALTER TABLE content_accommodations ADD COLUMN supplier_id INT AFTER branch_id",
    "ALTER TABLE content_accommodations ADD COLUMN brand_id INT AFTER supplier_id",
    "ALTER TABLE content_accommodations ADD INDEX idx_supplier (supplier_id)",
    "ALTER TABLE content_accommodations ADD INDEX idx_brand (brand_id)",
    "ALTER TABLE rate_contracts ADD COLUMN supplier_id INT AFTER accommodation_id",
    "ALTER TABLE rate_contracts ADD COLUMN brand_id INT AFTER supplier_id",
    "ALTER TABLE rate_contracts ADD INDEX idx_supplier (supplier_id)",
    "ALTER TABLE rate_contracts ADD INDEX idx_brand (brand_id)",
    "ALTER TABLE content_activities ADD COLUMN supplier_id INT AFTER branch_id",
    "ALTER TABLE content_activities ADD INDEX idx_supplier_act (supplier_id)",
    "ALTER TABLE supplier_payments ADD COLUMN supplier_id_ref INT AFTER accommodation_id",
    "ALTER TABLE supplier_payments ADD COLUMN brand_id INT AFTER supplier_id_ref",
];

$created = [];
$errors = [];

foreach ($tables as $sql) {
    try {
        $pdo->exec($sql);
        preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m);
        $created[] = $m[1] ?? 'unknown';
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

foreach ($alterations as $sql) {
    try {
        $pdo->exec($sql);
    } catch (Exception $e) {
        // Column likely already exists
    }
}

jsonResponse([
    'message' => 'Supplier tables setup complete',
    'tables_created' => $created,
    'errors' => $errors
]);
