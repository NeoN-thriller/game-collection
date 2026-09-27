<?php
// Core app code — loaded by config.php. Safe to overwrite on updates.
if (!defined('DB_HOST')) { http_response_code(403); exit; }

ob_start(); // Buffer output so headers can always be sent

/** True when this request reached us over HTTPS (directly or via a reverse proxy). */
function isHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') == 443
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// FORCE_HTTPS (set in config.php): redirect HTTP -> HTTPS and send HSTS.
// Leave it off on servers without a certificate (e.g. local testing).
if (defined('FORCE_HTTPS') && FORCE_HTTPS) {
    if (!isHttps()) {
        header('Location: https://'.($_SERVER['HTTP_HOST'] ?? '').($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    header('Strict-Transport-Security: max-age=31536000');
}

// Cookies are only marked Secure when the request is HTTPS, so plain-HTTP test servers keep working
ini_set('session.cookie_secure',    isHttps() ? 1 : 0);
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

const MIN_PASSWORD_LENGTH = 12;

function setRememberCookie(string $token, int $expires): void {
    setcookie('remember_token', $token, ['expires'=>$expires, 'path'=>'/', 'secure'=>isHttps(), 'httponly'=>true, 'samesite'=>'Lax']);
}

function clearRememberCookie(): void {
    setcookie('remember_token', '', ['expires'=>time() - 3600, 'path'=>'/', 'secure'=>isHttps(), 'httponly'=>true, 'samesite'=>'Lax']);
}

/** Creates a 30-day remember-me token. Only its SHA-256 hash is stored in the DB. */
function issueRememberToken(int $userId): void {
    $token = bin2hex(random_bytes(32));
    db()->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?,?,?)")
        ->execute([$userId, hash('sha256', $token), date('Y-m-d H:i:s', time()+60*60*24*30)]);
    setRememberCookie($token, time()+60*60*24*30);
}

/** Fingerprint of the password hash: sessions die when the password changes. */
function pwSig(array $u): string {
    return substr(hash('sha256', $u['password']), 0, 16);
}

function startUserSession(array $u): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['pw_sig']  = pwSig($u);
}

/** Sets a new password and logs out every other session / remembered device of that user. */
function setPassword(int $userId, string $plain): void {
    $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost'=>12]);
    db()->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $userId]);
    db()->prepare("DELETE FROM remember_tokens WHERE user_id=?")->execute([$userId]);
    if (($_SESSION['user_id'] ?? null) == $userId) {
        $_SESSION['pw_sig'] = pwSig(['password'=>$hash]); // keep the current session alive
        clearRememberCookie();
    }
}

