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
    'timezone'          => '',            // '' = the server's own timezone
    'default_value_type'=> 'cib',         // loose | cib | new: price used for "owned value" on new copies
    'defaults_completeness' => '',        // one per line, for new users; '' = the language's default list
    'defaults_played'   => '',            // idem
    'backup_schedule'   => 'off',         // off | daily | weekly | monthly: automatic site backups (site_backup.php)
    'backup_parts'      => 'db,photos,site', // what an automatic backup holds
    'backup_keep'       => '3',           // automatic backups kept per kind
    // Activity log (activity.php)
    'log_enabled'       => '1',           // 0 = nothing is recorded at all (the switches below keep their state)
    'log_on_collection' => '1',           // record this type of event: 1 | 0
    'log_on_catalogue'  => '1',
    'log_on_sharing'    => '1',
    'log_on_accounts'   => '1',
    'log_on_system'     => '1',
    'log_logins'        => '0',           // also successful logins (failed ones / lockouts always, with Accounts on)
    'log_retention_days'=> '90',          // 30 | 90 | 365 | 0 = forever
    'log_ip_mode'       => 'security',    // off | security | all (always masked)
    'log_user_history'  => '1',           // users see their own copy's History in the edit drawer
    'log_last_purge'    => '',            // date of the last daily clean-up
    // Barcodes (barcodes.php)
    'barcode_enabled'   => '1',           // 0 = barcode scanning is switched off everywhere (data is kept)
    'barcode_who'       => 'all',         // all | admins: who may link barcodes to games (only admins remove)
    'barcode_unknown_days' => '30',       // 7 | 30 | 90 | 0 = keep unknown scans for ever
    'barcode_last_purge'=> '',            // date of the last daily clean-up of unknown scans
    // Database export (data_export.php)
    'dx_config'         => '',            // JSON: columns, order and options (dxConfig()); '' = the defaults
    'dx_public'         => '0',           // 1 = anyone may download the catalogue with the saved settings (api/catalogue_export.php)
];

const VALUE_TYPES    = ['loose', 'cib', 'new'];

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

/**
 * Stores an uploaded file at $dest. Copied rather than moved: on Windows / IIS a moved file keeps the permissions of
 * PHP's temp folder, which the web server can't read (the file then answers 500); a copy gets the permissions of the
 * folder it lands in, like every other file there.
 */
function storeUploadedFile(string $tmp, string $dest): bool {
    if (!is_uploaded_file($tmp) || !@copy($tmp, $dest)) return false;
    @unlink($tmp);
    return true;
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

// ── Timezone ─────────────────────────────

/** Applies the site timezone to PHP and to this request's MySQL session, so both agree on "now". */
function applyTimezone(): void {
    $tz = setting('timezone');
    if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) return;
    date_default_timezone_set($tz);
    try { db()->exec("SET time_zone = '".date('P')."'"); } catch (PDOException) {}
}

// ── Defaults for new users ───────────────

/** Starting completeness options for a new user: the admin's list, else the language's default list. */
function defaultCompletenessOptions(): array {
    return defaultOptionList('defaults_completeness', 'defaults.completeness');
}

function defaultPlayedOptions(): array {
    return defaultOptionList('defaults_played', 'defaults.played');
}

function defaultOptionList(string $setting, string $langKey): array {
    $raw  = setting($setting);
    $list = $raw !== '' ? preg_split('/\R/', $raw) : explode('|', tRaw($langKey));
    return array_values(array_unique(array_filter(array_map(fn($s) => mb_substr(trim($s), 0, 100), $list), 'strlen')));
}

// ── Played status groups ─────────────────
// The Collection page counts games per group: finished (Finished, Cheated, 100% …) and started (Started, Stuck …).

const PLAY_GROUPS = ['finished', 'started', 'none'];

/** Group of a played status: the user's choice, else a guess from its name (English and Dutch). Mirror: playGroupGuess() in settings.js. */
function playGroupOf(string $label, ?string $stored = null): string {
    if (in_array($stored, PLAY_GROUPS, true)) return $stored;
    $l = mb_strtolower($label);
    if (preg_match('/finish|complet|100|cheat|beat|clear|done|platin|uitgespeeld|voltooid|valsgespeeld|gehaald|klaar/u', $l)) return 'finished';
    if (preg_match('/start|stuck|playing|progress|begonnen|vastgelopen|vast|bezig|gestart/u', $l)) return 'started';
    return 'none';
}

/** A user's played statuses in order: [{label, group}] (group guessed when not chosen yet). */
function userPlayedOptions(int $userId): array {
    $st = db()->prepare("SELECT label, play_group FROM user_played_options WHERE user_id=? ORDER BY sort_order");
    $st->execute([$userId]);
    return array_map(fn($r) => ['label' => $r['label'], 'group' => playGroupOf($r['label'], $r['play_group'])], $st->fetchAll());
}

function showPlayedCounters(array $user): bool { return (int)($user['show_played'] ?? 1) === 1; }

/** Show the collection value graphs on the dashboard (users.show_value_graph, default on). */
function showValueGraphs(array $user): bool { return (int)($user['show_value_graph'] ?? 1) === 1; }

/** The dashboard's value graph card folded away with its Hide graphs button (users.value_graph_collapsed). */
function valueGraphsCollapsed(array $user): bool { return (int)($user['value_graph_collapsed'] ?? 0) === 1; }

