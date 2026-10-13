<?php
// Full site backups (Settings › Site Backup, admins only): one zip with a dump of the database
// and/or the site's files, kept on the server in uploads/backups/site/ until it's deleted.
// A backup is built in steps of a few seconds: each request carries on where the last one stopped
// (the progress is kept in work files next to the zip), so big photo folders never run into the
// web server's time limit. Scheduled backups start from a background request on an admin's page
// view (there's no cron). Loaded by core.php.
if (!defined('DB_HOST')) { http_response_code(403); exit; }

const SITE_BACKUP_DIR       = BACKUP_DIR . 'site/';
const SITE_BACKUP_PARTS     = ['db', 'photos', 'site'];
const SITE_BACKUP_SCHEDULES = ['off' => 0, 'daily' => 86400, 'weekly' => 7 * 86400, 'monthly' => 30 * 86400];
const SITE_BACKUP_TRIGGERS  = ['manual', 'schedule', 'update'];
const SITE_BACKUP_STEP_SECS = 15;      // work per request; well below the usual 30–90 s web server limits
/** Tables dumped without their rows: they list zips on this server, which aren't in the backup. */
const SITE_BACKUP_NO_DATA   = ['site_backups', 'user_backups'];
/** Files stored compressed in the zip (photos are already compressed, so they're stored as they are). */
const SITE_BACKUP_DEFLATE   = ['sql', 'json', 'css', 'txt', 'md', 'svg', 'htaccess', 'config', 'js', 'html'];

