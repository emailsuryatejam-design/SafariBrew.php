<?php
/**
 * Module Management API — admin only.
 *
 * GET  /modules.php              → list all modules for the branch (with status)
 * PUT  /modules.php?module=xyz   → toggle a module on/off
 *   Body: { "is_active": true/false, "expires_at": "2025-12-31" (optional) }
 */
require_once __DIR__ . '/middleware.php';

$auth = requireAdmin();
$pdo = getDB();
$branchId = $auth['branch_id'];

$method = $_SERVER['REQUEST_METHOD'];

// ── All 7 modules with tier/metadata ──────────────────────────────────
$MODULE_META = [
    'core'            => ['label' => 'Core',            'tier' => 'free',    'description' => 'Dashboard, users, settings — always included'],
    'crm'             => ['label' => 'CRM',             'tier' => 'starter', 'description' => 'Requests, clients, calendar, communications'],
    'content'         => ['label' => 'Content Library',  'tier' => 'starter', 'description' => 'Destinations, accommodations, activities catalog'],
    'rate_management' => ['label' => 'Rate Management',  'tier' => 'pro',     'description' => 'Supplier rate contracts with AI extraction'],
    'quoting'         => ['label' => 'Quoting',          'tier' => 'pro',     'description' => 'Quote builder, branded templates, auto-pricing'],
    'finance'         => ['label' => 'Finance',          'tier' => 'pro',     'description' => 'Invoicing, payments, expenses, reports'],
    'ai_brew'         => ['label' => 'AI Brew',          'tier' => 'addon',   'description' => 'AI-powered extraction with prepaid credits'],
];

if ($method === 'GET') {
    // Return all modules with their current status for this branch
    $stmt = $pdo->prepare("
        SELECT module_name, is_active, activated_at, expires_at
        FROM branch_modules
        WHERE branch_id = ?
    ");
    $stmt->execute([$branchId]);
    $rows = $stmt->fetchAll();

    $existing = [];
    foreach ($rows as $r) {
        $existing[$r['module_name']] = $r;
    }

    $modules = [];
    foreach ($MODULE_META as $name => $meta) {
        $row = $existing[$name] ?? null;
        $modules[] = [
            'module_name'  => $name,
            'label'        => $meta['label'],
            'tier'         => $meta['tier'],
            'description'  => $meta['description'],
            'is_active'    => $row ? (bool)(int)$row['is_active'] : false,
            'activated_at' => $row['activated_at'] ?? null,
            'expires_at'   => $row['expires_at'] ?? null,
        ];
    }

    jsonResponse(['modules' => $modules]);
}

if ($method === 'PUT') {
    $moduleName = $_GET['module'] ?? '';
    if (!isset($MODULE_META[$moduleName])) {
        jsonError('Invalid module name', 400);
    }

    // 'core' cannot be disabled
    if ($moduleName === 'core') {
        jsonError('The Core module cannot be disabled', 400);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $isActive = isset($input['is_active']) ? ($input['is_active'] ? 1 : 0) : null;
    $expiresAt = $input['expires_at'] ?? null;

    if ($isActive === null) {
        jsonError('is_active is required (true or false)', 400);
    }

    // Validate expires_at format if provided
    if ($expiresAt !== null && $expiresAt !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $expiresAt);
        if (!$dt) {
            jsonError('expires_at must be in YYYY-MM-DD format', 400);
        }
        $expiresAt = $dt->format('Y-m-d 23:59:59');
    } else {
        $expiresAt = null;
    }

    // Upsert the module row
    $stmt = $pdo->prepare("
        INSERT INTO branch_modules (branch_id, module_name, is_active, activated_at, expires_at)
        VALUES (?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE
            is_active = VALUES(is_active),
            activated_at = IF(VALUES(is_active) = 1 AND is_active = 0, NOW(), activated_at),
            expires_at = VALUES(expires_at)
    ");
    $stmt->execute([$branchId, $moduleName, $isActive, $expiresAt]);

    // Return updated module list
    $updated = getActiveModules($pdo, $branchId);

    jsonResponse([
        'message' => $isActive
            ? "Module '{$MODULE_META[$moduleName]['label']}' activated"
            : "Module '{$MODULE_META[$moduleName]['label']}' deactivated",
        'module_name' => $moduleName,
        'is_active'   => (bool)$isActive,
        'modules'     => $updated,
    ]);
}

requireMethod('GET');
