<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAdmin();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, role, phone, is_active, created_at
        FROM users
        WHERE branch_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$bid]);

    jsonResponse(['data' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['full_name', 'email', 'password', 'role']);

    // Validate role
    $validRoles = ['admin', 'sales', 'operations', 'finance', 'viewer'];
    if (!in_array($data['role'], $validRoles)) jsonError('Invalid role', 400);

    // Check duplicate email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$data['email']]);
    if ($stmt->fetch()) jsonError('Email already exists', 409);

    $hash = password_hash($data['password'], PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (branch_id, full_name, email, password_hash, role, phone)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        $data['full_name'],
        $data['email'],
        $hash,
        $data['role'],
        $data['phone'] ?? null,
    ]);

    jsonResponse(['message' => 'User created', 'id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    $data = getJsonInput();

    // Branch settings update
    if (!empty($data['update_branch'])) {
        $branchFields = [];
        $branchParams = [];
        $allowed = ['company_name', 'email', 'phone', 'address', 'website', 'country', 'currency', 'logo_url', 'quote_color', 'quote_font'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $branchFields[] = "{$f} = ?";
                $branchParams[] = $data[$f];
            }
        }
        if (!empty($branchFields)) {
            $branchParams[] = $bid;
            $pdo->prepare("UPDATE branches SET " . implode(', ', $branchFields) . " WHERE id = ?")->execute($branchParams);
        }
        jsonResponse(['message' => 'Branch settings updated']);
    }

    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Verify user belongs to branch
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('User not found', 404);

    $fields = [];
    $params = [];
    $allowed = ['full_name', 'email', 'role', 'phone', 'is_active'];

    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "{$f} = ?";
            $params[] = $data[$f];
        }
    }

    // If role is provided, validate it
    if (isset($data['role'])) {
        $validRoles = ['admin', 'sales', 'operations', 'finance', 'viewer'];
        if (!in_array($data['role'], $validRoles)) jsonError('Invalid role', 400);
    }

    // If email is being changed, check for duplicates
    if (isset($data['email'])) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$data['email'], $id]);
        if ($stmt->fetch()) jsonError('Email already exists', 409);
    }

    // If password provided, hash and update
    if (!empty($data['password'])) {
        $fields[] = "password_hash = ?";
        $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
    }

    if (empty($fields)) jsonError('No fields to update', 400);

    $params[] = $id;
    $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    jsonResponse(['message' => 'User updated']);
}
