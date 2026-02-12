<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $sql = "SELECT sr.*, ca.name AS accommodation_name
            FROM seasonal_rates sr
            LEFT JOIN content_accommodations ca ON ca.id = sr.accommodation_id
            WHERE sr.branch_id = ?";
    $params = [$bid];

    if (!empty($_GET['accommodation_id'])) {
        $sql .= " AND sr.accommodation_id = ?";
        $params[] = (int)$_GET['accommodation_id'];
    }

    $sql .= " ORDER BY sr.start_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse(['data' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['accommodation_id', 'season_name', 'start_date', 'end_date', 'rate_adult']);

    $stmt = $pdo->prepare("
        INSERT INTO seasonal_rates (branch_id, accommodation_id, season_name, start_date, end_date,
            rate_adult, rate_child, rate_infant, rate_single_supplement, rate_extra_bed,
            currency, min_nights, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        (int)$data['accommodation_id'],
        $data['season_name'],
        $data['start_date'],
        $data['end_date'],
        $data['rate_adult'],
        $data['rate_child'] ?? 0,
        $data['rate_infant'] ?? 0,
        $data['rate_single_supplement'] ?? 0,
        $data['rate_extra_bed'] ?? 0,
        $data['currency'] ?? 'USD',
        $data['min_nights'] ?? 1,
        $data['notes'] ?? null,
    ]);

    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM seasonal_rates WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['message' => 'Rate created', 'data' => $stmt->fetch()], 201);
}

if ($method === 'PUT') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);
    $data = getJsonInput();

    $stmt = $pdo->prepare("SELECT id FROM seasonal_rates WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) jsonError('Rate not found', 404);

    $allowed = [
        'accommodation_id', 'season_name', 'start_date', 'end_date',
        'rate_adult', 'rate_child', 'rate_infant', 'rate_single_supplement',
        'rate_extra_bed', 'currency', 'min_nights', 'notes',
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

    $params[] = (int)$id;
    $stmt = $pdo->prepare("UPDATE seasonal_rates SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);

    $stmt = $pdo->prepare("SELECT * FROM seasonal_rates WHERE id = ?");
    $stmt->execute([(int)$id]);

    jsonResponse(['message' => 'Rate updated', 'data' => $stmt->fetch()]);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    $stmt = $pdo->prepare("SELECT id FROM seasonal_rates WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) jsonError('Rate not found', 404);

    $stmt = $pdo->prepare("DELETE FROM seasonal_rates WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);

    jsonResponse(['message' => 'Rate deleted']);
}
