<?php
// ═══════════════════════════════════════════════════════════════════
//  Game Collection — setup wizard
//
//  Runs only while there is no config.php and no installed.lock.
//  Nothing is written until the last step: the answers are kept in the
//  session, then "Install" creates the tables, saves the settings, adds
//  the systems and the admin account, and writes config.php.
//  Standalone on purpose: it can't load core.php before config.php exists.
// ═══════════════════════════════════════════════════════════════════

const INSTALL_MIN_PHP = '8.1.0';
const INSTALL_STEPS   = 8; // step 9 ("done") is shown right after installing

// Same lists as site.php (which can't be loaded here)
const I_REGIONS        = ['PAL', 'NTSC-U', 'NTSC-J', 'Mixed'];
const I_DATE_FORMATS   = ['DD-MMM-YYYY', 'D MMM YYYY', 'DD-MM-YYYY', 'DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'];
const I_DECIMAL_SEPS   = ['.', ','];
const I_THOUSANDS_SEPS = [',', '.', ' ', "'", ''];
const I_VALUE_TYPES    = ['loose', 'cib', 'new'];
const I_GRADING        = ['simple', 'points', 'both'];

session_name('gc_install');
session_start();

// ── Small helpers ────────────────────────

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }

function csrfToken(): string {
    if (empty($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['install_csrf'];
}

function csrfOk(): bool {
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['install_csrf'] ?? '', $sent);
}

/**
 * Systems offered on step 5, from assets/systems.json: maker => [id => system], makers A–Z.
 * A system is ['short', 'name', 'us_short'?, 'us_name'?]; its id is its short name.
 */
function systemCatalog(): array {
    static $cat = null;
    if ($cat !== null) return $cat;
    $cat  = [];
    $seen = [];
    $data = json_decode((string)@file_get_contents(__DIR__ . '/assets/systems.json'), true);
    foreach (is_array($data) ? $data : [] as $maker => $list) {
        if (!is_array($list) || str_starts_with((string)$maker, '_')) continue;
        foreach ($list as $sys) {
            $short = trim((string)($sys['short'] ?? ''));
            $name  = trim((string)($sys['name'] ?? ''));
            if ($short === '' || $name === '' || isset($seen[strtoupper($short)])) continue; // short names must be unique
            $seen[strtoupper($short)] = true;
            $cat[(string)$maker][$short] = [
                'short' => mb_substr($short, 0, 30), 'name' => mb_substr($name, 0, 100),
                'us_short' => mb_substr(trim((string)($sys['us_short'] ?? '')), 0, 30),
                'us_name'  => mb_substr(trim((string)($sys['us_name'] ?? '')), 0, 100),
            ];
        }
    }
    uksort($cat, 'strnatcasecmp');
    return $cat;
}

/** Name or short name of a catalog system for a region (NTSC-U uses the US names where given). */
function sysLabel(array $s, string $region, string $field = 'name'): string {
    return $region === 'NTSC-U' && $s['us_' . $field] !== '' ? $s['us_' . $field] : $s[$field];
}

/** Installed languages: code => name (from lang/*.json). */
function langList(): array {
    static $list = null;
    if ($list === null) {
        $list = [];
        foreach (glob(__DIR__ . '/lang/*.json') ?: [] as $f) {
            $code = basename($f, '.json');
            if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $code)) continue;
            $d = json_decode((string)file_get_contents($f), true);
            if (is_array($d)) $list[$code] = is_string($d['_meta']['name'] ?? null) ? $d['_meta']['name'] : $code;
        }
        if (!$list) $list = ['en' => 'English'];
    }
    return $list;
}

function langTexts(string $code): array {
    static $cache = [];
    if (!isset($cache[$code])) {
        $f = __DIR__ . "/lang/$code.json";
        $d = isset(langList()[$code]) && is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
        $cache[$code] = is_array($d) ? array_filter($d, 'is_string') : [];
    }
    return $cache[$code];
}

/** Text in the installer's language (English underneath), like t() in the app: safe for HTML. */
function it(string $key, array $vars = []): string {
    $s = str_replace('"', '&quot;', itRaw($key));
    foreach ($vars as $k => $v) $s = str_replace('{'.$k.'}', h((string)$v), $s);
    return $s;
}

function itRaw(string $key, array $vars = []): string {
    $s = langTexts(wiz('lang'))[$key] ?? langTexts('en')[$key] ?? $key;
    foreach ($vars as $k => $v) $s = str_replace('{'.$k.'}', (string)$v, $s);
    return $s;
}

/** Default option list of a language ("a|b|c" in the language file), one per line. */
function langDefaultList(string $code, string $key): string {
    $s = langTexts($code)[$key] ?? langTexts('en')[$key] ?? '';
    return implode("\n", array_map('trim', explode('|', $s)));
}

/** Today's date in a date format (e.g. DD-MMM-YYYY → 29-Sep-2026), month names in the given language. */
function fmtExample(string $format, string $lang): string {
    $months = explode(' ', langTexts($lang)['date.months_short'] ?? langTexts('en')['date.months_short'] ?? '');
    return strtr($format, ['YYYY' => date('Y'), 'MMM' => $months[(int)date('n') - 1] ?? date('M'),
                           'MM' => date('m'), 'DD' => date('d'), 'D' => date('j')]);
}

/** `--name: #hex` pairs from the first :root block of a stylesheet (same as themeRootVars() in core.php). */
function rootColors(string $css): array {
    if (!preg_match('~:root\s*\{(.*?)\}~s', $css, $m)) return [];
    preg_match_all('~--([a-z0-9-]+)\s*:\s*(#[0-9a-f]{3,8})\b~i', $m[1], $vars, PREG_SET_ORDER);
    $out = [];
    foreach ($vars as $v) $out[strtolower($v[1])] = strtolower($v[2]);
    return $out;
}

/** Theme files: slug => [name, about, fonts URL, swatches]. */
function themeList(): array {
    $out = [];
    $base = rootColors((string)@file_get_contents(__DIR__ . '/assets/css/main.css'));
    foreach (glob(__DIR__ . '/assets/themes/*.css') ?: [] as $f) {
        $slug = basename($f, '.css');
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) continue;
        $css = (string)file_get_contents($f);
        preg_match('~^\s*/\*(.*?)\*/~s', $css, $m);
        $meta = [];
        foreach (preg_split('/\R/', $m[1] ?? '') as $line) {
            if (preg_match('/^\s*([A-Za-z]+)\s*:\s*(.+?)\s*$/', $line, $kv)) $meta[strtolower($kv[1])] = $kv[2];
        }
        $fonts = preg_match('~^https://fonts\.googleapis\.com/css2?\?[^"\'<>\s]+$~', $meta['fonts'] ?? '') ? $meta['fonts'] : '';
        // Theme colours on top of the defaults in main.css (a theme that overrides nothing uses those)
        $sw = rootColors($css) + $base;
        $out[$slug] = ['name' => $meta['theme'] ?? $slug, 'about' => $meta['about'] ?? '', 'fonts' => $fonts,
                       'swatches' => array_values(array_filter(array_map(fn($k) => $sw[$k] ?? null, ['bg', 'surface', 'accent', 'accent2', 'wiiu', 'text'])))];
    }
    uksort($out, fn($a, $b) => [$a !== 'arcade-gold', $a] <=> [$b !== 'arcade-gold', $b]);
    return $out;
}

function detectBaseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

// ── Wizard state (session) ───────────────

function wiz(string $key, mixed $default = null): mixed { return $_SESSION['wiz'][$key] ?? $default; }

