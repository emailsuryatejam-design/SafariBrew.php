<?php
/**
 * Wallet API endpoint.
 * GET  — current balance + recent transactions
 * POST — topup or admin credit
 */
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/wallet-check.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// GET — Balance + recent transactions
if ($method === 'GET') {
    $balance = getWalletBalance($pdo, $bid);

    // Recent transactions (last 50)
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $stmt = $pdo->prepare("
        SELECT wt.*, u.name as user_name
        FROM wallet_transactions wt
        LEFT JOIN users u ON u.id = wt.user_id
        WHERE wt.branch_id = ?
        ORDER BY wt.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$bid, $limit, $offset]);
    $transactions = $stmt->fetchAll();

    // Total count
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM wallet_transactions WHERE branch_id = ?");
    $stmt->execute([$bid]);
    $total = $stmt->fetch()['cnt'];

    // Usage summary (last 30 days)
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_transactions,
            COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as total_spent,
            COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as total_credited,
            COALESCE(SUM(input_tokens), 0) as total_input_tokens,
            COALESCE(SUM(output_tokens), 0) as total_output_tokens
        FROM wallet_transactions
        WHERE branch_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute([$bid]);
    $summary = $stmt->fetch();

    // Current pricing
    $stmt = $pdo->query("
        SELECT model, input_price_per_1m, output_price_per_1m, markup_percent
        FROM wallet_pricing
        WHERE is_active = 1
        ORDER BY effective_from DESC LIMIT 1
    ");
    $pricing = $stmt->fetch();

    jsonResponse([
        'balance' => (float)$balance,
        'currency' => 'USD',
        'transactions' => $transactions,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset,
        ],
        'summary_30d' => [
            'total_spent' => (float)$summary['total_spent'],
            'total_credited' => (float)$summary['total_credited'],
            'total_input_tokens' => (int)$summary['total_input_tokens'],
            'total_output_tokens' => (int)$summary['total_output_tokens'],
        ],
        'pricing' => $pricing,
    ]);
}

// POST — Top up or admin credit
if ($method === 'POST') {
    $data = getJsonInput();
    $action = $data['action'] ?? '';

    if ($action === 'topup') {
        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0 || $amount > 1000) {
            jsonError('Amount must be between $0.01 and $1000', 400);
        }

        $newBalance = creditWallet($pdo, $bid, $auth['user_id'], $amount, 'Manual top-up', 'topup');
        jsonResponse([
            'message' => 'Credits added successfully',
            'balance' => $newBalance,
            'amount_added' => $amount,
        ]);
    }

    if ($action === 'estimate') {
        $pageCount = (int)($data['page_count'] ?? 10);
        $estimate = estimateExtractionCost($pageCount);
        $balance = getWalletBalance($pdo, $bid);
        jsonResponse([
            'estimated_cost' => $estimate,
            'balance' => $balance,
            'sufficient' => $balance >= $estimate,
        ]);
    }

    jsonError('Invalid action. Use "topup" or "estimate".', 400);
}

jsonError('Method not allowed', 405);
