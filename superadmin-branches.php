<?php
/**
 * Superadmin: Manage branches (companies).
 *
 * GET  /superadmin-branches.php             → list all branches with modules, user counts, plan
 * GET  /superadmin-branches.php?id=X        → single branch detail
 * PUT  /superadmin-branches.php?id=X        → update branch plan, max_users, is_active, notes
 * POST /superadmin-branches.php?id=X&action=modules → set modules for a branch
 *   Body: { "modules": { "crm": true, "finance": false, ... } }
 */
require_once __DIR__ . '/middleware.php';

$auth = requireSuperadmin();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// All module names
$ALL_MODULES = ['core', 'crm', 'content', 'rate_management', 'quoting', 'finance', 'ai_brew'];

$MODULE_META = [
    'core'            => ['label' => 'Core',            'tier' => 'free'],
    'crm'             => ['label' => 'CRM',             'tier' => 'starter'],
    'content'         => ['label' => 'Content Library',  'tier' => 'starter'],
    'rate_management' => ['label' => 'Rate Management',  'tier' => 'pro'],
    'quoting'         => ['label' => 'Quoting',          'tier' => 'pro'],
    'finance'         => ['label' => 'Finance',          'tier' => 'pro'],
    'ai_brew'         => ['label' => 'AI Brew',          'tier' => 'addon'],
];

