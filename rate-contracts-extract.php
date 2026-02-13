<?php
/**
 * AI Brew - Gemini Vision-powered contract data extraction.
 * POST { contract_id }
 *
 * This endpoint validates the request and launches a background CLI worker.
 * The worker converts PDF→images→Gemini Vision→structured JSON→DB tables.
 * Frontend polls extraction_status for progress.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') jsonError('Method not allowed', 405);

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$data = getJsonInput();
requireFields($data, ['contract_id']);

$contractId = (int)$data['contract_id'];

// Verify contract ownership and status
$stmt = $pdo->prepare("SELECT * FROM rate_contracts WHERE id = ? AND branch_id = ?");
$stmt->execute([$contractId, $bid]);
$contract = $stmt->fetch();
if (!$contract) jsonError('Contract not found', 404);

if ($contract['extraction_mode'] !== 'ai_brew') {
    jsonError('This contract is set to manual entry mode', 400);
}

// Update status to processing
$pdo->prepare("UPDATE rate_contracts SET extraction_status = 'processing' WHERE id = ?")
    ->execute([$contractId]);

// Launch background worker via CLI
$workerScript = __DIR__ . '/rate-contracts-extract-worker.php';
$userId = (int)$auth['user_id'];
$logFile = sys_get_temp_dir() . "/extract_{$contractId}.log";

$cmd = sprintf(
    'nohup php %s %d %d > %s 2>&1 &',
    escapeshellarg($workerScript),
    $contractId,
    $userId,
    escapeshellarg($logFile)
);

exec($cmd);

jsonResponse([
    'message' => 'Extraction started. This will take 1-2 minutes.',
    'extraction_status' => 'processing',
]);
