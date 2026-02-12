<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// GET - List all active accommodations for branch with destination name
if ($method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT a.*, d.name AS destination_name
        FROM content_accommodations a
        LEFT JOIN content_destinations d ON d.id = a.destination_id
        WHERE a.branch_id = ? AND a.is_active = 1
        ORDER BY a.name
    ");
    $stmt->execute([$bid]);

    jsonResponse(['data' => $stmt->fetchAll()]);
}

// POST - Create accommodation
if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['name']);

    $stmt = $pdo->prepare("
        INSERT INTO content_accommodations
            (branch_id, name, destination_id, acc_type, star_rating, description, cover_image,
             country, region, contact_email, contact_phone, board_basis,
             default_rate_adult, default_rate_child, default_currency)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        $data['name'],
        $data['destination_id'] ?? null,
        $data['acc_type'] ?? null,
        $data['star_rating'] ?? null,
        $data['description'] ?? null,
        $data['cover_image'] ?? null,
        $data['country'] ?? null,
        $data['region'] ?? null,
        $data['contact_email'] ?? null,
        $data['contact_phone'] ?? null,
        $data['board_basis'] ?? 'FB',
        $data['default_rate_adult'] ?? null,
        $data['default_rate_child'] ?? null,
        $data['default_currency'] ?? 'USD',
    ]);

    jsonResponse(['message' => 'Accommodation created', 'id' => (int)$pdo->lastInsertId()], 201);
}

// PUT - Update accommodation
if ($method === 'PUT') {
    $data = getJsonInput();
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Validate branch ownership
    $stmt = $pdo->prepare("SELECT id FROM content_accommodations WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Not found', 404);

    $fields = [];
    $params = [];
    $allowed = [
        'name', 'destination_id', 'acc_type', 'star_rating', 'description', 'cover_image',
        'country', 'region', 'contact_email', 'contact_phone', 'board_basis',
        'default_rate_adult', 'default_rate_child', 'default_currency',
    ];

    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "{$f} = ?";
            $params[] = $data[$f];
        }
    }

    if (empty($fields)) jsonError('No fields to update', 400);

    $params[] = $id;
    $pdo->prepare("UPDATE content_accommodations SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    jsonResponse(['message' => 'Accommodation updated']);
}

// DELETE - Soft delete
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Validate branch ownership
    $stmt = $pdo->prepare("SELECT id FROM content_accommodations WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Not found', 404);

    $pdo->prepare("UPDATE content_accommodations SET is_active = 0 WHERE id = ?")->execute([$id]);

    jsonResponse(['message' => 'Accommodation deleted']);
}
