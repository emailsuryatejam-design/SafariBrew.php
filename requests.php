<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    $sort = $_GET['sort'] ?? 'created_at';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = "r.branch_id = ?";
    $params = [$bid];

    if ($status && $status !== 'all') {
        $where .= " AND r.status = ?";
        $params[] = $status;
    }

    if ($search) {
        $where .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR r.request_code LIKE ?)";
        $s = "%{$search}%";
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }

    $orderBy = match($sort) {
        'travel_date' => 'r.travel_start DESC',
        'client_name' => 'c.first_name ASC',
        default => 'r.created_at DESC',
    };

    // Count
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM requests r LEFT JOIN clients c ON c.id = r.client_id WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Data
    $stmt = $pdo->prepare("
        SELECT r.*, c.first_name, c.last_name, c.email as client_email, c.country as client_country,
               u.full_name as assigned_name
        FROM requests r
        LEFT JOIN clients c ON c.id = r.client_id
        LEFT JOIN users u ON u.id = r.assigned_user_id
        WHERE {$where}
        ORDER BY {$orderBy}
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    // Status counts
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM requests WHERE branch_id = ? GROUP BY status");
    $stmt->execute([$bid]);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int)$row['cnt'];
    }

    jsonResponse([
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit),
        'counts' => $counts,
    ]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['travel_start', 'travel_end', 'pax_adults']);

    $pdo->beginTransaction();
    try {
        // Create or link client
        $clientId = $data['client_id'] ?? null;
        if (!$clientId && !empty($data['client_first_name'])) {
            $stmt = $pdo->prepare("INSERT INTO clients (branch_id, first_name, last_name, email, phone, country) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $bid,
                $data['client_first_name'],
                $data['client_last_name'] ?? '',
                $data['client_email'] ?? null,
                $data['client_phone'] ?? null,
                $data['client_country'] ?? null,
            ]);
            $clientId = (int)$pdo->lastInsertId();
        }

        $code = generateCode('REQ', $pdo, 'requests', 'request_code');

        $tourPlan = isset($data['tour_plan']) ? json_encode($data['tour_plan']) : null;

        $stmt = $pdo->prepare("INSERT INTO requests (branch_id, client_id, request_code, status, travel_start, travel_end, pax_adults, pax_children, pax_babies, countries, destinations, tour_type, source, assigned_user_id, budget_currency, budget_amount, notes, tour_plan) VALUES (?, ?, ?, 'new', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $bid,
            $clientId,
            $code,
            $data['travel_start'],
            $data['travel_end'],
            (int)($data['pax_adults'] ?? 1),
            (int)($data['pax_children'] ?? 0),
            (int)($data['pax_babies'] ?? 0),
            json_encode($data['countries'] ?? []),
            json_encode($data['destinations'] ?? []),
            $data['tour_type'] ?? null,
            $data['source'] ?? null,
            $data['assigned_user_id'] ?? $auth['user_id'],
            $data['budget_currency'] ?? 'USD',
            $data['budget_amount'] ?? null,
            $data['notes'] ?? null,
            $tourPlan,
        ]);

        $requestId = (int)$pdo->lastInsertId();
        $pdo->commit();

        jsonResponse(['message' => 'Request created', 'id' => $requestId, 'code' => $code], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed: ' . $e->getMessage(), 500);
    }
}