/** Creates site_backups when it isn't there yet (a backup before the database update that adds it). */
function siteBackupTable(): void {
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS `site_backups` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `filename` varchar(100) NOT NULL,
        `parts` varchar(30) NOT NULL,
        `trigger_type` enum('manual','schedule','update') NOT NULL DEFAULT 'manual',
        `status` enum('running','ready','failed') NOT NULL DEFAULT 'running',
        `file_count` int(10) unsigned NOT NULL DEFAULT 0,
        `missing_count` int(10) unsigned NOT NULL DEFAULT 0,
        `file_size` bigint(20) unsigned DEFAULT NULL,
        `error` varchar(500) DEFAULT NULL,
        `app_version` varchar(20) NOT NULL DEFAULT '',
        `created_by` int(10) unsigned DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `finished_at` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `filename` (`filename`),
        KEY `created_by` (`created_by`),
        CONSTRAINT `site_backups_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

// ── Helpers ──────────────────────────────

/** Path of a backup zip, or of one of its work files ($ext: .state, .list, .cd, .sql). Name always from the DB. */
function siteBackupPath(string $filename, string $ext = ''): string {
    return SITE_BACKUP_DIR . basename($filename) . $ext;
}

/** "850.3 MB" style size. */
function siteBackupSize(int|float|null $bytes): string {
    $b = (float)$bytes;
    if ($b >= 1024 ** 3) return fmtNum($b / 1024 ** 3, 2).' GB';
    if ($b >= 1024 ** 2) return fmtNum($b / 1024 ** 2, 1).' MB';
    return fmtNum(max(1, $b / 1024), 0).' KB';
}

/** Parts in a stored list ("db,photos") or a request, in the standard order, unknown ones dropped. */
function siteBackupParts(array|string $parts): array {
    if (is_string($parts)) $parts = explode(',', $parts);
    return array_values(array_intersect(SITE_BACKUP_PARTS, array_map('strval', $parts)));
}

/** Only one backup step runs at a time (per database: the lock name is server-wide). Waits up to $wait seconds. */
function siteBackupLock(int $wait = 0): bool {
    return (int)db()->query("SELECT GET_LOCK('gc_sb_".md5(DB_HOST.'/'.DB_NAME)."', ".max(0, $wait).")")->fetchColumn() === 1;
}
function siteBackupUnlock(): void {
    db()->query("SELECT RELEASE_LOCK('gc_sb_".md5(DB_HOST.'/'.DB_NAME)."')")->fetchColumn();
}

function siteBackupRow(int $id): ?array {
    siteBackupTable();
    $st = db()->prepare("SELECT * FROM site_backups WHERE id=?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** The backup being built (at most one), or null. */
function siteBackupRunning(): ?array {
    return db()->query("SELECT * FROM site_backups WHERE status='running' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
}

function siteBackupState(string $filename): ?array {
    $s = json_decode((string)@file_get_contents(siteBackupPath($filename, '.state')), true);
    return is_array($s) ? $s : null;
}

function siteBackupSaveState(string $filename, array $state): void {
    $path = siteBackupPath($filename, '.state');
    if (@file_put_contents($path.'.tmp', json_encode($state)) === false || !@rename($path.'.tmp', $path)) {
        throw new RuntimeException(tRaw('sbackup.err_write'));
    }
}

/**
 * Where each part's files come from: [folder on disk, folder inside the zip].
 * uploads/ keeps its own path, so it can be copied straight back. Languages and themes go to
 * site-files/: the zip has every one of them, but only the ones added or changed by hand should go back.
 */
function siteBackupSources(string $part): array {
    return match ($part) {
        'photos' => [[UPLOAD_DIR, 'uploads/users/']],
        'site'   => [[DEFAULTS_DIR, 'uploads/defaults/'], [__DIR__.'/uploads/icons/', 'uploads/icons/'],
                     [DEFECT_PHOTO_DIR, 'uploads/defects/'], [LANG_DIR, 'site-files/lang/'], [THEME_DIR, 'site-files/themes/']],
        default  => [],
    };
}

/** Calls $fn(SplFileInfo, path below $dir with /) for every regular file under $dir. */
function siteBackupWalk(string $dir, callable $fn): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS));
    foreach ($it as $f) {
        if ($f->isFile() && !$f->isLink()) $fn($f, str_replace('\\', '/', $it->getSubPathname()));
    }
}

/** Per part: ['files' => n, 'bytes' => size]. The database size is what MySQL reports (the dump is about as big). */
function siteBackupEstimate(array $parts = SITE_BACKUP_PARTS): array {
    $out = [];
    foreach ($parts as $part) {
        $files = 0; $bytes = 0;
        if ($part === 'db') {
            $bytes = (int)db()->query("SELECT COALESCE(SUM(DATA_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
        }
        foreach (siteBackupSources($part) as [$dir]) {
            try { siteBackupWalk($dir, function ($f) use (&$files, &$bytes) { $files++; $bytes += $f->getSize(); }); }
            catch (UnexpectedValueException) { /* unreadable folder: only an estimate, starting the backup reports it */ }
        }
        $out[$part] = ['files' => $files, 'bytes' => $bytes];
    }
    return $out;
}

// ── Starting, building, finishing ────────

/**
 * Starts a backup of $parts: lists the files, checks the disk space and adds the database row.
 * Nothing is written into the zip yet (siteBackupStep() does that). Returns [true, id] or [false, error].
 */
function siteBackupStart(array $parts, string $trigger, ?int $userId): array {
    siteBackupTable();
    $parts = siteBackupParts($parts);
    if (!$parts) return [false, tRaw('sbackup.err_no_parts')];
    if (!in_array($trigger, SITE_BACKUP_TRIGGERS, true)) $trigger = 'manual';
    if (!siteBackupLock()) return [false, tRaw('sbackup.err_busy')];
    try {
        if (siteBackupRunning()) return [false, tRaw('sbackup.err_running')];
        if (!is_dir(SITE_BACKUP_DIR)) @mkdir(SITE_BACKUP_DIR, 0755, true);
        if (!is_dir(SITE_BACKUP_DIR) || !is_writable(SITE_BACKUP_DIR)) return [false, tRaw('sbackup.err_folder')];

        $filename = 'site_'.date('Ymd-His').'_'.bin2hex(random_bytes(6)).'.zip';
        $list = @fopen(siteBackupPath($filename, '.list'), 'wb');
        if (!$list) return [false, tRaw('sbackup.err_folder')];
        // One line per file: name in the zip <tab> path on disk (names with a tab or line break are skipped)
        $total = 0; $bytes = 0;
        foreach ($parts as $part) {
            foreach (siteBackupSources($part) as [$dir, $prefix]) {
                siteBackupWalk($dir, function ($f, $sub) use ($list, $prefix, &$total, &$bytes) {
                    $line = $prefix.$sub."\t".$f->getPathname();
                    if (strpbrk($line, "\n\r") !== false || substr_count($line, "\t") !== 1) return;
                    fwrite($list, $line."\n");
                    $total++; $bytes += $f->getSize();
                });
            }
        }
        fclose($list);

        // Room for the zip (the database dump is about its size in MySQL), plus 20 MB to spare
        if (in_array('db', $parts, true)) $bytes += siteBackupEstimate(['db'])['db']['bytes'];
        $free = @disk_free_space(SITE_BACKUP_DIR);
        if ($free !== false && $free < $bytes * 1.05 + 20 * 1024 * 1024) {
            @unlink(siteBackupPath($filename, '.list'));
            return [false, tRaw('sbackup.err_space', ['need' => siteBackupSize($bytes), 'free' => siteBackupSize($free)])];
        }

        foreach (['', '.cd'] as $ext) touch(siteBackupPath($filename, $ext));
        siteBackupSaveState($filename, [
            'parts' => $parts, 'phase' => in_array('db', $parts, true) ? 'db' : 'files',
            'total' => $total, 'next' => 0, 'list_pos' => 0, 'missing' => 0,
            'bytes' => 0, 'cd_bytes' => 0, 'entries' => 0, 'tables' => 0,
        ]);
        db()->prepare("INSERT INTO site_backups (filename, parts, trigger_type, status, file_count, app_version, created_by)
                       VALUES (?, ?, ?, 'running', ?, ?, ?)")
            ->execute([$filename, implode(',', $parts), $trigger, $total, APP_VERSION, $userId]);
        return [true, (int)db()->lastInsertId()];
    } catch (Throwable $e) {
        if (isset($filename)) foreach (['', '.state', '.list', '.cd'] as $ext) @unlink(siteBackupPath($filename, $ext));
        return [false, $e->getMessage()]; // e.g. a folder that can't be read
    } finally {
        siteBackupUnlock();
    }
}

/** What the page shows for a backup: ['ok', 'id', 'status', 'phase', 'done', 'total', 'size', 'missing', 'error'] (+ 'busy'). */
function siteBackupProgress(array $row, ?array $state = null): array {
    if ($row['status'] === 'running') $state ??= siteBackupState($row['filename']);
    return [
        'ok'      => $row['status'] !== 'failed',
        'id'      => (int)$row['id'],
        'status'  => $row['status'],
        'phase'   => $state['phase'] ?? '',
        'done'    => (int)($state['next'] ?? $row['file_count']),
        'total'   => (int)($state['total'] ?? $row['file_count']),
        'size'    => (int)$row['file_size'],
        'missing' => (int)($state['missing'] ?? $row['missing_count']),
        'error'   => (string)$row['error'],
    ];
}

/**
 * Carries a running backup on for about $seconds: the database dump first, then the files, then the
 * zip's directory. Returns its progress (see siteBackupProgress()); 'busy' when another request is on it.
 */
function siteBackupStep(int $id, int $seconds = SITE_BACKUP_STEP_SECS): array {
    $row = siteBackupRow($id);
    if (!$row) return ['ok' => false, 'status' => 'failed', 'error' => tRaw('sbackup.err_gone')];
    if ($row['status'] !== 'running') return siteBackupProgress($row);
    if (!siteBackupLock()) return ['busy' => true] + siteBackupProgress($row);

    $deadline = microtime(true) + $seconds;
    @set_time_limit(max(120, $seconds * 6));
    ignore_user_abort(true);   // a closed tab doesn't stop the current step halfway
    $zh = $cdh = $lh = null;
    try {
        $row = siteBackupRow($id); // again, now that this request holds the lock
        if (!$row || $row['status'] !== 'running') return $row ? siteBackupProgress($row) : ['ok' => false, 'status' => 'failed', 'error' => tRaw('sbackup.err_gone')];
        $file  = $row['filename'];
        $state = siteBackupState($file);
        $zh    = @fopen(siteBackupPath($file), 'c+b');
        $cdh   = @fopen(siteBackupPath($file, '.cd'), 'c+b');
        if (!$state || !$zh || !$cdh) throw new RuntimeException(tRaw('sbackup.err_state'));
        // Whatever a step that broke off wrote after its last saved state goes; it's simply done again
        ftruncate($zh, $state['bytes']);
        ftruncate($cdh, $state['cd_bytes']);

        if ($state['phase'] === 'db') {
            $sql = siteBackupPath($file, '.sql');
            $state['tables'] = siteBackupDumpDb($sql);
            $in = fopen($sql, 'rb');
            try { sbZipAdd($zh, $cdh, $state, 'database.sql', $in, (int)filesize($sql), true, time()); }
            finally { fclose($in); }
            @unlink($sql);
            $state['phase'] = 'files';
            siteBackupSaveState($file, $state);
        }

        if ($state['phase'] === 'files') {
            $lh = fopen(siteBackupPath($file, '.list'), 'rb');
            if (!$lh) throw new RuntimeException(tRaw('sbackup.err_state'));
            fseek($lh, $state['list_pos']);
            $sinceSave = 0;
            while ($state['next'] < $state['total'] && microtime(true) < $deadline) {
                $line = fgets($lh);
                if ($line === false) { $state['next'] = $state['total']; break; } // list shorter than counted
                [$name, $path] = explode("\t", rtrim($line, "\n"), 2) + [1 => ''];
                $in = is_file($path) ? @fopen($path, 'rb') : false;
                if ($in) {
                    try {
                        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        sbZipAdd($zh, $cdh, $state, $name, $in, (int)filesize($path), in_array($ext, SITE_BACKUP_DEFLATE, true), (int)filemtime($path));
                    } finally { fclose($in); }
                } else {
                    $state['missing']++; // deleted since the backup started
                }
                $state['next']++;
                $state['list_pos'] = ftell($lh);
                if (++$sinceSave >= 25) { siteBackupSaveState($file, $state); $sinceSave = 0; }
            }
            if ($state['next'] >= $state['total']) $state['phase'] = 'finish';
            siteBackupSaveState($file, $state);
        }

        if ($state['phase'] === 'finish') {
            $site = siteName();
            $manifest = json_encode([
                'app'      => 'game-collection',
                'version'  => APP_VERSION,
                'site'     => $site,
                'created'  => gmdate('c', strtotime((string)$row['created_at']) ?: time()),
                'parts'    => $state['parts'],
                'files'    => $state['total'] - $state['missing'],
                'missing'  => $state['missing'],
                'tables'   => $state['tables'],
                'database' => in_array('db', $state['parts'], true) ? 'database.sql' : null,
                'trigger'  => $row['trigger_type'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $readme = str_replace("\n", "\r\n", tRaw('sbackup.readme', [
                'site' => $site, 'version' => APP_VERSION, 'date' => fmtDate((string)$row['created_at'], true),
                'parts' => implode(', ', array_map(fn($p) => tRaw('sbackup.part_'.$p), $state['parts'])),
            ]));
            foreach (['backup.json' => $manifest, 'README.txt' => $readme] as $name => $text) {
                $in = fopen('php://temp', 'w+b');
                fwrite($in, $text); rewind($in);
                try { sbZipAdd($zh, $cdh, $state, $name, $in, strlen($text), true, time()); }
                finally { fclose($in); }
            }
            $size = sbZipFinish($zh, $cdh, $state);
            fclose($zh); fclose($cdh); $zh = $cdh = null;
            if ($lh) { fclose($lh); $lh = null; }
            foreach (['.state', '.list', '.cd', '.sql'] as $ext) @unlink(siteBackupPath($file, $ext));
            db()->prepare("UPDATE site_backups SET status='ready', file_size=?, file_count=?, missing_count=?, finished_at=NOW() WHERE id=?")
                ->execute([$size, $state['total'] - $state['missing'], $state['missing'], $id]);
            siteBackupLog('sitebackup.create', $row, [activityChange('backup_parts', null, implode(',', $state['parts']), 'parts'),
                                                      activityChange('size', null, siteBackupSize($size))]);
            siteBackupPrune();
            return siteBackupProgress(siteBackupRow($id));
        }
        return siteBackupProgress($row, $state);
    } catch (Throwable $e) {
        foreach ([$zh, $cdh, $lh] as $h) if ($h) @fclose($h);
        $zh = $cdh = $lh = null;
        siteBackupFail($row, $e->getMessage());
        return siteBackupProgress(siteBackupRow($id) ?? $row);
    } finally {
        foreach ([$zh, $cdh, $lh] as $h) if ($h) @fclose($h);
        siteBackupUnlock();
    }
}

/** Builds a backup within this request (the database backup before a database update). Returns [true, id] or [false, error]. */
function siteBackupNow(array $parts, string $trigger, ?int $userId): array {
    [$ok, $id] = siteBackupStart($parts, $trigger, $userId);
    if (!$ok) return [false, $id];
    for ($tries = 0; $tries < 200; $tries++) {
        $p = siteBackupStep($id, 60);
        if (!empty($p['busy'])) { sleep(2); continue; }
        if ($p['status'] !== 'running') break;
    }
    return ($p['status'] ?? '') === 'ready' ? [true, $id] : [false, ($p['error'] ?? '') !== '' ? $p['error'] : tRaw('sbackup.err_unknown')];
}

/** Marks a backup failed and removes its files. */
function siteBackupFail(array $row, string $error): void {
    foreach (['', '.state', '.state.tmp', '.list', '.cd', '.sql'] as $ext) @unlink(siteBackupPath($row['filename'], $ext));
    db()->prepare("UPDATE site_backups SET status='failed', error=?, finished_at=NOW() WHERE id=?")
        ->execute([mb_substr($error, 0, 500), $row['id']]);
    siteBackupLog('sitebackup.fail', $row, [], mb_substr($error, 0, 500));
}

/**
 * A backup event for the activity log (System, Admin). Made by the admin who started it;
 * a scheduled one by the site itself ("Automatic"), whichever page view happened to run it.
 */
function siteBackupLog(string $action, array $row, array $changes = [], string $note = ''): void {
    $auto  = ($row['trigger_type'] ?? '') === 'schedule';
    $actor = null;
    if (!$auto && !empty($row['created_by'])) {
        $st = db()->prepare("SELECT id, username FROM users WHERE id=?");
        $st->execute([(int)$row['created_by']]);
        $actor = $st->fetch() ?: null;
    }
    logEvent('system', $action, ['admin' => true, 'actor' => $auto ? false : ($actor ?? activityMe() ?? false),
        'warn' => $action === 'sitebackup.fail', 'params' => ['auto' => $auto ? 1 : 0],
        'changes' => array_merge([activityChange('backup_kind', null, (string)($row['trigger_type'] ?? 'manual'), 'enum')], $changes),
        'note' => $note]);
}

/** Deletes a backup (zip, work files and row). A running one stops after its current step (waits for it). */
function siteBackupDelete(int $id): array {
    $row = siteBackupRow($id);
    if (!$row) return [false, tRaw('sbackup.err_gone')];
    if (!siteBackupLock(SITE_BACKUP_STEP_SECS + 15)) return [false, tRaw('sbackup.err_busy')];
    try {
        foreach (['', '.state', '.state.tmp', '.list', '.cd', '.sql'] as $ext) @unlink(siteBackupPath($row['filename'], $ext));
        db()->prepare("DELETE FROM site_backups WHERE id=?")->execute([$id]);
        $ch = [activityChange('backup_kind', null, (string)$row['trigger_type'], 'enum'), activityChange('made', null, (string)strtotime((string)$row['created_at']), 'date')];
        if ($row['status'] === 'ready') $ch[] = activityChange('size', null, siteBackupSize($row['file_size']));
        logEvent('system', 'sitebackup.delete', ['admin' => true, 'changes' => $ch]);
    } finally {
        siteBackupUnlock();
    }
    return [true, ''];
}

/**
 * Keeps the newest backup_keep backups of each automatic kind (scheduled / before an update);
 * the ones admins make themselves stay until they're deleted. Failed rows go after 30 days.
 */
function siteBackupPrune(): void {
    $keep = max(1, (int)setting('backup_keep'));
    $old  = db()->prepare("SELECT id, filename FROM site_backups WHERE status='ready' AND trigger_type=?
                           ORDER BY created_at DESC, id DESC LIMIT 1000 OFFSET $keep");
    $del  = db()->prepare("DELETE FROM site_backups WHERE id=?");
    foreach (['schedule', 'update'] as $kind) {
        $old->execute([$kind]);
        foreach ($old->fetchAll() as $r) {
            @unlink(siteBackupPath($r['filename']));
            $del->execute([$r['id']]);
        }
    }
    // Failed rows normally have no file; a picked-up zip that wasn't a complete backup does
    $failed = db()->query("SELECT id, filename FROM site_backups WHERE status='failed' AND created_at < NOW() - INTERVAL 30 DAY")->fetchAll();
    foreach ($failed as $r) {
        @unlink(siteBackupPath($r['filename']));
        $del->execute([$r['id']]);
    }
}

/**
 * Picks up files in uploads/backups/site/ that site_backups doesn't know, e.g. after the database
 * was restored from a backup (its dump leaves out the site_backups rows). A finished backup zip gets
 * its row back (contents, date and kind from its backup.json), so it can be downloaded again and the
 * "keep the last" rule sees it; a zip that isn't a complete backup is listed as failed (and goes after
 * 30 days). Work files of a backup that has no row any more are deleted.
 * Only while no backup is starting or running (the lock), so their files are never touched.
 */
function siteBackupAdopt(): void {
    if (!is_dir(SITE_BACKUP_DIR) || !siteBackupLock()) return;
    try {
        $known = array_flip(db()->query("SELECT filename FROM site_backups")->fetchAll(PDO::FETCH_COLUMN));
        foreach (glob(SITE_BACKUP_DIR . 'site_*') ?: [] as $path) {
            $base = basename($path);
            if (!preg_match('/^(site_[0-9]{8}-[0-9]{6}_[a-f0-9]{12}\.zip)(\.state|\.state\.tmp|\.list|\.cd|\.sql)?$/', $base, $m)) continue;
            if (isset($known[$m[1]])) continue;
            if (!empty($m[2])) { @unlink($path); continue; }                       // work file of a lost backup
            if (is_file($path.'.state')) { @unlink($path); continue; }              // unfinished zip of a lost backup
            $man   = json_decode((string)sbZipReadEntry($path, 'backup.json'), true);
            $parts = is_array($man) ? siteBackupParts((array)($man['parts'] ?? [])) : [];
            $when  = is_array($man) ? strtotime((string)($man['created'] ?? '')) : false;
            $ok    = $parts && ($man['app'] ?? '') === 'game-collection';
            db()->prepare("INSERT INTO site_backups (filename, parts, trigger_type, status, file_count, missing_count, file_size,
                                                     error, app_version, created_at, finished_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $m[1], implode(',', $parts),
                    in_array($man['trigger'] ?? '', SITE_BACKUP_TRIGGERS, true) ? $man['trigger'] : 'manual',
                    $ok ? 'ready' : 'failed',
                    (int)($man['files'] ?? 0), (int)($man['missing'] ?? 0), (int)filesize($path),
                    $ok ? null : tRaw('sbackup.err_not_backup'),
                    mb_substr((string)($man['version'] ?? ''), 0, 20),
                    date('Y-m-d H:i:s', $when ?: (int)filemtime($path)), date('Y-m-d H:i:s', (int)filemtime($path)),
                ]);
        }
    } finally {
        siteBackupUnlock();
    }
}

// ── Schedule ─────────────────────────────

/**
 * True when the schedule wants a new backup: the last scheduled one is older than the interval
 * (an hour early is fine, so a daily backup doesn't creep later each day). A failed one is retried after 6 hours.
 */
function siteBackupDue(): bool {
    $iv = SITE_BACKUP_SCHEDULES[setting('backup_schedule')] ?? 0;
    if (!$iv || !siteBackupParts(setting('backup_parts'))) return false;
    $last = db()->query("SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM site_backups
                         WHERE trigger_type='schedule' AND (status <> 'failed' OR created_at > NOW() - INTERVAL 6 HOUR)")->fetchColumn();
    return !$last || time() - (int)$last >= $iv - 3600;
}

/**
 * For admins: when a backup is being built, or the schedule wants one, keep it going with background
 * requests while the page is open (api/site_backup.php, action "auto"). Put it after csrfScript().
 */
function siteBackupAutoScript(array $user): string {
    if ($user['role'] !== 'admin') return '';
    try {
        if (!siteBackupRunning() && !siteBackupDue()) return '';
    } catch (PDOException) {
        return ''; // no site_backups table yet (database update waiting)
    }
    $url = json_encode(BASE_URL.'/api/site_backup.php', JSON_UNESCAPED_SLASHES);
    return <<<HTML
<script>
(async () => {
  for (let i = 0; i < 5000; i++) {
    let r;
    try {
      r = await fetch($url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{"action":"auto"}' }).then(r => r.json());
    } catch (e) { return; }
    if (!r.ok || r.status !== 'running') return;
    if (r.busy) await new Promise(f => setTimeout(f, 5000));
  }
})();
</script>
HTML;
}

// ── Database dump ────────────────────────

/**
 * Writes every table of the database to $path as SQL (DROP + CREATE + INSERTs), the way mysqldump
 * would: importable with phpMyAdmin or the mysql client. Uses its own connection that reads one
 * consistent snapshot and streams the rows. Returns the number of tables.
 */
function siteBackupDumpDb(string $path): int {
    $pdo = dbConnect([
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        PDO::ATTR_STRINGIFY_FETCHES        => true,
        PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_NUM,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");   // timestamps go out (and come back) in UTC: no shift
    $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
    $pdo->exec("START TRANSACTION WITH CONSISTENT SNAPSHOT");
    $out = @fopen($path, 'wb');
    if (!$out) throw new RuntimeException(tRaw('sbackup.err_write'));
    $w = function (string $s) use ($out) {
        if (fwrite($out, $s) !== strlen($s)) throw new RuntimeException(tRaw('sbackup.err_write'));
    };
    $q = fn(string $name) => '`'.str_replace('`', '``', $name).'`';

    $w("-- Game Collection database backup\n"
     . "-- Site version ".APP_VERSION.", made ".gmdate('Y-m-d H:i:s')." UTC\n"
     . "-- Import it into an empty database (phpMyAdmin › Import, or: mysql dbname < database.sql).\n\n"
     . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n"
     . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n");

    $tables = array_column($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(), 0);
    $colSt  = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    $binary = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit'];
    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE ".$q($table))->fetchAll()[0][1]; // fetchAll: an unbuffered result must be read to the end
        $w("DROP TABLE IF EXISTS ".$q($table).";\n".$create.";\n\n");
        if (in_array($table, SITE_BACKUP_NO_DATA, true)) continue;

        $colSt->execute([$table]);
        $cols = [];
        foreach ($colSt->fetchAll() as [$name, $type, $extra]) {
            if (stripos((string)$extra, 'GENERATED') !== false) continue; // computed by MySQL itself
            $cols[] = [$name, in_array(strtolower($type), $binary, true)];
        }
        if (!$cols) continue;
        $head = "INSERT INTO ".$q($table)." (".implode(', ', array_map(fn($c) => $q($c[0]), $cols)).") VALUES\n";
        $rows = $pdo->query("SELECT ".implode(', ', array_map(fn($c) => $q($c[0]), $cols))." FROM ".$q($table));
        $batch = ''; $n = 0;
        while (($r = $rows->fetch()) !== false) {
            $vals = [];
            foreach ($r as $i => $v) {
                $vals[] = $v === null ? 'NULL'
                        : ($cols[$i][1] ? ($v === '' ? "''" : '0x'.bin2hex($v)) : $pdo->quote((string)$v));
            }
            $batch .= ($n ? ",\n" : $head).'('.implode(',', $vals).')';
            // Statements of up to ~256 KB / 500 rows: well under MySQL's max_allowed_packet
            if (++$n >= 500 || strlen($batch) > 262144) { $w($batch.";\n"); $batch = ''; $n = 0; }
        }
        $rows->closeCursor();
        if ($n) $w($batch.";\n");
        $w("\n");
    }
    $w("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n");
    $pdo->exec("COMMIT");
    fclose($out);
    return count($tables);
}

