<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Invoice with client info
    $stmt = $pdo->prepare("
        SELECT i.*,
               c.first_name, c.last_name, c.email as client_email, c.phone as client_phone, c.country as client_country,
               q.quote_code,
               u.full_name as created_by_name
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        LEFT JOIN quotes q ON q.id = i.quote_id
        LEFT JOIN users u ON u.id = i.created_by
        WHERE i.id = ? AND i.branch_id = ?
    ");
    $stmt->execute([$id, $bid]);
    $invoice = $stmt->fetch();
    if (!$invoice) jsonError('Not found', 404);

    // Lines
    $stmt = $pdo->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$id]);
    $lines = $stmt->fetchAll();

    // Payments
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC");
    $stmt->execute([$id]);
    $payments = $stmt->fetchAll();

    jsonResponse([
        'invoice' => $invoice,
        'lines' => $lines,
        'payments' => $payments,
    ]);
}

if ($method === 'PUT') {
    $data = getJsonInput();
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Verify ownership
    $stmt = $pdo->prepare("SELECT id FROM invoices WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Not found', 404);

    $pdo->beginTransaction();
    try {
        // Update invoice fields
        $fields = [];
        $params = [];
        $allowed = ['status', 'currency', 'subtotal', 'tax_amount', 'total', 'due_date', 'notes'];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $params[] = $data[$f];
            }
        }

        if (!empty($fields)) {
            $params[] = $id;
            $pdo->prepare("UPDATE invoices SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        }

        // Replace-all pattern for lines
        if (array_key_exists('lines', $data) && is_array($data['lines'])) {
            $pdo->prepare("DELETE FROM invoice_lines WHERE invoice_id = ?")->execute([$id]);

            $stmt = $pdo->prepare("
                INSERT INTO invoice_lines (invoice_id, description, qty, unit_price, line_total, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach ($data['lines'] as $i => $line) {
                $stmt->execute([
                    $id,
                    $line['description'] ?? '',
                    (int)($line['qty'] ?? 1),
                    (float)($line['unit_price'] ?? 0),
                    (float)($line['line_total'] ?? 0),
                    $line['sort_order'] ?? $i,
                ]);
            }
        }

        $pdo->commit();
        jsonResponse(['message' => 'Invoice updated']);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed: ' . $e->getMessage(), 500);
    }
}
