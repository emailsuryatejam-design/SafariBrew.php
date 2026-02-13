<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Quote with request and client info
    $stmt = $pdo->prepare("
        SELECT q.*,
               r.request_code, r.travel_start, r.travel_end, r.pax_adults, r.pax_children, r.pax_babies,
               r.countries, r.destinations, r.tour_type, r.client_id,
               c.first_name, c.last_name, c.email as client_email, c.phone as client_phone, c.country as client_country,
               u.full_name as created_by_name
        FROM quotes q
        LEFT JOIN requests r ON r.id = q.request_id
        LEFT JOIN clients c ON c.id = r.client_id
        LEFT JOIN users u ON u.id = q.created_by
        WHERE q.id = ? AND q.branch_id = ?
    ");
    $stmt->execute([$id, $bid]);
    $quote = $stmt->fetch();
    if (!$quote) jsonError('Not found', 404);

    // Lines
    $stmt = $pdo->prepare("SELECT * FROM quote_lines WHERE quote_id = ? ORDER BY sort_order ASC, day_number ASC");
    $stmt->execute([$id]);
    $lines = $stmt->fetchAll();

    // Options
    $stmt = $pdo->prepare("SELECT * FROM quote_options WHERE quote_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$id]);
    $options = $stmt->fetchAll();

    // Inclusions
    $stmt = $pdo->prepare("SELECT * FROM quote_inclusions WHERE quote_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$id]);
    $inclusions = $stmt->fetchAll();

    jsonResponse([
        'quote' => $quote,
        'lines' => $lines,
        'options' => $options,
        'inclusions' => $inclusions,
    ]);
}

if ($method === 'PUT') {
    $data = getJsonInput();
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Verify ownership
    $stmt = $pdo->prepare("SELECT id FROM quotes WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Not found', 404);

    $pdo->beginTransaction();
    try {
        // Update quote fields
        $fields = [];
        $params = [];
        $allowed = ['currency', 'rate_type', 'valid_until', 'payment_terms', 'terms_conditions', 'notes',
                     'subtotal', 'tax_amount', 'total'];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $params[] = $data[$f];
            }
        }

        if (!empty($fields)) {
            $params[] = $id;
            $pdo->prepare("UPDATE quotes SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        }

        // Replace-all pattern for lines
        if (array_key_exists('lines', $data) && is_array($data['lines'])) {
            $pdo->prepare("DELETE FROM quote_lines WHERE quote_id = ?")->execute([$id]);

            $stmt = $pdo->prepare("
                INSERT INTO quote_lines (quote_id, day_number, service_type, rate_type,
                                         accommodation_id, contract_id, title, description,
                                         traveler_type, qty, nights, unit_price, markup_type, markup_value,
                                         sell_price, line_total, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($data['lines'] as $i => $line) {
                $stmt->execute([
                    $id,
                    $line['day_number'] ?? null,
                    $line['service_type'] ?? null,
                    $line['rate_type'] ?? null,
                    $line['accommodation_id'] ?? null,
                    $line['contract_id'] ?? null,
                    $line['title'] ?? '',
                    $line['description'] ?? null,
                    $line['traveler_type'] ?? 'adult',
                    (int)($line['qty'] ?? 1),
                    (int)($line['nights'] ?? 1),
                    (float)($line['unit_price'] ?? 0),
                    $line['markup_type'] ?? 'none',
                    (float)($line['markup_value'] ?? 0),
                    (float)($line['sell_price'] ?? 0),
                    (float)($line['line_total'] ?? 0),
                    $line['sort_order'] ?? $i,
                ]);
            }
        }

        // Replace-all pattern for options
        if (array_key_exists('options', $data) && is_array($data['options'])) {
            $pdo->prepare("DELETE FROM quote_options WHERE quote_id = ?")->execute([$id]);

            $stmt = $pdo->prepare("
                INSERT INTO quote_options (quote_id, group_name, option_name, price, sort_order)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($data['options'] as $i => $opt) {
                $stmt->execute([
                    $id,
                    $opt['group_name'] ?? null,
                    $opt['option_name'] ?? '',
                    (float)($opt['price'] ?? 0),
                    $opt['sort_order'] ?? $i,
                ]);
            }
        }

        // Replace-all pattern for inclusions
        if (array_key_exists('inclusions', $data) && is_array($data['inclusions'])) {
            $pdo->prepare("DELETE FROM quote_inclusions WHERE quote_id = ?")->execute([$id]);

            $stmt = $pdo->prepare("
                INSERT INTO quote_inclusions (quote_id, item_text, is_included, sort_order)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($data['inclusions'] as $i => $inc) {
                $stmt->execute([
                    $id,
                    $inc['item_text'] ?? '',
                    isset($inc['is_included']) ? (int)$inc['is_included'] : 1,
                    $inc['sort_order'] ?? $i,
                ]);
            }
        }

        $pdo->commit();
        jsonResponse(['message' => 'Quote updated']);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed: ' . $e->getMessage(), 500);
    }
}
