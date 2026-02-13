<?php
/**
 * Setup branch_modules table and seed all modules as active for existing branches.
 * Run once: php setup-modules.php
 */
require_once __DIR__ . '/config.php';

$pdo = getDB();

$queries = [
    // Module definitions per branch
    "CREATE TABLE IF NOT EXISTS branch_modules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        module_name VARCHAR(50) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        activated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uq_branch_module (branch_id, module_name),
        INDEX idx_module (module_name, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
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

// Seed all 7 modules as active for every existing branch
$modules = ['core', 'crm', 'content', 'rate_management', 'quoting', 'finance', 'ai_brew'];

$stmt = $pdo->query("SELECT id FROM branches");
$branches = $stmt->fetchAll();

$insertStmt = $pdo->prepare("INSERT IGNORE INTO branch_modules (branch_id, module_name, is_active) VALUES (?, ?, 1)");

$count = 0;
foreach ($branches as $branch) {
    foreach ($modules as $module) {
        $insertStmt->execute([$branch['id'], $module]);
        $count++;
    }
}

echo "\nSeeded {$count} module entries for " . count($branches) . " branch(es).\n";
echo "Modules: " . implode(', ', $modules) . "\n";
echo "\nModule setup complete.\n";
