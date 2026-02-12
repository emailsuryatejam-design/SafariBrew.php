<?php
require_once __DIR__ . '/config.php';

function jwtEncode($payload) {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + JWT_EXPIRY;
    $payload['iat'] = time();
    $body = base64url_encode(json_encode($payload));
    $sig = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

function jwtDecode($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $signature] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $signature)) return null;
    $data = json_decode(base64url_decode($payload), true);
    if (!$data) return null;
    if (isset($data['exp']) && $data['exp'] < time()) return null;
    return $data;
}

function base64url_encode($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function base64url_decode($d) { return base64_decode(strtr($d, '-_', '+/')); }

function requireAuth() {
    $h = getallheaders();
    $auth = $h['Authorization'] ?? $h['authorization'] ?? '';
    if (empty($auth) || !str_starts_with($auth, 'Bearer ')) jsonError('Auth required', 401);
    $payload = jwtDecode(substr($auth, 7));
    if (!$payload) jsonError('Invalid/expired token', 401);
    return $payload;
}

function requireRole($roles) {
    $u = requireAuth();
    if (!in_array($u['role'], $roles)) jsonError('Forbidden', 403);
    return $u;
}

function requireAdmin() {
    return requireRole(['admin']);
}

function requireSalesOrAbove() {
    return requireRole(['admin', 'sales', 'operations']);
}
