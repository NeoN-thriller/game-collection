<?php
// Site settings (key/value in app_settings), money / number / date formatting,
// regions and the site name + logo. Loaded by core.php.
if (!defined('DB_HOST')) { http_response_code(403); exit; }

/** Every site setting with its default. Values are stored as strings in app_settings. */
const SITE_DEFAULTS = [
    'site_name'         => 'Game Collection',
    'site_name_style'   => 'last',        // single | last | custom
    'site_name_colors'  => '[]',          // JSON: one colour token per word (style "custom")
    'default_region'    => 'PAL',
    'currency_symbol'   => '€',
    'currency_position' => 'before',      // before | after
    'currency_space'    => '0',           // 1 = space between symbol and amount
    'decimal_sep'       => '.',
    'thousands_sep'     => ',',
    'date_format'       => 'DD-MMM-YYYY',
    'default_language'  => 'en',
    'default_theme'     => 'arcade-gold',
    'default_grading'   => 'simple',      // simple | points | both, for new users
    'img_max_width'     => '1200',
    'img_max_height'    => '1200',
    'img_quality'       => '80',
];

const REGIONS        = ['PAL', 'NTSC-U', 'NTSC-J', 'Mixed'];
const DATE_FORMATS   = ['DD-MMM-YYYY', 'D MMM YYYY', 'DD-MM-YYYY', 'DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'];
const DECIMAL_SEPS   = ['.', ','];
const THOUSANDS_SEPS = [',', '.', ' ', "'", ''];
/** Theme colours a word of the site name can use (CSS variable names). */
const LOGO_COLORS    = ['header-logo', 'header-logo2', 'accent', 'accent2', 'wiiu', 'text', 'text2', 'muted', 'red', 'green', 'orange', 'blue', 'pink'];

/** A site setting (falls back to SITE_DEFAULTS). All settings are loaded once per request. */
function setting(string $key): string {
    $all = siteSettings();
    return $all[$key] ?? (SITE_DEFAULTS[$key] ?? '');
}

