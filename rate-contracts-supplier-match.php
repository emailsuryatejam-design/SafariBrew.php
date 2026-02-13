<?php
/**
 * Fuzzy-match a property name against existing suppliers/accommodations.
 * POST { property_name: string }
 * Returns top matches with scores.
 */
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/rate-contracts-delete-logic.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') jsonError('Method not allowed', 405);

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$data = getJsonInput();
$propertyName = trim($data['property_name'] ?? '');

if (empty($propertyName)) {
    jsonError('property_name is required', 400);
}

$matches = findSupplierMatches($pdo, $propertyName, $bid);

jsonResponse([
    'property_name' => $propertyName,
    'matches' => $matches,
    'is_new' => empty($matches),
]);
