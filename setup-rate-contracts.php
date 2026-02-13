<?php
require_once __DIR__ . '/config.php';

$pdo = getDB();

$tables = [
    // Master contract record - one per uploaded PDF
    "CREATE TABLE IF NOT EXISTS rate_contracts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        accommodation_id INT,
        contract_code VARCHAR(20),
        property_name VARCHAR(200),
        contract_type VARCHAR(40) DEFAULT 'STO',
        validity_start DATE,
        validity_end DATE,
        currency VARCHAR(10) DEFAULT 'USD',
        secondary_currency VARCHAR(10),
        rate_basis VARCHAR(40) DEFAULT 'net',
        market VARCHAR(100),
        status ENUM('draft','extracted','verified','active','expired','archived') DEFAULT 'draft',
        extraction_mode ENUM('manual','ai_brew') DEFAULT 'manual',
        extraction_status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
        extraction_raw JSON,
        original_file_url VARCHAR(500),
        original_file_name VARCHAR(200),
        file_size INT,
        notes TEXT,
        tax_info JSON,
        contact_info JSON,
        booking_procedures JSON,
        terms_conditions TEXT,
        confidentiality_note TEXT,
        governing_law VARCHAR(200),
        rate_variation_clause TEXT,
        signatory_info JSON,
        uploaded_by INT,
        verified_by INT,
        verified_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_branch (branch_id),
        INDEX idx_accommodation (accommodation_id),
        INDEX idx_status (status),
        INDEX idx_validity (validity_start, validity_end)
    )",

    // Room types defined in the contract
    "CREATE TABLE IF NOT EXISTS contract_room_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        room_name VARCHAR(150) NOT NULL,
        room_category VARCHAR(80),
        max_occupancy INT DEFAULT 2,
        bed_config VARCHAR(100),
        description TEXT,
        sort_order INT DEFAULT 0,
        INDEX idx_contract (contract_id)
    )",

    // Seasons defined in the contract
    "CREATE TABLE IF NOT EXISTS contract_seasons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        season_name VARCHAR(80) NOT NULL,
        season_type ENUM('peak','high','mid','low','green','festive','special') DEFAULT 'mid',
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        notes TEXT,
        sort_order INT DEFAULT 0,
        INDEX idx_contract (contract_id),
        INDEX idx_dates (start_date, end_date)
    )",

    // Core rate table: intersection of room_type x season x meal_plan
    "CREATE TABLE IF NOT EXISTS contract_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        room_type_id INT,
        season_id INT,
        meal_plan ENUM('RO','BB','HB','FB','AI') DEFAULT 'FB',
        rate_basis ENUM('per_person','per_room') DEFAULT 'per_person',
        rate_pps DECIMAL(10,2) DEFAULT 0,
        rate_single DECIMAL(10,2) DEFAULT 0,
        rate_double DECIMAL(10,2) DEFAULT 0,
        rate_triple DECIMAL(10,2) DEFAULT 0,
        rate_child DECIMAL(10,2) DEFAULT 0,
        rate_infant DECIMAL(10,2) DEFAULT 0,
        rate_single_supplement DECIMAL(10,2) DEFAULT 0,
        rate_extra_bed DECIMAL(10,2) DEFAULT 0,
        rate_extra_adult DECIMAL(10,2) DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'USD',
        min_nights INT DEFAULT 1,
        notes TEXT,
        INDEX idx_contract (contract_id),
        INDEX idx_room_season (room_type_id, season_id)
    )",

    // Meal plan supplement rates
    "CREATE TABLE IF NOT EXISTS contract_meal_supplements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        from_plan ENUM('RO','BB','HB','FB','AI'),
        to_plan ENUM('RO','BB','HB','FB','AI'),
        supplement_adult DECIMAL(10,2) DEFAULT 0,
        supplement_child DECIMAL(10,2) DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // A-la-carte meal pricing
    "CREATE TABLE IF NOT EXISTS contract_meal_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        meal_type VARCHAR(60) NOT NULL,
        rate_adult DECIMAL(10,2) DEFAULT 0,
        rate_child DECIMAL(10,2) DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // Child policy brackets
    "CREATE TABLE IF NOT EXISTS contract_child_policies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        age_from INT DEFAULT 0,
        age_to INT DEFAULT 0,
        policy_type ENUM('free','percentage','fixed','full_rate') DEFAULT 'percentage',
        sharing_with_1_adult DECIMAL(5,2) DEFAULT 0,
        sharing_with_2_adults DECIMAL(5,2) DEFAULT 0,
        own_room DECIMAL(5,2) DEFAULT 0,
        fixed_rate DECIMAL(10,2) DEFAULT 0,
        max_children_per_room INT,
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // Special season supplements (Easter, Christmas, etc.)
    "CREATE TABLE IF NOT EXISTS contract_special_supplements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        supplement_name VARCHAR(100) NOT NULL,
        supplement_type ENUM('fixed_per_night','fixed_total','percentage') DEFAULT 'fixed_per_night',
        amount DECIMAL(10,2) DEFAULT 0,
        percentage DECIMAL(5,2) DEFAULT 0,
        start_date DATE,
        end_date DATE,
        applies_to VARCHAR(60) DEFAULT 'all',
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // Offers: group discounts, fam trips, long stay, etc.
    "CREATE TABLE IF NOT EXISTS contract_offers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        offer_name VARCHAR(150) NOT NULL,
        offer_type ENUM('group_discount','fam_trip','honeymoon','long_stay','early_bird','repeat_guest','free_night','complimentary','other') DEFAULT 'other',
        discount_type ENUM('percentage','fixed','free_nights','complimentary_service') DEFAULT 'percentage',
        discount_value DECIMAL(10,2) DEFAULT 0,
        min_pax INT,
        min_nights INT,
        conditions TEXT,
        valid_start DATE,
        valid_end DATE,
        blackout_dates JSON,
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // Tour leader / group companion rates
    "CREATE TABLE IF NOT EXISTS contract_tour_leader_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        min_pax_for_free INT,
        free_rooms INT DEFAULT 1,
        reduced_rate DECIMAL(10,2) DEFAULT 0,
        reduced_rate_below_pax INT,
        driver_guide_rate DECIMAL(10,2) DEFAULT 0,
        driver_guide_meal_included TINYINT(1) DEFAULT 1,
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // Activities and extras offered by the property
    "CREATE TABLE IF NOT EXISTS contract_activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        activity_name VARCHAR(150) NOT NULL,
        category VARCHAR(60),
        rate_adult DECIMAL(10,2) DEFAULT 0,
        rate_child DECIMAL(10,2) DEFAULT 0,
        rate_per_vehicle DECIMAL(10,2) DEFAULT 0,
        min_pax INT,
        duration VARCHAR(60),
        included_in_package TINYINT(1) DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // Park / concession / conservation fees
    "CREATE TABLE IF NOT EXISTS contract_park_fees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        fee_name VARCHAR(150) NOT NULL,
        fee_type ENUM('park_entry','concession','conservation','camping','infrastructure','bed_levy','other') DEFAULT 'park_entry',
        rate_adult DECIMAL(10,2) DEFAULT 0,
        rate_child DECIMAL(10,2) DEFAULT 0,
        child_age_limit INT DEFAULT 12,
        per_unit ENUM('per_person_per_day','per_person_per_stay','per_vehicle','flat') DEFAULT 'per_person_per_day',
        high_season_rate_adult DECIMAL(10,2) DEFAULT 0,
        high_season_rate_child DECIMAL(10,2) DEFAULT 0,
        included_in_rate TINYINT(1) DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // Transfer rates
    "CREATE TABLE IF NOT EXISTS contract_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        transfer_name VARCHAR(150) NOT NULL,
        transfer_type ENUM('airport','inter_hotel','road','charter','boat','other') DEFAULT 'road',
        rate_per_person DECIMAL(10,2) DEFAULT 0,
        rate_per_vehicle DECIMAL(10,2) DEFAULT 0,
        vehicle_capacity INT,
        distance VARCHAR(60),
        duration VARCHAR(60),
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // Cancellation policy tiers
    "CREATE TABLE IF NOT EXISTS contract_cancellation_policies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        days_before_from INT NOT NULL,
        days_before_to INT,
        charge_type ENUM('percentage','fixed','free') DEFAULT 'percentage',
        charge_value DECIMAL(10,2) DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        sort_order INT DEFAULT 0,
        INDEX idx_contract (contract_id)
    )",

    // Payment terms from contract
    "CREATE TABLE IF NOT EXISTS contract_payment_terms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        term_type ENUM('deposit','balance','full_payment','other') DEFAULT 'deposit',
        percentage DECIMAL(5,2) DEFAULT 0,
        fixed_amount DECIMAL(10,2) DEFAULT 0,
        days_before_arrival INT,
        payment_methods JSON,
        bank_details JSON,
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // Check-in/out and day-use policies
    "CREATE TABLE IF NOT EXISTS contract_policies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        policy_type VARCHAR(60) NOT NULL,
        policy_value TEXT NOT NULL,
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // Resident rates (special local pricing)
    "CREATE TABLE IF NOT EXISTS contract_resident_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        room_type_id INT,
        season_id INT,
        meal_plan ENUM('RO','BB','HB','FB','AI') DEFAULT 'FB',
        rate_adult DECIMAL(10,2) DEFAULT 0,
        rate_child DECIMAL(10,2) DEFAULT 0,
        eligible_nationalities JSON,
        required_documents JSON,
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // Free-form items that don't fit categories above
    "CREATE TABLE IF NOT EXISTS contract_other_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        category VARCHAR(80) NOT NULL,
        item_name VARCHAR(200) NOT NULL,
        item_value TEXT,
        amount DECIMAL(10,2),
        currency VARCHAR(10) DEFAULT 'USD',
        notes TEXT,
        INDEX idx_contract (contract_id)
    )",

    // Audit trail for contract changes
    "CREATE TABLE IF NOT EXISTS contract_audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        user_id INT,
        action VARCHAR(60) NOT NULL,
        details JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_contract (contract_id)
    )",
];

