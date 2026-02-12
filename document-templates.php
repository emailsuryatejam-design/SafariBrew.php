<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM document_templates WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$_GET['id'], $bid]);
        $row = $stmt->fetch();
        if (!$row) jsonError('Template not found', 404);
        if ($row['variables']) $row['variables'] = json_decode($row['variables'], true);
        jsonResponse(['data' => $row]);
    }

    $sql = "SELECT * FROM document_templates WHERE branch_id = ?";
    $params = [$bid];

    if (!empty($_GET['doc_type'])) {
        $sql .= " AND doc_type = ?";
        $params[] = $_GET['doc_type'];
    }

    $sql .= " ORDER BY doc_type ASC, name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        if ($r['variables']) $r['variables'] = json_decode($r['variables'], true);
    }
    unset($r);

    jsonResponse(['data' => $rows]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['name', 'doc_type', 'body']);

    $stmt = $pdo->prepare("
        INSERT INTO document_templates (branch_id, name, doc_type, subject, body, variables, is_default, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $bid,
        $data['name'],
        $data['doc_type'],
        $data['subject'] ?? null,
        $data['body'],
        isset($data['variables']) ? json_encode($data['variables']) : null,
        $data['is_default'] ?? 0,
        $auth['user_id'],
    ]);

    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM document_templates WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row['variables']) $row['variables'] = json_decode($row['variables'], true);

    jsonResponse(['message' => 'Template created', 'data' => $row], 201);
}

if ($method === 'PUT') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);
    $data = getJsonInput();

    $stmt = $pdo->prepare("SELECT id FROM document_templates WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) jsonError('Template not found', 404);

    $allowed = ['name', 'doc_type', 'subject', 'body', 'is_default'];
    $sets = [];
    $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $sets[] = "{$f} = ?";
            $params[] = $data[$f];
        }
    }
    if (array_key_exists('variables', $data)) {
        $sets[] = "variables = ?";
        $params[] = json_encode($data['variables']);
    }

    if (empty($sets)) jsonError('No fields to update', 400);

    $sets[] = "updated_at = NOW()";
    $params[] = (int)$id;

    $stmt = $pdo->prepare("UPDATE document_templates SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);

    $stmt = $pdo->prepare("SELECT * FROM document_templates WHERE id = ?");
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch();
    if ($row['variables']) $row['variables'] = json_decode($row['variables'], true);

    jsonResponse(['message' => 'Template updated', 'data' => $row]);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    $stmt = $pdo->prepare("SELECT id FROM document_templates WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) jsonError('Template not found', 404);

    $stmt = $pdo->prepare("DELETE FROM document_templates WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);

    jsonResponse(['message' => 'Template deleted']);
}
