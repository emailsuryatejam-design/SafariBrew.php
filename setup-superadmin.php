<?php
/**
 * Setup superadmin capabilities:
 * 1. Add is_superadmin column to users table
 * 2. Add max_users and plan columns to branches table
 * 3. Set user #1 as superadmin
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

// 3. Set the first admin user as superadmin
try {
    $stmt = $pdo->prepare("UPDATE users SET is_superadmin = 1 WHERE id = 1");
    $stmt->execute();
    echo "\nSet user #1 as superadmin.\n";
} catch (PDOException $e) {
    echo "\nERR setting superadmin: " . $e->getMessage() . "\n";
}

echo "\nSuperadmin setup complete.\n";