// ── GET: list all branches ──────────────────────────────────────────────
if ($method === 'GET') {
    $branchId = $_GET['id'] ?? null;

    if ($branchId) {
        // Single branch detail
        $stmt = $pdo->prepare("
            SELECT b.*,
                   (SELECT COUNT(*) FROM users WHERE branch_id = b.id AND is_active = 1) as user_count
            FROM branches b WHERE b.id = ?
        ");
        $stmt->execute([$branchId]);
        $branch = $stmt->fetch();
        if (!$branch) jsonError('Branch not found', 404);

        // Get modules
        $mStmt = $pdo->prepare("SELECT module_name, is_active, activated_at, expires_at FROM branch_modules WHERE branch_id = ?");
        $mStmt->execute([$branchId]);
        $moduleRows = $mStmt->fetchAll();
        $moduleMap = [];
        foreach ($moduleRows as $m) {
            $moduleMap[$m['module_name']] = $m;
        }

        $modules = [];
        foreach ($ALL_MODULES as $mod) {
            $row = $moduleMap[$mod] ?? null;
            $modules[$mod] = [
                'is_active'    => $row ? (bool)(int)$row['is_active'] : false,
                'label'        => $MODULE_META[$mod]['label'] ?? $mod,
                'tier'         => $MODULE_META[$mod]['tier'] ?? 'unknown',
                'activated_at' => $row['activated_at'] ?? null,
                'expires_at'   => $row['expires_at'] ?? null,
            ];
        }

        // Get wallet balance
        $wBalance = 0;
        try {
            $wStmt = $pdo->prepare("SELECT balance FROM wallet_balances WHERE branch_id = ?");
            $wStmt->execute([$branchId]);
            $wr = $wStmt->fetch();
            if ($wr) $wBalance = (float)$wr['balance'];
        } catch (Exception $e) {}

        jsonResponse([
            'branch' => [
                'id'           => (int)$branch['id'],
                'name'         => $branch['name'],
                'company_name' => $branch['company_name'],
                'email'        => $branch['email'],
                'phone'        => $branch['phone'],
                'country'      => $branch['country'],
                'currency'     => $branch['currency'],
                'plan'         => $branch['plan'] ?? 'trial',
                'max_users'    => (int)($branch['max_users'] ?? 5),
                'is_active'    => (bool)(int)($branch['is_active'] ?? 1),
                'notes'        => $branch['notes'] ?? '',
                'user_count'   => (int)$branch['user_count'],
                'wallet_balance' => $wBalance,
                'created_at'   => $branch['created_at'],
            ],
            'modules' => $modules,
        ]);
    } else {
        // List all branches
        $stmt = $pdo->query("
            SELECT b.*,
                   (SELECT COUNT(*) FROM users WHERE branch_id = b.id AND is_active = 1) as user_count
            FROM branches b
            ORDER BY b.id ASC
        ");
        $branches = $stmt->fetchAll();

        // Get modules per branch
        $allModules = $pdo->query("SELECT branch_id, module_name, is_active FROM branch_modules")->fetchAll();
        $branchModuleMap = [];
        foreach ($allModules as $m) {
            $branchModuleMap[$m['branch_id']][$m['module_name']] = (bool)(int)$m['is_active'];
        }

        // Get wallet balances
        $walletBalances = [];
        try {
            $wStmt = $pdo->query("SELECT branch_id, balance FROM wallet_balances");
            foreach ($wStmt->fetchAll() as $wr) {
                $walletBalances[$wr['branch_id']] = (float)$wr['balance'];
            }
        } catch (Exception $e) {}

        $result = [];
        foreach ($branches as $b) {
            $activeModules = [];
            foreach ($ALL_MODULES as $mod) {
                if (!empty($branchModuleMap[$b['id']][$mod])) {
                    $activeModules[] = $mod;
                }
            }

            $result[] = [
                'id'             => (int)$b['id'],
                'name'           => $b['name'],
                'company_name'   => $b['company_name'],
                'email'          => $b['email'],
                'country'        => $b['country'],
                'plan'           => $b['plan'] ?? 'trial',
                'max_users'      => (int)($b['max_users'] ?? 5),
                'is_active'      => (bool)(int)($b['is_active'] ?? 1),
                'user_count'     => (int)$b['user_count'],
                'active_modules' => $activeModules,
                'wallet_balance' => $walletBalances[$b['id']] ?? 0,
                'created_at'     => $b['created_at'],
            ];
        }

        jsonResponse(['branches' => $result]);
    }
}

// ── PUT: update branch settings ─────────────────────────────────────────
if ($method === 'PUT') {
    $branchId = $_GET['id'] ?? null;
    if (!$branchId) jsonError('Branch ID required', 400);

    $input = json_decode(file_get_contents('php://input'), true);

    $updates = [];
    $params = [];

    if (isset($input['plan'])) {
        $allowed = ['trial', 'starter', 'pro', 'enterprise', 'custom'];
        if (!in_array($input['plan'], $allowed)) jsonError('Invalid plan', 400);
        $updates[] = 'plan = ?';
        $params[] = $input['plan'];
    }
    if (isset($input['max_users'])) {
        $maxUsers = (int)$input['max_users'];
        if ($maxUsers < 1 || $maxUsers > 500) jsonError('max_users must be 1-500', 400);
        $updates[] = 'max_users = ?';
        $params[] = $maxUsers;
    }
    if (isset($input['is_active'])) {
        $updates[] = 'is_active = ?';
        $params[] = $input['is_active'] ? 1 : 0;
    }
    if (isset($input['notes'])) {
        $updates[] = 'notes = ?';
        $params[] = $input['notes'];
    }

    if (empty($updates)) jsonError('No fields to update', 400);

    $params[] = $branchId;
    $sql = "UPDATE branches SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse(['message' => 'Branch updated', 'branch_id' => (int)$branchId]);
}

// ── POST: set modules for a branch ──────────────────────────────────────
if ($method === 'POST') {
    $branchId = $_GET['id'] ?? null;
    $action = $_GET['action'] ?? '';

    if (!$branchId) jsonError('Branch ID required', 400);

    if ($action === 'modules') {
        $input = json_decode(file_get_contents('php://input'), true);
        $moduleStates = $input['modules'] ?? null;

        if (!is_array($moduleStates)) jsonError('modules object required: { "crm": true, ... }', 400);

        $upsertStmt = $pdo->prepare("
            INSERT INTO branch_modules (branch_id, module_name, is_active, activated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                is_active = VALUES(is_active),
                activated_at = IF(VALUES(is_active) = 1 AND is_active = 0, NOW(), activated_at)
        ");

        $changed = [];
        foreach ($moduleStates as $mod => $active) {
            if (!in_array($mod, $ALL_MODULES)) continue;
            if ($mod === 'core') continue; // core always on
            $upsertStmt->execute([$branchId, $mod, $active ? 1 : 0]);
            $changed[$mod] = (bool)$active;
        }

        // Return updated active module list
        $activeModules = getActiveModules($pdo, $branchId);

        jsonResponse([
            'message' => 'Modules updated for branch ' . $branchId,
            'changed' => $changed,
            'active_modules' => $activeModules,
        ]);
    }

    jsonError('Unknown action', 400);
}

requireMethod('GET');
