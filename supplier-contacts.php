<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $sql = "SELECT sc.*, ca.name AS accommodation_name
            FROM supplier_contacts sc
            LEFT JOIN content_accommodations ca ON ca.id = sc.accommodation_id
            WHERE sc.branch_id = ?";
    $params = [$bid];

    if (!empty($_GET['accommodation_id'])) {
        $sql .= " AND sc.accommodation_id = ?";
        $params[] = (int)$_GET['accommodation_id'];
    }

    $sql .= " ORDER BY sc.supplier_name ASC, sc.contact_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse(['data' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['contact_name']);

    $stmt = $pdo->prepare("
        INSERT INTO supplier_contacts (branch_id, accommodation_id, supplier_name, contact_name, role, email, phone, is_primary, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        $data['accommodation_id'] ?? null,
        $data['supplier_name'] ?? null,
        $data['contact_name'],
        $data['role'] ?? null,
        $data['email'] ?? null,
        $data['phone'] ?? null,
        $data['is_primary'] ?? 0,
        $data['notes'] ?? null,
    ]);

    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM supplier_contacts WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['message' => 'Contact created', 'data' => $stmt->fetch()], 201);
}

if ($method === 'PUT') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);
    $data = getJsonInput();

    $stmt = $pdo->prepare("SELECT id FROM supplier_contacts WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) jsonError('Contact not found', 404);

    $allowed = ['accommodation_id', 'supplier_name', 'contact_name', 'role', 'email', 'phone', 'is_primary', 'notes'];
    $sets = [];
    $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $sets[] = "{$f} = ?";
            $params[] = $data[$f];
        }
    }
    if (empty($sets)) jsonError('No fields to update', 400);

    $params[] = (int)$id;
    $stmt = $pdo->prepare("UPDATE supplier_contacts SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);

    $stmt = $pdo->prepare("SELECT * FROM supplier_contacts WHERE id = ?");
    $stmt->execute([(int)$id]);

    jsonResponse(['message' => 'Contact updated', 'data' => $stmt->fetch()]);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    $stmt = $pdo->prepare("SELECT id FROM supplier_contacts WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) jsonError('Contact not found', 404);

    $stmt = $pdo->prepare("DELETE FROM supplier_contacts WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);

    jsonResponse(['message' => 'Contact deleted']);
}
