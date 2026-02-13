<?php
/**
 * Rate Types CRUD
 * Manages available rate type definitions (STO, International, Rack, etc.)
 */
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// GET - List rate types
if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM rate_types WHERE branch_id = ? AND is_active = 1 ORDER BY sort_order, type_code");
    $stmt->execute([$bid]);
    jsonResponse(['data' => $stmt->fetchAll()]);
}

// POST - Create rate type
if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['type_code', 'type_label']);

    $stmt = $pdo->prepare("
        INSERT INTO rate_types (branch_id, type_code, type_label, description, is_default, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        $data['type_code'],
        $data['type_label'],
        $data['description'] ?? null,
        $data['is_default'] ?? 0,
        $data['sort_order'] ?? 0,
    ]);

    jsonResponse(['message' => 'Rate type created', 'id' => (int)$pdo->lastInsertId()], 201);
}

// DELETE - Soft delete
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('id is required', 400);

    $pdo->prepare("UPDATE rate_types SET is_active = 0 WHERE id = ? AND branch_id = ?")->execute([$id, $bid]);
    jsonResponse(['message' => 'Rate type deleted']);
}

jsonError('Method not allowed', 405);
