<?php
// Updates: the check for a new GitHub release, the release zip admins can fetch to the
// server (Settings › Updates), and the database migration runner. Loaded by core.php.
if (!defined('DB_HOST')) { http_response_code(403); exit; }

const MIGRATIONS_DIR   = __DIR__ . '/migrations/';
const UPDATES_DIR      = __DIR__ . '/uploads/updates/';
const UPDATE_CHECK_TTL = 24 * 60 * 60;        // ask GitHub at most once a day (unless "Check now")
const UPDATE_MAX_ZIP   = 50 * 1024 * 1024;

/**
 * Migrations from before the runner existed, and a column each one adds: on a database
 * without schema_migrations, the ones whose column is there were already run by hand.
 */
const LEGACY_MIGRATIONS = [
    '001_point_grading.sql'      => ['users', 'grading_mode'],
    '002_themes.sql'             => ['users', 'theme'],
    '003_languages_settings.sql' => ['users', 'language'],
    '004_editions.sql'           => ['games', 'group_id'],
    '006_condition_report.sql'   => ['users', 'label_template_id'],
];

// ── Database migrations ──────────────────
// Each file in migrations/ runs once, in number order (001_, 002_, …), and is then recorded
// in schema_migrations. schema.sql lists the ones it already includes, so fresh installs skip them.

/** Every migration file name, in the order they must run. */
function migrationFiles(): array {
    $files = array_filter(array_map('basename', glob(MIGRATIONS_DIR . '*.sql') ?: []),
                          fn($f) => preg_match('/^[A-Za-z0-9_.-]+\.sql$/', $f));
    usort($files, 'strnatcmp');
    return $files;
}

