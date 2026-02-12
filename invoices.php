<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = "i.branch_id = ?";
    $params = [$bid];

    if ($status && $status !== 'all') {
        $where .= " AND i.status = ?";
        $params[] = $status;
    }

    if ($search) {
        $where .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR i.invoice_code LIKE ?)";
        $s = "%{$search}%";
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }

    // Count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE {$where}
    ");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Data
    $stmt = $pdo->prepare("
        SELECT i.*, c.first_name, c.last_name, c.email as client_email
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE {$where}
        ORDER BY i.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonResponse([
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit),
    ]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['quote_id']);

    $pdo->beginTransaction();
    try {
        $quoteId = (int)$data['quote_id'];

        // Fetch quote and verify branch ownership
        $stmt = $pdo->prepare("
            SELECT q.*, r.client_id, r.id as req_id
            FROM quotes q
            LEFT JOIN requests r ON r.id = q.request_id
            WHERE q.id = ? AND q.branch_id = ?
        ");
        $stmt->execute([$quoteId, $bid]);
        $quote = $stmt->fetch();
        if (!$quote) {
            $pdo->rollBack();
            jsonError('Quote not found', 404);
        }

        $invoiceCode = generateCode('INV', $pdo, 'invoices', 'invoice_code');

        $stmt = $pdo->prepare("
            INSERT INTO invoices (branch_id, request_id, quote_id, invoice_code, client_id, status,
                                  currency, subtotal, tax_amount, total, due_date, notes, created_by)
            VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $bid,
            $quote['request_id'],
            $quoteId,
            $invoiceCode,
            $quote['client_id'],
            $quote['currency'],
            (float)$quote['subtotal'],
            (float)$quote['tax_amount'],
            (float)$quote['total'],
            $data['due_date'] ?? null,
            $data['notes'] ?? $quote['notes'],
            $auth['user_id'],
        ]);
        $invoiceId = (int)$pdo->lastInsertId();

        // Copy quote lines to invoice lines
        $stmt = $pdo->prepare("SELECT * FROM quote_lines WHERE quote_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$quoteId]);
        $quoteLines = $stmt->fetchAll();

        $insertLine = $pdo->prepare("
            INSERT INTO invoice_lines (invoice_id, description, qty, unit_price, line_total, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($quoteLines as $line) {
            $desc = $line['title'];
            if ($line['description']) {
                $desc .= ' - ' . $line['description'];
            }

            $insertLine->execute([
                $invoiceId,
                $desc,
                (int)$line['qty'],
                (float)$line['sell_price'],
                (float)$line['line_total'],
                (int)$line['sort_order'],
            ]);
        }

        $pdo->commit();
        jsonResponse(['message' => 'Invoice created', 'id' => $invoiceId, 'code' => $invoiceCode], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed: ' . $e->getMessage(), 500);
    }
}