function siteSettings(bool $fresh = false): array {
    static $cache = null;
    if ($cache === null || $fresh) {
        try { $rows = db()->query("SELECT name, value FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR); }
        catch (PDOException) { $rows = []; /* table missing on a very old install: defaults only */ }
        if (!isset($rows['img_settings_migrated'])) $rows = migrateImageSettingsFile($rows);
        $cache = SITE_DEFAULTS;
        foreach ($rows as $k => $v) if ($v !== null) $cache[$k] = (string)$v;
    }
    return $cache;
}

function setSetting(string $key, string $value): void {
    setAppSetting($key, $value);
    siteSettings(true);
}

/**
 * Image settings used to live in uploads/img_settings.json: copy them into app_settings once.
 * Takes and returns the stored rows. Runs until the 'img_settings_migrated' flag is saved.
 */
function migrateImageSettingsFile(array $rows): array {
    $file = __DIR__ . '/uploads/img_settings.json';
    $cfg  = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    try {
        if (is_array($cfg)) {
            foreach (['max_width' => 'img_max_width', 'max_height' => 'img_max_height', 'quality' => 'img_quality'] as $old => $new) {
                if (isset($cfg[$old]) && !isset($rows[$new])) {
                    setAppSetting($new, (string)(int)$cfg[$old]);
                    $rows[$new] = (string)(int)$cfg[$old];
                }
            }
        }
        setAppSetting('img_settings_migrated', '1');
        $rows['img_settings_migrated'] = '1';
        if ($cfg !== null) @unlink($file);
    } catch (PDOException) { /* no app_settings table yet */ }
    return $rows;
}

/** Image resize settings as ints. */
function imageSettings(): array {
    return [
        'max_width'  => max(200, min(4000, (int)setting('img_max_width'))),
        'max_height' => max(200, min(4000, (int)setting('img_max_height'))),
        'quality'    => max(10,  min(100,  (int)setting('img_quality'))),
    ];
}

// ── Formatting ───────────────────────────

/** Number with the site's separators. */
function fmtNum(float|int|string|null $v, int $dec = 0): string {
    return number_format((float)$v, $dec, setting('decimal_sep'), setting('thousands_sep'));
}

/** Amount with the site's currency symbol, e.g. "€ 1.234,56". Prices are never converted. */
function money(float|int|string|null $v, int $dec = 2): string {
    $n   = fmtNum($v, $dec);
    $sym = setting('currency_symbol');
    $sp  = setting('currency_space') === '1' ? ' ' : '';
    return setting('currency_position') === 'after' ? $n.$sp.$sym : $sym.$sp.$n;
}

/** Date in the site's format. Accepts a timestamp or anything strtotime() reads; '' / null gives ''. */
function fmtDate(int|string|null $when, bool $withTime = false): string {
    if ($when === null || $when === '') return '';
    $ts = is_int($when) ? $when : strtotime($when);
    if ($ts === false) return '';
    $months = explode(' ', tRaw('date.months_short'));
    $out = strtr(setting('date_format'), [
        'YYYY' => date('Y', $ts),
        'MMM'  => $months[(int)date('n', $ts) - 1] ?? date('M', $ts),
        'MM'   => date('m', $ts),
        'DD'   => date('d', $ts),
        'D'    => date('j', $ts),
    ]);
    return $withTime ? $out.' '.date('H:i', $ts) : $out;
}

// ── Regions ──────────────────────────────

/** Region of a system (its own), else the site default. "Mixed" means none. */
function systemRegion(?array $sys): string {
    $r = $sys['region'] ?? '';
    return in_array($r, REGIONS, true) ? $r : setting('default_region');
}

// ── Site name + logo ─────────────────────

function siteName(): string {
    $n = trim(setting('site_name'));
    return $n !== '' ? $n : SITE_DEFAULTS['site_name'];
}

/** "Page — Site name" for <title>. */
function pageTitle(string $page = ''): string {
    return htmlspecialchars($page !== '' ? $page.' — '.siteName() : siteName());
}

/**
 * The words of the site name with their colour (CSS variable name, or '' for the default logo colour).
 * @return array<array{0:string,1:string}>
 */
function siteNameWords(?string $name = null, ?string $style = null, ?array $colors = null): array {
    $words  = preg_split('/\s+/u', trim($name ?? siteName())) ?: [];
    $style  = $style ?? setting('site_name_style');
    $colors = $colors ?? (json_decode(setting('site_name_colors'), true) ?: []);
    $out = [];
    foreach ($words as $i => $w) {
        $c = '';
        if ($style === 'last' && count($words) > 1 && $i === count($words) - 1) $c = 'header-logo2';
        if ($style === 'custom') $c = in_array($colors[$i] ?? '', LOGO_COLORS, true) ? $colors[$i] : 'header-logo';
        $out[] = [$w, $c];
    }
    return $out;
}

/** Inner HTML of the logo link. $extra is appended as-is (trusted HTML). */
function siteLogoHtml(string $extra = ''): string {
    $parts = [];
    foreach (siteNameWords() as [$w, $c]) {
        $parts[] = $c === '' ? htmlspecialchars($w) : '<span style="color:var(--'.$c.')">'.htmlspecialchars($w).'</span>';
    }
    return implode(' ', $parts).($extra !== '' ? ' '.$extra : '');
}

// ── Client side (JS) ─────────────────────

/** Formatting settings for the JS helpers in appScript(). */
function formatClientConfig(): array {
    return [
        'sym'    => setting('currency_symbol'),
        'after'  => setting('currency_position') === 'after',
        'space'  => setting('currency_space') === '1',
        'dec'    => setting('decimal_sep'),
        'thou'   => setting('thousands_sep'),
        'date'   => setting('date_format'),
        'months' => explode(' ', tRaw('date.months_short')),
        'region' => setting('default_region'),
    ];
}
