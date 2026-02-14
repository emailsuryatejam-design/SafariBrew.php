<?php
/**
 * Smart Quote: Generate quote lines from a request's tour plan.
 *
 * POST {
 *   request_id,
 *   rate_type (STO/MTO/GRO),
 *   include_park_fees: true,
 *   include_meals: true,
 *   include_transfers: true,
 *   overrides: { "2": { accommodation_id: 45 }, ... } // day overrides
 * }
 *
 * Returns proposed lines + warnings for frontend preview.
 */
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') jsonError('Method not allowed', 405);

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$data = getJsonInput();
$requestId = (int)($data['request_id'] ?? 0);
if (!$requestId) jsonError('request_id is required', 400);

$rateType = $data['rate_type'] ?? 'STO';
$includeParkFees = $data['include_park_fees'] ?? true;
$includeMeals = $data['include_meals'] ?? true;
$includeTransfers = $data['include_transfers'] ?? false;
$overrides = $data['overrides'] ?? [];

// Get request with tour plan
$stmt = $pdo->prepare("
    SELECT r.*, c.first_name, c.last_name
    FROM requests r
    LEFT JOIN clients c ON c.id = r.client_id
    WHERE r.id = ? AND r.branch_id = ?
");
$stmt->execute([$requestId, $bid]);
$request = $stmt->fetch();
if (!$request) jsonError('Request not found', 404);

$tourPlan = json_decode($request['tour_plan'] ?? '{}', true);
if (empty($tourPlan['days'])) {
    jsonError('Request has no tour plan. Please add a tour plan first.', 400);
}

$paxAdults = (int)($request['pax_adults'] ?? 1);
$paxChildren = (int)($request['pax_children'] ?? 0);
$travelDate = $request['travel_start'] ?? null;

$lines = [];
$warnings = [];
$sortOrder = 0;

foreach ($tourPlan['days'] as $dayEntry) {
    $dayNum = (int)($dayEntry['day'] ?? 0);
    $destination = $dayEntry['destination'] ?? '';
    $accommodationId = $dayEntry['accommodation_id'] ?? null;
    $nights = (int)($dayEntry['nights'] ?? 0);

    // Apply override if provided
    if (isset($overrides[$dayNum])) {
        if (isset($overrides[$dayNum]['accommodation_id'])) {
            $accommodationId = (int)$overrides[$dayNum]['accommodation_id'];
        }
    }

    if (!$accommodationId || $nights < 1) continue;

    // Calculate the travel date for this day entry
    $dayTravelDate = $travelDate ? date('Y-m-d', strtotime($travelDate . ' + ' . ($dayNum - 1) . ' days')) : null;

    // === ACCOMMODATION RATES ===
    $accResult = lookupAccommodationRate($pdo, $bid, $accommodationId, $rateType, $dayTravelDate);

    if ($accResult) {
        // Adult accommodation line
        if ($paxAdults > 0 && $accResult['rate_adult'] > 0) {
            $lines[] = [
                'day_number' => $dayNum,
                'service_type' => 'Accommodation',
                'rate_type' => $rateType,
                'accommodation_id' => $accommodationId,
                'contract_id' => $accResult['contract_id'],
                'description' => $accResult['property_name'] . ($accResult['room_type'] ? ' - ' . $accResult['room_type'] : ''),
                'traveler_type' => 'Adult',
                'qty' => $paxAdults,
                'nights' => $nights,
                'unit_price' => $accResult['rate_adult'],
                'sell_price' => $accResult['rate_adult'],
                'line_total' => $accResult['rate_adult'] * $paxAdults * $nights,
                'source' => $accResult['source'],
                'sort_order' => $sortOrder++,
            ];
        }

        // Child accommodation line
        if ($paxChildren > 0) {
            $childRate = $accResult['rate_child'] ?? ($accResult['rate_adult'] * 0.5);
            if ($childRate <= 0) {
                $childRate = $accResult['rate_adult'] * 0.5;
                $warnings[] = "Day {$dayNum}: No child rate found for {$accResult['property_name']}, using 50% of adult rate.";
            }
            $lines[] = [
                'day_number' => $dayNum,
                'service_type' => 'Accommodation',
                'rate_type' => $rateType,
                'accommodation_id' => $accommodationId,
                'contract_id' => $accResult['contract_id'],
                'description' => $accResult['property_name'] . ($accResult['room_type'] ? ' - ' . $accResult['room_type'] : '') . ' (Child)',
                'traveler_type' => 'Child',
                'qty' => $paxChildren,
                'nights' => $nights,
                'unit_price' => $childRate,
                'sell_price' => $childRate,
                'line_total' => $childRate * $paxChildren * $nights,
                'source' => $accResult['source'],
                'sort_order' => $sortOrder++,
            ];
        }

        // === PARK FEES ===
        if ($includeParkFees && $accResult['contract_id']) {
            $parkFees = getParkFees($pdo, $accResult['contract_id']);
            foreach ($parkFees as $pf) {
                $feeType = $pf['fee_type'] ?? 'per_person';
                if ($feeType === 'per_person' || $feeType === 'per_adult' || $feeType === 'adult') {
                    $lines[] = [
                        'day_number' => $dayNum,
                        'service_type' => 'Park Fee',
                        'rate_type' => $rateType,
                        'accommodation_id' => $accommodationId,
                        'contract_id' => $accResult['contract_id'],
                        'description' => $pf['park_name'] . ' - Adult Park Fee',
                        'traveler_type' => 'Adult',
                        'qty' => $paxAdults,
                        'nights' => $nights,
                        'unit_price' => (float)($pf['fee_amount'] ?? $pf['adult_fee'] ?? 0),
                        'sell_price' => (float)($pf['fee_amount'] ?? $pf['adult_fee'] ?? 0),
                        'line_total' => (float)($pf['fee_amount'] ?? $pf['adult_fee'] ?? 0) * $paxAdults * $nights,
                        'source' => 'contract_park_fees',
                        'sort_order' => $sortOrder++,
                    ];
                }
                if ($paxChildren > 0 && ($pf['child_fee'] ?? 0) > 0) {
                    $lines[] = [
                        'day_number' => $dayNum,
                        'service_type' => 'Park Fee',
                        'rate_type' => $rateType,
                        'accommodation_id' => $accommodationId,
                        'contract_id' => $accResult['contract_id'],
                        'description' => $pf['park_name'] . ' - Child Park Fee',
                        'traveler_type' => 'Child',
                        'qty' => $paxChildren,
                        'nights' => $nights,
                        'unit_price' => (float)$pf['child_fee'],
                        'sell_price' => (float)$pf['child_fee'],
                        'line_total' => (float)$pf['child_fee'] * $paxChildren * $nights,
                        'source' => 'contract_park_fees',
                        'sort_order' => $sortOrder++,
                    ];
                }
            }
        }

        // === MEAL SUPPLEMENTS ===
        if ($includeMeals && $accResult['contract_id']) {
            $meals = getMealSupplements($pdo, $accResult['contract_id']);
            foreach ($meals as $meal) {
                $mealRate = (float)($meal['rate_adult'] ?? $meal['supplement_rate'] ?? $meal['rate'] ?? 0);
                if ($mealRate > 0) {
                    $lines[] = [
                        'day_number' => $dayNum,
                        'service_type' => 'Meal',
                        'rate_type' => $rateType,
                        'accommodation_id' => $accommodationId,
                        'contract_id' => $accResult['contract_id'],
                        'description' => ($meal['meal_plan'] ?? $meal['supplement_name'] ?? 'Meal Supplement'),
                        'traveler_type' => 'Adult',
                        'qty' => $paxAdults,
                        'nights' => $nights,
                        'unit_price' => $mealRate,
                        'sell_price' => $mealRate,
                        'line_total' => $mealRate * $paxAdults * $nights,
                        'source' => 'contract_meal_supplements',
                        'sort_order' => $sortOrder++,
                    ];
                }
            }
        }
    } else {
        $warnings[] = "Day {$dayNum}: No rates found for accommodation ID {$accommodationId}.";
    }
}

// Calculate totals
$subtotal = array_sum(array_column($lines, 'line_total'));

jsonResponse([
    'lines' => $lines,
    'subtotal' => round($subtotal, 2),
    'warnings' => $warnings,
    'pax' => ['adults' => $paxAdults, 'children' => $paxChildren],
    'rate_type' => $rateType,
]);

// === HELPER FUNCTIONS ===

function lookupAccommodationRate($pdo, $branchId, $accommodationId, $rateType, $travelDate) {
    // Get accommodation info
    $stmt = $pdo->prepare("SELECT id, name, default_rate_adult, default_rate_child, default_currency, board_basis FROM content_accommodations WHERE id = ? AND branch_id = ?");
    $stmt->execute([$accommodationId, $branchId]);
    $acc = $stmt->fetch();
    if (!$acc) return null;

    // Try to find active contract
    $contractSql = "
        SELECT rc.id, rc.contract_code, rc.property_name, rc.currency
        FROM rate_contracts rc
        WHERE rc.branch_id = ? AND rc.accommodation_id = ? AND rc.contract_type = ? AND rc.status = 'active'
    ";
    $contractParams = [$branchId, $accommodationId, $rateType];

    if ($travelDate) {
        $contractSql .= " AND rc.validity_start <= ? AND rc.validity_end >= ?";
        $contractParams[] = $travelDate;
        $contractParams[] = $travelDate;
    }
    $contractSql .= " ORDER BY rc.validity_start DESC LIMIT 1";

    $stmt = $pdo->prepare($contractSql);
    $stmt->execute($contractParams);
    $contract = $stmt->fetch();

    // Fallback: try without date filter
    if (!$contract && $travelDate) {
        $stmt = $pdo->prepare("
            SELECT rc.id, rc.contract_code, rc.property_name, rc.currency
            FROM rate_contracts rc
            WHERE rc.branch_id = ? AND rc.accommodation_id = ? AND rc.contract_type = ? AND rc.status = 'active'
            ORDER BY rc.validity_start DESC LIMIT 1
        ");
        $stmt->execute([$branchId, $accommodationId, $rateType]);
        $contract = $stmt->fetch();
    }

    if (!$contract) {
        // Use accommodation defaults
        return [
            'property_name' => $acc['name'],
            'room_type' => null,
            'rate_adult' => (float)($acc['default_rate_adult'] ?? 0),
            'rate_child' => (float)($acc['default_rate_child'] ?? 0),
            'currency' => $acc['default_currency'] ?? 'USD',
            'contract_id' => null,
            'source' => 'accommodation_default',
        ];
    }

    $contractId = $contract['id'];

    // Find matching season
    $seasonId = null;
    if ($travelDate) {
        $stmt = $pdo->prepare("SELECT id FROM contract_seasons WHERE contract_id = ? AND start_date <= ? AND end_date >= ? LIMIT 1");
        $stmt->execute([$contractId, $travelDate, $travelDate]);
        $season = $stmt->fetch();
        if ($season) $seasonId = $season['id'];
    }

    // Find rate (cascading: season+room → room → any)
    $rateSql = "SELECT cr.*, rt.room_name FROM contract_rates cr LEFT JOIN contract_room_types rt ON rt.id = cr.room_type_id WHERE cr.contract_id = ?";
    $rateParams = [$contractId];
    if ($seasonId) {
        $rateSql .= " AND cr.season_id = ?";
        $rateParams[] = $seasonId;
    }
    $rateSql .= " ORDER BY cr.id ASC LIMIT 1";

    $stmt = $pdo->prepare($rateSql);
    $stmt->execute($rateParams);
    $rate = $stmt->fetch();

    // Fallback without season
    if (!$rate && $seasonId) {
        $stmt = $pdo->prepare("SELECT cr.*, rt.room_name FROM contract_rates cr LEFT JOIN contract_room_types rt ON rt.id = cr.room_type_id WHERE cr.contract_id = ? ORDER BY cr.id ASC LIMIT 1");
        $stmt->execute([$contractId]);
        $rate = $stmt->fetch();
    }

    if (!$rate) {
        return [
            'property_name' => $contract['property_name'] ?? $acc['name'],
            'room_type' => null,
            'rate_adult' => (float)($acc['default_rate_adult'] ?? 0),
            'rate_child' => (float)($acc['default_rate_child'] ?? 0),
            'currency' => $contract['currency'] ?? 'USD',
            'contract_id' => $contractId,
            'source' => 'contract_no_rates',
        ];
    }

    return [
        'property_name' => $contract['property_name'] ?? $acc['name'],
        'room_type' => $rate['room_name'] ?? null,
        'rate_adult' => (float)($rate['rate_pps'] ?? $rate['rate_double'] ?? $rate['rate_single'] ?? 0),
        'rate_child' => (float)($rate['rate_child'] ?? $rate['rate_triple'] ?? 0),
        'currency' => $rate['currency'] ?? $contract['currency'] ?? 'USD',
        'contract_id' => $contractId,
        'source' => 'contract',
    ];
}

function getParkFees($pdo, $contractId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM contract_park_fees WHERE contract_id = ?");
        $stmt->execute([$contractId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getMealSupplements($pdo, $contractId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM contract_meal_supplements WHERE contract_id = ?");
        $stmt->execute([$contractId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}
