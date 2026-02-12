<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $requestId = $_GET['request_id'] ?? '';
    $clientId = $_GET['client_id'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = "c.branch_id = ?";
    $params = [$bid];

    if ($requestId) {
        $where .= " AND c.request_id = ?";
        $params[] = (int)$requestId;
    }
    if ($clientId) {
        $where .= " AND c.client_id = ?";
        $params[] = (int)$clientId;
    }

    // Count
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM communications c WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Data with creator name
    $stmt = $pdo->prepare("
        SELECT c.*,
               u.first_name AS creator_first_name,
               u.last_name AS creator_last_name
        FROM communications c
        LEFT JOIN users u ON u.id = c.created_by
        WHERE {$where}
        ORDER BY c.communication_date DESC, c.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
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
    requireFields($data, ['communication_type', 'subject']);

    $stmt = $pdo->prepare("
        INSERT INTO communications (
            branch_id, request_id, client_id, communication_type,
            direction, subject, body, contact_name, contact_email,
            contact_phone, communication_date, notes,
            created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $bid,
        $data['request_id'] ?? null,
        $data['client_id'] ?? null,
        $data['communication_type'],
        $data['direction'] ?? 'outbound',
        $data['subject'],
        $data['body'] ?? null,
        $data['contact_name'] ?? null,
        $data['contact_email'] ?? null,
        $data['contact_phone'] ?? null,
        $data['communication_date'] ?? date('Y-m-d H:i:s'),
        $data['notes'] ?? null,
        $auth['user_id'],
    ]);

    $id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("
        SELECT c.*, u.first_name AS creator_first_name, u.last_name AS creator_last_name
        FROM communications c
        LEFT JOIN users u ON u.id = c.created_by
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);

    jsonResponse(['message' => 'Communication logged', 'data' => $stmt->fetch()], 201);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    // Verify communication belongs to this branch
    $stmt = $pdo->prepare("SELECT id FROM communications WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) {
        jsonError('Communication not found', 404);
    }

    $stmt = $pdo->prepare("DELETE FROM communications WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);

    jsonResponse(['message' => 'Communication deleted']);
}
