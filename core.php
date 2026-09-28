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

// ── Themes ──────────────────────────────
// A theme is one file in assets/themes/<slug>.css that overrides the :root
// variables of main.css. Its header comment holds the metadata:
//   /* Theme:  Nintendo
//      Scheme: light
//      Fonts:  https://fonts.googleapis.com/css2?family=...   (optional)
//      About:  one line for the picker                         (optional) */
const THEME_DIR     = __DIR__ . '/assets/themes/';
const DEFAULT_THEME = 'arcade-gold';

/** CSS variables used for the swatch previews in the theme pickers. */
const THEME_SWATCH_VARS = ['bg', 'surface', 'accent', 'accent2', 'wiiu', 'text'];

/** Reads `--name: value` pairs (hex colours only) from the first :root block of a stylesheet. */
function themeRootVars(string $css): array {
    if (!preg_match('~:root\s*\{(.*?)\}~s', $css, $m)) return [];
    preg_match_all('~--([a-z0-9-]+)\s*:\s*(#[0-9a-f]{3,8})\b~i', $m[1], $vars, PREG_SET_ORDER);
    $out = [];
    foreach ($vars as $v) $out[strtolower($v[1])] = strtolower($v[2]);
    return $out;
}

/**
 * All installed themes, keyed by slug: slug, name, scheme, fonts (URL), font_names, about, swatches.
 * Slugs come from file names and are whitelisted, so a slug is always safe to put in a path.
 */
function availableThemes(): array {
    static $themes = null;
    if ($themes !== null) return $themes;
    $themes = [];
    $base = themeRootVars((string)@file_get_contents(__DIR__ . '/assets/css/main.css'));
    foreach (glob(THEME_DIR . '*.css') ?: [] as $file) {
        $slug = basename($file, '.css');
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) continue;
        $css  = (string)@file_get_contents($file);
        $meta = [];
        if (preg_match('~^\s*/\*(.*?)\*/~s', $css, $m)) {
            foreach (preg_split('/\R/', $m[1]) as $line) {
                if (preg_match('/^\s*([A-Za-z]+)\s*:\s*(.+?)\s*$/', $line, $kv)) $meta[strtolower($kv[1])] = $kv[2];
            }
        }
        $fonts = $meta['fonts'] ?? '';
        // Only Google Fonts: it's the only font host the Content-Security-Policy allows
        if (!preg_match('~^https://fonts\.googleapis\.com/css2?\?[^"\'<>\s]+$~', $fonts)) $fonts = '';
        preg_match_all('/family=([^:&]+)/', $fonts, $fam);
        $vars = themeRootVars($css) + $base;
        $themes[$slug] = [
            'slug'     => $slug,
            'name'     => $meta['theme'] ?? ucwords(str_replace('-', ' ', $slug)),
            'scheme'   => strtolower($meta['scheme'] ?? '') === 'light' ? 'light' : 'dark',
            'fonts'    => $fonts,
            'font_names' => array_map(fn($f) => urldecode(str_replace('+', ' ', $f)), $fam[1]),
            'about'    => $meta['about'] ?? '',
            'swatches' => array_map(fn($k) => $vars[$k] ?? '#888888', THEME_SWATCH_VARS),
        ];
    }
    // Default theme first, the rest alphabetically
    uasort($themes, fn($a, $b) => [$a['slug'] !== DEFAULT_THEME, $a['name']] <=> [$b['slug'] !== DEFAULT_THEME, $b['name']]);
    return $themes;
}

/** Site-wide default theme (admin setting), falling back to Arcade Gold. */
function siteTheme(): string {
    static $slug = null;
    if ($slug === null) {
        $themes = availableThemes();
        try { $s = appSetting('default_theme'); } catch (PDOException) { $s = null; }
        $slug = ($s !== null && isset($themes[$s])) ? $s
              : (isset($themes[DEFAULT_THEME]) ? DEFAULT_THEME : (string)array_key_first($themes));
    }
    return $slug;
}

/** Theme for a page: the user's own choice, else the site default. */
function activeTheme(?array $user = null): string {
    $pick = $user['theme'] ?? null;
    return (is_string($pick) && isset(availableThemes()[$pick])) ? $pick : siteTheme();
}

/**
 * <head> tags for the active theme: font stylesheet + theme stylesheet.
 * Put it right after main.css. Rendered server-side, so there is no flash of the wrong theme.
 */
function themeHead(?array $user = null): string {
    $t = availableThemes()[activeTheme($user)] ?? null;
    if (!$t) return '';
    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
    $out = '<meta name="color-scheme" content="'.$t['scheme'].'">'."\n";
    if ($t['fonts']) {
        $out .= '<link rel="preconnect" href="https://fonts.googleapis.com">'."\n"
              . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'."\n"
              . '<link id="theme-fonts" href="'.$h($t['fonts']).'" rel="stylesheet">'."\n";
    }
    $file = THEME_DIR . $t['slug'] . '.css';
    $out .= '<link id="theme-css" rel="stylesheet" href="'.$h(BASE_URL.'/assets/themes/'.$t['slug'].'.css?v='.@filemtime($file)).'">'."\n";
    return $out;
}

/** Theme list for the pickers' live preview (JS), with ready-to-use URLs. */
function themesClientJson(): string {
    $out = [];
    foreach (availableThemes() as $t) {
        $out[$t['slug']] = [
            'name'   => $t['name'],
            'scheme' => $t['scheme'],
            'fonts'  => $t['fonts'],
            'css'    => BASE_URL.'/assets/themes/'.$t['slug'].'.css?v='.@filemtime(THEME_DIR.$t['slug'].'.css'),
        ];
    }
    return json_encode($out, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/** Small row of colour dots previewing a theme. */
function themeSwatchesHtml(array $t): string {
    $html = '<span class="theme-swatches" aria-hidden="true">';
    foreach ($t['swatches'] as $c) $html .= '<i style="background:'.htmlspecialchars($c).'"></i>';
    return $html.'</span>';
}

/**
 * One clickable theme card (a radio in a label) for the theme pickers in Settings and Admin.
 * The site default gets a small "Site default" mark. $notes are optional extra lines, one per entry.
 */
function themeCardHtml(array $t, string $group, bool $checked, string $onchange, array $notes = []): string {
    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
    return '<label class="theme-card">'
         . '<input type="radio" name="'.$h($group).'" value="'.$h($t['slug']).'"'.($checked ? ' checked' : '').' onchange="'.$h($onchange).'(this.value)">'
         . '<span class="theme-card-head"><span class="theme-name">'.$h($t['name']).'</span>'.themeSwatchesHtml($t).'</span>'
         . ($t['about'] ? '<span class="theme-about">'.$h($t['about']).'</span>' : '')
         . '<span class="theme-card-foot"><span class="theme-tag">'.$t['scheme'].'</span>'
         . ($t['slug'] === siteTheme() ? '<span class="theme-default">★ Site default</span>' : '')
         . '</span>'
         . ($notes ? '<span class="theme-note">'.implode('<br>', array_map($h, $notes)).'</span>' : '')
         . '</label>';
}

// Condition grading (labels, templates, profiles, scoring)
require_once __DIR__ . '/grading.php';

// Every state-changing request to /api/* must carry a valid CSRF token
// (X-CSRF-Token header added by csrfScript(), or a 'csrf' form field).
if (basename(dirname($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'api'
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && !csrfValid()) {
    jsonOut(['ok'=>false, 'error'=>'Security token expired — please reload the page.'], 403);
}
