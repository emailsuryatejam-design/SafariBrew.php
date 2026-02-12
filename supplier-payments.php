<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $accommodationId = $_GET['accommodation_id'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = "branch_id = ?";
    $params = [$bid];

    if ($accommodationId) {
        $where .= " AND accommodation_id = ?";
        $params[] = (int)$accommodationId;
    }

    // Count
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM supplier_payments WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Data
    $stmt = $pdo->prepare("SELECT * FROM supplier_payments WHERE {$where} ORDER BY payment_date DESC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonResponse([
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit),
    ]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['supplier_name', 'amount', 'payment_date']);

    $stmt = $pdo->prepare("
        INSERT INTO supplier_payments (branch_id, accommodation_id, supplier_name, amount, currency,
                                       payment_date, reference, request_id, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        $data['accommodation_id'] ?? null,
        $data['supplier_name'],
        (float)$data['amount'],
        $data['currency'] ?? 'USD',
        $data['payment_date'],
        $data['reference'] ?? null,
        $data['request_id'] ?? null,
        $data['notes'] ?? null,
        $auth['user_id'],
    ]);

    jsonResponse(['message' => 'Supplier payment created', 'id' => (int)$pdo->lastInsertId()], 201);
}
