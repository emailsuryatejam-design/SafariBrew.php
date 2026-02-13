<?php
/**
 * Bulk delete/archive rate contracts.
 * POST { contract_ids: [1,2,3], mode: 'archive' | 'hard_delete' | 'revert_extraction' }
 */
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/rate-contracts-delete-logic.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') jsonError('Method not allowed', 405);

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$data = getJsonInput();
$contractIds = $data['contract_ids'] ?? [];
$mode = $data['mode'] ?? 'archive';

if (empty($contractIds) || !is_array($contractIds)) {
    jsonError('contract_ids array is required', 400);
}

if (!in_array($mode, ['archive', 'hard_delete', 'revert_extraction'])) {
    jsonError('mode must be archive, hard_delete, or revert_extraction', 400);
}

$results = [];
$errors = [];

foreach ($contractIds as $cid) {
    try {
        $results[(int)$cid] = deleteContractData($pdo, (int)$cid, $mode, $auth['user_id'], $bid);
    } catch (Exception $e) {
        $errors[(int)$cid] = $e->getMessage();
    }
}

jsonResponse([
    'message' => count($results) . ' contract(s) processed',
    'results' => $results,
    'errors' => $errors,
]);
