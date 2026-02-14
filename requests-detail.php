<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    $stmt = $pdo->prepare("
        SELECT r.*, c.first_name, c.last_name, c.email as client_email, c.phone as client_phone, c.country as client_country,
               u.full_name as assigned_name
        FROM requests r
        LEFT JOIN clients c ON c.id = r.client_id
        LEFT JOIN users u ON u.id = r.assigned_user_id
        WHERE r.id = ? AND r.branch_id = ?
    ");
    $stmt->execute([$id, $bid]);
    $request = $stmt->fetch();
    if (!$request) jsonError('Not found', 404);

    // Notes
    $stmt = $pdo->prepare("SELECT rn.*, u.full_name as author_name FROM request_notes rn JOIN users u ON u.id = rn.user_id WHERE rn.request_id = ? ORDER BY rn.created_at DESC");
    $stmt->execute([$id]);
    $notes = $stmt->fetchAll();

    // Related quotes
    $stmt = $pdo->prepare("SELECT id, quote_code, status, total, currency, created_at FROM quotes WHERE request_id = ? ORDER BY created_at DESC");
    $stmt->execute([$id]);
    $relatedQuotes = $stmt->fetchAll();

    // Related invoices
    $stmt = $pdo->prepare("SELECT id, invoice_code, status, total, currency, amount_paid, created_at FROM invoices WHERE request_id = ? ORDER BY created_at DESC");
    $stmt->execute([$id]);
    $relatedInvoices = $stmt->fetchAll();

    jsonResponse([
        'request' => $request,
        'notes' => $notes,
        'quotes' => $relatedQuotes,
        'invoices' => $relatedInvoices,
    ]);
}

if ($method === 'PUT') {
    $data = getJsonInput();
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Check ownership
    $stmt = $pdo->prepare("SELECT id FROM requests WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Not found', 404);

    $fields = [];
    $params = [];
    $allowed = ['travel_start', 'travel_end', 'pax_adults', 'pax_children', 'pax_babies', 'tour_type', 'source', 'assigned_user_id', 'budget_currency', 'budget_amount', 'notes'];

    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "{$f} = ?";
            $params[] = $data[$f];
        }
    }

    // Handle tour_plan separately (JSON field)
    if (array_key_exists('tour_plan', $data)) {
        $fields[] = "tour_plan = ?";
        $params[] = $data['tour_plan'] !== null ? json_encode($data['tour_plan']) : null;
    }

    if (!empty($data['countries'])) {
        $fields[] = "countries = ?";
        $params[] = json_encode($data['countries']);
    }
    if (!empty($data['destinations'])) {
        $fields[] = "destinations = ?";
        $params[] = json_encode($data['destinations']);
    }

    if (empty($fields)) jsonError('No fields to update', 400);

    $params[] = $id;
    $pdo->prepare("UPDATE requests SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    // Add note if provided
    if (!empty($data['note'])) {
        $stmt = $pdo->prepare("INSERT INTO request_notes (request_id, user_id, note) VALUES (?, ?, ?)");
        $stmt->execute([$id, $auth['user_id'], $data['note']]);
    }

    jsonResponse(['message' => 'Updated']);
}
