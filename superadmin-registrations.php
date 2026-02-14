<?php
/**
 * Superadmin Registration Requests Management
 * GET — list all registration requests
 * POST ?id=X&action=approve — approve a registration (create branch + admin user + modules)
 * POST ?id=X&action=reject — reject a registration
 */
require_once __DIR__ . '/middleware.php';

$auth = requireSuperadmin();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// GET — List registrations
if ($method === 'GET') {
    $status = $_GET['status'] ?? '';

    $where = "1=1";
    $params = [];

    if ($status && $status !== 'all') {
        $where .= " AND status = ?";
        $params[] = $status;
    }

    $stmt = $pdo->prepare("
        SELECT rr.*, b.name AS approved_branch_name
        FROM registration_requests rr
        LEFT JOIN branches b ON b.id = rr.approved_branch_id
        WHERE {$where}
        ORDER BY rr.created_at DESC
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    // Status counts
    $cStmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM registration_requests GROUP BY status");
    $counts = [];
    foreach ($cStmt->fetchAll() as $row) {
        $counts[$row['status']] = (int)$row['cnt'];
    }

    jsonResponse(['data' => $data, 'counts' => $counts]);
}

// POST — Approve or Reject
if ($method === 'POST') {
    $id = (int)($_GET['id'] ?? 0);
    $action = $_GET['action'] ?? '';

    if (!$id) jsonError('Missing registration id', 400);
    if (!in_array($action, ['approve', 'reject'])) jsonError('Invalid action. Use approve or reject.', 400);

    // Get the registration
    $stmt = $pdo->prepare("SELECT * FROM registration_requests WHERE id = ?");
    $stmt->execute([$id]);
    $reg = $stmt->fetch();
    if (!$reg) jsonError('Registration not found', 404);

    if ($reg['status'] !== 'pending') {
        jsonError("Registration is already {$reg['status']}.", 400);
    }

    if ($action === 'reject') {
        $data = getJsonInput();
        $reason = $data['reason'] ?? null;
        $pdo->prepare("UPDATE registration_requests SET status = 'rejected', notes = ? WHERE id = ?")
            ->execute([$reason, $id]);
        jsonResponse(['message' => 'Registration rejected.']);
    }

    // ======= APPROVE =======
    $data = getJsonInput();
    $plan = $data['plan'] ?? 'trial';
    $maxUsers = (int)($data['max_users'] ?? 5);
    $tempPassword = $data['password'] ?? bin2hex(random_bytes(4)); // 8-char hex
    $selectedModules = $data['modules'] ?? ['crm', 'content']; // Default starter modules

    $pdo->beginTransaction();
    try {
        // 1. Create branch
        $stmt = $pdo->prepare("
            INSERT INTO branches (name, company_name, email, phone, country, currency, plan, max_users, is_active)
            VALUES (?, ?, ?, ?, ?, 'USD', ?, ?, 1)
        ");
        $stmt->execute([
            $reg['company_name'],
            $reg['company_name'],
            $reg['email'],
            $reg['phone'],
            $reg['country'],
            $plan,
            $maxUsers,
        ]);
        $branchId = (int)$pdo->lastInsertId();

        // 2. Create admin user
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (branch_id, full_name, email, password_hash, role, is_active)
            VALUES (?, ?, ?, ?, 'admin', 1)
        ");
        $stmt->execute([
            $branchId,
            $reg['contact_name'],
            $reg['email'],
            $passwordHash,
        ]);
        $userId = (int)$pdo->lastInsertId();

        // 3. Seed modules
        // 'core' is always implicitly available (not stored in branch_modules)
        foreach ($selectedModules as $moduleName) {
            if ($moduleName === 'core') continue;
            $stmt = $pdo->prepare("
                INSERT INTO branch_modules (branch_id, module_name, is_active)
                VALUES (?, ?, 1)
            ");
            $stmt->execute([$branchId, $moduleName]);
        }

        // 4. Seed wallet balance ($0)
        try {
            $pdo->prepare("INSERT INTO wallet_balances (branch_id, balance) VALUES (?, 0)")
                ->execute([$branchId]);
        } catch (Exception $e) {
            // Table may not exist — ignore
        }

        // 5. Update registration status
        $pdo->prepare("
            UPDATE registration_requests SET status = 'approved', approved_branch_id = ?, notes = ?
            WHERE id = ?
        ")->execute([$branchId, $data['notes'] ?? null, $id]);

        $pdo->commit();

        jsonResponse([
            'message' => 'Registration approved. Branch and admin user created.',
            'branch_id' => $branchId,
            'user_id' => $userId,
            'temp_password' => $tempPassword,
            'email' => $reg['email'],
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed to approve: ' . $e->getMessage(), 500);
    }
}
