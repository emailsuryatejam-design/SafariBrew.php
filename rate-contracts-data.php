<?php
/**
 * Bulk save/update child data for a rate contract.
 * POST with { contract_id, section, items: [...] }
 * Supports: room_types, seasons, rates, meal_supplements, meal_rates,
 *           child_policies, special_supplements, offers, tour_leader_rates,
 *           activities, park_fees, transfers, cancellation_policies,
 *           payment_terms, policies, resident_rates, other_items
 */
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') jsonError('Method not allowed', 405);

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$data = getJsonInput();
requireFields($data, ['contract_id', 'section', 'items']);

$contractId = (int)$data['contract_id'];
$section = $data['section'];
$items = $data['items'];

// Verify contract ownership
$stmt = $pdo->prepare("SELECT id FROM rate_contracts WHERE id = ? AND branch_id = ?");
$stmt->execute([$contractId, $bid]);
if (!$stmt->fetch()) jsonError('Contract not found', 404);

// Section -> table mapping with allowed fields
$sectionMap = [
    'room_types' => [
        'table' => 'contract_room_types',
        'fields' => ['room_name', 'room_category', 'max_occupancy', 'bed_config', 'description', 'sort_order'],
    ],
    'seasons' => [
        'table' => 'contract_seasons',
        'fields' => ['season_name', 'season_type', 'start_date', 'end_date', 'notes', 'sort_order'],
    ],
    'rates' => [
        'table' => 'contract_rates',
        'fields' => ['room_type_id', 'season_id', 'meal_plan', 'rate_basis',
                     'rate_pps', 'rate_single', 'rate_double', 'rate_triple',
                     'rate_child', 'rate_infant', 'rate_single_supplement',
                     'rate_extra_bed', 'rate_extra_adult', 'currency', 'min_nights', 'notes'],
    ],
    'meal_supplements' => [
        'table' => 'contract_meal_supplements',
        'fields' => ['from_plan', 'to_plan', 'supplement_adult', 'supplement_child', 'currency', 'notes'],
    ],
    'meal_rates' => [
        'table' => 'contract_meal_rates',
        'fields' => ['meal_type', 'rate_adult', 'rate_child', 'currency', 'notes'],
    ],
    'child_policies' => [
        'table' => 'contract_child_policies',
        'fields' => ['age_from', 'age_to', 'policy_type', 'sharing_with_1_adult',
                     'sharing_with_2_adults', 'own_room', 'fixed_rate', 'max_children_per_room', 'currency', 'notes'],
    ],
    'special_supplements' => [
        'table' => 'contract_special_supplements',
        'fields' => ['supplement_name', 'supplement_type', 'amount', 'percentage',
                     'start_date', 'end_date', 'applies_to', 'currency', 'notes'],
    ],
    'offers' => [
        'table' => 'contract_offers',
        'fields' => ['offer_name', 'offer_type', 'discount_type', 'discount_value',
                     'min_pax', 'min_nights', 'conditions', 'valid_start', 'valid_end',
                     'blackout_dates', 'currency', 'notes'],
    ],
    'tour_leader_rates' => [
        'table' => 'contract_tour_leader_rates',
        'fields' => ['min_pax_for_free', 'free_rooms', 'reduced_rate', 'reduced_rate_below_pax',
                     'driver_guide_rate', 'driver_guide_meal_included', 'currency', 'notes'],
    ],
    'activities' => [
        'table' => 'contract_activities',
        'fields' => ['activity_name', 'category', 'rate_adult', 'rate_child',
                     'rate_per_vehicle', 'min_pax', 'duration', 'included_in_package', 'currency', 'notes'],
    ],
    'park_fees' => [
        'table' => 'contract_park_fees',
        'fields' => ['fee_name', 'fee_type', 'rate_adult', 'rate_child', 'child_age_limit',
                     'per_unit', 'high_season_rate_adult', 'high_season_rate_child',
                     'included_in_rate', 'currency', 'notes'],
    ],
    'transfers' => [
        'table' => 'contract_transfers',
        'fields' => ['transfer_name', 'transfer_type', 'rate_per_person', 'rate_per_vehicle',
                     'vehicle_capacity', 'distance', 'duration', 'currency', 'notes'],
    ],
    'cancellation_policies' => [
        'table' => 'contract_cancellation_policies',
        'fields' => ['days_before_from', 'days_before_to', 'charge_type', 'charge_value', 'currency', 'notes', 'sort_order'],
    ],
    'payment_terms' => [
        'table' => 'contract_payment_terms',
        'fields' => ['term_type', 'percentage', 'fixed_amount', 'days_before_arrival',
                     'payment_methods', 'bank_details', 'currency', 'notes'],
    ],
    'policies' => [
        'table' => 'contract_policies',
        'fields' => ['policy_type', 'policy_value', 'notes'],
    ],
    'resident_rates' => [
        'table' => 'contract_resident_rates',
        'fields' => ['room_type_id', 'season_id', 'meal_plan', 'rate_adult', 'rate_child',
                     'eligible_nationalities', 'required_documents', 'currency', 'notes'],
    ],
    'other_items' => [
        'table' => 'contract_other_items',
        'fields' => ['category', 'item_name', 'item_value', 'amount', 'currency', 'notes'],
    ],
];

if (!isset($sectionMap[$section])) {
    jsonError("Invalid section: {$section}", 400);
}

$config = $sectionMap[$section];
$table = $config['table'];
$fields = $config['fields'];

// Strategy: replace mode - delete existing then insert fresh
// This simplifies the update logic for contract data entry
$pdo->beginTransaction();

try {
    // If replace_all is set (default), clear existing
    if ($data['replace_all'] ?? true) {
        $pdo->prepare("DELETE FROM {$table} WHERE contract_id = ?")->execute([$contractId]);
    }

    $insertedIds = [];

    foreach ($items as $item) {
        $cols = ['contract_id'];
        $placeholders = ['?'];
        $values = [$contractId];

        foreach ($fields as $f) {
            if (array_key_exists($f, $item)) {
                $cols[] = $f;
                $placeholders[] = '?';
                $val = $item[$f];
                // Encode JSON fields
                if (is_array($val)) {
                    $val = json_encode($val);
                }
                $values[] = $val;
            }
        }

        $colStr = implode(', ', $cols);
        $placeholderStr = implode(', ', $placeholders);
        $stmt = $pdo->prepare("INSERT INTO {$table} ({$colStr}) VALUES ({$placeholderStr})");
        $stmt->execute($values);
        $insertedIds[] = (int)$pdo->lastInsertId();
    }

    $pdo->commit();

    // Audit log
    $pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action, details) VALUES (?, ?, 'data_saved', ?)")
        ->execute([$contractId, $auth['user_id'], json_encode(['section' => $section, 'count' => count($items)])]);

    // Return the saved items
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE contract_id = ? ORDER BY id");
    $stmt->execute([$contractId]);

    jsonResponse([
        'message' => ucfirst(str_replace('_', ' ', $section)) . ' saved',
        'data' => $stmt->fetchAll(),
        'count' => count($items),
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    jsonError('Failed to save data: ' . $e->getMessage(), 500);
}
