<?php
/**
 * Setup wallet tables for AI credit billing.
 * Run once: php setup-wallet.php
 */
require_once __DIR__ . '/config.php';

$pdo = getDB();

$queries = [
    // Wallet balance per branch
    "CREATE TABLE IF NOT EXISTS wallet_balances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        balance DECIMAL(10,4) NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'USD',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_branch (branch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Transaction ledger
    "CREATE TABLE IF NOT EXISTS wallet_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        user_id INT NOT NULL,
        type ENUM('credit','debit','refund') NOT NULL,
        amount DECIMAL(10,4) NOT NULL,
        balance_after DECIMAL(10,4) NOT NULL,
        description VARCHAR(255),
        reference_type VARCHAR(50) DEFAULT NULL,
        reference_id INT DEFAULT NULL,
        input_tokens INT DEFAULT NULL,
        output_tokens INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_branch_date (branch_id, created_at),
        INDEX idx_ref (reference_type, reference_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // AI model pricing (admin-managed)
    "CREATE TABLE IF NOT EXISTS wallet_pricing (
        id INT AUTO_INCREMENT PRIMARY KEY,
        model VARCHAR(50) NOT NULL,
        input_price_per_1m DECIMAL(10,6) NOT NULL DEFAULT 0.150000,
        output_price_per_1m DECIMAL(10,6) NOT NULL DEFAULT 0.600000,
        markup_percent DECIMAL(5,2) NOT NULL DEFAULT 30.00,
        effective_from DATE NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_model_date (model, effective_from)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Seed default pricing for Gemini 2.5 Flash
    "INSERT IGNORE INTO wallet_pricing (model, input_price_per_1m, output_price_per_1m, markup_percent, effective_from, is_active)
     VALUES ('gemini-2.5-flash', 0.150000, 0.600000, 30.00, '2025-01-01', 1)",

    // Seed wallet balance for all existing branches (start with $0)
    "INSERT IGNORE INTO wallet_balances (branch_id, balance)
     SELECT id, 0 FROM branches",
];

header('Content-Type: text/plain');
foreach ($queries as $i => $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: Query " . ($i + 1) . "\n";
    } catch (PDOException $e) {
        echo "ERR: Query " . ($i + 1) . " — " . $e->getMessage() . "\n";
    }
}
echo "\nWallet setup complete.\n";
