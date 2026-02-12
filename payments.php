<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $invoiceId = $_GET['invoice_id'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = "p.branch_id = ?";
    $params = [$bid];

    if ($invoiceId) {
        $where .= " AND p.invoice_id = ?";
        $params[] = (int)$invoiceId;
    }

    // Count
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM payments p WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Data
    $stmt = $pdo->prepare("
        SELECT p.*, i.invoice_code, c.first_name, c.last_name
        FROM payments p
        LEFT JOIN invoices i ON i.id = p.invoice_id
        LEFT JOIN clients c ON c.id = p.client_id
        WHERE {$where}
        ORDER BY p.payment_date DESC
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
    requireFields($data, ['invoice_id', 'amount', 'payment_date']);

    $invoiceId = (int)$data['invoice_id'];

    $pdo->beginTransaction();
    try {
        // Verify invoice belongs to branch
        $stmt = $pdo->prepare("SELECT id, client_id, total, amount_paid FROM invoices WHERE id = ? AND branch_id = ?");
        $stmt->execute([$invoiceId, $bid]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            $pdo->rollBack();
            jsonError('Invoice not found', 404);
        }

        // Create payment
        $stmt = $pdo->prepare("
            INSERT INTO payments (branch_id, invoice_id, client_id, amount, currency, payment_method,
                                  payment_date, reference, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $bid,
            $invoiceId,
            $invoice['client_id'],
            (float)$data['amount'],
            $data['currency'] ?? 'USD',
            $data['payment_method'] ?? null,
            $data['payment_date'],
            $data['reference'] ?? null,
            $data['notes'] ?? null,
            $auth['user_id'],
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        // Recalculate amount_paid for invoice
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM payments WHERE invoice_id = ?");
        $stmt->execute([$invoiceId]);
        $totalPaid = (float)$stmt->fetch()['total_paid'];

        // Update invoice amount_paid and status
        $invoiceTotal = (float)$invoice['total'];
        if ($totalPaid >= $invoiceTotal) {
            $newStatus = 'paid';
        } elseif ($totalPaid > 0) {
            $newStatus = 'partial';
        } else {
            $newStatus = null; // no change
        }

        if ($newStatus) {
            $pdo->prepare("UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?")
                ->execute([$totalPaid, $newStatus, $invoiceId]);
        } else {
            $pdo->prepare("UPDATE invoices SET amount_paid = ? WHERE id = ?")
                ->execute([$totalPaid, $invoiceId]);
        }

        $pdo->commit();
        jsonResponse(['message' => 'Payment recorded', 'id' => $paymentId, 'invoice_amount_paid' => $totalPaid], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed: ' . $e->getMessage(), 500);
    }
}