function initWizard(): void {
    if (isset($_SESSION['wiz'])) return;
    $lang = 'en';
    foreach (explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') as $part) {
        $code = strtolower(substr(trim($part), 0, 2));
        if (isset(langList()[$code])) { $lang = $code; break; }
    }
    if (!isset(langList()[$lang])) $lang = array_key_first(langList());
    $https = str_starts_with(detectBaseUrl(), 'https://');
    $_SESSION['wiz'] = [
        'lang' => $lang,
        // database
        'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => 'game_collection',
        'db_user' => '', 'db_pass' => '', 'db_create' => '1',
        // site
        'site_name' => 'Game Collection', 'base_url' => detectBaseUrl(), 'force_https' => $https ? '1' : '0',
        'session_name' => 'gc_' . bin2hex(random_bytes(4)), 'timezone' => date_default_timezone_get(),
        'upload_mb' => '8', 'img_max_width' => '1200', 'img_max_height' => '1200', 'img_quality' => '80',
        // region & currency
        'default_region' => 'PAL', 'currency_symbol' => '€', 'currency_position' => 'before', 'currency_space' => '0',
        'decimal_sep' => '.', 'thousands_sep' => ',', 'date_format' => 'DD-MMM-YYYY', 'default_language' => $lang,
        // systems
        'systems' => [],
        // collection defaults
        'default_grading' => 'simple', 'default_value_type' => 'cib', 'default_theme' => 'arcade-gold',
        'defaults_completeness' => null, 'defaults_played' => null,
        // admin
        'admin_user' => '', 'admin_hash' => '', 'admin_lang' => $lang, 'make_invites' => '1',
    ];
    $_SESSION['wiz_max'] = 1;
}

// ── Checks ───────────────────────────────

/** Server requirements: [label, ok, required, detail]. */
function requirementChecks(): array {
    $gdWebp = function_exists('gd_info') && !empty(gd_info()['WebP Support']);
    $dirOk  = function (string $rel) {
        $p = __DIR__ . '/' . $rel;
        if (!is_dir($p)) @mkdir($p, 0755, true);
        return is_dir($p) && is_writable($p);
    };
    return [
        [itRaw('install.req.php', ['v' => INSTALL_MIN_PHP]), version_compare(PHP_VERSION, INSTALL_MIN_PHP, '>='), true, PHP_VERSION],
        ['pdo_mysql',  extension_loaded('pdo_mysql'), true, ''],
        ['mbstring',   extension_loaded('mbstring'),  true, ''],
        ['fileinfo',   extension_loaded('fileinfo'),  true, ''],
        ['json',       function_exists('json_encode'), true, ''],
        ['gd',         extension_loaded('gd'),        true, itRaw('install.req.gd')],
        [itRaw('install.req.webp'), $gdWebp,          false, itRaw('install.req.webp_note')],
        ['exif',       function_exists('exif_read_data'), false, itRaw('install.req.exif_note')],
        ['zip',        class_exists('ZipArchive'),    false, itRaw('install.req.zip_note')],
        [itRaw('install.req.w_uploads'), $dirOk('uploads') && $dirOk('uploads/users') && $dirOk('uploads/defaults')
                                         && $dirOk('uploads/icons') && $dirOk('uploads/backups'), true, 'uploads/'],
        [itRaw('install.req.w_config'), is_writable(__DIR__), false, itRaw('install.req.w_config_note')],
        [itRaw('install.req.w_lang'),   is_dir(__DIR__ . '/lang') && is_writable(__DIR__ . '/lang'), false, itRaw('install.req.w_lang_note')],
    ];
}

function requirementsMet(): bool {
    foreach (requirementChecks() as [, $ok, $required]) if ($required && !$ok) return false;
    return true;
}

/** Tables created by schema.sql. */
function schemaTables(): array {
    preg_match_all('/CREATE TABLE `(\w+)`/', (string)file_get_contents(__DIR__ . '/schema.sql'), $m);
    return $m[1];
}

function wizPdo(array $w, bool $withDb): PDO {
    $dsn = 'mysql:host=' . $w['db_host'] . ';port=' . (int)$w['db_port'] . ';charset=utf8mb4' . ($withDb ? ';dbname=' . $w['db_name'] : '');
    // @: connection problems come back as the exception; don't also print them as PHP warnings
    return @new PDO($dsn, $w['db_user'], $w['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5,
    ]);
}

/**
 * Connects with the given details. Returns ['ok', 'error', 'version', 'exists', 'tables'].
 * Never creates anything: "create the database" happens at install time.
 */
function checkDatabase(array $w): array {
    $r = ['ok' => false, 'error' => '', 'version' => '', 'exists' => false, 'tables' => []];
    if (!preg_match('/^[A-Za-z0-9.\-_:\[\]]{1,255}$/', $w['db_host'])) { $r['error'] = itRaw('install.db.err_host'); return $r; }
    if ((int)$w['db_port'] < 1 || (int)$w['db_port'] > 65535)        { $r['error'] = itRaw('install.db.err_port'); return $r; }
    if (!preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $w['db_name']))     { $r['error'] = itRaw('install.db.err_name'); return $r; }
    if ($w['db_user'] === '')                                          { $r['error'] = itRaw('install.db.err_user'); return $r; }
    try {
        $pdo = wizPdo($w, false);
        $r['version'] = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
        $st->execute([$w['db_name']]);
        $r['exists'] = (bool)$st->fetchColumn();
        if ($r['exists']) {
            $st = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=?');
            $st->execute([$w['db_name']]);
            $r['tables'] = array_values(array_intersect(schemaTables(), $st->fetchAll(PDO::FETCH_COLUMN)));
        }
    } catch (Throwable $e) {
        $r['error'] = itRaw('install.db.err_connect', ['error' => $e->getMessage()]);
        return $r;
    }
    // MySQL 8+ or MariaDB 10.5+
    $isMaria = stripos($r['version'], 'mariadb') !== false;
    preg_match('/^(\d+\.\d+)/', $r['version'], $vm);
    if (version_compare($vm[1] ?? '0', $isMaria ? '10.5' : '8.0', '<')) { $r['error'] = itRaw('install.db.err_version', ['version' => $r['version']]); return $r; }
    if (!$r['exists'] && $w['db_create'] !== '1') { $r['error'] = itRaw('install.db.err_missing', ['name' => $w['db_name']]); return $r; }
    if ($r['tables'])                              { $r['error'] = itRaw('install.db.err_tables', ['tables' => implode(', ', $r['tables'])]); return $r; }
    $r['ok'] = true;
    return $r;
}

// ── Validation per step ──────────────────
// Each returns a list of error messages; valid values are stored in the session.