/**
 * Finished / Started counters per system for the dashboard, counted per game the same way as the
 * Collection page: [system_id => ['finished' => n, 'started' => n, 'base' => n]], base = what the
 * percentage is of (all games, or the owned ones with played_pct 'owned').
 * $unit: SQL of the counting unit (editions); $compContents: compilations count their contents.
 */
function playedCountersBySystem(array $user, string $unit, bool $compContents): array {
    $fin = []; $sta = [];
    foreach (userPlayedOptions((int)$user['id']) as $o) {
        if ($o['group'] === 'finished') $fin[] = $o['label'];
        if ($o['group'] === 'started')  $sta[] = $o['label'];
    }
    $in = fn(array $l) => $l ? 'ce.played_status IN (' . implode(',', array_fill(0, count($l), '?')) . ')' : '0';
    $owned = $compContents ? '(ce.owned = 1 OR via.game_id IS NOT NULL)' : 'ce.owned = 1';
    $st = db()->prepare("
        SELECT g.system_id, $unit AS u,
               MAX(CASE WHEN $owned THEN 1 ELSE 0 END) AS o,
               MAX(CASE WHEN {$in($fin)} THEN 1 ELSE 0 END) AS f,
               MAX(CASE WHEN {$in($sta)} THEN 1 ELSE 0 END) AS s
        FROM games g
        LEFT JOIN collection_entries ce ON ce.game_id = g.id AND ce.user_id = ?
        " . ($compContents ? compilationCountJoins() : '') . "
        WHERE g.active = 1" . ($compContents ? ' AND comp.compilation_id IS NULL' : '') . "
        GROUP BY g.system_id, u
    ");
    $st->execute(array_merge($fin, $sta, [$user['id']], $compContents ? [$user['id']] : []));
    $ownedOnly = ($user['played_pct'] ?? 'all') === 'owned';
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $sid = (int)$r['system_id'];
        $out[$sid] ??= ['finished' => 0, 'started' => 0, 'base' => 0];
        if ($ownedOnly && !$r['o']) continue;
        $out[$sid]['base']++;
        if ($r['f']) $out[$sid]['finished']++;
        elseif ($r['s']) $out[$sid]['started']++;
    }
    return $out;
}

/** Gives a new user their starting completeness and played options. */
function seedUserOptions(int $userId): void {
    $ins = db()->prepare("INSERT INTO user_completeness_options (user_id, label, sort_order) VALUES (?,?,?)");
    foreach (defaultCompletenessOptions() as $i => $label) $ins->execute([$userId, $label, $i]);
    $ins = db()->prepare("INSERT INTO user_played_options (user_id, label, sort_order) VALUES (?,?,?)");
    foreach (defaultPlayedOptions() as $i => $label) $ins->execute([$userId, $label, $i]);
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

// ── Search all systems (header) ──────────

/** The search box for the header nav, with its script (assets/js/global-search.js). Needs appScript() on the page. */
function globalSearchHtml(): string {
    $js = BASE_URL . '/assets/js/global-search.js?v=' . @filemtime(__DIR__ . '/assets/js/global-search.js');
    return '<div class="gsearch" id="gsearch" data-base="' . htmlspecialchars(BASE_URL) . '" data-barcodes="' . (barcodeEnabled() ? '1' : '0') . '">'
         . '<span class="search-icon">⌕</span>'
         . '<input type="search" id="gs-input" autocomplete="off" spellcheck="false" placeholder="' . t('common.gs.placeholder') . '"'
         . ' title="' . t('common.gs.shortcut') . '" aria-label="' . t('common.gs.placeholder') . '" aria-controls="gs-panel" aria-expanded="false">'
         . '<div class="gs-panel" id="gs-panel" hidden></div>'
         . '</div>'
         . '<script src="' . htmlspecialchars($js) . '"></script>';
}

/**
 * The menu of the site header, the same on every page: search, Scan, Dashboard, Collection, Wishlist, Settings and
 * "Sign out <name>". $active: the page's own link (scan | dashboard | collection | wishlist | settings), highlighted.
 * Without a signed-in user (a public wishlist) the menu is empty.
 */
function siteNav(?array $user, string $active = ''): string {
    if (!$user) return '<nav class="site-nav"></nav>';
    $b = htmlspecialchars(BASE_URL);
    $links = [
        'scan'       => ['scan.php',       '<span aria-hidden="true">▥</span> ' . t('common.nav.scan')],
        'dashboard'  => ['dashboard.php',  t('common.nav.dashboard')],
        'collection' => ['collection.php', t('common.nav.collection')],
        'wishlist'   => ['wishlist.php',   t('common.nav.wishlist')],
        'settings'   => ['settings.php',   t('common.nav.settings')],
    ];
    $html = '<nav class="site-nav">' . globalSearchHtml();
    if (!barcodeEnabled()) unset($links['scan']);
    foreach ($links as $key => [$href, $label]) {
        $on = $key === $active;
        $html .= '<a href="' . $b . '/' . $href . '" class="nav-link' . ($on ? ' active' : '') . ($key === 'scan' ? ' gs-scan' : '') . '"'
               . ($on ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    }
    $html .= '<a href="' . $b . '/api/logout.php" class="nav-link">' . t('common.nav.sign_out_user', ['user' => $user['username']]) . '</a>';
    return $html . '</nav>';
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
        'valueType' => in_array(setting('default_value_type'), VALUE_TYPES, true) ? setting('default_value_type') : 'cib',
    ];
}
