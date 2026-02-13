<?php
/**
 * Link a rate contract to an existing or new supplier/brand.
 * POST { contract_id, action, supplier_id?, supplier_name?, brand_id?, brand_name?, accommodation_id? }
 */
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') jsonError('Method not allowed', 405);

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$data = getJsonInput();
requireFields($data, ['contract_id', 'action']);

$contractId = (int)$data['contract_id'];
$action = $data['action'];

// Verify contract ownership
$stmt = $pdo->prepare("SELECT * FROM rate_contracts WHERE id = ? AND branch_id = ?");
$stmt->execute([$contractId, $bid]);
$contract = $stmt->fetch();
if (!$contract) jsonError('Contract not found', 404);

$supplierId = null;
$brandId = null;
$accommodationId = null;

switch ($action) {
    case 'link_existing':
        $supplierId = (int)($data['supplier_id'] ?? 0);
        if (!$supplierId) jsonError('supplier_id required for link_existing', 400);

        // Verify supplier belongs to this branch
        $stmt = $pdo->prepare("SELECT id, brand_id, accommodation_id FROM suppliers WHERE id = ? AND branch_id = ?");
        $stmt->execute([$supplierId, $bid]);
        $supplier = $stmt->fetch();
        if (!$supplier) jsonError('Supplier not found', 404);

        $brandId = $supplier['brand_id'];
        $accommodationId = $supplier['accommodation_id'] ?: ($data['accommodation_id'] ?? null);
        break;

    case 'create_new':
        $supplierName = trim($data['supplier_name'] ?? $contract['property_name']);
        $brandId = !empty($data['brand_id']) ? (int)$data['brand_id'] : null;

        $code = generateCode('SUP', $pdo, 'suppliers', 'supplier_code');
        $stmt = $pdo->prepare("
            INSERT INTO suppliers (branch_id, supplier_name, supplier_code, supplier_type, brand_id, is_active, created_by)
            VALUES (?, ?, ?, 'accommodation', ?, 1, ?)
        ");
        $stmt->execute([$bid, $supplierName, $code, $brandId, $auth['user_id']]);
        $supplierId = (int)$pdo->lastInsertId();
        $accommodationId = !empty($data['accommodation_id']) ? (int)$data['accommodation_id'] : null;
        break;

    case 'create_with_brand':
        $brandName = trim($data['brand_name'] ?? '');
        if (empty($brandName)) jsonError('brand_name required for create_with_brand', 400);

        // Create brand
        $brandCode = generateCode('BRD', $pdo, 'supplier_brands', 'brand_code');
        $stmt = $pdo->prepare("
            INSERT INTO supplier_brands (branch_id, brand_name, brand_code, is_active)
            VALUES (?, ?, ?, 1)
        ");
        $stmt->execute([$bid, $brandName, $brandCode]);
        $brandId = (int)$pdo->lastInsertId();

        // Create supplier under brand
        $supplierName = trim($data['supplier_name'] ?? $contract['property_name']);
        $supCode = generateCode('SUP', $pdo, 'suppliers', 'supplier_code');
        $stmt = $pdo->prepare("
            INSERT INTO suppliers (branch_id, supplier_name, supplier_code, supplier_type, brand_id, is_active, created_by)
            VALUES (?, ?, ?, 'accommodation', ?, 1, ?)
        ");
        $stmt->execute([$bid, $supplierName, $supCode, $brandId, $auth['user_id']]);
        $supplierId = (int)$pdo->lastInsertId();
        $accommodationId = !empty($data['accommodation_id']) ? (int)$data['accommodation_id'] : null;
        break;

    default:
        jsonError('action must be link_existing, create_new, or create_with_brand', 400);
}

// Update contract with supplier/brand linkage
$updates = ['supplier_id = ?'];
$params = [$supplierId];

if ($brandId) {
    $updates[] = 'brand_id = ?';
    $params[] = $brandId;
}
if ($accommodationId) {
    $updates[] = 'accommodation_id = ?';
    $params[] = $accommodationId;
}

$params[] = $contractId;
$pdo->prepare("UPDATE rate_contracts SET " . implode(', ', $updates) . " WHERE id = ?")
    ->execute($params);

// Audit
$pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action, details) VALUES (?, ?, 'linked_supplier', ?)")
    ->execute([$contractId, $auth['user_id'], json_encode([
        'action' => $action,
        'supplier_id' => $supplierId,
        'brand_id' => $brandId,
    ])]);

// Return updated contract
$stmt = $pdo->prepare("SELECT * FROM rate_contracts WHERE id = ?");
$stmt->execute([$contractId]);

jsonResponse([
    'message' => 'Contract linked to supplier',
    'data' => $stmt->fetch(),
    'supplier_id' => $supplierId,
    'brand_id' => $brandId,
]);
