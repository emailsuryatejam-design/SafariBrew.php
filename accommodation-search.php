<?php
/**
 * Accommodation Search API
 * GET ?search=serengeti&limit=10
 * Returns accommodations with active contract status.
 * Used by tour plan builder and quote builder.
 */
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') jsonError('Method not allowed', 405);

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$search = $_GET['search'] ?? '';
$limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));

$where = "a.branch_id = ? AND a.is_active = 1";
$params = [$bid];

if ($search) {
    $where .= " AND (a.name LIKE ? OR d.name LIKE ? OR a.country LIKE ? OR a.region LIKE ?)";
    $s = "%{$search}%";
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
}

$stmt = $pdo->prepare("
    SELECT a.id, a.name, a.acc_type, a.star_rating, a.country, a.region,
           a.default_rate_adult, a.default_rate_child, a.default_currency, a.board_basis,
           a.cover_image,
           d.name AS destination_name,
           (SELECT COUNT(*) FROM rate_contracts rc
            WHERE rc.accommodation_id = a.id AND rc.branch_id = a.branch_id
              AND rc.status = 'active'
              AND (rc.validity_end IS NULL OR rc.validity_end >= CURDATE())
           ) AS active_contracts
    FROM content_accommodations a
    LEFT JOIN content_destinations d ON d.id = a.destination_id
    WHERE {$where}
    ORDER BY a.name
    LIMIT {$limit}
");
$stmt->execute($params);

jsonResponse(['data' => $stmt->fetchAll()]);
