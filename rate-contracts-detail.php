<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$id = (int)($_GET['id'] ?? 0);
if (!$id) jsonError('id is required', 400);

// Verify ownership
$stmt = $pdo->prepare("SELECT * FROM rate_contracts WHERE id = ? AND branch_id = ?");
$stmt->execute([$id, $bid]);
$contract = $stmt->fetch();
if (!$contract) jsonError('Contract not found', 404);

// GET - Full contract detail with all related data
if ($method === 'GET') {
    // Room types
    $stmt = $pdo->prepare("SELECT * FROM contract_room_types WHERE contract_id = ? ORDER BY sort_order");
    $stmt->execute([$id]);
    $contract['room_types'] = $stmt->fetchAll();

    // Seasons
    $stmt = $pdo->prepare("SELECT * FROM contract_seasons WHERE contract_id = ? ORDER BY sort_order, start_date");
    $stmt->execute([$id]);
    $contract['seasons'] = $stmt->fetchAll();

    // Rates
    $stmt = $pdo->prepare("
        SELECT cr.*, crt.room_name, cs.season_name
        FROM contract_rates cr
        LEFT JOIN contract_room_types crt ON crt.id = cr.room_type_id
        LEFT JOIN contract_seasons cs ON cs.id = cr.season_id
        WHERE cr.contract_id = ?
        ORDER BY cr.room_type_id, cr.season_id
    ");
    $stmt->execute([$id]);
    $contract['rates'] = $stmt->fetchAll();

    // Meal supplements
    $stmt = $pdo->prepare("SELECT * FROM contract_meal_supplements WHERE contract_id = ?");
    $stmt->execute([$id]);
    $contract['meal_supplements'] = $stmt->fetchAll();

    // Meal rates (a-la-carte)
    $stmt = $pdo->prepare("SELECT * FROM contract_meal_rates WHERE contract_id = ?");
    $stmt->execute([$id]);
    $contract['meal_rates'] = $stmt->fetchAll();

    // Child policies
    $stmt = $pdo->prepare("SELECT * FROM contract_child_policies WHERE contract_id = ? ORDER BY age_from");
    $stmt->execute([$id]);
    $contract['child_policies'] = $stmt->fetchAll();

    // Special supplements
    $stmt = $pdo->prepare("SELECT * FROM contract_special_supplements WHERE contract_id = ? ORDER BY start_date");
    $stmt->execute([$id]);
    $contract['special_supplements'] = $stmt->fetchAll();

    // Offers
    $stmt = $pdo->prepare("SELECT * FROM contract_offers WHERE contract_id = ?");
    $stmt->execute([$id]);
    $contract['offers'] = $stmt->fetchAll();

    // Tour leader rates
    $stmt = $pdo->prepare("SELECT * FROM contract_tour_leader_rates WHERE contract_id = ?");
    $stmt->execute([$id]);
    $contract['tour_leader_rates'] = $stmt->fetchAll();

    // Activities
    $stmt = $pdo->prepare("SELECT * FROM contract_activities WHERE contract_id = ?");
    $stmt->execute([$id]);
    $contract['activities'] = $stmt->fetchAll();

    // Park fees
    $stmt = $pdo->prepare("SELECT * FROM contract_park_fees WHERE contract_id = ?");
    $stmt->execute([$id]);
    $contract['park_fees'] = $stmt->fetchAll();

    // Transfers
    $stmt = $pdo->prepare("SELECT * FROM contract_transfers WHERE contract_id = ?");
    $stmt->execute([$id]);
    $contract['transfers'] = $stmt->fetchAll();

    // Cancellation policies
    $stmt = $pdo->prepare("SELECT * FROM contract_cancellation_policies WHERE contract_id = ? ORDER BY sort_order, days_before_from DESC");
    $stmt->execute([$id]);
    $contract['cancellation_policies'] = $stmt->fetchAll();

    // Payment terms
    $stmt = $pdo->prepare("SELECT * FROM contract_payment_terms WHERE contract_id = ?");
    $stmt->execute([$id]);
    $contract['payment_terms'] = $stmt->fetchAll();

    // Policies (check-in/out, day use, etc.)
    $stmt = $pdo->prepare("SELECT * FROM contract_policies WHERE contract_id = ?");
    $stmt->execute([$id]);
    $contract['policies'] = $stmt->fetchAll();

    // Resident rates
    $stmt = $pdo->prepare("SELECT * FROM contract_resident_rates WHERE contract_id = ?");
    $stmt->execute([$id]);
    $contract['resident_rates'] = $stmt->fetchAll();

    // Other items
    $stmt = $pdo->prepare("SELECT * FROM contract_other_items WHERE contract_id = ?");
    $stmt->execute([$id]);
    $contract['other_items'] = $stmt->fetchAll();

    // Decode JSON fields
    foreach (['tax_info', 'contact_info', 'booking_procedures', 'signatory_info', 'extraction_raw'] as $f) {
        if (isset($contract[$f]) && is_string($contract[$f])) {
            $contract[$f] = json_decode($contract[$f], true);
        }
    }

    jsonResponse(['data' => $contract]);
}

// PUT - Update contract header or status
if ($method === 'PUT') {
    $data = getJsonInput();

    $allowed = [
        'accommodation_id', 'property_name', 'contract_type',
        'validity_start', 'validity_end', 'currency', 'secondary_currency',
        'rate_basis', 'market', 'status', 'notes',
        'terms_conditions', 'confidentiality_note', 'governing_law',
        'rate_variation_clause',
    ];
    $jsonFields = ['tax_info', 'contact_info', 'booking_procedures', 'signatory_info'];

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

    // Handle verify action
    if (isset($data['status']) && $data['status'] === 'verified') {
        $sets[] = "verified_by = ?";
        $params[] = $auth['user_id'];
        $sets[] = "verified_at = NOW()";
    }

    if (empty($sets)) jsonError('No fields to update', 400);

    $params[] = $id;
    $pdo->prepare("UPDATE rate_contracts SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

    // Audit log
    $pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action, details) VALUES (?, ?, 'updated', ?)")
        ->execute([$id, $auth['user_id'], json_encode(array_keys($data))]);

    $stmt = $pdo->prepare("SELECT * FROM rate_contracts WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['message' => 'Contract updated', 'data' => $stmt->fetch()]);
}

// DELETE - Soft archive the contract
if ($method === 'DELETE') {
    $pdo->prepare("UPDATE rate_contracts SET status = 'archived' WHERE id = ? AND branch_id = ?")
        ->execute([$id, $bid]);

    $pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action) VALUES (?, ?, 'archived')")
        ->execute([$id, $auth['user_id']]);

    jsonResponse(['message' => 'Contract archived']);
}

jsonError('Method not allowed', 405);
