<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $category = $_GET['category'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = "branch_id = ?";
    $params = [$bid];

    if ($dateFrom) {
        $where .= " AND expense_date >= ?";
        $params[] = $dateFrom;
    }

    if ($dateTo) {
        $where .= " AND expense_date <= ?";
        $params[] = $dateTo;
    }

    if ($category) {
        $where .= " AND category = ?";
        $params[] = $category;
    }

    // Count
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM expenses WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Data
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE {$where} ORDER BY expense_date DESC LIMIT {$limit} OFFSET {$offset}");
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
    requireFields($data, ['description', 'amount', 'expense_date']);

    $stmt = $pdo->prepare("
        INSERT INTO expenses (branch_id, description, amount, currency, expense_date, category, supplier_name, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        $data['description'],
        (float)$data['amount'],
        $data['currency'] ?? 'USD',
        $data['expense_date'],
        $data['category'] ?? null,
        $data['supplier_name'] ?? null,
        $data['notes'] ?? null,
        $auth['user_id'],
    ]);

    jsonResponse(['message' => 'Expense created', 'id' => (int)$pdo->lastInsertId()], 201);
}
