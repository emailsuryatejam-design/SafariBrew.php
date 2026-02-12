<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// GET - List all active destinations for branch
if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM content_destinations WHERE branch_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$bid]);

    jsonResponse(['data' => $stmt->fetchAll()]);
}

// POST - Create destination
if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['name']);

    $stmt = $pdo->prepare("
        INSERT INTO content_destinations (branch_id, name, country, description, cover_image, latitude, longitude)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        $data['name'],
        $data['country'] ?? null,
        $data['description'] ?? null,
        $data['cover_image'] ?? null,
        $data['latitude'] ?? null,
        $data['longitude'] ?? null,
    ]);

    jsonResponse(['message' => 'Destination created', 'id' => (int)$pdo->lastInsertId()], 201);
}

// PUT - Update destination
if ($method === 'PUT') {
    $data = getJsonInput();
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Validate branch ownership
    $stmt = $pdo->prepare("SELECT id FROM content_destinations WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Not found', 404);

    $fields = [];
    $params = [];
    $allowed = ['name', 'country', 'description', 'cover_image', 'latitude', 'longitude'];

    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "{$f} = ?";
            $params[] = $data[$f];
        }
    }

    if (empty($fields)) jsonError('No fields to update', 400);

    $params[] = $id;
    $pdo->prepare("UPDATE content_destinations SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    jsonResponse(['message' => 'Destination updated']);
}

// DELETE - Soft delete
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Validate branch ownership
    $stmt = $pdo->prepare("SELECT id FROM content_destinations WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Not found', 404);

    $pdo->prepare("UPDATE content_destinations SET is_active = 0 WHERE id = ?")->execute([$id]);

    jsonResponse(['message' => 'Destination deleted']);
}
