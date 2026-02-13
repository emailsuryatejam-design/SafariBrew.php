<?php
/**
 * Rate Lookup API
 * Finds the best matching rate for a given accommodation + rate_type + date + room/meal.
 *
 * GET params:
 *   accommodation_id (required)
 *   rate_type (optional, defaults to 'STO')
 *   travel_date (optional, for season matching)
 *   room_type_id (optional)
 *   meal_plan (optional)
 *
 * Returns the matching contract rate or falls back to accommodation default rate.
 */
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') jsonError('Method not allowed', 405);

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$accommodationId = (int)($_GET['accommodation_id'] ?? 0);
if (!$accommodationId) jsonError('accommodation_id is required', 400);

$rateType = $_GET['rate_type'] ?? 'STO';
$travelDate = $_GET['travel_date'] ?? null;
$roomTypeId = !empty($_GET['room_type_id']) ? (int)$_GET['room_type_id'] : null;
$mealPlan = $_GET['meal_plan'] ?? null;

// Find the best active contract for this accommodation + rate type
$contractSql = "
    SELECT rc.id, rc.contract_code, rc.property_name, rc.currency,
           rc.validity_start, rc.validity_end
    FROM rate_contracts rc
    WHERE rc.branch_id = ?
      AND rc.accommodation_id = ?
      AND rc.contract_type = ?
      AND rc.status = 'active'
";
$contractParams = [$bid, $accommodationId, $rateType];

// If travel date provided, prefer contract covering that date
if ($travelDate) {
    $contractSql .= " AND rc.validity_start <= ? AND rc.validity_end >= ?";
    $contractParams[] = $travelDate;
    $contractParams[] = $travelDate;
}

$contractSql .= " ORDER BY rc.validity_start DESC LIMIT 1";
$stmt = $pdo->prepare($contractSql);
$stmt->execute($contractParams);
$contract = $stmt->fetch();

// If no contract with travel date filter, try without date filter
if (!$contract && $travelDate) {
    $stmt = $pdo->prepare("
        SELECT rc.id, rc.contract_code, rc.property_name, rc.currency,
               rc.validity_start, rc.validity_end
        FROM rate_contracts rc
        WHERE rc.branch_id = ?
          AND rc.accommodation_id = ?
          AND rc.contract_type = ?
          AND rc.status = 'active'
        ORDER BY rc.validity_start DESC LIMIT 1
    ");
    $stmt->execute([$bid, $accommodationId, $rateType]);
    $contract = $stmt->fetch();
}

if (!$contract) {
    // Fallback to accommodation default rates
    $stmt = $pdo->prepare("SELECT id, name, default_rate_adult, default_rate_child, default_currency, board_basis FROM content_accommodations WHERE id = ? AND branch_id = ?");
    $stmt->execute([$accommodationId, $bid]);
    $acc = $stmt->fetch();

    jsonResponse([
        'source' => 'accommodation_default',
        'contract_id' => null,
        'rate_type' => $rateType,
        'rate' => $acc ? [
            'rate_pps' => (float)($acc['default_rate_adult'] ?? 0),
            'rate_child' => (float)($acc['default_rate_child'] ?? 0),
            'currency' => $acc['default_currency'] ?? 'USD',
            'meal_plan' => $acc['board_basis'] ?? 'FB',
        ] : null,
        'message' => $acc ? 'No active contract found, using accommodation default rates' : 'Accommodation not found',
    ]);
    return;
}

$contractId = $contract['id'];

// Find matching season if travel date provided
$seasonId = null;
if ($travelDate) {
    $stmt = $pdo->prepare("
        SELECT id, season_name FROM contract_seasons
        WHERE contract_id = ? AND start_date <= ? AND end_date >= ?
        LIMIT 1
    ");
    $stmt->execute([$contractId, $travelDate, $travelDate]);
    $season = $stmt->fetch();
    if ($season) $seasonId = $season['id'];
}

// Build rate query
$rateSql = "SELECT cr.* FROM contract_rates cr WHERE cr.contract_id = ?";
$rateParams = [$contractId];

if ($roomTypeId) {
    $rateSql .= " AND cr.room_type_id = ?";
    $rateParams[] = $roomTypeId;
}
if ($seasonId) {
    $rateSql .= " AND cr.season_id = ?";
    $rateParams[] = $seasonId;
}
if ($mealPlan) {
    $rateSql .= " AND cr.meal_plan = ?";
    $rateParams[] = $mealPlan;
}

$rateSql .= " ORDER BY cr.id ASC LIMIT 1";
$stmt = $pdo->prepare($rateSql);
$stmt->execute($rateParams);
$rate = $stmt->fetch();

// If no exact match, try without season filter
if (!$rate && $seasonId) {
    $rateSql2 = "SELECT cr.* FROM contract_rates cr WHERE cr.contract_id = ?";
    $rateParams2 = [$contractId];
    if ($roomTypeId) {
        $rateSql2 .= " AND cr.room_type_id = ?";
        $rateParams2[] = $roomTypeId;
    }
    if ($mealPlan) {
        $rateSql2 .= " AND cr.meal_plan = ?";
        $rateParams2[] = $mealPlan;
    }
    $rateSql2 .= " ORDER BY cr.id ASC LIMIT 1";
    $stmt = $pdo->prepare($rateSql2);
    $stmt->execute($rateParams2);
    $rate = $stmt->fetch();
}

// If still no match, try just contract_id (first available rate)
if (!$rate) {
    $stmt = $pdo->prepare("SELECT cr.* FROM contract_rates cr WHERE cr.contract_id = ? ORDER BY cr.id ASC LIMIT 1");
    $stmt->execute([$contractId]);
    $rate = $stmt->fetch();
}

// Also get available room types and seasons for this contract
$stmt = $pdo->prepare("SELECT id, room_name, room_category FROM contract_room_types WHERE contract_id = ? ORDER BY sort_order");
$stmt->execute([$contractId]);
$roomTypes = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, season_name, season_type, start_date, end_date FROM contract_seasons WHERE contract_id = ? ORDER BY start_date");
$stmt->execute([$contractId]);
$seasons = $stmt->fetchAll();

jsonResponse([
    'source' => 'contract',
    'contract_id' => $contractId,
    'contract_code' => $contract['contract_code'],
    'rate_type' => $rateType,
    'rate' => $rate ?: null,
    'room_types' => $roomTypes,
    'seasons' => $seasons,
    'season_id' => $seasonId,
]);
