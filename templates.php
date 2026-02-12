<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// GET - List templates with optional status filter and search
if ($method === 'GET') {
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';

    $where = "branch_id = ?";
    $params = [$bid];

    if ($status) {
        $where .= " AND status = ?";
        $params[] = $status;
    }

    if ($search) {
        $where .= " AND name LIKE ?";
        $params[] = "%{$search}%";
    }

    $stmt = $pdo->prepare("SELECT * FROM tour_templates WHERE {$where} ORDER BY updated_at DESC");
    $stmt->execute($params);

    jsonResponse(['data' => $stmt->fetchAll()]);
}

// POST - Create template
if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['name']);

    $countries = null;
    if (!empty($data['countries'])) {
        $countries = is_string($data['countries']) ? $data['countries'] : json_encode($data['countries']);
    }

    $stmt = $pdo->prepare("
        INSERT INTO tour_templates
            (branch_id, name, tour_type, description, countries, start_destination, end_destination,
             duration_days, duration_nights, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        $data['name'],
        $data['tour_type'] ?? null,
        $data['description'] ?? null,
        $countries,
        $data['start_destination'] ?? null,
        $data['end_destination'] ?? null,
        $data['duration_days'] ?? 1,
        $data['duration_nights'] ?? 0,
        $auth['user_id'],
    ]);

    jsonResponse(['message' => 'Template created', 'id' => (int)$pdo->lastInsertId()], 201);
}
