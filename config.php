<?php
// ─────────────────────────────────────────
//  CONFIGURATION — edit these values
// ─────────────────────────────────────────

define('BASE_URL',   'https://someurl.us');   // No trailing slash. Change to /games if needed
define('DB_HOST',    'bd_host');
define('DB_NAME',    'db_name');
define('DB_USER',    'db_user');
define('DB_PASS',    'db_pass');
define('DB_CHARSET', 'utf8mb4');

// Session name (change this to something unique for your site)
define('SESSION_NAME', 'gcollect_session');

// Upload settings
define('UPLOAD_DIR',      __DIR__ . '/uploads/users/');
define('DEFAULTS_DIR',    __DIR__ . '/uploads/defaults/');
define('UPLOAD_URL',      BASE_URL . '/uploads/users/');
define('DEFAULTS_URL',    BASE_URL . '/uploads/defaults/');
define('MAX_FILE_SIZE',   8 * 1024 * 1024); // 8MB per image
define('ALLOWED_TYPES',   ['image/jpeg','image/png','image/gif','image/webp']);

// ─────────────────────────────────────────
//  DO NOT EDIT BELOW THIS LINE
// ─────────────────────────────────────────
ob_start(); // Buffer output so headers can always be sent
ini_set('session.cookie_httponly',  1);
ini_set('session.use_strict_mode',  1);
ini_set('session.gc_maxlifetime',   60*60*24*2); // 2 days
ini_set('session.cookie_lifetime',  0);           // until browser closes
// SameSite=Lax for PHP 7.3+
if (PHP_VERSION_ID >= 70300) {
    ini_set('session.cookie_samesite', 'Lax');
}
session_name(SESSION_NAME);
session_start();

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function setRememberCookie(string $token, int $expires): void {
    setcookie('remember_token', $token, $expires, '/', '', false, true);
}

function clearRememberCookie(): void {
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

function auth(): array|false {
    if (empty($_SESSION['user_id'])) {
        $token = $_COOKIE['remember_token'] ?? '';
        if ($token) {
            $st = db()->prepare("SELECT rt.user_id FROM remember_tokens rt WHERE rt.token=? AND rt.expires_at > NOW()");
            $st->execute([$token]);
            $row = $st->fetch();
            if ($row) {
                $st2 = db()->prepare("SELECT * FROM users WHERE id=? AND status='active'");
                $st2->execute([$row['user_id']]);
                $u = $st2->fetch();
                if ($u) {
                    $_SESSION['user_id'] = $u['id'];
                    setRememberCookie($token, time() + 60*60*24*30);
                    return $u;
                }
            }
            clearRememberCookie();
        }
        return false;
    }
    $st = db()->prepare("SELECT * FROM users WHERE id=? AND status='active'");
    $st->execute([$_SESSION['user_id']]);
    return $st->fetch() ?: false;
}

function requireAuth(): array {
    $u = auth();
    if (!$u) { header('Location: '.BASE_URL.'/index.php'); exit; }
    return $u;
}

function requireAdmin(): array {
    $u = requireAuth();
    if ($u['role'] !== 'admin') { header('Location: '.BASE_URL.'/collection.php'); exit; }
    return $u;
}

function isAdmin(): bool {
    $u = auth();
    return $u && $u['role'] === 'admin';
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        http_response_code(403); die('Invalid CSRF token');
    }
}

function jsonOut(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function sanitizeFilename(string $name): string {
    return preg_replace('/[^a-z0-9_\-\.]/i', '_', $name);
}