/** File names recorded in schema_migrations (creating the table on an install from before the runner). */
function appliedMigrations(): array {
    try {
        return db()->query("SELECT filename FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') throw $e; // only "table doesn't exist" is expected
    }
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `filename`   varchar(190) NOT NULL,
        `applied_at` timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`filename`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $col = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $ins = $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename) VALUES (?)");
    foreach (LEGACY_MIGRATIONS as $file => [$table, $column]) {
        $col->execute([$table, $column]);
        if ((int)$col->fetchColumn() > 0) $ins->execute([$file]);
    }
    return $pdo->query("SELECT filename FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
}

/** Migration files this database hasn't had yet, in run order. */
function pendingMigrations(): array {
    return array_values(array_diff(migrationFiles(), appliedMigrations()));
}

/** The statements of a .sql file (same splitting as the installer: full-line comments out, split on ; at line end). */
function migrationStatements(string $sql): array {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', $sql)), 'strlen'));
}

/**
 * Runs every pending migration in order and records each one; stops at the first error.
 * MySQL can't roll back schema changes, so statements before the failing one stay applied.
 * Returns ['ok', 'done' => [files], 'failed' => file|null, 'error' => message].
 */
function runPendingMigrations(): array {
    $pdo = db();
    $res = ['ok' => true, 'done' => [], 'failed' => null, 'error' => ''];
    if (!(int)$pdo->query("SELECT GET_LOCK('gc_migrations', 5)")->fetchColumn()) {
        return ['ok' => false, 'done' => [], 'failed' => null, 'error' => tRaw('updates.db_busy')];
    }
    @set_time_limit(0);
    try {
        foreach (pendingMigrations() as $file) {
            $n = 0;
            try {
                foreach (migrationStatements((string)file_get_contents(MIGRATIONS_DIR . $file)) as $stmt) {
                    $n++;
                    $pdo->exec($stmt);
                }
            } catch (PDOException $e) {
                $res = ['ok' => false, 'done' => $res['done'], 'failed' => $file,
                        'error' => tRaw('updates.db_failed', ['file' => $file, 'n' => $n, 'error' => $e->getMessage()])];
                break;
            }
            $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?)")->execute([$file]);
            $res['done'][] = $file;
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('gc_migrations')")->fetchColumn();
    }
    return $res;
}

/**
 * Called by core.php on every request. While migrations are pending, only signing in and out
 * and Settings › Updates (admins) work: admins are sent there, everyone else gets a short notice.
 */
function migrationGate(): void {
    try { if (!pendingMigrations()) return; }
    catch (PDOException) { return; } // database unreachable: let the page report it as usual
    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $isApi  = basename(dirname($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'api';
    if ($isApi ? $script === 'logout.php' : $script === 'index.php') return;
    $admin = isAdmin();
    if ($admin && !$isApi && $script === 'settings.php' && ($_GET['s'] ?? '') === 'updates') return;
    if ($isApi) jsonOut(['ok' => false, 'error' => tRaw('updates.maint_text')], 503);
    if ($admin) { header('Location: '.BASE_URL.'/settings.php?s=updates'); exit; }
    http_response_code(503);
    header('Retry-After: 300');
    exit('<!DOCTYPE html><html lang="'.currentLang().'"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>'.pageTitle(tRaw('updates.maint_title')).'</title>'
       . '<link rel="stylesheet" href="'.BASE_URL.'/assets/css/main.css">'.themeHead().'</head><body>'
       . '<section class="cp-card" style="max-width:480px;margin:80px auto;text-align:center">'
       . '<h2>'.t('updates.maint_title').'</h2><p style="font-size:.85rem">'.t('updates.maint_text').'</p>'
       . '</section></body></html>');
}

// ── Release check (GitHub) ───────────────

/**
 * HTTPS GET with cURL, else PHP's own streams. With $sink the body goes into that file
 * (max UPDATE_MAX_ZIP) instead of being returned. Returns [HTTP status, body, error].
 */
function httpGet(string $url, ?string $sink = null): array {
    $headers = ['User-Agent: game-collection/'.APP_VERSION, 'Accept: application/vnd.github+json'];
    $timeout = $sink ? 300 : 10;
    if (function_exists('curl_init')) {
        $fh = $sink ? fopen($sink, 'wb') : null;
        if ($sink && !$fh) return [0, '', tRaw('updates.err_folder')];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_FOLLOWLOCATION  => true,      // zipball → codeload.github.com
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_NOPROGRESS      => false,
            CURLOPT_XFERINFOFUNCTION => fn($c, $total, $now) => $now > UPDATE_MAX_ZIP ? 1 : 0, // non-zero aborts
        ] + ($fh ? [CURLOPT_FILE => $fh] : [CURLOPT_RETURNTRANSFER => true]));
        $body   = curl_exec($ch);
        $err    = $body === false ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        if ($fh) fclose($fh);
        return [$status, $fh || $body === false ? '' : (string)$body, $err];
    }
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) return [0, '', tRaw('updates.err_no_http')];
    $ctx = stream_context_create(['http' => [
        'header' => implode("\r\n", $headers), 'timeout' => $timeout, 'ignore_errors' => true,
        'follow_location' => 1, 'max_redirects' => 5,
    ]]);
    $in = @fopen($url, 'rb', false, $ctx);
    if (!$in) return [0, '', error_get_last()['message'] ?? tRaw('updates.err_connect')];
    $status = 0;
    foreach (stream_get_meta_data($in)['wrapper_data'] ?? [] as $line) {
        if (is_string($line) && preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) $status = (int)$m[1]; // last one = after redirects
    }
    $body = '';
    if ($sink) {
        $out = @fopen($sink, 'wb');
        if (!$out) { fclose($in); return [0, '', tRaw('updates.err_folder')]; }
        stream_copy_to_stream($in, $out, UPDATE_MAX_ZIP + 1);
        fclose($out);
    } else {
        $body = (string)stream_get_contents($in);
    }
    fclose($in);
    return [$status, $body, ''];
}

/**
 * Asks GitHub for the latest release and caches the answer in app_settings ('update_check'):
 * ['checked' => time, 'ok', 'error', 'release' => null | [tag, version, name, notes, url, published, zip]].
 * A failed check keeps the release found last time.
 */
function updateCheckNow(): array {
    $old   = updateCache();
    $cache = ['checked' => time(), 'ok' => false, 'error' => '', 'release' => $old['release'] ?? null];
    [$status, $body, $err] = httpGet('https://api.github.com/repos/'.APP_REPO.'/releases/latest');
    if ($err !== '' || $status === 0) {
        $cache['error'] = $err !== '' ? $err : tRaw('updates.err_connect');
    } elseif ($status === 404) {
        $cache['ok'] = true; $cache['release'] = null; // no release published yet
    } elseif ($status !== 200) {
        $cache['error'] = tRaw('updates.err_http', ['status' => $status]);
    } else {
        $r = json_decode($body, true);
        if (!is_array($r) || !is_string($r['tag_name'] ?? null)) {
            $cache['error'] = tRaw('updates.err_response');
        } else {
            // A .zip attached to the release wins over GitHub's automatic source zip
            $zip = '';
            foreach ($r['assets'] ?? [] as $a) {
                if (str_ends_with(strtolower((string)($a['name'] ?? '')), '.zip')) { $zip = (string)($a['browser_download_url'] ?? ''); break; }
            }
            $cache['ok'] = true;
            $cache['release'] = [
                'tag'       => $r['tag_name'],
                'version'   => ltrim($r['tag_name'], 'vV'),
                'name'      => (string)($r['name'] ?? ''),
                'notes'     => mb_substr((string)($r['body'] ?? ''), 0, 20000),
                'url'       => (string)($r['html_url'] ?? ''),
                'published' => (string)($r['published_at'] ?? ''),
                'zip'       => $zip !== '' ? $zip : (string)($r['zipball_url'] ?? ''),
            ];
        }
    }
    try { setAppSetting('update_check', json_encode($cache)); } catch (PDOException) {}
    return $cache;
}