function auth(): array|false {
    if (!empty($_SESSION['user_id'])) {
        $st = db()->prepare("SELECT * FROM users WHERE id=? AND status='active'");
        $st->execute([$_SESSION['user_id']]);
        $u = $st->fetch();
        if ($u && hash_equals(pwSig($u), $_SESSION['pw_sig'] ?? '')) return $u;
        unset($_SESSION['user_id'], $_SESSION['pw_sig']);
    }
    $token = $_COOKIE['remember_token'] ?? '';
    if ($token && is_string($token)) {
        $st = db()->prepare("SELECT u.* FROM remember_tokens rt JOIN users u ON u.id = rt.user_id
                             WHERE rt.token=? AND rt.expires_at > NOW() AND u.status='active'");
        $st->execute([hash('sha256', $token)]);
        if ($u = $st->fetch()) {
            startUserSession($u);
            return $u;
        }
        clearRememberCookie();
    }
    return false;
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

function csrfValid(): bool {
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf'] ?? '', $sent);
}

/**
 * Include in every page's <head>. Adds the CSRF token as an X-CSRF-Token header
 * to all same-site fetch() calls, and turns "Sign Out" links into POSTs.
 */
function csrfScript(): string {
    $t = json_encode(csrf());
    $b = json_encode(BASE_URL, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    return <<<HTML
<script>
(() => {
  const TOKEN = $t, BASE = $b, _fetch = window.fetch;
  window.fetch = (input, init = {}) => {
    const url = new URL(input instanceof Request ? input.url : String(input), location.href);
    if (url.origin === location.origin || url.href.startsWith(BASE + '/')) {
      const h = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
      h.set('X-CSRF-Token', TOKEN);
      init = { ...init, headers: h };
    }
    return _fetch(input, init);
  };
  document.addEventListener('click', e => {
    const a = e.target.closest('a[href$="/api/logout.php"]');
    if (!a) return;
    e.preventDefault();
    const f = document.createElement('form');
    f.method = 'POST'; f.action = a.href;
    f.innerHTML = '<input type="hidden" name="csrf">';
    f.firstChild.value = TOKEN;
    document.body.appendChild(f); f.submit();
  });
})();
</script>
HTML;
}

function verifyCsrf(): void {
    if (!csrfValid()) {
        http_response_code(403); die('Invalid CSRF token');
    }
}

function jsonOut(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── Login throttling ─────────────────────
// Failure count => lockout seconds. 5, 10 and 20 lock once when reached;
// from 30 on, every further failure locks for 7 days.
// Counters reset on successful login, or after 30 days without a failure.
const LOGIN_LOCK_TIERS = [5 => 15*60, 10 => 60*60, 20 => 24*60*60, 30 => 7*24*60*60];

function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function loginKeys(string $username): array {
    return ['u:'.strtolower($username), 'ip:'.clientIp()];
}

/** Seconds left on the longest active lock among $keys (0 = not locked). */
function lockRemaining(array $keys): int {
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = db()->prepare("SELECT MAX(TIMESTAMPDIFF(SECOND, NOW(), locked_until)) FROM login_attempts
                         WHERE attempt_key IN ($in) AND locked_until > NOW()");
    $st->execute($keys);
    return max(0, (int)$st->fetchColumn());
}

function recordFailure(array $keys): void {
    $up = db()->prepare("INSERT INTO login_attempts (attempt_key, fail_count, last_fail_at) VALUES (?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            fail_count   = IF(last_fail_at < NOW() - INTERVAL 30 DAY, 1, fail_count + 1),
            last_fail_at = NOW()");
    $get  = db()->prepare("SELECT fail_count FROM login_attempts WHERE attempt_key=?");
    $lock = db()->prepare("UPDATE login_attempts SET locked_until = NOW() + INTERVAL ? SECOND WHERE attempt_key=?");
    foreach ($keys as $k) {
        $up->execute([$k]);
        $get->execute([$k]);
        $n = (int)$get->fetchColumn();
        $secs = $n >= 30 ? LOGIN_LOCK_TIERS[30] : (LOGIN_LOCK_TIERS[$n] ?? 0);
        if ($secs) $lock->execute([$secs, $k]);
    }
}

function clearFailures(array $keys): void {
    $in = implode(',', array_fill(0, count($keys), '?'));
    db()->prepare("DELETE FROM login_attempts WHERE attempt_key IN ($in)")->execute($keys);
}

function lockMessage(int $secs): string {
    $t = $secs >= 86400 ? ceil($secs/86400).' day(s)'
       : ($secs >= 3600 ? ceil($secs/3600).' hour(s)' : ceil($secs/60).' minute(s)');
    return "Too many failed attempts. Try again in $t.";
}

// ── Photo backups ───────────────────────
define('BACKUP_DIR', __DIR__ . '/uploads/backups/');

/** Absolute path of a stored backup zip (filename always comes from the DB, never from the request). */
function backupFilePath(int $userId, string $filename): string {
    return BACKUP_DIR . $userId . '/' . basename($filename);
}

/** Removes expired backup zips (all users) from disk and DB. */
function purgeExpiredBackups(): void {
    $rows = db()->query("SELECT user_id, filename FROM user_backups WHERE expires_at < NOW()")->fetchAll();
    foreach ($rows as $r) @unlink(backupFilePath((int)$r['user_id'], $r['filename']));
    db()->exec("DELETE FROM user_backups WHERE expires_at < NOW()");
}

/** Makes a string safe to use as a folder name inside a zip (Windows-safe, max 100 chars). */
function zipSafeName(string $s): string {
    $s = trim(preg_replace('~[\x00-\x1F\\\\/:*?"<>|]+~u', '_', $s) ?? '', " .");
    $s = preg_replace('/^(.{0,100}).*$/us', '$1', $s);
    return $s === '' ? '_' : $s;
}

function sanitizeFilename(string $name): string {
    return preg_replace('/[^a-z0-9_\-\.]/i', '_', $name);
}

// Every state-changing request to /api/* must carry a valid CSRF token
// (X-CSRF-Token header added by csrfScript(), or a 'csrf' form field).
if (basename(dirname($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'api'
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && !csrfValid()) {
    jsonOut(['ok'=>false, 'error'=>'Security token expired — please reload the page.'], 403);
}
