<?php
require_once __DIR__ . '/middleware.php';
requireMethod('GET');

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// Support period parameter or explicit date_from/date_to
$period = $_GET['period'] ?? null;
if ($period) {
    switch ($period) {
        case 'this_month':
            $dateFrom = date('Y-m-01');
            $dateTo = date('Y-m-t');
            break;
        case 'last_month':
            $dateFrom = date('Y-m-01', strtotime('first day of last month'));
            $dateTo = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'this_quarter':
            $q = ceil(date('n') / 3);
            $dateFrom = date('Y-m-01', mktime(0, 0, 0, ($q - 1) * 3 + 1, 1));
            $dateTo = date('Y-m-t', mktime(0, 0, 0, $q * 3, 1));
            break;
        case 'this_year':
            $dateFrom = date('Y-01-01');
            $dateTo = date('Y-12-31');
            break;
        case 'last_year':
            $dateFrom = date('Y-01-01', strtotime('-1 year'));
            $dateTo = date('Y-12-31', strtotime('-1 year'));
            break;
        case 'all_time':
            $dateFrom = '2000-01-01';
            $dateTo = '2099-12-31';
            break;
        default:
            $dateFrom = date('Y-m-01');
            $dateTo = date('Y-m-t');
    }
} else {
    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo = $_GET['date_to'] ?? date('Y-m-t');
}

// Total revenue: SUM of amount_paid from invoices in date range
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount_paid), 0) as total
    FROM invoices
    WHERE branch_id = ? AND created_at >= ? AND created_at <= CONCAT(?, ' 23:59:59')
");
$stmt->execute([$bid, $dateFrom, $dateTo]);
$totalRevenue = (float)$stmt->fetch()['total'];

// Total expenses
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total
    FROM expenses
    WHERE branch_id = ? AND expense_date >= ? AND expense_date <= ?
");
$stmt->execute([$bid, $dateFrom, $dateTo]);
$totalExpenses = (float)$stmt->fetch()['total'];

// Total supplier payments
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total
    FROM supplier_payments
    WHERE branch_id = ? AND payment_date >= ? AND payment_date <= ?
");
$stmt->execute([$bid, $dateFrom, $dateTo]);
$totalSupplierPayments = (float)$stmt->fetch()['total'];

// Profit
$profit = $totalRevenue - $totalExpenses - $totalSupplierPayments;

// Outstanding receivables: invoices that are sent/partial/overdue
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total - amount_paid), 0) as total
    FROM invoices
    WHERE branch_id = ? AND status IN ('sent', 'partial', 'overdue')
");
$stmt->execute([$bid]);
$outstandingReceivables = (float)$stmt->fetch()['total'];

// Outstanding payables (simple: total supplier payments)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total
    FROM supplier_payments
    WHERE branch_id = ?
");
$stmt->execute([$bid]);
$outstandingPayables = (float)$stmt->fetch()['total'];

// Monthly breakdown: last 6 months of revenue and expenses for chart data
$monthlyBreakdown = [];
for ($i = 5; $i >= 0; $i--) {
    $monthStart = date('Y-m-01', strtotime("-{$i} months"));
    $monthEnd = date('Y-m-t', strtotime("-{$i} months"));
    $monthLabel = date('Y-m', strtotime("-{$i} months"));

    // Revenue for this month
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_paid), 0) as total
        FROM invoices
        WHERE branch_id = ? AND created_at >= ? AND created_at <= CONCAT(?, ' 23:59:59')
    ");
    $stmt->execute([$bid, $monthStart, $monthEnd]);
    $monthRevenue = (float)$stmt->fetch()['total'];

    // Expenses for this month
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM expenses
        WHERE branch_id = ? AND expense_date >= ? AND expense_date <= ?
    ");
    $stmt->execute([$bid, $monthStart, $monthEnd]);
    $monthExpenses = (float)$stmt->fetch()['total'];

    $monthlyBreakdown[] = [
        'month' => $monthLabel,
        'revenue' => $monthRevenue,
        'expenses' => $monthExpenses,
    ];
}

// Quote stats
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM quotes WHERE branch_id = ?");
$stmt->execute([$bid]);
$totalQuotes = (int)$stmt->fetch()['total'];

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM quotes WHERE branch_id = ? AND status = 'accepted'");
$stmt->execute([$bid]);
$acceptedQuotes = (int)$stmt->fetch()['total'];

$conversionRate = $totalQuotes > 0 ? round(($acceptedQuotes / $totalQuotes) * 100, 1) : 0;

// Outstanding invoices list (top 10)
$stmt = $pdo->prepare("
    SELECT i.id, i.invoice_code, i.total, i.amount_paid, i.due_date, c.full_name as client_name
    FROM invoices i
    LEFT JOIN clients c ON c.id = i.client_id
    WHERE i.branch_id = ? AND i.status IN ('sent', 'partial', 'overdue')
    ORDER BY (i.total - i.amount_paid) DESC
    LIMIT 10
");
$stmt->execute([$bid]);
$outstandingInvoices = $stmt->fetchAll();

jsonResponse([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'total_revenue' => $totalRevenue,
    'total_expenses' => $totalExpenses,
    'total_supplier_payments' => $totalSupplierPayments,
    'profit' => $profit,
    'outstanding_receivables' => $outstandingReceivables,
    'outstanding_payables' => $outstandingPayables,
    'monthly_breakdown' => $monthlyBreakdown,
    'outstanding_invoices' => $outstandingInvoices,
    'quote_stats' => [
        'total' => $totalQuotes,
        'accepted' => $acceptedQuotes,
        'conversion_rate' => $conversionRate,
    ],
]);