/** The last check's result (see updateCheckNow()), or null. Never contacts GitHub. */
function updateCache(): ?array {
    try { $c = json_decode((string)appSetting('update_check', ''), true); }
    catch (PDOException) { return null; } // no app_settings yet (very old install)
    return is_array($c) ? $c : null;
}

function updateStale(?array $cache): bool {
    return !$cache || time() - (int)($cache['checked'] ?? 0) >= UPDATE_CHECK_TTL;
}

/** The cached latest release when it's newer than this install, else null. */
function updateAvailable(?array $cache): ?array {
    $r = $cache['release'] ?? null;
    return is_array($r) && version_compare($r['version'], APP_VERSION, '>') ? $r : null;
}

/** For admins: a notice linking to Settings › Updates when a newer release is known (dashboard). */
function updateNoticeHtml(array $user): string {
    if ($user['role'] !== 'admin' || !($r = updateAvailable(updateCache()))) return '';
    return '<a class="cp-msg" href="'.BASE_URL.'/settings.php?s=updates" style="display:block;margin-bottom:18px;text-decoration:none">'
         . t('updates.dash_notice', ['version' => $r['version']]).'</a>';
}

/** For admins, when the cached check is a day old: refresh it in the background. Put it after csrfScript(). */
function updateRefreshScript(array $user): string {
    if ($user['role'] !== 'admin' || !updateStale(updateCache())) return '';
    return '<script>fetch('.json_encode(BASE_URL.'/api/update_check.php', JSON_UNESCAPED_SLASHES).', { method: "POST" }).catch(() => {});</script>';
}

// ── Release zip on the server ────────────

/** Zips in uploads/updates/, newest first: [name, size, time]. */
function updateZips(): array {
    $out = [];
    foreach (glob(UPDATES_DIR . '*.zip') ?: [] as $f) $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/** Full path of a stored release zip, or null (the name must look like one we saved). */
function updateZipPath(string $name): ?string {
    if (!preg_match('/^game-collection-[A-Za-z0-9._-]+\.zip$/', $name)) return null;
    $path = UPDATES_DIR . $name;
    return is_file($path) ? $path : null;
}

/** Downloads a release's zip into uploads/updates/, replacing older ones. Returns [ok, message]. */
function updateDownload(array $release): array {
    $url = (string)$release['zip'];
    if (!preg_match('~^https://(api\.github\.com/repos|github\.com)/'.preg_quote(APP_REPO, '~').'/~', $url)) {
        return [false, tRaw('updates.err_dl', ['error' => tRaw('updates.err_url')])];
    }
    if (!is_dir(UPDATES_DIR) && !@mkdir(UPDATES_DIR, 0755, true)) return [false, tRaw('updates.err_dl', ['error' => tRaw('updates.err_folder')])];
    $name = 'game-collection-'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $release['tag']).'.zip';
    $tmp  = UPDATES_DIR . $name . '.part';
    @set_time_limit(0);
    [$status, , $err] = httpGet($url, $tmp);
    clearstatcache();
    $size = is_file($tmp) ? (int)filesize($tmp) : 0;
    $fail = $err !== '' ? $err
          : ($status !== 200 ? tRaw('updates.err_http', ['status' => $status])
          : ($size > UPDATE_MAX_ZIP ? tRaw('updates.err_too_big', ['n' => UPDATE_MAX_ZIP / 1024 / 1024])
          : ((string)@file_get_contents($tmp, false, null, 0, 4) !== "PK\x03\x04" ? tRaw('updates.err_not_zip') : '')));
    if ($fail !== '') { @unlink($tmp); return [false, tRaw('updates.err_dl', ['error' => $fail])]; }
    foreach (updateZips() as $z) @unlink(UPDATES_DIR . $z['name']);
    if (!@rename($tmp, UPDATES_DIR . $name)) { @unlink($tmp); return [false, tRaw('updates.err_dl', ['error' => tRaw('updates.err_folder')])]; }
    return [true, tRaw('updates.dl_done', ['file' => $name])];
}