// ── Zip writer ───────────────────────────
// PHP's ZipArchive writes the whole zip again each time a file is added to an existing one, so a
// backup built over many requests would copy every photo over and over. This small writer only appends:
// each file goes in as soon as it's read, and its central directory record waits in the .cd work file
// (one JSON line per file) until sbZipFinish(). Zip64 kicks in for files, offsets or counts past the
// 4 GB / 65535 limits. Names are UTF-8 (flag bit 11).

/** [time, date] in MS-DOS format, as zip headers want them. */
function sbDosTime(int $ts): array {
    $d = getdate(max($ts, 315532800)); // not before 1980
    return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
}

function sbWrite($fh, string $data): void {
    if ($data !== '' && fwrite($fh, $data) !== strlen($data)) throw new RuntimeException(tRaw('sbackup.err_write'));
}

/**
 * Appends one file to the zip at $st['bytes'], reading it from stream $in ($size = expected size).
 * Writes the local header, streams the data (raw deflate or stored) while computing the CRC, then
 * fills in the CRC and sizes. Updates $st (bytes, cd_bytes, entries).
 */
function sbZipAdd($zh, $cdh, array &$st, string $name, $in, int $size, bool $deflate, int $mtime): void {
    $offset = (int)$st['bytes'];
    $zip64  = $size >= 0xFFFF0000;             // a little room: the file may grow while it's read
    [$dosTime, $dosDate] = sbDosTime($mtime);
    $method = $deflate ? 8 : 0;
    $extra  = $zip64 ? pack('vvPP', 0x0001, 16, 0, 0) : '';
    $head   = pack('VvvvvvVVVvv', 0x04034b50, $zip64 ? 45 : 20, 0x0800, $method, $dosTime, $dosDate,
                   0, $zip64 ? 0xFFFFFFFF : 0, $zip64 ? 0xFFFFFFFF : 0, strlen($name), strlen($extra)).$name.$extra;
    fseek($zh, $offset);
    sbWrite($zh, $head);

    $hash = hash_init('crc32b');
    $z    = $deflate ? deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]) : null;
    $usize = 0; $csize = 0;
    while (!feof($in)) {
        $buf = fread($in, 1048576);
        if ($buf === false) throw new RuntimeException(tRaw('sbackup.err_read', ['file' => $name]));
        if ($buf === '') continue;
        hash_update($hash, $buf);
        $usize += strlen($buf);
        if ($z) $buf = deflate_add($z, $buf, ZLIB_NO_FLUSH);
        sbWrite($zh, $buf);
        $csize += strlen($buf);
    }
    if ($z) { $buf = deflate_add($z, '', ZLIB_FINISH); sbWrite($zh, $buf); $csize += strlen($buf); }
    if (!$zip64 && ($usize >= 0xFFFFFFFF || $csize >= 0xFFFFFFFF)) throw new RuntimeException(tRaw('sbackup.err_read', ['file' => $name]));
    $crc = (int)hexdec(hash_final($hash));

    // Fill in the CRC and the sizes
    fseek($zh, $offset + 14);
    sbWrite($zh, $zip64 ? pack('V', $crc) : pack('VVV', $crc, $csize, $usize));
    if ($zip64) { fseek($zh, $offset + 30 + strlen($name) + 4); sbWrite($zh, pack('PP', $usize, $csize)); }
    $st['bytes'] = $offset + strlen($head) + $csize;
    fseek($zh, $st['bytes']);

    $line = json_encode([bin2hex($name), $method, $crc, $csize, $usize, $offset, $dosTime, $dosDate, $zip64])."\n";
    fseek($cdh, (int)$st['cd_bytes']);
    sbWrite($cdh, $line);
    fflush($zh); fflush($cdh);
    $st['cd_bytes'] += strlen($line);
    $st['entries']++;
}

