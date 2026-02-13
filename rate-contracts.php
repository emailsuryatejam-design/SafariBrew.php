<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// GET - List all rate contracts
if ($method === 'GET') {
    $sql = "SELECT rc.*, ca.name AS accommodation_name,
                   u.full_name AS uploaded_by_name,
                   v.full_name AS verified_by_name
            FROM rate_contracts rc
            LEFT JOIN content_accommodations ca ON ca.id = rc.accommodation_id
            LEFT JOIN users u ON u.id = rc.uploaded_by
            LEFT JOIN users v ON v.id = rc.verified_by
            WHERE rc.branch_id = ?";
    $params = [$bid];

    if (!empty($_GET['accommodation_id'])) {
        $sql .= " AND rc.accommodation_id = ?";
        $params[] = (int)$_GET['accommodation_id'];
    }
    if (!empty($_GET['status'])) {
        $sql .= " AND rc.status = ?";
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['search'])) {
        $sql .= " AND (rc.property_name LIKE ? OR rc.contract_code LIKE ?)";
        $params[] = '%' . $_GET['search'] . '%';
        $params[] = '%' . $_GET['search'] . '%';
    }

    // Pagination
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Count
    $countSql = str_replace(
        "SELECT rc.*, ca.name AS accommodation_name,\n                   u.full_name AS uploaded_by_name,\n                   v.full_name AS verified_by_name",
        "SELECT COUNT(*) as total",
        $sql
    );
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch()['total'];

    $sql .= " ORDER BY rc.created_at DESC LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse([
        'data' => $stmt->fetchAll(),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit),
        ]
    ]);
}

// POST - Create / Upload a new rate contract
if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['property_name']);

    $code = generateCode('RCT', $pdo, 'rate_contracts', 'contract_code');
    $extractionMode = $data['extraction_mode'] ?? 'manual';

    $stmt = $pdo->prepare("
        INSERT INTO rate_contracts (
            branch_id, accommodation_id, contract_code, property_name, contract_type,
            validity_start, validity_end, currency, secondary_currency, rate_basis,
            market, status, extraction_mode, extraction_status,
            original_file_url, original_file_name, file_size,
            notes, tax_info, contact_info, booking_procedures,
            terms_conditions, confidentiality_note, governing_law,
            rate_variation_clause, signatory_info, uploaded_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bid,
        $data['accommodation_id'] ?? null,
        $code,
        $data['property_name'],
        $data['contract_type'] ?? 'STO',
        $data['validity_start'] ?? null,
        $data['validity_end'] ?? null,
        $data['currency'] ?? 'USD',
        $data['secondary_currency'] ?? null,
        $data['rate_basis'] ?? 'net',
        $data['market'] ?? null,
        $extractionMode === 'ai_brew' ? 'draft' : 'draft',
        $extractionMode,
        $extractionMode === 'ai_brew' ? 'pending' : 'completed',
        $data['original_file_url'] ?? null,
        $data['original_file_name'] ?? null,
        $data['file_size'] ?? null,
        $data['notes'] ?? null,
        isset($data['tax_info']) ? json_encode($data['tax_info']) : null,
        isset($data['contact_info']) ? json_encode($data['contact_info']) : null,
        isset($data['booking_procedures']) ? json_encode($data['booking_procedures']) : null,
        $data['terms_conditions'] ?? null,
        $data['confidentiality_note'] ?? null,
        $data['governing_law'] ?? null,
        $data['rate_variation_clause'] ?? null,
        isset($data['signatory_info']) ? json_encode($data['signatory_info']) : null,
        $auth['user_id'],
    ]);

    $contractId = (int)$pdo->lastInsertId();

    // Log creation
    $pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action, details) VALUES (?, ?, 'created', ?)")
        ->execute([$contractId, $auth['user_id'], json_encode(['mode' => $extractionMode])]);

    $stmt = $pdo->prepare("SELECT * FROM rate_contracts WHERE id = ?");
    $stmt->execute([$contractId]);

    jsonResponse(['message' => 'Contract created', 'data' => $stmt->fetch()], 201);
}

jsonError('Method not allowed', 405);
