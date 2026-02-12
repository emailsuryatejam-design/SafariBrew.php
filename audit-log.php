<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method !== 'GET') {
    jsonError('Method not allowed', 405);
}

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
$offset = ($page - 1) * $limit;

$sql = "SELECT al.*, u.full_name AS user_name
        FROM audit_log al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE al.branch_id = ?";
$countSql = "SELECT COUNT(*) FROM audit_log WHERE branch_id = ?";
$params = [$bid];
$countParams = [$bid];

if (!empty($_GET['entity_type'])) {
    $sql .= " AND al.entity_type = ?";
    $countSql .= " AND entity_type = ?";
    $params[] = $_GET['entity_type'];
    $countParams[] = $_GET['entity_type'];
}
if (!empty($_GET['entity_id'])) {
    $sql .= " AND al.entity_id = ?";
    $countSql .= " AND entity_id = ?";
    $params[] = (int)$_GET['entity_id'];
    $countParams[] = (int)$_GET['entity_id'];
}
if (!empty($_GET['user_id'])) {
    $sql .= " AND al.user_id = ?";
    $countSql .= " AND user_id = ?";
    $params[] = (int)$_GET['user_id'];
    $countParams[] = (int)$_GET['user_id'];
}
if (!empty($_GET['action'])) {
    $sql .= " AND al.action = ?";
    $countSql .= " AND action = ?";
    $params[] = $_GET['action'];
    $countParams[] = $_GET['action'];
}

$sql .= " ORDER BY al.created_at DESC LIMIT {$limit} OFFSET {$offset}";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    if ($r['old_values']) $r['old_values'] = json_decode($r['old_values'], true);
    if ($r['new_values']) $r['new_values'] = json_decode($r['new_values'], true);
}
unset($r);

jsonResponse([
    'data' => $rows,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => (int)ceil($total / $limit),
    ],
]);