/**
 * Reads one file (at most 1 MB unpacked) from a zip made by this writer, without ZipArchive:
 * end record (or its zip64 version) → central directory → local header → data. Null when it isn't there.
 */
function sbZipReadEntry(string $path, string $want): ?string {
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    try {
        $size = (int)filesize($path);
        if ($size < 22) return null;
        $tail = min($size, 65557);                              // end record + the longest possible comment
        fseek($fh, $size - $tail);
        $buf = (string)fread($fh, $tail);
        $pos = strrpos($buf, "PK\x05\x06");
        if ($pos === false || strlen($buf) - $pos < 22) return null;
        $end = unpack('vdisk/vcddisk/ventries/vtotal/Vcdsize/Vcdoffset', substr($buf, $pos + 4, 16));
        $cdSize = $end['cdsize']; $cdOffset = $end['cdoffset'];
        if ($pos >= 20 && substr($buf, $pos - 20, 4) === "PK\x06\x07") {   // zip64 locator → zip64 end record
            $z64 = unpack('P', substr($buf, $pos - 12, 8))[1];
            fseek($fh, $z64);
            $rec = (string)fread($fh, 56);
            if (strlen($rec) === 56 && substr($rec, 0, 4) === "PK\x06\x06") {
                $cdSize   = unpack('P', substr($rec, 40, 8))[1];
                $cdOffset = unpack('P', substr($rec, 48, 8))[1];
            }
        }
        if ($cdOffset + $cdSize > $size || $cdSize > 64 * 1024 * 1024) return null;
        fseek($fh, $cdOffset);
        $cd = (string)fread($fh, $cdSize);
        for ($i = 0; $i + 46 <= strlen($cd); ) {
            if (substr($cd, $i, 4) !== "PK\x01\x02") return null;
            $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/vint/Vext/Voffset', substr($cd, $i + 4, 42));
            $name  = substr($cd, $i + 46, $h['nlen']);
            $extra = substr($cd, $i + 46 + $h['nlen'], $h['xlen']);
            $i += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];
            if ($name !== $want) continue;
            // zip64 extra: the fields set to 0xFFFFFFFF, in the order uncompressed size, compressed size, offset
            for ($x = 0; $x + 4 <= strlen($extra); $x += 4 + $len) {
                ['id' => $id, 'len' => $len] = unpack('vid/vlen', substr($extra, $x, 4));
                if ($id !== 1) continue;
                $vals = array_values(unpack('P*', substr($extra, $x + 4, $len)) ?: []);
                foreach (['usize', 'csize', 'offset'] as $f) if ($h[$f] === 0xFFFFFFFF) $h[$f] = (int)array_shift($vals);
            }
            if ($h['usize'] > 1048576 || $h['csize'] > 1048576 || !in_array($h['method'], [0, 8], true)) return null;
            fseek($fh, $h['offset']);
            $local = (string)fread($fh, 30);
            if (substr($local, 0, 4) !== "PK\x03\x04") return null;
            $l = unpack('vnlen/vxlen', substr($local, 26, 4));
            fseek($fh, $h['offset'] + 30 + $l['nlen'] + $l['xlen']);
            $data = $h['csize'] ? (string)fread($fh, $h['csize']) : '';
            if ($h['method'] === 8) $data = @gzinflate($data);
            return is_string($data) && (int)hexdec(hash('crc32b', $data)) === $h['crc'] ? $data : null;
        }
        return null;
    } finally {
        fclose($fh);
    }
}

