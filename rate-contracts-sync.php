<?php
/**
 * Sync verified contract rates into the seasonal_rates table
 * so the existing quote/pricing system can use them.
 * POST { contract_id }
 */
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') jsonError('Method not allowed', 405);

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$data = getJsonInput();
requireFields($data, ['contract_id']);

$contractId = (int)$data['contract_id'];

// Verify contract
$stmt = $pdo->prepare("SELECT * FROM rate_contracts WHERE id = ? AND branch_id = ?");
$stmt->execute([$contractId, $bid]);
$contract = $stmt->fetch();
if (!$contract) jsonError('Contract not found', 404);

if (!$contract['accommodation_id']) {
    jsonError('Contract must be linked to an accommodation before syncing rates', 400);
}

$accommodationId = (int)$contract['accommodation_id'];

// Get seasons and rates
$stmt = $pdo->prepare("SELECT * FROM contract_seasons WHERE contract_id = ? ORDER BY start_date");
$stmt->execute([$contractId]);
$seasons = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT cr.*, crt.room_name, cs.season_name, cs.start_date AS season_start, cs.end_date AS season_end
    FROM contract_rates cr
    LEFT JOIN contract_room_types crt ON crt.id = cr.room_type_id
    LEFT JOIN contract_seasons cs ON cs.id = cr.season_id
    WHERE cr.contract_id = ?
");
$stmt->execute([$contractId]);
$rates = $stmt->fetchAll();

$pdo->beginTransaction();
try {
    // Remove old synced rates from this contract
    $pdo->prepare("DELETE FROM seasonal_rates WHERE contract_id = ? AND branch_id = ?")
        ->execute([$contractId, $bid]);

    $synced = 0;

    foreach ($rates as $rate) {
        // Create a seasonal_rate entry for each contract rate
        $seasonName = ($rate['season_name'] ?? 'Default') .
                      ($rate['room_name'] ? ' - ' . $rate['room_name'] : '') .
                      ' (' . strtoupper($rate['meal_plan'] ?? 'FB') . ')';

        $stmt = $pdo->prepare("
            INSERT INTO seasonal_rates (
                branch_id, accommodation_id, contract_id, season_name,
                start_date, end_date,
                rate_adult, rate_child, rate_infant,
                rate_single_supplement, rate_extra_bed,
                currency, min_nights, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // Use the best available adult rate
        $adultRate = $rate['rate_pps'] ?: ($rate['rate_double'] ?: $rate['rate_single']);

        $stmt->execute([
            $bid,
            $accommodationId,
            $contractId,
            $seasonName,
            $rate['season_start'] ?? $contract['validity_start'],
            $rate['season_end'] ?? $contract['validity_end'],
            $adultRate ?: 0,
            $rate['rate_child'] ?: 0,
            $rate['rate_infant'] ?: 0,
            $rate['rate_single_supplement'] ?: 0,
            $rate['rate_extra_bed'] ?: 0,
            $rate['currency'] ?? $contract['currency'] ?? 'USD',
            $rate['min_nights'] ?? 1,
            'Synced from contract ' . $contract['contract_code'],
        ]);
        $synced++;
    }

    // Update contract status to active
    $pdo->prepare("UPDATE rate_contracts SET status = 'active' WHERE id = ?")
        ->execute([$contractId]);

    $pdo->commit();

    // Audit log
    $pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action, details) VALUES (?, ?, 'synced', ?)")
        ->execute([$contractId, $auth['user_id'], json_encode(['rates_synced' => $synced])]);

    jsonResponse([
        'message' => "Synced {$synced} rates to seasonal rates",
        'synced_count' => $synced,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    jsonError('Sync failed: ' . $e->getMessage(), 500);
}
