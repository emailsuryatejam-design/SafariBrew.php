<?php
require_once __DIR__ . '/middleware.php';
requireMethod('POST');

$data = getJsonInput();
requireFields($data, ['email', 'password']);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT u.*, b.name as branch_name, b.company_name, b.currency as branch_currency, b.quote_color, b.logo_url as branch_logo FROM users u JOIN branches b ON b.id = u.branch_id WHERE u.email = ? AND u.is_active = 1");
$stmt->execute([$data['email']]);
$user = $stmt->fetch();

if (!$user || !password_verify($data['password'], $user['password_hash'])) {
    jsonError('Invalid email or password', 401);
}

$token = jwtEncode([
    'user_id' => $user['id'],
    'branch_id' => $user['branch_id'],
    'role' => $user['role'],
    'name' => $user['full_name'],
]);

jsonResponse([
    'token' => $token,
    'user' => [
        'id' => (int)$user['id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'phone' => $user['phone'],
        'avatar_url' => $user['avatar_url'],
        'branch_id' => (int)$user['branch_id'],
        'branch_name' => $user['branch_name'],
        'company_name' => $user['company_name'],
        'branch_currency' => $user['branch_currency'],
        'quote_color' => $user['quote_color'],
        'branch_logo' => $user['branch_logo'],
    ]
]);
