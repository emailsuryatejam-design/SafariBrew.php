<?php
/**
 * Setup superadmin capabilities + registration system + tour plan:
 * 1. Add is_superadmin column to users table
 * 2. Add max_users and plan columns to branches table
 * 3. Set user #1 as superadmin
 * 4. Create registration_requests table
 * 5. Add tour_plan column to requests table
 *
 * Run once: php setup-superadmin.php
 */
require_once __DIR__ . '/config.php';

$pdo = getDB();
header('Content-Type: text/plain');

$queries = [
    // 1. Add is_superadmin flag to users
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_superadmin TINYINT(1) NOT NULL DEFAULT 0",

    // 2. Add plan + max_users to branches
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS plan VARCHAR(30) NOT NULL DEFAULT 'trial'",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS max_users INT NOT NULL DEFAULT 5",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1",
    "ALTER TABLE branches ADD COLUMN IF NOT EXISTS notes TEXT NULL",

    // 3. Create registration_requests table
    "CREATE TABLE IF NOT EXISTS registration_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name VARCHAR(150) NOT NULL,
        contact_name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL,
        phone VARCHAR(30) NULL,
        country VARCHAR(60) NULL,
        message TEXT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        notes TEXT NULL,
        approved_branch_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // 4. Add tour_plan JSON column to requests
    "ALTER TABLE requests ADD COLUMN IF NOT EXISTS tour_plan JSON NULL",
];

foreach ($queries as $i => $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: Query " . ($i + 1) . "\n";
    } catch (PDOException $e) {
        // Ignore duplicate column errors
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "SKIP: Query " . ($i + 1) . " (column already exists)\n";
        } else {
            echo "ERR: Query " . ($i + 1) . " — " . $e->getMessage() . "\n";
        }
    }
}

// Set the first admin user as superadmin
try {
    $stmt = $pdo->prepare("UPDATE users SET is_superadmin = 1 WHERE id = 1");
    $stmt->execute();
    echo "\nSet user #1 as superadmin.\n";
} catch (PDOException $e) {
    echo "\nERR setting superadmin: " . $e->getMessage() . "\n";
}

echo "\nSetup complete.\n";