function saveStep(int $step, array $in): array {
    $w = &$_SESSION['wiz'];
    $v = fn(string $k) => trim((string)($in[$k] ?? ''));
    $raw = fn(string $k) => (string)($in[$k] ?? '');
    $e = [];
    switch ($step) {
        case 1:
            if (!requirementsMet()) $e[] = itRaw('install.req.err');
            break;
        case 2:
            foreach (['db_host', 'db_port', 'db_name', 'db_user'] as $k) $w[$k] = $v($k);
            $w['db_pass']   = $raw('db_pass');
            $w['db_create'] = !empty($in['db_create']) ? '1' : '0';
            $chk = checkDatabase($w);
            if (!$chk['ok']) $e[] = $chk['error'];
            break;
        case 3:
            $w['site_name'] = $v('site_name');
            $w['base_url']  = rtrim($v('base_url'), '/');
            $w['force_https'] = !empty($in['force_https']) ? '1' : '0';
            $w['session_name'] = $v('session_name');
            $w['timezone'] = $v('timezone');
            foreach (['upload_mb', 'img_max_width', 'img_max_height', 'img_quality'] as $k) $w[$k] = (string)(int)$v($k);
            if ($w['site_name'] === '' || mb_strlen($w['site_name']) > 60) $e[] = itRaw('admin.site.err_name');
            if (!preg_match('~^https?://[^\s"\'<>]+$~', $w['base_url'])) $e[] = itRaw('install.site.err_url');
            if ($w['force_https'] === '1' && !str_starts_with($w['base_url'], 'https://')) $e[] = itRaw('install.site.err_https');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{2,39}$/', $w['session_name'])) $e[] = itRaw('install.site.err_session');
            if (!in_array($w['timezone'], timezone_identifiers_list(), true)) $e[] = itRaw('install.site.err_tz');
            if ((int)$w['upload_mb'] < 1 || (int)$w['upload_mb'] > 64) $e[] = itRaw('install.site.err_mb');
            if ((int)$w['img_max_width'] < 200 || (int)$w['img_max_width'] > 4000
                || (int)$w['img_max_height'] < 200 || (int)$w['img_max_height'] > 4000) $e[] = itRaw('install.site.err_size');
            if ((int)$w['img_quality'] < 10 || (int)$w['img_quality'] > 100) $e[] = itRaw('install.site.err_quality');
            break;
        case 4:
            $w['default_region']    = in_array($v('default_region'), I_REGIONS, true) ? $v('default_region') : 'PAL';
            $w['currency_symbol']   = $v('currency_symbol');
            $w['currency_position'] = $v('currency_position') === 'after' ? 'after' : 'before';
            $w['currency_space']    = $v('currency_space') === '1' ? '1' : '0';
            $w['decimal_sep']       = $raw('decimal_sep');
            $w['thousands_sep']     = $raw('thousands_sep');
            $w['date_format']       = in_array($v('date_format'), I_DATE_FORMATS, true) ? $v('date_format') : 'DD-MMM-YYYY';
            $w['default_language']  = isset(langList()[$v('default_language')]) ? $v('default_language') : $w['lang'];
            if ($w['currency_symbol'] === '' || mb_strlen($w['currency_symbol']) > 5) $e[] = itRaw('admin.site.err_symbol');
            if (!in_array($w['decimal_sep'], I_DECIMAL_SEPS, true) || !in_array($w['thousands_sep'], I_THOUSANDS_SEPS, true)
                || $w['decimal_sep'] === $w['thousands_sep']) $e[] = itRaw('admin.site.err_seps');
            break;
        case 5:
            // Ids like 2600 turn into ints as PHP array keys: compare and store them as strings
            $known = array_map('strval', array_merge(...array_map('array_keys', array_values(systemCatalog() ?: [[]]))));
            $w['systems'] = array_values(array_intersect($known, array_map('strval', (array)($in['systems'] ?? []))));
            break;
        case 6:
            $w['default_grading']    = in_array($v('default_grading'), I_GRADING, true) ? $v('default_grading') : 'simple';
            $w['default_value_type'] = in_array($v('default_value_type'), I_VALUE_TYPES, true) ? $v('default_value_type') : 'cib';
            $w['default_theme']      = isset(themeList()[$v('default_theme')]) ? $v('default_theme') : 'arcade-gold';
            foreach (['defaults_completeness', 'defaults_played'] as $k) {
                $lines = array_values(array_unique(array_filter(array_map(fn($s) => mb_substr(trim($s), 0, 100), preg_split('/\R/', $raw($k))), 'strlen')));
                $w[$k] = implode("\n", array_slice($lines, 0, 30));
            }
            if ($w['defaults_completeness'] === '' || $w['defaults_played'] === '') $e[] = itRaw('install.def.err_lists');
            break;
        case 7:
            $user = $v('admin_user'); $pass = $raw('admin_pass'); $conf = $raw('admin_pass2');
            $w['admin_user']   = $user;
            $w['admin_lang']   = isset(langList()[$v('admin_lang')]) ? $v('admin_lang') : $w['lang'];
            $w['make_invites'] = !empty($in['make_invites']) ? '1' : '0';
            if (strlen($user) < 3 || strlen($user) > 50) $e[] = itRaw('auth.err_username_length');
            elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $user)) $e[] = itRaw('auth.err_username_chars');
            // A password is required the first time; after that an empty field keeps the one already given
            if ($pass !== '' || $w['admin_hash'] === '') {
                if (strlen($pass) < 12) $e[] = itRaw('common.err_password_length', ['n' => 12]);
                elseif ($pass !== $conf) $e[] = itRaw('common.err_password_match');
                elseif (strtolower($pass) === strtolower($user)) $e[] = itRaw('install.admin.err_same');
                else $w['admin_hash'] = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            }
            break;
    }
    return $e;
}

// ── Install ──────────────────────────────

/** config.php as written by the installer. */
function configPhp(array $w): string {
    $x = fn($v) => var_export((string)$v, true);
    $date    = date('Y-m-d H:i');
    $baseUrl = $x($w['base_url']);
    $host    = $x($w['db_host']);
    $port    = (int)$w['db_port'];
    $name    = $x($w['db_name']);
    $user    = $x($w['db_user']);
    $pass    = $x($w['db_pass']);
    $https   = $w['force_https'] === '1' ? 'true' : 'false';
    $session = $x($w['session_name']);
    $maxMb   = (int)$w['upload_mb'];
    return <<<PHP
<?php
// ─────────────────────────────────────────
//  CONFIGURATION — written by install.php on $date
//  Everything else (site name, currency, languages…) is set on the admin page.
// ─────────────────────────────────────────

define('BASE_URL',   $baseUrl);   // No trailing slash
define('DB_HOST',    $host);
define('DB_PORT',    $port);
define('DB_NAME',    $name);
define('DB_USER',    $user);
define('DB_PASS',    $pass);
define('DB_CHARSET', 'utf8mb4');

// true: redirect http:// to https:// and send HSTS (needs an SSL certificate)
define('FORCE_HTTPS', $https);

// Session cookie name (unique per site)
define('SESSION_NAME', $session);

// Upload settings
define('UPLOAD_DIR',      __DIR__ . '/uploads/users/');
define('DEFAULTS_DIR',    __DIR__ . '/uploads/defaults/');
define('UPLOAD_URL',      BASE_URL . '/uploads/users/');
define('DEFAULTS_URL',    BASE_URL . '/uploads/defaults/');
define('MAX_FILE_SIZE',   $maxMb * 1024 * 1024); // per image
define('ALLOWED_TYPES',   ['image/jpeg','image/png','image/gif','image/webp']);

// ─────────────────────────────────────────
//  DO NOT EDIT BELOW THIS LINE
// ─────────────────────────────────────────
require_once __DIR__ . '/core.php';

PHP;
}

/**
 * Runs the whole install. Returns ['ok', 'log' => [...], 'error', 'invites' => [...], 'config_written'].
 * Tables it created are dropped again when something fails, so the install can simply be retried.
 */
