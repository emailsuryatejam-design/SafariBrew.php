<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// POST only - Upload base64 image
if ($method !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonInput();
requireFields($data, ['entity_type', 'entity_id', 'image_data']);

$entityType = $data['entity_type'];
$entityId = (int)$data['entity_id'];
$imageData = $data['image_data'];
$fileName = $data['file_name'] ?? 'upload';

// Validate entity_type
$validTypes = ['destination', 'accommodation', 'activity', 'template'];
if (!in_array($entityType, $validTypes)) {
    jsonError('Invalid entity_type. Must be: ' . implode(', ', $validTypes), 400);
}

// Parse base64 data - support both raw and data URI formats
$mimeType = 'image/jpeg';
if (preg_match('/^data:(image\/\w+);base64,/', $imageData, $matches)) {
    $mimeType = $matches[1];
    $imageData = substr($imageData, strpos($imageData, ',') + 1);
}

$decoded = base64_decode($imageData, true);
if ($decoded === false) {
    jsonError('Invalid base64 image data', 400);
}

// Determine file extension from mime type
$extMap = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    'image/svg+xml' => 'svg',
];
$ext = $extMap[$mimeType] ?? 'jpg';

// Create uploads directory if it doesn't exist
$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
    if (!mkdir($uploadsDir, 0755, true)) {
        jsonError('Failed to create uploads directory', 500);
    }
}

// Generate unique filename and save
$uniqueName = uniqid($entityType . '_', true) . '.' . $ext;
$filePath = $uploadsDir . '/' . $uniqueName;

if (file_put_contents($filePath, $decoded) === false) {
    jsonError('Failed to save file', 500);
}

$fileUrl = BASE_URL . '/api/uploads/' . $uniqueName;

// Store record in content_media table
$stmt = $pdo->prepare("
    INSERT INTO content_media (branch_id, entity_type, entity_id, file_url, file_name, mime_type)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $bid,
    $entityType,
    $entityId,
    $fileUrl,
    $fileName,
    $mimeType,
]);

jsonResponse([
    'message'  => 'File uploaded',
    'id'       => (int)$pdo->lastInsertId(),
    'file_url' => $fileUrl,
], 201);
