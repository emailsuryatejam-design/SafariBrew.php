<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $requestId = $_GET['request_id'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = "v.branch_id = ?";
    $params = [$bid];

    if ($requestId) {
        $where .= " AND v.request_id = ?";
        $params[] = (int)$requestId;
    }

    // Count
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM vouchers v WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Data
    $stmt = $pdo->prepare("
        SELECT v.*, r.request_code,
               c.first_name AS client_first_name, c.last_name AS client_last_name
        FROM vouchers v
        LEFT JOIN requests r ON r.id = v.request_id
        LEFT JOIN clients c ON c.id = r.client_id
        WHERE {$where}
        ORDER BY v.created_at DESC
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
    requireFields($data, ['request_id', 'voucher_type', 'title']);

    // Verify request belongs to branch
    $stmt = $pdo->prepare("SELECT id FROM requests WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$data['request_id'], $bid]);
    if (!$stmt->fetch()) {
        jsonError('Request not found', 404);
    }

    // Auto-generate voucher_code as VCH-XXXXX
    $voucherCode = generateCode('VCH', $pdo, 'vouchers', 'voucher_code');

    $stmt = $pdo->prepare("
        INSERT INTO vouchers (
            branch_id, request_id, voucher_code, voucher_type, title,
            description, start_date, end_date, accommodation_name,
            accommodation_details, meal_plan, special_instructions,
            status, notes, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $bid,
        (int)$data['request_id'],
        $voucherCode,
        $data['voucher_type'],
        $data['title'],
        $data['description'] ?? null,
        $data['start_date'] ?? null,
        $data['end_date'] ?? null,
        $data['accommodation_name'] ?? null,
        $data['accommodation_details'] ?? null,
        $data['meal_plan'] ?? null,
        $data['special_instructions'] ?? null,
        $data['status'] ?? 'draft',
        $data['notes'] ?? null,
        $auth['user_id'],
    ]);

    $id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['message' => 'Voucher created', 'data' => $stmt->fetch()], 201);
}

if ($method === 'PUT') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    $data = getJsonInput();

    // Verify voucher belongs to this branch
    $stmt = $pdo->prepare("SELECT id FROM vouchers WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) {
        jsonError('Voucher not found', 404);
    }

    $allowed = [
        'voucher_type', 'title', 'description', 'start_date', 'end_date',
        'accommodation_name', 'accommodation_details', 'meal_plan',
        'special_instructions', 'status', 'notes',
    ];

    $sets = [];
    $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $sets[] = "{$f} = ?";
            $params[] = $data[$f];
        }
    }

    if (empty($sets)) jsonError('No fields to update', 400);

    $sets[] = "updated_at = NOW()";
    $params[] = (int)$id;
    $params[] = $bid;

    $stmt = $pdo->prepare("UPDATE vouchers SET " . implode(', ', $sets) . " WHERE id = ? AND branch_id = ?");
    $stmt->execute($params);

    $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = ?");
    $stmt->execute([(int)$id]);

    jsonResponse(['message' => 'Voucher updated', 'data' => $stmt->fetch()]);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    // Verify voucher belongs to this branch
    $stmt = $pdo->prepare("SELECT id FROM vouchers WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) {
        jsonError('Voucher not found', 404);
    }

    $stmt = $pdo->prepare("DELETE FROM vouchers WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);

    jsonResponse(['message' => 'Voucher deleted']);
}