function runInstall(): array {
    $w = $_SESSION['wiz'];
    $log = []; $res = ['ok' => false, 'log' => &$log, 'error' => '', 'invites' => [], 'config_written' => false];
    $created = false;
    try {
        $chk = checkDatabase($w);
        if (!$chk['ok']) throw new RuntimeException($chk['error']);

        if (!$chk['exists']) {
            wizPdo($w, false)->exec('CREATE DATABASE `' . $w['db_name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $log[] = itRaw('install.log.db_created', ['name' => $w['db_name']]);
        }
        $pdo = wizPdo($w, true);

        // Tables
        $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents(__DIR__ . '/schema.sql'));
        $created = true;
        foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $stmt) {
            if (trim($stmt) !== '') $pdo->exec($stmt);
        }
        $pdo->exec('SET foreign_key_checks = 1');
        $log[] = itRaw('install.log.tables', ['n' => count(schemaTables())]);

        $pdo->beginTransaction();

        // Site settings (app_settings)
        $settings = [
            'site_name' => $w['site_name'], 'site_name_style' => 'last', 'site_name_colors' => '[]',
            'timezone' => $w['timezone'], 'img_max_width' => $w['img_max_width'], 'img_max_height' => $w['img_max_height'],
            'img_quality' => $w['img_quality'], 'img_settings_migrated' => '1',
            'default_region' => $w['default_region'], 'currency_symbol' => $w['currency_symbol'],
            'currency_position' => $w['currency_position'], 'currency_space' => $w['currency_space'],
            'decimal_sep' => $w['decimal_sep'], 'thousands_sep' => $w['thousands_sep'], 'date_format' => $w['date_format'],
            'default_language' => $w['default_language'], 'default_theme' => $w['default_theme'],
            'default_grading' => $w['default_grading'], 'default_value_type' => $w['default_value_type'],
            'defaults_completeness' => $w['defaults_completeness'], 'defaults_played' => $w['defaults_played'],
        ];
        $ins = $pdo->prepare('INSERT INTO app_settings (name, value) VALUES (?,?)');
        foreach ($settings as $k => $val) $ins->execute([$k, (string)$val]);
        $log[] = itRaw('install.log.settings');

        // Systems, in the order they are listed
        $ins = $pdo->prepare('INSERT INTO systems (name, short_name, region, sort_order) VALUES (?,?,?,?)');
        $so = 0; $n = 0;
        foreach (systemCatalog() as $group) foreach ($group as $id => $s) {
            if (!in_array((string)$id, $w['systems'], true)) continue;
            $so += 10; $n++;
            $ins->execute([sysLabel($s, $w['default_region']), strtoupper(sysLabel($s, $w['default_region'], 'short')), $w['default_region'], $so]);
        }
        $log[] = itRaw('install.log.systems', ['n' => $n]);

        // Admin account + starting options
        $gDef = $w['default_grading'] === 'both' ? 'simple' : $w['default_grading'];
        $pdo->prepare("INSERT INTO users (username, password, role, status, wishlist_token, grading_mode, grading_default, language)
                       VALUES (?,?,'admin','active',?,?,?,?)")
            ->execute([$w['admin_user'], $w['admin_hash'], bin2hex(random_bytes(12)), $w['default_grading'], $gDef,
                       $w['admin_lang'] === $w['default_language'] ? null : $w['admin_lang']]);
        $uid = (int)$pdo->lastInsertId();
        foreach (['user_completeness_options' => 'defaults_completeness', 'user_played_options' => 'defaults_played'] as $table => $k) {
            $st = $pdo->prepare("INSERT INTO $table (user_id, label, sort_order) VALUES (?,?,?)");
            foreach (explode("\n", $w[$k]) as $i => $label) $st->execute([$uid, $label, $i]);
        }
        $log[] = itRaw('install.log.admin', ['user' => $w['admin_user']]);

        if ($w['make_invites'] === '1') {
            $st = $pdo->prepare('INSERT INTO invite_codes (code, created_by) VALUES (?,?)');
            for ($i = 0; $i < 3; $i++) { $code = bin2hex(random_bytes(16)); $st->execute([$code, $uid]); $res['invites'][] = $code; }
            $log[] = itRaw('install.log.invites');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        // Remove what this run created, so it can be retried on a clean database
        if ($created && isset($pdo)) {
            try {
                $pdo->exec('SET foreign_key_checks = 0');
                foreach (schemaTables() as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
            } catch (Throwable) {}
        }
        $res['error'] = $e->getMessage();
        return $res;
    }

    // config.php last: once it exists, the installer locks itself
    $config = configPhp($w);
    if (@file_put_contents(__DIR__ . '/config.php', $config) !== false) {
        $res['config_written'] = true;
        $log[] = itRaw('install.log.config');
    } else {
        $_SESSION['pending_config'] = $config;
        $log[] = itRaw('install.log.config_failed');
    }
    if (@file_put_contents(__DIR__ . '/installed.lock', 'Installed ' . date('c') . "\n") !== false) $log[] = itRaw('install.log.lock');
    $res['ok'] = true;
    return $res;
}

// ═══════════════════════════════════════════════════════════════════
//  Request handling
// ═══════════════════════════════════════════════════════════════════

$locked = is_file(__DIR__ . '/installed.lock') || is_file(__DIR__ . '/config.php');

// The "done" screen belongs to an install that still exists. With config.php and installed.lock
// both gone, start over with a fresh wizard (unless the config.php download is still pending).
if (isset($_SESSION['done']) && !$locked && !isset($_SESSION['pending_config'])) {
    $lang = $_SESSION['wiz']['lang'] ?? null;
    unset($_SESSION['done'], $_SESSION['wiz'], $_SESSION['wiz_max']);
    initWizard();
    if ($lang && isset(langList()[$lang])) $_SESSION['wiz']['lang'] = $_SESSION['wiz']['default_language'] = $_SESSION['wiz']['admin_lang'] = $lang;
}
initWizard();

// config.php the installer couldn't write: offered as a download to the session that installed
if (isset($_GET['download']) && isset($_SESSION['pending_config']) && !is_file(__DIR__ . '/config.php')) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="config.php"');
    echo $_SESSION['pending_config'];
    exit;
}

// Database test (AJAX, step 2)
if (($_POST['action'] ?? '') === 'test_db') {
    // Always answer with JSON: anything PHP prints meanwhile (warnings, notices) is caught and added to the message
    ob_start();
    try {
        if ($locked) $out = ['ok' => false, 'message' => strip_tags(itRaw('install.locked.text'))];
        elseif (!csrfOk()) $out = ['ok' => false, 'message' => itRaw('common.err_csrf')];
        else {
            $w = $_SESSION['wiz'];
            foreach (['db_host', 'db_port', 'db_name', 'db_user'] as $k) $w[$k] = trim((string)($_POST[$k] ?? ''));
            $w['db_pass'] = (string)($_POST['db_pass'] ?? '');
            $w['db_create'] = !empty($_POST['db_create']) ? '1' : '0';
            $r = checkDatabase($w);
            $out = ['ok' => $r['ok'], 'message' => $r['ok']
                ? itRaw($r['exists'] ? 'install.db.ok_exists' : 'install.db.ok_create', ['version' => $r['version'], 'name' => $w['db_name']])
                : $r['error']];
        }
    } catch (Throwable $e) {
        $out = ['ok' => false, 'message' => itRaw('install.db.err_connect', ['error' => $e->getMessage()])];
    }
    $noise = trim(preg_replace('/\s+/', ' ', strip_tags((string)ob_get_clean())));
    if ($noise !== '') $out['message'] .= ' — ' . mb_substr($noise, 0, 400);
    header('Content-Type: application/json');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$step   = max(1, min(INSTALL_STEPS, (int)($_GET['step'] ?? $_POST['step'] ?? 1)));
$step   = min($step, (int)$_SESSION['wiz_max']);
$errors = [];
$result = null;

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfOk()) {
        $errors[] = itRaw('common.err_csrf');
    } elseif (isset($_POST['set_lang'])) {
        // Installer language (step 1): also the default for the site and the admin, until changed later
        $code = (string)$_POST['set_lang'];
        if (isset(langList()[$code])) {
            $_SESSION['wiz']['lang'] = $_SESSION['wiz']['default_language'] = $_SESSION['wiz']['admin_lang'] = $code;
        }
        header('Location: install.php?step=1'); exit;
    } elseif (isset($_POST['back'])) {
        header('Location: install.php?step=' . max(1, $step - 1)); exit;
    } elseif ($step === INSTALL_STEPS) {
        if (!requirementsMet()) $errors[] = itRaw('install.req.err');
        else {
            $result = runInstall();
            if ($result['ok']) {
                $_SESSION['done'] = ['invites' => $result['invites'], 'log' => $result['log'], 'config_written' => $result['config_written'],
                                     'base_url' => $_SESSION['wiz']['base_url']];
                // Keep only what the "done" screen needs; the database password leaves the session
                $lang = $_SESSION['wiz']['lang'];
                $_SESSION['wiz'] = ['lang' => $lang];
            } else {
                $errors[] = itRaw('install.review.failed', ['error' => $result['error']]);
            }
        }
    } else {
        $errors = saveStep($step, $_POST);
        if (!$errors) {
            $_SESSION['wiz_max'] = max((int)$_SESSION['wiz_max'], $step + 1);
            header('Location: install.php?step=' . ($step + 1)); exit;
        }
    }
}

$done = $_SESSION['done'] ?? null;
if ($done) $locked = false; // this session just installed: show the "done" screen
$w = $_SESSION['wiz'];
if (!$done && $step === 6) {
    // Prefill the starting lists from the chosen site language
    foreach (['defaults_completeness' => 'defaults.completeness', 'defaults_played' => 'defaults.played'] as $k => $key) {
        if (($w[$k] ?? null) === null) $w[$k] = $_SESSION['wiz'][$k] = langDefaultList($w['default_language'], $key);
    }
}

$themes    = themeList();
$themeSlug = isset($themes[$w['default_theme'] ?? '']) ? $w['default_theme'] : (string)array_key_first($themes);
$theme     = $themes[$themeSlug] ?? ['fonts' => ''];
$stepNames = [1 => 'welcome', 2 => 'database', 3 => 'site', 4 => 'region', 5 => 'systems', 6 => 'defaults', 7 => 'admin', 8 => 'review'];

// ── Page helpers ─────────────────────────

function field(string $label, string $inner, string $hint = '', string $style = ''): string {
    return '<div class="field"' . ($style ? ' style="' . $style . '"' : '') . '><label>' . $label . '</label>' . $inner
         . ($hint !== '' ? '<span class="i-hint">' . $hint . '</span>' : '') . '</div>';
}

function input(string $name, string $value, string $attrs = ''): string {
    return '<input type="text" name="' . $name . '" value="' . h($value) . '" ' . $attrs . '>';
}

function select(string $name, array $options, string $current, string $attrs = ''): string {
    $o = '';
    foreach ($options as $val => $label) $o .= '<option value="' . h((string)$val) . '"' . ((string)$val === $current ? ' selected' : '') . '>' . $label . '</option>';
    return '<select name="' . $name . '" ' . $attrs . '>' . $o . '</select>';
}

function check(string $name, bool $on, string $label): string {
    return '<label class="i-check"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> <span>' . $label . '</span></label>';
}

$sepLabel = fn(string $s) => it('admin.site.sep_' . ['.' => 'dot', ',' => 'comma', ' ' => 'space', "'" => 'apos', '' => 'none'][$s]);
?>
<!DOCTYPE html>
<html lang="<?= h($w['lang']) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?= it('install.title') ?> — Game Collection</title>
<link rel="stylesheet" href="assets/css/main.css?v=<?= @filemtime(__DIR__ . '/assets/css/main.css') ?>">
<?php if ($theme['fonts']): ?><link rel="stylesheet" href="<?= h($theme['fonts']) ?>"><?php endif; ?>
<link rel="stylesheet" href="assets/themes/<?= h($themeSlug) ?>.css">
<style>
  .i-wrap { max-width:880px; margin:32px auto 60px; padding:0 20px; display:grid; grid-template-columns:200px minmax(0,1fr); gap:24px; }
  .i-steps { list-style:none; display:flex; flex-direction:column; gap:2px; position:sticky; top:20px; align-self:start; }
  .i-steps li { font-size:.7rem; letter-spacing:.08em; color:var(--muted); padding:7px 10px; border-left:2px solid var(--border2); }
  .i-steps li.on { color:var(--accent); border-left-color:var(--accent2); background:var(--surface); }
  .i-steps li.ok { color:var(--text2); }
  .i-steps a { color:inherit; }
  .i-card { background:var(--surface); border:1px solid var(--border2); border-radius:var(--radius); padding:26px 28px; display:flex; flex-direction:column; gap:18px; }
  .i-card h1 { font-family:var(--font-display); font-weight:var(--display-weight); text-transform:var(--display-case); font-size:1.8rem; color:var(--accent); letter-spacing:.05em; line-height:1.1; }
  .i-lead { font-size:.78rem; color:var(--text2); line-height:1.7; }
  .i-row { display:flex; gap:14px; flex-wrap:wrap; align-items:flex-start; }
  .i-row > .field { flex:1; min-width:160px; }
  .i-hint { font-size:.64rem; color:var(--muted); line-height:1.5; }
  .i-group { border:1px solid var(--border); border-radius:var(--radius); padding:14px 16px; display:flex; flex-direction:column; gap:12px; background:var(--surface2); }
  .i-group-head { font-size:.58rem; letter-spacing:.2em; text-transform:uppercase; color:var(--muted); display:flex; justify-content:space-between; align-items:center; gap:8px; }
  .i-check { display:flex; gap:8px; align-items:flex-start; font-size:.76rem; color:var(--text2); cursor:pointer; }
  .i-check input { width:auto; margin-top:3px; }
  .i-errors { background:color-mix(in srgb,var(--red) 10%,transparent); border:1px solid color-mix(in srgb,var(--red) 35%,transparent); color:var(--red); padding:10px 14px; font-size:.76rem; border-radius:var(--radius); }
  .i-errors li { margin-left:16px; }
  .i-nav { display:flex; justify-content:space-between; gap:10px; border-top:1px solid var(--border); padding-top:16px; }
  .i-req { width:100%; border-collapse:collapse; font-size:.74rem; }
  .i-req td { padding:6px 8px; border-bottom:1px solid var(--border); }
  .i-req .st { width:90px; text-align:right; white-space:nowrap; }
  .i-yes { color:var(--green); } .i-no { color:var(--red); } .i-warn { color:var(--orange); }
  .i-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:8px; }
  .i-opt { position:relative; display:flex; flex-direction:column; gap:4px; padding:12px 14px; background:var(--surface2); border:1px solid var(--border2); border-radius:var(--radius); cursor:pointer; }
  .i-opt input { position:absolute; opacity:0; pointer-events:none; }
  .i-opt:has(input:checked) { border-color:var(--accent2); background:color-mix(in srgb,var(--accent2) 8%,var(--surface2)); }
  .i-opt:has(input:focus-visible) { outline:1px solid var(--accent); }
  .i-opt b { font-weight:400; color:var(--accent); font-size:.85rem; }
  .i-opt span { font-size:.64rem; color:var(--muted); line-height:1.5; }
  .i-opt .theme-swatches { align-self:flex-start; line-height:0; margin:2px 0; }
  .i-chips { display:flex; flex-wrap:wrap; gap:6px; }
  .i-chip { position:relative; }
  .i-chip input { position:absolute; opacity:0; pointer-events:none; }
  .i-chip span { display:inline-block; padding:6px 11px; font-size:.7rem; border:1px solid var(--border2); border-radius:var(--radius); color:var(--text2); cursor:pointer; background:var(--surface); }
  .i-chip input:checked + span { border-color:var(--accent2); color:var(--accent); background:color-mix(in srgb,var(--accent2) 10%,var(--surface)); }
  .i-chip input:focus-visible + span { outline:1px solid var(--accent); }
  .i-preview { font-size:.72rem; color:var(--muted); }
  .i-preview b { font-family:var(--font-display); font-weight:400; color:var(--accent); font-size:1.15rem; letter-spacing:.03em; }
  .i-meter { height:4px; background:var(--border2); border-radius:2px; overflow:hidden; }
  .i-meter i { display:block; height:100%; width:0; transition:width .2s, background .2s; }
  .i-rules { font-size:.68rem; color:var(--muted); list-style:none; display:flex; flex-direction:column; gap:2px; }
  .i-rules li.ok { color:var(--green); }
  .i-review { display:flex; flex-direction:column; gap:10px; }
  .i-review section { border:1px solid var(--border); border-radius:var(--radius); padding:12px 14px; background:var(--surface2); }
  .i-review h3 { font-size:.6rem; letter-spacing:.2em; text-transform:uppercase; color:var(--muted); font-weight:400; display:flex; justify-content:space-between; margin-bottom:6px; }
  .i-review dl { display:grid; grid-template-columns:180px minmax(0,1fr); gap:3px 12px; font-size:.74rem; }
  .i-review dt { color:var(--muted); } .i-review dd { color:var(--text); word-break:break-word; }
  .i-log { font-size:.74rem; color:var(--text2); line-height:1.9; list-style:none; }
  .i-log li::before { content:'✓ '; color:var(--green); }
  .i-code { font-size:.7rem; background:var(--bg); border:1px solid var(--border2); padding:12px; white-space:pre; overflow:auto; max-height:360px; border-radius:var(--radius); color:var(--text2); }
  #db-test-msg { font-size:.74rem; }
  @media(max-width:760px) { .i-wrap { grid-template-columns:1fr; } .i-steps { position:static; flex-direction:row; flex-wrap:wrap; } .i-review dl { grid-template-columns:1fr; } }
