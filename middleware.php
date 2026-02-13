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

/**
 * Require the current user to be a platform superadmin.
 * Superadmin flag is stored in JWT and verified against DB.
 */
function requireSuperadmin() {
    $auth = requireAuth();
    if (empty($auth['is_superadmin'])) {
        jsonError('Superadmin access required', 403);
    }
    return $auth;
}

/**
 * Check if the authenticated user's branch has access to a module.
 * Module names: core, crm, content, rate_management, quoting, finance, ai_brew
 */
function requireModule($module) {
    $auth = requireAuth();
    $pdo = getDB();

    // 'core' is always available
    if ($module === 'core') return $auth;

    $stmt = $pdo->prepare("
        SELECT 1 FROM branch_modules
        WHERE branch_id = ? AND module_name = ? AND is_active = 1
          AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$auth['branch_id'], $module]);

    if (!$stmt->fetch()) {
        $moduleName = str_replace('_', ' ', ucfirst($module));
        jsonError("This feature requires the {$moduleName} module. Please upgrade your plan.", 403);
    }

    return $auth;
}

/**
 * Get list of active modules for a branch.
 */
function getActiveModules($pdo, $branchId) {
    $stmt = $pdo->prepare("
        SELECT module_name FROM branch_modules
        WHERE branch_id = ? AND is_active = 1
          AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$branchId]);
    return array_column($stmt->fetchAll(), 'module_name');
}
