<?php
require_once __DIR__ . '/middleware.php';
requireMethod('GET');

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// Request counts by status
$stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM requests WHERE branch_id = ? GROUP BY status");
$stmt->execute([$bid]);
$statusCounts = [];
foreach ($stmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
}

$totalRequests = array_sum($statusCounts);
$booked = $statusCounts['booked'] ?? 0;
$completed = $statusCounts['completed'] ?? 0;

// Quotes sent
$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM quotes WHERE branch_id = ? AND status IN ('sent','accepted')");
$stmt->execute([$bid]);
$quotesSent = (int)$stmt->fetch()['cnt'];

// Total revenue (paid invoices)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) as total FROM invoices WHERE branch_id = ? AND status IN ('paid','partial')");
$stmt->execute([$bid]);
$totalRevenue = (float)$stmt->fetch()['total'];

// Average booking value
$stmt = $pdo->prepare("SELECT COALESCE(AVG(total), 0) as avg_val FROM quotes WHERE branch_id = ? AND status = 'accepted'");
$stmt->execute([$bid]);
$avgBookingValue = (float)$stmt->fetch()['avg_val'];

// Conversion rate
$conversionRate = $totalRequests > 0 ? round(($booked + $completed) / $totalRequests * 100, 1) : 0;

// Upcoming bookings (next 7 days)
$stmt = $pdo->prepare("SELECT r.id, r.request_code, r.travel_start, r.travel_end, r.status, r.pax_adults, r.pax_children, c.first_name, c.last_name FROM requests r LEFT JOIN clients c ON c.id = r.client_id WHERE r.branch_id = ? AND r.travel_start BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND r.status IN ('booked','open','working_on') ORDER BY r.travel_start ASC LIMIT 10");
$stmt->execute([$bid]);
$upcoming = $stmt->fetchAll();

// Recent requests
$stmt = $pdo->prepare("SELECT r.id, r.request_code, r.status, r.created_at, c.first_name, c.last_name FROM requests r LEFT JOIN clients c ON c.id = r.client_id WHERE r.branch_id = ? ORDER BY r.created_at DESC LIMIT 5");
$stmt->execute([$bid]);
$recent = $stmt->fetchAll();

jsonResponse([
    'status_counts' => $statusCounts,
    'total_requests' => $totalRequests,
    'quotes_sent' => $quotesSent,
    'booked' => $booked + $completed,
    'conversion_rate' => $conversionRate,
    'total_revenue' => $totalRevenue,
    'avg_booking_value' => $avgBookingValue,
    'upcoming' => $upcoming,
    'recent' => $recent,
]);