</style>
</head>
<body>
<header class="site-header">
  <span class="site-logo">Game <span>Collection</span></span>
  <nav class="site-nav"><span class="nav-user"><?= it('install.title') ?></span></nav>
</header>

<?php if ($locked && !$done): ?>
<div class="i-wrap" style="grid-template-columns:1fr;max-width:620px">
  <div class="i-card">
    <h1><?= it('install.locked.title') ?></h1>
    <p class="i-lead"><?= it('install.locked.text') ?></p>
    <div><a class="btn" href="index.php"><?= it('install.done.login') ?></a></div>
  </div>
</div>

<?php elseif ($done): ?>
<div class="i-wrap" style="grid-template-columns:1fr;max-width:720px">
  <div class="i-card">
    <h1><?= it('install.done.title') ?></h1>
    <ul class="i-log"><?php foreach ($done['log'] as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul>

    <?php if (!$done['config_written']): ?>
    <div class="i-group" style="border-color:var(--orange)">
      <div class="i-group-head" style="color:var(--orange)"><?= it('install.done.config_head') ?></div>
      <p class="i-lead"><?= it('install.done.config_text') ?></p>
      <?php if (isset($_SESSION['pending_config']) && !is_file(__DIR__ . '/config.php')): ?>
      <div><a class="btn btn-sm" href="install.php?download=config">⬇ <?= it('install.done.config_download') ?></a></div>
      <div class="i-code"><?= h($_SESSION['pending_config']) ?></div>
      <?php else: ?>
      <p class="i-lead i-yes">✓ <?= it('install.done.config_found') ?></p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($done['invites']): ?>
    <div class="i-group">
      <div class="i-group-head"><?= it('admin.inv.title') ?></div>
      <p class="i-lead"><?= it('install.done.invites') ?></p>
      <?php foreach ($done['invites'] as $code): ?><span class="invite-code"><?= h($code) ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="i-group">
      <div class="i-group-head"><?= it('install.done.lock_head') ?></div>
      <p class="i-lead"><?= it('install.done.lock_text') ?></p>
    </div>
    <div class="i-nav" style="justify-content:flex-end"><a class="btn" href="index.php"><?= it('install.done.login') ?> →</a></div>
  </div>
</div>

<?php else: ?>
<div class="i-wrap">
  <ol class="i-steps">
    <?php foreach ($stepNames as $n => $name): ?>
    <li class="<?= $n === $step ? 'on' : ($n < (int)$_SESSION['wiz_max'] ? 'ok' : '') ?>">
      <?php if ($n <= (int)$_SESSION['wiz_max'] && $n !== $step): ?><a href="install.php?step=<?= $n ?>"><?= $n ?>. <?= it('install.step.' . $name) ?></a>
      <?php else: ?><?= $n ?>. <?= it('install.step.' . $name) ?><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>

  <form class="i-card" method="POST" action="install.php" id="wiz-form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="step" value="<?= $step ?>">
    <h1><?= it('install.step.' . $stepNames[$step]) ?></h1>

    <?php if ($errors): ?>
    <div class="i-errors" role="alert"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

<?php if ($step === 1): /* ── WELCOME & REQUIREMENTS ── */ ?>
    <p class="i-lead"><?= it('install.welcome.lead') ?></p>
    <div class="i-row">
      <?= field(it('install.welcome.language'), select('lang_pick', array_map('h', langList()), $w['lang'], 'onchange="setLang(this.value)"'), '', 'max-width:260px') ?>
    </div>
    <table class="i-req">
      <?php foreach (requirementChecks() as [$label, $ok, $required, $detail]): ?>
      <tr>
        <td><?= h($label) ?><?= $detail !== '' ? ' <span class="i-hint">— ' . h($detail) . '</span>' : '' ?></td>
        <td class="st"><?= $ok ? '<span class="i-yes">✓ ' . it('install.req.ok') . '</span>'
                               : ($required ? '<span class="i-no">✗ ' . it('install.req.missing') . '</span>' : '<span class="i-warn">! ' . it('install.req.optional') . '</span>') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <p class="i-hint"><?= it('install.welcome.nothing_written') ?></p>

<?php elseif ($step === 2): /* ── DATABASE ── */ ?>
    <p class="i-lead"><?= it('install.db.lead') ?></p>
    <div class="i-row">
      <?= field(it('install.db.host'), input('db_host', $w['db_host'], 'required'), '', 'flex:3') ?>
      <?= field(it('install.db.port'), input('db_port', $w['db_port'], 'inputmode="numeric" required'), '', 'flex:1;min-width:90px') ?>
    </div>
    <div class="i-row">
      <?= field(it('install.db.name'), input('db_name', $w['db_name'], 'required')) ?>
      <?= field(it('install.db.user'), input('db_user', $w['db_user'], 'required autocomplete="off"')) ?>
      <?= field(it('auth.password'), '<input type="password" name="db_pass" value="' . h($w['db_pass']) . '" autocomplete="new-password">') ?>
    </div>
    <?= check('db_create', $w['db_create'] === '1', it('install.db.create')) ?>
    <div class="i-row" style="align-items:center">
      <button type="button" class="btn-ghost" onclick="testDb()"><?= it('install.db.test') ?></button>
      <span id="db-test-msg" role="status"></span>
    </div>
    <p class="i-hint"><?= it('install.db.note') ?></p>

<?php elseif ($step === 3): /* ── SITE SETTINGS ── */ ?>
    <p class="i-lead"><?= it('install.site.lead') ?></p>
    <div class="i-row">
      <?= field(it('admin.site.name'), input('site_name', $w['site_name'], 'maxlength="60" required')) ?>
      <?= field(it('install.site.base_url'), input('base_url', $w['base_url'], 'required'), it('install.site.base_url_hint'), 'flex:2') ?>
    </div>
    <?= check('force_https', $w['force_https'] === '1', it('install.site.https')) ?>
    <div class="i-row">
      <?= field(it('install.site.session'), input('session_name', $w['session_name'], 'maxlength="40" required'), it('install.site.session_hint')) ?>
      <?= field(it('admin.site.timezone'), select('timezone', array_combine(timezone_identifiers_list(), array_map('h', timezone_identifiers_list())), $w['timezone'])) ?>
    </div>
    <div class="i-group">
      <div class="i-group-head"><?= it('install.site.photos') ?></div>
      <div class="i-row">
        <?= field(it('install.site.max_mb'), '<input type="number" name="upload_mb" min="1" max="64" value="' . h($w['upload_mb']) . '">') ?>
        <?= field(it('admin.img.max_w'), '<input type="number" name="img_max_width" min="200" max="4000" step="100" value="' . h($w['img_max_width']) . '">') ?>
        <?= field(it('admin.img.max_h'), '<input type="number" name="img_max_height" min="200" max="4000" step="100" value="' . h($w['img_max_height']) . '">') ?>
        <?= field(it('admin.img.quality'), '<input type="number" name="img_quality" min="10" max="100" value="' . h($w['img_quality']) . '">') ?>
      </div>
      <p class="i-hint"><?= it('install.site.photos_hint') ?></p>
    </div>

<?php elseif ($step === 4): /* ── REGION & CURRENCY ── */ ?>
    <p class="i-lead"><?= it('install.region.lead') ?></p>
    <div class="i-cards" role="radiogroup" aria-label="<?= it('admin.site.region') ?>">
      <?php foreach (I_REGIONS as $r): ?>
      <label class="i-opt"><input type="radio" name="default_region" value="<?= $r ?>" <?= $w['default_region'] === $r ? 'checked' : '' ?>>
        <b><?= $r === 'Mixed' ? it('admin.site.region_mixed') : $r ?></b><span><?= it('install.region.' . strtolower(str_replace('-', '_', $r))) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="i-group">
      <div class="i-group-head"><?= it('admin.site.money_head') ?></div>
      <div class="i-row">
        <?= field(it('admin.site.symbol'), input('currency_symbol', $w['currency_symbol'], 'maxlength="5" id="f-sym" oninput="moneyPreview()"'), '', 'max-width:110px') ?>
        <?= field(it('admin.site.position'), select('currency_position', ['before' => it('admin.site.pos_before'), 'after' => it('admin.site.pos_after')], $w['currency_position'], 'id="f-pos" onchange="moneyPreview()"')) ?>
        <?= field(it('admin.site.space'), select('currency_space', ['0' => it('admin.site.no'), '1' => it('admin.site.yes')], $w['currency_space'], 'id="f-space" onchange="moneyPreview()"'), '', 'max-width:120px') ?>
      </div>
      <div class="i-row">
        <?= field(it('admin.site.decimal'), select('decimal_sep', array_combine(I_DECIMAL_SEPS, array_map($sepLabel, I_DECIMAL_SEPS)), $w['decimal_sep'], 'id="f-dec" onchange="moneyPreview()"')) ?>
        <?= field(it('admin.site.thousands'), select('thousands_sep', array_combine(I_THOUSANDS_SEPS, array_map($sepLabel, I_THOUSANDS_SEPS)), $w['thousands_sep'], 'id="f-thou" onchange="moneyPreview()"')) ?>
      </div>
      <div class="i-preview"><?= it('admin.site.preview') ?>: <b id="money-preview"></b></div>
      <p class="i-hint"><?= it('install.region.currency_note') ?></p>
    </div>
    <div class="i-row">
      <?= field(it('admin.site.date'), select('date_format', array_combine(I_DATE_FORMATS, I_DATE_FORMATS), $w['date_format'], 'id="f-date" onchange="datePreview()"')) ?>
      <?= field(it('admin.site.language'), select('default_language', array_map('h', langList()), $w['default_language'])) ?>
    </div>
    <div class="i-preview"><?= it('admin.site.preview') ?>: <b id="date-preview"></b></div>

<?php elseif ($step === 5): /* ── SYSTEMS ── */ ?>
    <p class="i-lead"><?= it('install.sys.lead') ?></p>
    <?php if (!systemCatalog()): ?><div class="i-errors"><?= it('install.sys.no_file') ?></div><?php endif; ?>
    <?php foreach (systemCatalog() as $maker => $list): ?>
    <div class="i-group" data-sys-group>
      <div class="i-group-head"><span><?= h($maker) ?></span>
        <span><button type="button" class="btn-icon" onclick="sysGroup(this, true)"><?= it('install.sys.all') ?></button>
              <button type="button" class="btn-icon" onclick="sysGroup(this, false)"><?= it('wish.none') ?></button></span></div>
      <div class="i-chips">
        <?php foreach ($list as $id => $s): ?>
        <label class="i-chip"><input type="checkbox" name="systems[]" value="<?= h((string)$id) ?>" <?= in_array((string)$id, $w['systems'], true) ? 'checked' : '' ?> onchange="sysCount()">
          <span title="<?= h(sysLabel($s, $w['default_region'], 'short')) ?>"><?= h(sysLabel($s, $w['default_region'])) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <p class="i-preview"><?= it('install.sys.selected') ?>: <b id="sys-count">0</b></p>
    <p class="i-hint"><?= it('install.sys.note', ['region' => $w['default_region']]) ?></p>

<?php elseif ($step === 6): /* ── COLLECTION DEFAULTS ── */ ?>
    <p class="i-lead"><?= it('install.def.lead') ?></p>
    <div class="i-group">
      <div class="i-group-head"><?= it('settings.grading') ?></div>
      <div class="i-cards" role="radiogroup" aria-label="<?= it('settings.grading') ?>">
        <?php foreach (I_GRADING as $g): ?>
        <label class="i-opt"><input type="radio" name="default_grading" value="<?= $g ?>" <?= $w['default_grading'] === $g ? 'checked' : '' ?>>
          <b><?= it('grading.mode_' . $g) ?></b><span><?= it('grading.mode_' . $g . '_desc') ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="i-row">
      <?= field(it('settings.comp'), '<textarea name="defaults_completeness" rows="9">' . h($w['defaults_completeness']) . '</textarea>') ?>
      <?= field(it('settings.played'), '<textarea name="defaults_played" rows="9">' . h($w['defaults_played']) . '</textarea>') ?>
    </div>
    <p class="i-hint" style="margin-top:-8px"><?= it('install.def.lists_hint') ?></p>
    <div class="i-row">
      <?= field(it('admin.site.value_type'), select('default_value_type', array_combine(I_VALUE_TYPES, array_map(fn($v) => it('common.price.' . $v), I_VALUE_TYPES)), $w['default_value_type']), '', 'max-width:280px') ?>
    </div>
    <div class="i-group">
      <div class="i-group-head"><?= it('admin.theme.title') ?></div>
      <div class="i-cards" role="radiogroup" aria-label="<?= it('admin.theme.title') ?>">
        <?php foreach ($themes as $slug => $t): ?>
        <label class="i-opt"><input type="radio" name="default_theme" value="<?= h($slug) ?>" <?= $w['default_theme'] === $slug ? 'checked' : '' ?>>
          <b><?= h($t['name']) ?></b>
          <span class="theme-swatches" aria-hidden="true"><?php foreach ($t['swatches'] as $c): ?><i style="background:<?= h($c) ?>"></i><?php endforeach; ?></span>
          <span><?= h($t['about']) ?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="i-hint"><?= it('install.def.theme_hint') ?></p>
    </div>

<?php elseif ($step === 7): /* ── ADMIN ACCOUNT ── */ ?>
    <p class="i-lead"><?= it('install.admin.lead') ?></p>
    <div class="i-row">
      <?= field(it('auth.username'), input('admin_user', $w['admin_user'], 'maxlength="50" required autocomplete="username" id="f-user" oninput="pwCheck()"')) ?>
      <?= field(it('settings.language'), select('admin_lang', array_map('h', langList()), $w['admin_lang'])) ?>
    </div>
    <div class="i-row">
      <?= field(it('auth.password'), '<input type="password" name="admin_pass" id="f-pw" autocomplete="new-password" oninput="pwCheck()"' . ($w['admin_hash'] === '' ? ' required' : '') . '>',
                $w['admin_hash'] !== '' ? it('install.admin.keep_pw') : '') ?>
      <?= field(it('auth.confirm_password'), '<input type="password" name="admin_pass2" id="f-pw2" autocomplete="new-password" oninput="pwCheck()">') ?>
    </div>
    <div class="i-meter" aria-hidden="true"><i id="pw-meter"></i></div>
    <ul class="i-rules">
      <li id="r-len"><?= it('install.admin.rule_len') ?></li>
      <li id="r-match"><?= it('install.admin.rule_match') ?></li>
      <li id="r-user"><?= it('install.admin.rule_user') ?></li>
    </ul>
    <?= check('make_invites', $w['make_invites'] === '1', it('install.admin.invites')) ?>

<?php elseif ($step === 8): /* ── REVIEW & INSTALL ── */ ?>
    <p class="i-lead"><?= it('install.review.lead') ?></p>
    <?php
    $yes = fn(bool $b) => $b ? it('admin.site.yes') : it('admin.site.no');
    $sysNames = [];
    foreach (systemCatalog() as $list) foreach ($list as $id => $s) if (in_array((string)$id, $w['systems'], true)) $sysNames[] = sysLabel($s, $w['default_region']);
    $moneyEx = fn() => ($w['currency_position'] === 'after' ? '' : $w['currency_symbol'] . ($w['currency_space'] === '1' ? ' ' : ''))
                       . number_format(1234.56, 2, $w['decimal_sep'], $w['thousands_sep'])
                       . ($w['currency_position'] === 'after' ? ($w['currency_space'] === '1' ? ' ' : '') . $w['currency_symbol'] : '');
    $sections = [
        2 => [it('install.db.name') => $w['db_name'] . ($w['db_create'] === '1' ? ' (' . it('install.review.create_if_missing') . ')' : ''),
              it('install.db.host') => $w['db_host'] . ':' . $w['db_port'], it('install.db.user') => $w['db_user']],
        3 => [it('admin.site.name') => $w['site_name'], it('install.site.base_url') => $w['base_url'], 'HTTPS' => $yes($w['force_https'] === '1'),
              it('admin.site.timezone') => $w['timezone'], it('install.site.photos') => $w['upload_mb'] . ' MB · ' . $w['img_max_width'] . '×' . $w['img_max_height'] . ' · JPEG ' . $w['img_quality']],
        4 => [it('admin.site.region') => $w['default_region'], it('admin.site.money_head') => $moneyEx(),
              it('admin.site.date') => fmtExample($w['date_format'], $w['default_language']), it('admin.site.language') => langList()[$w['default_language']] ?? $w['default_language']],
        5 => [it('install.step.systems') => $sysNames ? implode(', ', $sysNames) : '—'],
        6 => [it('settings.grading') => it('grading.mode_' . $w['default_grading']), it('settings.comp') => str_replace("\n", ', ', $w['defaults_completeness']),
              it('settings.played') => str_replace("\n", ', ', $w['defaults_played']), it('admin.site.value_type') => it('common.price.' . $w['default_value_type']),
              it('admin.theme.title') => $themes[$w['default_theme']]['name'] ?? $w['default_theme']],
        7 => [it('auth.username') => $w['admin_user'], it('settings.language') => langList()[$w['admin_lang']] ?? $w['admin_lang'],
              it('admin.inv.title') => $yes($w['make_invites'] === '1')],
    ];
    ?>
    <div class="i-review">
      <?php foreach ($sections as $n => $rows): ?>
      <section>
        <h3><span><?= it('install.step.' . $stepNames[$n]) ?></span><a href="install.php?step=<?= $n ?>"><?= it('common.edit') ?></a></h3>
        <dl><?php foreach ($rows as $k => $v): ?><dt><?= $k ?></dt><dd><?= h((string)$v) ?></dd><?php endforeach; ?></dl>
      </section>
      <?php endforeach; ?>
    </div>
    <p class="i-hint"><?= it('install.review.steps') ?></p>
<?php endif; ?>

    <div class="i-nav">
      <?php if ($step > 1): ?><button type="submit" name="back" value="1" class="btn-ghost" formnovalidate>← <?= it('install.back') ?></button><?php else: ?><span></span><?php endif; ?>
      <?php if ($step === INSTALL_STEPS): ?>
      <button type="submit" class="btn" onclick="this.disabled=true;this.textContent=<?= h(json_encode(itRaw('install.review.installing'))) ?>;this.form.submit()"><?= it('install.review.install') ?></button>
      <?php else: ?>
      <button type="submit" class="btn" <?= $step === 1 && !requirementsMet() ? 'disabled' : '' ?>><?= it('install.next') ?> →</button>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<script>
const T = <?= json_encode([
    'months' => explode(' ', itRaw('date.months_short')),
    'testing' => itRaw('install.db.testing'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const $ = id => document.getElementById(id);

function setLang(code) {
  const f = $('wiz-form'), i = document.createElement('input');
  i.type = 'hidden'; i.name = 'set_lang'; i.value = code;
  f.appendChild(i); f.submit();
}

async function testDb() {
  const f = $('wiz-form'), msg = $('db-test-msg');
  msg.className = ''; msg.textContent = T.testing;
  const fd = new FormData(f); fd.set('action', 'test_db');
  try {
    const res = await fetch('install.php', { method: 'POST', body: fd }), text = await res.text();
    let r;
    try { r = JSON.parse(text); }
    catch { // not JSON: show what the server sent instead (e.g. a PHP error), without the HTML
      const d = document.createElement('div'); d.innerHTML = text;
      r = { ok: false, message: 'HTTP ' + res.status + ': ' + (d.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 400) };
    }
    msg.textContent = (r.ok ? '✓ ' : '✗ ') + r.message; msg.className = r.ok ? 'i-yes' : 'i-no';
  } catch (e) { msg.textContent = '✗ ' + e.message; msg.className = 'i-no'; }
}

function moneyPreview() {
  if (!$('money-preview')) return;
  const [i, d] = (1234.56).toFixed(2).split('.'), sp = $('f-space').value === '1' ? ' ' : '';
  const n = i.replace(/\B(?=(\d{3})+(?!\d))/g, $('f-thou').value) + $('f-dec').value + d;
  $('money-preview').textContent = $('f-pos').value === 'after' ? n + sp + $('f-sym').value : $('f-sym').value + sp + n;
}

function datePreview() {
  if (!$('date-preview')) return;
  const dt = new Date(), p = n => String(n).padStart(2, '0');
  $('date-preview').textContent = $('f-date').value.replace(/YYYY|MMM|MM|DD|D/g, m => ({
    YYYY: dt.getFullYear(), MMM: T.months[dt.getMonth()], MM: p(dt.getMonth() + 1), DD: p(dt.getDate()), D: dt.getDate()
  }[m]));
}

function sysGroup(btn, on) {
  btn.closest('[data-sys-group]').querySelectorAll('input[type=checkbox]').forEach(c => { c.checked = on; });
  sysCount();
}
function sysCount() {
  if ($('sys-count')) $('sys-count').textContent = document.querySelectorAll('input[name="systems[]"]:checked').length;
}

function pwCheck() {
  if (!$('f-pw')) return;
  const pw = $('f-pw').value, pw2 = $('f-pw2').value, user = $('f-user').value.trim().toLowerCase();
  const rules = { 'r-len': pw.length >= 12, 'r-match': pw !== '' && pw === pw2, 'r-user': pw !== '' && pw.toLowerCase() !== user };
  for (const id in rules) $(id).className = rules[id] ? 'ok' : '';
  // Rough strength: length and character variety
  let s = Math.min(pw.length, 20) / 20 * 60;
  s += [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter(re => re.test(pw)).length * 10;
  const m = $('pw-meter');
  m.style.width = Math.min(100, s) + '%';
  m.style.background = s < 50 ? 'var(--red)' : s < 80 ? 'var(--orange)' : 'var(--green)';
}

moneyPreview(); datePreview(); sysCount(); pwCheck();
</script>
</body>
</html>
