<?php
require_once __DIR__ . '/middleware.php';
requireMethod('GET');

$auth = requireAuth();
$pdo = getDB();

$stmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, u.role, u.phone, u.avatar_url, u.branch_id,
           b.name as branch_name, b.company_name, b.currency, b.quote_color, b.quote_font,
           b.logo_url, b.email as branch_email, b.phone as branch_phone,
           b.address, b.website, b.country
    FROM users u
    JOIN branches b ON b.id = u.branch_id
    WHERE u.id = ? AND u.is_active = 1
");
$stmt->execute([$auth['user_id']]);
$user = $stmt->fetch();

if (!$user) jsonError('User not found', 404);

jsonResponse([
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
        'branch_currency' => $user['currency'],
        'quote_color' => $user['quote_color'],
        'branch_logo' => $user['logo_url'],
    ],
    'branch' => [
        'company_name' => $user['company_name'],
        'email' => $user['branch_email'],
        'phone' => $user['branch_phone'],
        'address' => $user['address'],
        'website' => $user['website'],
        'country' => $user['country'],
        'currency' => $user['currency'],
        'logo_url' => $user['logo_url'],
        'quote_color' => $user['quote_color'],
        'quote_font' => $user['quote_font'],
    ]
]);
