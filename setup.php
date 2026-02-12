<?php
require_once __DIR__ . '/config.php';

$pdo = getDB();

$tables = [
    "CREATE TABLE IF NOT EXISTS branches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        company_name VARCHAR(150),
        logo_url VARCHAR(500),
        country VARCHAR(60),
        currency VARCHAR(10) DEFAULT 'USD',
        email VARCHAR(150),
        phone VARCHAR(30),
        address TEXT,
        website VARCHAR(200),
        quote_color VARCHAR(7) DEFAULT '#b45309',
        quote_font VARCHAR(50) DEFAULT 'Inter',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(150) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','sales','operations','finance','viewer') DEFAULT 'sales',
        phone VARCHAR(30),
        avatar_url VARCHAR(500),
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_branch (branch_id)
    )",

    "CREATE TABLE IF NOT EXISTS clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        first_name VARCHAR(80) NOT NULL,
        last_name VARCHAR(80),
        email VARCHAR(150),
        phone VARCHAR(30),
        country VARCHAR(60),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_branch (branch_id)
    )",

    "CREATE TABLE IF NOT EXISTS requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        client_id INT,
        request_code VARCHAR(20),
        status ENUM('new','working_on','open','booked','completed','not_booked') DEFAULT 'new',
        travel_start DATE,
        travel_end DATE,
        pax_adults INT DEFAULT 1,
        pax_children INT DEFAULT 0,
        pax_babies INT DEFAULT 0,
        countries JSON,
        destinations JSON,
        tour_type VARCHAR(60),
        source VARCHAR(60),
        assigned_user_id INT,
        budget_currency VARCHAR(10) DEFAULT 'USD',
        budget_amount DECIMAL(12,2),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_branch_status (branch_id, status),
        INDEX idx_travel (travel_start, travel_end)
    )",

    "CREATE TABLE IF NOT EXISTS request_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        user_id INT NOT NULL,
        note TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_request (request_id)
    )",

    "CREATE TABLE IF NOT EXISTS content_destinations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        country VARCHAR(60),
        description TEXT,
        cover_image VARCHAR(500),
        latitude DECIMAL(10,7),
        longitude DECIMAL(10,7),
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_branch (branch_id)
    )",

    "CREATE TABLE IF NOT EXISTS content_accommodations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        destination_id INT,
        acc_type VARCHAR(40),
        star_rating TINYINT,
        description TEXT,
        cover_image VARCHAR(500),
        country VARCHAR(60),
        region VARCHAR(80),
        contact_email VARCHAR(150),
        contact_phone VARCHAR(30),
        board_basis VARCHAR(20) DEFAULT 'FB',
        default_rate_adult DECIMAL(10,2),
        default_rate_child DECIMAL(10,2),
        default_currency VARCHAR(10) DEFAULT 'USD',
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_branch (branch_id)
    )",

    "CREATE TABLE IF NOT EXISTS content_activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        category VARCHAR(60),
        description TEXT,
        cover_image VARCHAR(500),
        duration VARCHAR(40),
        default_rate DECIMAL(10,2),
        default_currency VARCHAR(10) DEFAULT 'USD',
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_branch (branch_id)
    )",

    "CREATE TABLE IF NOT EXISTS content_media (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        entity_type ENUM('destination','accommodation','activity','template') NOT NULL,
        entity_id INT NOT NULL,
        file_url VARCHAR(500) NOT NULL,
        file_name VARCHAR(200),
        mime_type VARCHAR(50),
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_entity (entity_type, entity_id)
    )",

    "CREATE TABLE IF NOT EXISTS tour_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        tour_type VARCHAR(60),
        status ENUM('draft','active','locked') DEFAULT 'draft',
        duration_days INT DEFAULT 1,
        duration_nights INT DEFAULT 0,
        countries JSON,
        start_destination VARCHAR(100),
        end_destination VARCHAR(100),
        description TEXT,
        cover_image VARCHAR(500),
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_branch_status (branch_id, status)
    )",

    "CREATE TABLE IF NOT EXISTS template_days (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_id INT NOT NULL,
        day_number INT NOT NULL,
        destination_id INT,
        destination_name VARCHAR(100),
        description TEXT,
        sort_order INT DEFAULT 0,
        INDEX idx_template (template_id)
    )",

    "CREATE TABLE IF NOT EXISTS template_day_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_day_id INT NOT NULL,
        service_type ENUM('accommodation','activity','transfer','meal','park_fee','other') NOT NULL,
        accommodation_id INT,
        activity_id INT,
        title VARCHAR(150),
        description TEXT,
        nights INT DEFAULT 0,
        room_type VARCHAR(60),
        board_basis VARCHAR(20),
        meal_breakfast TINYINT(1) DEFAULT 0,
        meal_lunch TINYINT(1) DEFAULT 0,
        meal_dinner TINYINT(1) DEFAULT 0,
        sort_order INT DEFAULT 0,
        INDEX idx_day (template_day_id)
    )",

    "CREATE TABLE IF NOT EXISTS quotes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        request_id INT,
        template_id INT,
        quote_code VARCHAR(20),
        status ENUM('draft','sent','accepted','rejected','expired') DEFAULT 'draft',
        version INT DEFAULT 1,
        currency VARCHAR(10) DEFAULT 'USD',
        subtotal DECIMAL(12,2) DEFAULT 0,
        tax_amount DECIMAL(12,2) DEFAULT 0,
        total DECIMAL(12,2) DEFAULT 0,
        valid_until DATE,
        payment_terms TEXT,
        terms_conditions TEXT,
        notes TEXT,
        created_by INT,
        sent_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_branch_status (branch_id, status),
        INDEX idx_request (request_id)
    )",

    "CREATE TABLE IF NOT EXISTS quote_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quote_id INT NOT NULL,
        day_number INT,
        service_type VARCHAR(30),
        title VARCHAR(150) NOT NULL,
        description TEXT,
        traveler_type ENUM('adult','child','baby','senior','discount','flat') DEFAULT 'adult',
        qty INT DEFAULT 1,
        nights INT DEFAULT 1,
        unit_price DECIMAL(10,2) DEFAULT 0,
        markup_type ENUM('markup','margin','none') DEFAULT 'none',
        markup_value DECIMAL(5,2) DEFAULT 0,
        sell_price DECIMAL(10,2) DEFAULT 0,
        line_total DECIMAL(12,2) DEFAULT 0,
        sort_order INT DEFAULT 0,
        INDEX idx_quote (quote_id)
    )",

    "CREATE TABLE IF NOT EXISTS quote_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quote_id INT NOT NULL,
        group_name VARCHAR(100),
        option_name VARCHAR(150) NOT NULL,
        price DECIMAL(10,2) DEFAULT 0,
        sort_order INT DEFAULT 0,
        INDEX idx_quote (quote_id)
    )",

    "CREATE TABLE IF NOT EXISTS quote_inclusions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quote_id INT NOT NULL,
        item_text VARCHAR(300) NOT NULL,
        is_included TINYINT(1) DEFAULT 1,
        sort_order INT DEFAULT 0,
        INDEX idx_quote (quote_id)
    )",

    "CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        request_id INT,
        quote_id INT,
        invoice_code VARCHAR(20),
        client_id INT,
        status ENUM('draft','sent','paid','partial','overdue','cancelled') DEFAULT 'draft',
        currency VARCHAR(10) DEFAULT 'USD',
        subtotal DECIMAL(12,2) DEFAULT 0,
        tax_amount DECIMAL(12,2) DEFAULT 0,
        total DECIMAL(12,2) DEFAULT 0,
        amount_paid DECIMAL(12,2) DEFAULT 0,
        due_date DATE,
        notes TEXT,
        created_by INT,
        sent_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_branch_status (branch_id, status)
    )",

    "CREATE TABLE IF NOT EXISTS invoice_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        description VARCHAR(300) NOT NULL,
        qty INT DEFAULT 1,
        unit_price DECIMAL(10,2) DEFAULT 0,
        line_total DECIMAL(12,2) DEFAULT 0,
        sort_order INT DEFAULT 0,
        INDEX idx_invoice (invoice_id)
    )",

    "CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        invoice_id INT,
        client_id INT,
        amount DECIMAL(12,2) NOT NULL,
        currency VARCHAR(10) DEFAULT 'USD',
        payment_method VARCHAR(40),
        payment_date DATE NOT NULL,
        reference VARCHAR(100),
        notes TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_branch (branch_id),
        INDEX idx_invoice (invoice_id)
    )",

    "CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        category VARCHAR(60),
        description VARCHAR(300) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        currency VARCHAR(10) DEFAULT 'USD',
        expense_date DATE NOT NULL,
        supplier_name VARCHAR(150),
        receipt_url VARCHAR(500),
        notes TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_branch_date (branch_id, expense_date)
    )",

    "CREATE TABLE IF NOT EXISTS supplier_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        accommodation_id INT,
        supplier_name VARCHAR(150) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        currency VARCHAR(10) DEFAULT 'USD',
        payment_date DATE NOT NULL,
        reference VARCHAR(100),
        request_id INT,
        notes TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_branch (branch_id)
    )",

    "CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        setting_key VARCHAR(80) NOT NULL,
        setting_value TEXT,
        UNIQUE KEY uk_branch_key (branch_id, setting_key)
    )",
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

// Seed default branch
try {
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM branches");
    if ($stmt->fetch()['cnt'] === 0) {
        $pdo->exec("INSERT INTO branches (name, company_name, currency) VALUES ('Main', 'SafariBrew', 'USD')");
    }
} catch (Exception $e) {
    $errors[] = 'Branch seed: ' . $e->getMessage();
}

// Seed admin user (password: admin123)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM users");
    if ($stmt->fetch()['cnt'] === 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (branch_id, full_name, email, password_hash, role) VALUES (1, 'Admin', 'admin@safaribrew.com', ?, 'admin')");
        $stmt->execute([$hash]);
    }
} catch (Exception $e) {
    $errors[] = 'User seed: ' . $e->getMessage();
}

jsonResponse([
    'message' => 'Setup complete',
    'tables_created' => $created,
    'errors' => $errors
]);
