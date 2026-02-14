<?php
/**
 * Public Company Registration Endpoint
 * No authentication required.
 * Creates a registration request for superadmin review.
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonInput();
requireFields($data, ['company_name', 'contact_name', 'email']);

$pdo = getDB();

// Check if email already has a pending/approved registration
$stmt = $pdo->prepare("SELECT id, status FROM registration_requests WHERE email = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$data['email']]);
$existing = $stmt->fetch();

if ($existing) {
    if ($existing['status'] === 'pending') {
        jsonError('A registration request with this email is already pending review.', 409);
    }
    if ($existing['status'] === 'approved') {
        jsonError('This email has already been registered. Please login or contact support.', 409);
    }
}

// Also check if a user with this email already exists
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$data['email']]);
if ($stmt->fetch()) {
    jsonError('An account with this email already exists. Please login or contact support.', 409);
}

$stmt = $pdo->prepare("
    INSERT INTO registration_requests (company_name, contact_name, email, phone, country, message)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    trim($data['company_name']),
    trim($data['contact_name']),
    trim($data['email']),
    $data['phone'] ?? null,
    $data['country'] ?? null,
    $data['message'] ?? null,
]);

jsonResponse([
    'message' => 'Registration request submitted successfully. Our team will review and set up your account.',
    'id' => (int)$pdo->lastInsertId(),
], 201);
