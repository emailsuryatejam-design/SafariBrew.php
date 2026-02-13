<?php
/**
 * Migration: Add rate_type support to quotes and quote_lines.
 * Also creates a rate_types reference table for managing available types.
 */
require_once __DIR__ . '/config.php';

$pdo = getDB();

$alterations = [
    // Reference table for rate types
    "CREATE TABLE IF NOT EXISTS rate_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        type_code VARCHAR(40) NOT NULL,
        type_label VARCHAR(100) NOT NULL,
        description TEXT,
        is_default TINYINT(1) DEFAULT 0,
        sort_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_branch_code (branch_id, type_code),
        INDEX idx_branch (branch_id)
    )",

    // Add rate_type to quotes (default for entire quote)
    "ALTER TABLE quotes ADD COLUMN rate_type VARCHAR(40) DEFAULT NULL AFTER currency",

    // Add rate_type to quote_lines (per-line override)
    "ALTER TABLE quote_lines ADD COLUMN rate_type VARCHAR(40) DEFAULT NULL AFTER service_type",

    // Add accommodation_id to quote_lines for rate lookups
    "ALTER TABLE quote_lines ADD COLUMN accommodation_id INT DEFAULT NULL AFTER rate_type",

    // Add contract_id to quote_lines to track which contract rate was pulled from
    "ALTER TABLE quote_lines ADD COLUMN contract_id INT DEFAULT NULL AFTER accommodation_id",
];

$created = [];
$errors = [];

foreach ($alterations as $sql) {
    try {
        $pdo->exec($sql);
        if (strpos($sql, 'CREATE TABLE') !== false) {
            preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m);
            $created[] = 'Created table: ' . ($m[1] ?? 'unknown');
        } else {
            $created[] = 'Altered: ' . substr($sql, 0, 60) . '...';
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// Seed default rate types if table is empty
try {
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM rate_types");
    if ((int)$stmt->fetch()['cnt'] === 0) {
        $defaults = [
            ['STO', 'STO (Safari Tour Operator)', 'Standard tour operator contracted rates', 1, 1],
            ['international', 'International', 'International / non-resident rates', 0, 2],
            ['rack', 'Rack Rate', 'Published rack rates (walk-in)', 0, 3],
            ['resident', 'Resident', 'Resident / local national rates', 0, 4],
            ['corporate', 'Corporate', 'Corporate / business rates', 0, 5],
        ];

        // Get all branch IDs
        $branches = $pdo->query("SELECT id FROM branches")->fetchAll();
        $stmt = $pdo->prepare("INSERT INTO rate_types (branch_id, type_code, type_label, description, is_default, sort_order) VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($branches as $branch) {
            foreach ($defaults as $rt) {
                $stmt->execute([$branch['id'], $rt[0], $rt[1], $rt[2], $rt[3], $rt[4]]);
            }
        }
        $created[] = 'Seeded default rate types';
    }
} catch (Exception $e) {
    $errors[] = 'Seed rate types: ' . $e->getMessage();
}

jsonResponse([
    'message' => 'Rate types migration complete',
    'created' => $created,
    'errors' => $errors,
]);
