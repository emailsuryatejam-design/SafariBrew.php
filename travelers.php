<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $requestId = $_GET['request_id'] ?? '';
    if (!$requestId) {
        jsonError('request_id is required', 400);
    }

    // Verify request belongs to branch
    $stmt = $pdo->prepare("SELECT id FROM requests WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$requestId, $bid]);
    if (!$stmt->fetch()) {
        jsonError('Request not found', 404);
    }

    $stmt = $pdo->prepare("
        SELECT t.*
        FROM travelers t
        JOIN requests r ON r.id = t.request_id
        WHERE t.request_id = ? AND r.branch_id = ?
        ORDER BY t.is_lead_traveler DESC, t.last_name ASC, t.first_name ASC
    ");
    $stmt->execute([(int)$requestId, $bid]);

    jsonResponse(['data' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['request_id', 'first_name', 'last_name']);

    // Verify request belongs to branch
    $stmt = $pdo->prepare("SELECT id FROM requests WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$data['request_id'], $bid]);
    if (!$stmt->fetch()) {
        jsonError('Request not found', 404);
    }

    $stmt = $pdo->prepare("
        INSERT INTO travelers (
            request_id, first_name, last_name, traveler_type, date_of_birth,
            age_at_travel, gender, nationality, passport_number, passport_expiry,
            passport_country, email, phone, dietary_requirements, medical_notes,
            is_lead_traveler, room_number, notes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        (int)$data['request_id'],
        $data['first_name'],
        $data['last_name'],
        $data['traveler_type'] ?? 'adult',
        $data['date_of_birth'] ?? null,
        $data['age_at_travel'] ?? null,
        $data['gender'] ?? null,
        $data['nationality'] ?? null,
        $data['passport_number'] ?? null,
        $data['passport_expiry'] ?? null,
        $data['passport_country'] ?? null,
        $data['email'] ?? null,
        $data['phone'] ?? null,
        $data['dietary_requirements'] ?? null,
        $data['medical_notes'] ?? null,
        $data['is_lead_traveler'] ?? 0,
        $data['room_number'] ?? null,
        $data['notes'] ?? null,
    ]);

    $id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM travelers WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['message' => 'Traveler created', 'data' => $stmt->fetch()], 201);
}

if ($method === 'PUT') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    $data = getJsonInput();

    // Verify traveler belongs to a request in this branch
    $stmt = $pdo->prepare("
        SELECT t.id FROM travelers t
        JOIN requests r ON r.id = t.request_id
        WHERE t.id = ? AND r.branch_id = ?
    ");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) {
        jsonError('Traveler not found', 404);
    }

    $allowed = [
        'first_name', 'last_name', 'traveler_type', 'date_of_birth',
        'age_at_travel', 'gender', 'nationality', 'passport_number',
        'passport_expiry', 'passport_country', 'email', 'phone',
        'dietary_requirements', 'medical_notes', 'is_lead_traveler',
        'room_number', 'notes',
    ];

    $sets = [];
    $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $sets[] = "{$f} = ?";
            $params[] = $data[$f];
        }
    }

    if (empty($sets)) jsonError('No fields to update', 400);

    $sets[] = "updated_at = NOW()";
    $params[] = (int)$id;

    $stmt = $pdo->prepare("UPDATE travelers SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);

    $stmt = $pdo->prepare("SELECT * FROM travelers WHERE id = ?");
    $stmt->execute([(int)$id]);

    jsonResponse(['message' => 'Traveler updated', 'data' => $stmt->fetch()]);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    // Verify traveler belongs to a request in this branch
    $stmt = $pdo->prepare("
        SELECT t.id FROM travelers t
        JOIN requests r ON r.id = t.request_id
        WHERE t.id = ? AND r.branch_id = ?
    ");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) {
        jsonError('Traveler not found', 404);
    }

    $stmt = $pdo->prepare("DELETE FROM travelers WHERE id = ?");
    $stmt->execute([(int)$id]);

    jsonResponse(['message' => 'Traveler deleted']);
}
