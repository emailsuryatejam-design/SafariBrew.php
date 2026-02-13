<?php
/**
 * Upload a rate contract PDF file.
 * Accepts multipart/form-data with 'file' field.
 * Returns the file URL and contract ID.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware.php';

// Override Content-Type header for this endpoint since it accepts form data
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(200); exit; }
if ($method !== 'POST') jsonError('Method not allowed', 405);

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// Check for uploaded file
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'] ?? -1;
    $errMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds server size limit',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form size limit',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
    ];
    jsonError($errMessages[$errCode] ?? 'File upload failed', 400);
}

$file = $_FILES['file'];
$allowedTypes = ['application/pdf'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    jsonError('Only PDF files are allowed', 400);
}

$maxSize = 20 * 1024 * 1024; // 20MB
if ($file['size'] > $maxSize) {
    jsonError('File size exceeds 20MB limit', 400);
}

// Create uploads directory
$uploadDir = __DIR__ . '/uploads/contracts';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
$uniqueName = $safeName . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$targetPath = $uploadDir . '/' . $uniqueName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    jsonError('Failed to save file', 500);
}

$fileUrl = BASE_URL . '/uploads/contracts/' . $uniqueName;

// Get extraction mode from form data
$extractionMode = $_POST['extraction_mode'] ?? 'manual';
$propertyName = $_POST['property_name'] ?? pathinfo($file['name'], PATHINFO_FILENAME);
$accommodationId = !empty($_POST['accommodation_id']) ? (int)$_POST['accommodation_id'] : null;

// Create the contract record
$code = generateCode('RCT', $pdo, 'rate_contracts', 'contract_code');

$stmt = $pdo->prepare("
    INSERT INTO rate_contracts (
        branch_id, accommodation_id, contract_code, property_name,
        status, extraction_mode, extraction_status,
        original_file_url, original_file_name, file_size,
        currency, uploaded_by
    ) VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, 'USD', ?)
");
$stmt->execute([
    $bid,
    $accommodationId,
    $code,
    $propertyName,
    $extractionMode,
    $extractionMode === 'ai_brew' ? 'pending' : 'completed',
    $fileUrl,
    $file['name'],
    $file['size'],
    $auth['user_id'],
]);

$contractId = (int)$pdo->lastInsertId();

// Audit log
$pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action, details) VALUES (?, ?, 'uploaded', ?)")
    ->execute([$contractId, $auth['user_id'], json_encode([
        'file' => $file['name'],
        'size' => $file['size'],
        'mode' => $extractionMode,
    ])]);

$stmt = $pdo->prepare("SELECT * FROM rate_contracts WHERE id = ?");
$stmt->execute([$contractId]);

jsonResponse([
    'message' => 'Contract uploaded successfully',
    'data' => $stmt->fetch(),
    'file_url' => $fileUrl,
], 201);
