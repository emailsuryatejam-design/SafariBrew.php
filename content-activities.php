<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// GET - List all active activities for branch
if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM content_activities WHERE branch_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$bid]);

    jsonResponse(['data' => $stmt->fetchAll()]);
}

// POST - Create activity
if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['name']);

    $stmt = $pdo->prepare("
        INSERT INTO content_activities
            (branch_id, name, category, description, cover_image, duration, default_rate, default_currency)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        $data['name'],
        $data['category'] ?? null,
        $data['description'] ?? null,
        $data['cover_image'] ?? null,
        $data['duration'] ?? null,
        $data['default_rate'] ?? null,
        $data['default_currency'] ?? 'USD',
    ]);

    jsonResponse(['message' => 'Activity created', 'id' => (int)$pdo->lastInsertId()], 201);
}

// PUT - Update activity
if ($method === 'PUT') {
    $data = getJsonInput();
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Validate branch ownership
    $stmt = $pdo->prepare("SELECT id FROM content_activities WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Not found', 404);

    $fields = [];
    $params = [];
    $allowed = ['name', 'category', 'description', 'cover_image', 'duration', 'default_rate', 'default_currency'];

    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "{$f} = ?";
            $params[] = $data[$f];
        }
    }

    if (empty($fields)) jsonError('No fields to update', 400);

    $params[] = $id;
    $pdo->prepare("UPDATE content_activities SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    jsonResponse(['message' => 'Activity updated']);
}

// DELETE - Soft delete
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Validate branch ownership
    $stmt = $pdo->prepare("SELECT id FROM content_activities WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Not found', 404);

    $pdo->prepare("UPDATE content_activities SET is_active = 0 WHERE id = ?")->execute([$id]);

    jsonResponse(['message' => 'Activity deleted']);
}
