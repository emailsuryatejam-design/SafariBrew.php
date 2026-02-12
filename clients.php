<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $search = $_GET['search'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = "branch_id = ?";
    $params = [$bid];

    if ($search) {
        $where .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
        $s = "%{$search}%";
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM clients WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    $stmt = $pdo->prepare("SELECT * FROM clients WHERE {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);

    jsonResponse([
        'data' => $stmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit),
    ]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['first_name']);

    $stmt = $pdo->prepare("INSERT INTO clients (branch_id, first_name, last_name, email, phone, country, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $bid,
        $data['first_name'],
        $data['last_name'] ?? '',
        $data['email'] ?? null,
        $data['phone'] ?? null,
        $data['country'] ?? null,
        $data['notes'] ?? null,
    ]);

    jsonResponse(['message' => 'Client created', 'id' => (int)$pdo->lastInsertId()], 201);
}
