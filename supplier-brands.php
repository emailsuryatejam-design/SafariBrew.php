<?php
/**
 * Master Supplier Brands (hotel groups / chains)
 * e.g., "Serena Hotels", "andBeyond", "Asilia Africa"
 */
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// GET - List all brands
if ($method === 'GET') {
    $sql = "SELECT sb.*,
                   (SELECT COUNT(*) FROM suppliers s WHERE s.brand_id = sb.id AND s.is_active = 1) AS property_count,
                   (SELECT COUNT(*) FROM rate_contracts rc WHERE rc.brand_id = sb.id) AS contract_count
            FROM supplier_brands sb
            WHERE sb.branch_id = ? AND sb.is_active = 1";
    $params = [$bid];

    if (!empty($_GET['search'])) {
        $sql .= " AND sb.brand_name LIKE ?";
        $params[] = '%' . $_GET['search'] . '%';
    }

    $sql .= " ORDER BY sb.brand_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $brands = $stmt->fetchAll();

    // Decode JSON fields
    foreach ($brands as &$b) {
        if (isset($b['bank_details']) && is_string($b['bank_details'])) {
            $b['bank_details'] = json_decode($b['bank_details'], true);
        }
    }

    // If detail for single brand requested
    if (!empty($_GET['id'])) {
        $brandId = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM supplier_brands WHERE id = ? AND branch_id = ?");
        $stmt->execute([$brandId, $bid]);
        $brand = $stmt->fetch();
        if (!$brand) jsonError('Brand not found', 404);

        if (isset($brand['bank_details']) && is_string($brand['bank_details'])) {
            $brand['bank_details'] = json_decode($brand['bank_details'], true);
        }

        // Get properties under this brand
        $stmt = $pdo->prepare("SELECT s.*, ca.name AS accommodation_name
                               FROM suppliers s
                               LEFT JOIN content_accommodations ca ON ca.id = s.accommodation_id
                               WHERE s.brand_id = ? AND s.branch_id = ? AND s.is_active = 1
                               ORDER BY s.supplier_name");
        $stmt->execute([$brandId, $bid]);
        $brand['properties'] = $stmt->fetchAll();

        // Get contacts
        $stmt = $pdo->prepare("SELECT * FROM supplier_contact_persons WHERE brand_id = ? ORDER BY is_primary DESC");
        $stmt->execute([$brandId]);
        $brand['contacts'] = $stmt->fetchAll();

        // Get contracts
        $stmt = $pdo->prepare("SELECT rc.* FROM rate_contracts rc WHERE rc.brand_id = ? AND rc.branch_id = ? ORDER BY rc.created_at DESC");
        $stmt->execute([$brandId, $bid]);
        $brand['contracts'] = $stmt->fetchAll();

        jsonResponse(['data' => $brand]);
    }

    jsonResponse(['data' => $brands]);
}

// POST - Create a brand
if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['brand_name']);

    $code = 'BRD-' . str_pad((int)$pdo->query("SELECT COUNT(*) FROM supplier_brands")->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO supplier_brands (
            branch_id, brand_name, brand_code, description, logo_url, website,
            headquarters_country, headquarters_address,
            primary_contact_name, primary_contact_email, primary_contact_phone,
            payment_terms, bank_details, tax_id, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        $data['brand_name'],
        $code,
        $data['description'] ?? null,
        $data['logo_url'] ?? null,
        $data['website'] ?? null,
        $data['headquarters_country'] ?? null,
        $data['headquarters_address'] ?? null,
        $data['primary_contact_name'] ?? null,
        $data['primary_contact_email'] ?? null,
        $data['primary_contact_phone'] ?? null,
        $data['payment_terms'] ?? null,
        isset($data['bank_details']) ? json_encode($data['bank_details']) : null,
        $data['tax_id'] ?? null,
        $data['notes'] ?? null,
        $auth['user_id'],
    ]);

    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM supplier_brands WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['message' => 'Brand created', 'data' => $stmt->fetch()], 201);
}

// PUT - Update a brand
if ($method === 'PUT') {
    $data = getJsonInput();
    $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
    if (!$id) jsonError('id is required', 400);

    $stmt = $pdo->prepare("SELECT id FROM supplier_brands WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Brand not found', 404);

    $allowed = [
        'brand_name', 'description', 'logo_url', 'website',
        'headquarters_country', 'headquarters_address',
        'primary_contact_name', 'primary_contact_email', 'primary_contact_phone',
        'payment_terms', 'tax_id', 'notes',
    ];
    $sets = [];
    $params = [];

    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $sets[] = "{$f} = ?";
            $params[] = $data[$f];
        }
    }
    if (array_key_exists('bank_details', $data)) {
        $sets[] = "bank_details = ?";
        $params[] = json_encode($data['bank_details']);
    }

    if (empty($sets)) jsonError('No fields to update', 400);

    $params[] = $id;
    $pdo->prepare("UPDATE supplier_brands SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

    $stmt = $pdo->prepare("SELECT * FROM supplier_brands WHERE id = ?");
    $stmt->execute([$id]);
    jsonResponse(['message' => 'Brand updated', 'data' => $stmt->fetch()]);
}

// DELETE - Soft delete
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('id is required', 400);

    $stmt = $pdo->prepare("SELECT id FROM supplier_brands WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Brand not found', 404);

    $pdo->prepare("UPDATE supplier_brands SET is_active = 0 WHERE id = ?")->execute([$id]);
    jsonResponse(['message' => 'Brand deleted']);
}

jsonError('Method not allowed', 405);