// Add seasonal_rates table if missing (referenced by existing system)
$tables[] = "CREATE TABLE IF NOT EXISTS seasonal_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    accommodation_id INT NOT NULL,
    contract_id INT,
    season_name VARCHAR(80),
    start_date DATE,
    end_date DATE,
    rate_adult DECIMAL(10,2) DEFAULT 0,
    rate_child DECIMAL(10,2) DEFAULT 0,
    rate_infant DECIMAL(10,2) DEFAULT 0,
    rate_single_supplement DECIMAL(10,2) DEFAULT 0,
    rate_extra_bed DECIMAL(10,2) DEFAULT 0,
    currency VARCHAR(10) DEFAULT 'USD',
    min_nights INT DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_branch (branch_id),
    INDEX idx_accommodation (accommodation_id),
    INDEX idx_contract (contract_id)
)";

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

// Add contract_id column to seasonal_rates if it doesn't exist
try {
    $pdo->exec("ALTER TABLE seasonal_rates ADD COLUMN contract_id INT AFTER accommodation_id");
    $pdo->exec("ALTER TABLE seasonal_rates ADD INDEX idx_contract (contract_id)");
} catch (Exception $e) {
    // Column likely already exists
}

jsonResponse([
    'message' => 'Rate contract tables setup complete',
    'tables_created' => $created,
    'errors' => $errors
]);