/** Writes the central directory and the end records after the last file. Returns the zip's size. */
function sbZipFinish($zh, $cdh, array $st): int {
    $cdStart = (int)$st['bytes'];
    fseek($zh, $cdStart);
    rewind($cdh);
    $n = 0; $cdSize = 0; $any64 = false;
    while (ftell($cdh) < $st['cd_bytes'] && ($line = fgets($cdh)) !== false) {
        [$hexName, $method, $crc, $csize, $usize, $offset, $t, $d, $local64] = json_decode($line, true);
        $name  = hex2bin($hexName);
        $extra = ''; $u = $usize; $c = $csize; $o = $offset;
        if ($usize  >= 0xFFFFFFFF) { $extra .= pack('P', $usize);  $u = 0xFFFFFFFF; }
        if ($csize  >= 0xFFFFFFFF) { $extra .= pack('P', $csize);  $c = 0xFFFFFFFF; }
        if ($offset >= 0xFFFFFFFF) { $extra .= pack('P', $offset); $o = 0xFFFFFFFF; }
        if ($extra !== '') $extra = pack('vv', 0x0001, strlen($extra)).$extra;
        $is64 = $local64 || $extra !== '';
        $any64 = $any64 || $is64;
        $rec = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 45, $is64 ? 45 : 20, 0x0800, $method, $t, $d,
                    $crc, $c, $u, strlen($name), strlen($extra), 0, 0, 0, 0, $o).$name.$extra;
        sbWrite($zh, $rec);
        $cdSize += strlen($rec);
        $n++;
    }
    $cdEnd = $cdStart + $cdSize;
    $end = '';
    if ($any64 || $n >= 0xFFFF || $cdSize >= 0xFFFFFFFF || $cdStart >= 0xFFFFFFFF) {
        $end .= pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $n, $n, $cdSize, $cdStart); // zip64 end record
        $end .= pack('VVPV', 0x07064b50, 0, $cdEnd, 1);                                       // its locator
    }
    $end .= pack('VvvvvVVv', 0x06054b50, 0, 0, min($n, 0xFFFF), min($n, 0xFFFF),
                 min($cdSize, 0xFFFFFFFF), min($cdStart, 0xFFFFFFFF), 0);
    sbWrite($zh, $end);
    $total = $cdEnd + strlen($end);
    ftruncate($zh, $total);
    fflush($zh);
    return $total;
}
