<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $sql = "SELECT * FROM tags WHERE branch_id = ?";
    $params = [$bid];

    if (!empty($_GET['entity_type'])) {
        $sql .= " AND entity_type = ?";
        $params[] = $_GET['entity_type'];
    }
    if (!empty($_GET['entity_id'])) {
        $sql .= " AND entity_id = ?";
        $params[] = (int)$_GET['entity_id'];
    }

    $sql .= " ORDER BY tag_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse(['data' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['entity_type', 'entity_id', 'tag_name']);

    // Check duplicate
    $stmt = $pdo->prepare("SELECT id FROM tags WHERE branch_id = ? AND entity_type = ? AND entity_id = ? AND tag_name = ?");
    $stmt->execute([$bid, $data['entity_type'], (int)$data['entity_id'], $data['tag_name']]);
    if ($stmt->fetch()) jsonError('Tag already exists', 409);

    $stmt = $pdo->prepare("INSERT INTO tags (branch_id, entity_type, entity_id, tag_name) VALUES (?, ?, ?, ?)");
    $stmt->execute([$bid, $data['entity_type'], (int)$data['entity_id'], $data['tag_name']]);

    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM tags WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['message' => 'Tag created', 'data' => $stmt->fetch()], 201);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    $stmt = $pdo->prepare("SELECT id FROM tags WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) jsonError('Tag not found', 404);

    $stmt = $pdo->prepare("DELETE FROM tags WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);

    jsonResponse(['message' => 'Tag deleted']);
}
