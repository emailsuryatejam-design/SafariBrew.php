<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    $client = $stmt->fetch();
    if (!$client) jsonError('Not found', 404);

    // Linked requests
    $stmt = $pdo->prepare("SELECT id, request_code, status, travel_start, travel_end, pax_adults, pax_children, tour_type, created_at FROM requests WHERE client_id = ? ORDER BY created_at DESC");
    $stmt->execute([$id]);
    $linkedRequests = $stmt->fetchAll();

    jsonResponse([
        'client' => $client,
        'requests' => $linkedRequests,
    ]);
}

if ($method === 'PUT') {
    $data = getJsonInput();
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    $stmt = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Not found', 404);

    $stmt = $pdo->prepare("UPDATE clients SET first_name = ?, last_name = ?, email = ?, phone = ?, country = ?, notes = ? WHERE id = ?");
    $stmt->execute([
        $data['first_name'] ?? '',
        $data['last_name'] ?? '',
        $data['email'] ?? null,
        $data['phone'] ?? null,
        $data['country'] ?? null,
        $data['notes'] ?? null,
        $id,
    ]);

    jsonResponse(['message' => 'Client updated']);
}
