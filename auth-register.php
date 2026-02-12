<?php
require_once __DIR__ . '/middleware.php';
requireMethod('POST');

$auth = requireAdmin();
$data = getJsonInput();
requireFields($data, ['full_name', 'email', 'password', 'role']);

$pdo = getDB();

// Check duplicate
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$data['email']]);
if ($stmt->fetch()) jsonError('Email already exists', 409);

$hash = password_hash($data['password'], PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO users (branch_id, full_name, email, password_hash, role, phone) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([
    $auth['branch_id'],
    $data['full_name'],
    $data['email'],
    $hash,
    $data['role'],
    $data['phone'] ?? null,
]);

jsonResponse(['message' => 'User created', 'id' => (int)$pdo->lastInsertId()], 201);
