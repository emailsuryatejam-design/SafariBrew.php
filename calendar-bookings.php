<?php
require_once __DIR__ . '/middleware.php';
requireMethod('GET');

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-t', strtotime($startDate));

// Get requests where travel dates overlap with the month
$stmt = $pdo->prepare("
    SELECT r.id, r.request_code, r.status, r.travel_start, r.travel_end,
           r.pax_adults, r.pax_children, r.tour_type,
           c.first_name, c.last_name
    FROM requests r
    LEFT JOIN clients c ON c.id = r.client_id
    WHERE r.branch_id = ?
      AND r.travel_start <= ?
      AND r.travel_end >= ?
      AND r.status NOT IN ('not_booked')
    ORDER BY r.travel_start ASC
");
$stmt->execute([$bid, $endDate, $startDate]);
$bookings = $stmt->fetchAll();

jsonResponse([
    'year' => $year,
    'month' => $month,
    'bookings' => $bookings,
]);
