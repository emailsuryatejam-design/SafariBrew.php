<?php
// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'u929828006_SBV01');
define('DB_USER', 'u929828006_SBV01');
define('DB_PASS', '6145ury@Teja');

// App
define('JWT_SECRET', 'safaribrew-jwt-secret-change-in-production-2026');
define('JWT_EXPIRY', 8 * 3600); // 8 hours
define('BASE_URL', 'http://localhost:8000');

// AI APIs (for AI Brew extraction)
define('GEMINI_API_KEY', 'AIzaSyDso0Ae7zMkPuswSzrmPYfr9Q1KhQlls8c');
define('GEMINI_MODEL', 'gemini-2.0-flash');
define('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// PDO singleton
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed']);
            exit;
        }
    }
    return $pdo;
}

// Response helpers
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError($message, $status = 400) {
    jsonResponse(['error' => $message], $status);
}

function getJsonInput() {
    $i = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) jsonError('Invalid JSON', 400);
    return $i;
}

function requireMethod($m) {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($m)) jsonError('Method not allowed', 405);
}

function requireFields($data, $fields) {
    foreach ($fields as $f) {
        if (!isset($data[$f]) || $data[$f] === '') jsonError("Missing: {$f}", 400);
    }
}

function generateCode($prefix, $pdo, $table, $column) {
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM {$table}");
    $count = $stmt->fetch()['cnt'] + 1;
    return $prefix . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
}
