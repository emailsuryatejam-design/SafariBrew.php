<?php
/**
 * Suppliers (individual properties / service providers)
 * Each accommodation or activity provider is a supplier.
 * Suppliers can belong to a brand (master supplier / group).
 */
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// GET - List suppliers or get single supplier detail
if ($method === 'GET') {

    // Single supplier detail
    if (!empty($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare("
            SELECT s.*, sb.brand_name, ca.name AS accommodation_name
            FROM suppliers s
            LEFT JOIN supplier_brands sb ON sb.id = s.brand_id
            LEFT JOIN content_accommodations ca ON ca.id = s.accommodation_id
            WHERE s.id = ? AND s.branch_id = ?
        ");
        $stmt->execute([$id, $bid]);
        $supplier = $stmt->fetch();
        if (!$supplier) jsonError('Supplier not found', 404);

        // Decode JSON
        foreach (['bank_details', 'tags'] as $f) {
            if (isset($supplier[$f]) && is_string($supplier[$f])) {
                $supplier[$f] = json_decode($supplier[$f], true);
            }
        }

        // Contacts
        $stmt = $pdo->prepare("SELECT * FROM supplier_contact_persons WHERE supplier_id = ? ORDER BY is_primary DESC");
        $stmt->execute([$id]);
        $supplier['contacts'] = $stmt->fetchAll();

        // Contracts
        $stmt = $pdo->prepare("SELECT * FROM rate_contracts WHERE supplier_id = ? ORDER BY created_at DESC");
        $stmt->execute([$id]);
        $supplier['contracts'] = $stmt->fetchAll();

        // Documents
        $stmt = $pdo->prepare("SELECT * FROM supplier_documents WHERE supplier_id = ? ORDER BY created_at DESC");
        $stmt->execute([$id]);
        $supplier['documents'] = $stmt->fetchAll();

        jsonResponse(['data' => $supplier]);
    }

    // List all suppliers
    $sql = "SELECT s.*, sb.brand_name, ca.name AS accommodation_name,
                   (SELECT COUNT(*) FROM rate_contracts rc WHERE rc.supplier_id = s.id) AS contract_count
            FROM suppliers s
            LEFT JOIN supplier_brands sb ON sb.id = s.brand_id
            LEFT JOIN content_accommodations ca ON ca.id = s.accommodation_id
            WHERE s.branch_id = ? AND s.is_active = 1";
    $params = [$bid];

    if (!empty($_GET['brand_id'])) {
        $sql .= " AND s.brand_id = ?";
        $params[] = (int)$_GET['brand_id'];
    }
    if (!empty($_GET['supplier_type'])) {
        $sql .= " AND s.supplier_type = ?";
        $params[] = $_GET['supplier_type'];
    }
    if (!empty($_GET['search'])) {
        $sql .= " AND (s.supplier_name LIKE ? OR s.country LIKE ?)";
        $params[] = '%' . $_GET['search'] . '%';
        $params[] = '%' . $_GET['search'] . '%';
    }

    $sql .= " ORDER BY s.supplier_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $suppliers = $stmt->fetchAll();

    foreach ($suppliers as &$s) {
        if (isset($s['tags']) && is_string($s['tags'])) {
            $s['tags'] = json_decode($s['tags'], true);
        }
    }

    jsonResponse(['data' => $suppliers]);
}

// POST - Create supplier
if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['supplier_name']);

    $code = 'SUP-' . str_pad((int)$pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn() + 1, 5, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO suppliers (
            branch_id, brand_id, accommodation_id, supplier_name, supplier_code,
            supplier_type, description, country, region, address,
            contact_name, contact_email, contact_phone,
            reservations_email, reservations_phone,
            finance_email, finance_phone,
            website, payment_terms, bank_details, tax_id,
            preferred_currency, commission_rate, credit_limit,
            rating, tags, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        $data['brand_id'] ?? null,
        $data['accommodation_id'] ?? null,
        $data['supplier_name'],
        $code,
        $data['supplier_type'] ?? 'accommodation',
        $data['description'] ?? null,
        $data['country'] ?? null,
        $data['region'] ?? null,
        $data['address'] ?? null,
        $data['contact_name'] ?? null,
        $data['contact_email'] ?? null,
        $data['contact_phone'] ?? null,
        $data['reservations_email'] ?? null,
        $data['reservations_phone'] ?? null,
        $data['finance_email'] ?? null,
        $data['finance_phone'] ?? null,
        $data['website'] ?? null,
        $data['payment_terms'] ?? null,
        isset($data['bank_details']) ? json_encode($data['bank_details']) : null,
        $data['tax_id'] ?? null,
        $data['preferred_currency'] ?? 'USD',
        $data['commission_rate'] ?? 0,
        $data['credit_limit'] ?? 0,
        $data['rating'] ?? null,
        isset($data['tags']) ? json_encode($data['tags']) : null,
        $data['notes'] ?? null,
        $auth['user_id'],
    ]);

    $supplierId = (int)$pdo->lastInsertId();

    // Link to accommodation if provided
    if (!empty($data['accommodation_id'])) {
        $pdo->prepare("UPDATE content_accommodations SET supplier_id = ?, brand_id = ? WHERE id = ? AND branch_id = ?")
            ->execute([$supplierId, $data['brand_id'] ?? null, (int)$data['accommodation_id'], $bid]);
    }

    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$supplierId]);

    jsonResponse(['message' => 'Supplier created', 'data' => $stmt->fetch()], 201);
}

// PUT - Update supplier
if ($method === 'PUT') {
    $data = getJsonInput();
    $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
    if (!$id) jsonError('id is required', 400);

    $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Supplier not found', 404);

    $allowed = [
        'brand_id', 'accommodation_id', 'supplier_name', 'supplier_type',
        'description', 'country', 'region', 'address',
        'contact_name', 'contact_email', 'contact_phone',
        'reservations_email', 'reservations_phone',
        'finance_email', 'finance_phone',
        'website', 'payment_terms', 'tax_id',
        'preferred_currency', 'commission_rate', 'credit_limit',
        'rating', 'notes',
    ];
    $jsonFields = ['bank_details', 'tags'];

    $sets = [];
    $params = [];

    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $sets[] = "{$f} = ?";
            $params[] = $data[$f];
        }
    }
    foreach ($jsonFields as $f) {
        if (array_key_exists($f, $data)) {
            $sets[] = "{$f} = ?";
            $params[] = json_encode($data[$f]);
        }
    }

    if (empty($sets)) jsonError('No fields to update', 400);

    $params[] = $id;
    $pdo->prepare("UPDATE suppliers SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$id]);
    jsonResponse(['message' => 'Supplier updated', 'data' => $stmt->fetch()]);
}

// DELETE - Soft delete
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('id is required', 400);

    $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Supplier not found', 404);

    $pdo->prepare("UPDATE suppliers SET is_active = 0 WHERE id = ?")->execute([$id]);
    jsonResponse(['message' => 'Supplier deleted']);
}

jsonError('Method not allowed', 405);
