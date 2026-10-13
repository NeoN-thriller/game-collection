<?php
// Activity log: what changed on the site, written by the server after a change succeeded
// (logEvent()), read by admins in Settings › Activity log and by users as the History of
// their own copies (edit drawer). Rows hold machine keys plus the values the sentence needs;
// the text is made at read time, in the reader's language. Loaded by core.php.
if (!defined('DB_HOST')) { http_response_code(403); exit; }

const LOG_CATEGORIES = ['collection', 'catalogue', 'sharing', 'accounts', 'system'];
const LOG_RETENTIONS = ['30' => 30, '90' => 90, '365' => 365, '0' => 0];   // days; 0 = forever
const LOG_IP_MODES   = ['off', 'security', 'all'];
const LOG_PERIODS    = ['7' => 7, '30' => 30, '90' => 90, 'all' => 0];
const LOG_DATA_MAX   = 8192;
/** Change keys with values only the owner sees (money and free text): the site-wide log says "changed". */
const LOG_PRIVATE    = ['paid', 'personal_price', 'buy_min', 'buy_max', 'notes', 'upgrade_reason'];

/** Every event: action => type. The summary text is log.a.<action, dots as underscores> (+ _<p.v> for variants). */
const LOG_ACTIONS = [
    'copy.add' => 'collection', 'copy.edit' => 'collection', 'copy.unown' => 'collection', 'copy.delete' => 'collection',
    'copy.move_edition' => 'collection', 'wishlist.add' => 'collection', 'wishlist.remove' => 'collection',
    'copy.photos_add' => 'collection', 'copy.photos_delete' => 'collection', 'games.add_many' => 'collection',
    'data.import' => 'collection', 'backup.generate' => 'collection', 'backup.delete' => 'collection',

    'prices.import' => 'catalogue', 'games.import' => 'catalogue', 'game.add' => 'catalogue', 'game.edit' => 'catalogue', 'game.toggle' => 'catalogue',
    'game.image' => 'catalogue', 'edition.link' => 'catalogue', 'edition.update' => 'catalogue', 'edition.remove' => 'catalogue',
    'edition.unlink' => 'catalogue', 'edition.ignore' => 'catalogue', 'edition.unignore' => 'catalogue',
    'compilation.save' => 'catalogue', 'compilation.dismiss' => 'catalogue',
    'system.add' => 'catalogue', 'system.icon' => 'catalogue', 'system.region' => 'catalogue',
    'barcode.add' => 'catalogue', 'barcode.flag' => 'catalogue', 'barcode.remove' => 'catalogue', 'barcode.keep' => 'catalogue',
    'barcode.edit' => 'catalogue', 'barcode.import' => 'catalogue',
    'igdb.link' => 'catalogue', 'igdb.platform' => 'catalogue', 'igdb.refresh' => 'catalogue', 'igdb.cover' => 'catalogue',
    'igdb.cover_default' => 'catalogue',

    'share.create' => 'sharing', 'share.update' => 'sharing', 'share.regenerate' => 'sharing', 'share.revoke' => 'sharing',
    'share.mode' => 'sharing', 'share.for_sale' => 'sharing', 'wishlist.public' => 'sharing', 'wishlist.token' => 'sharing',
    'labeltpl.share' => 'sharing',

    'login.success' => 'accounts', 'login.lockout' => 'accounts', 'password.lockout' => 'accounts', 'register.invite' => 'accounts',
    'password.change' => 'accounts', 'invite.create' => 'accounts', 'invite.delete' => 'accounts', 'user.status' => 'accounts',
    'user.password_reset' => 'accounts', 'user.unlock' => 'accounts', 'user.unlock_all' => 'accounts',

    'settings.save' => 'system', 'theme.default' => 'system', 'language.upload' => 'system', 'language.default' => 'system',
    'grading.labels' => 'system', 'grading.template' => 'system', 'grading.template_delete' => 'system',
    'grading.profile' => 'system', 'grading.profile_delete' => 'system', 'grading.own_weight' => 'system',
    'barcode.settings' => 'system', 'grading.auto_assign' => 'system', 'grading.fill' => 'system', 'grading.recalc' => 'system', 'grading.import' => 'system',
    'grading.reset' => 'system', 'grading.defect_photo_add' => 'system', 'grading.defect_photo_delete' => 'system',
    'labelsize.add' => 'system', 'labelsize.edit' => 'system', 'labelsize.delete' => 'system',
    'update.fetch' => 'system', 'update.delete' => 'system', 'migration.run' => 'system',
    'sitebackup.create' => 'system', 'sitebackup.fail' => 'system', 'sitebackup.delete' => 'system', 'sitebackup.settings' => 'system',
    'log.settings' => 'system', 'log.purge' => 'system', 'dataexport.settings' => 'system',
];

// ── Writing ──────────────────────────────

/**
 * Writes one event. Never throws and never slows a request down much (one INSERT, or one
 * UPDATE when merged). Call it after the work succeeded (after commit()).
 * $o: admin (bool) · warn (bool) · security (bool: may store the IP with the default setting) ·
 *     actor (user row / ['id','username'] / false = nobody, e.g. a scheduled job; default the signed-in user) ·
 *     subject ([type, id]) · target (['id', 'name']) · params (array) · changes (list from activityChange()) ·
 *     note (string) · merge (['key' => …, 'window' => seconds, 'into' => action]: see activityMerge())
 */
function logEvent(string $category, string $action, array $o = []): void {
    try {
        if (setting('log_enabled') !== '1' || !in_array($category, LOG_CATEGORIES, true) || setting('log_on_'.$category) !== '1') return;
        $actor = array_key_exists('actor', $o) ? $o['actor'] : activityMe();
        $uid   = is_array($actor) && !empty($actor['id']) ? (int)$actor['id'] : null;
        $uname = is_array($actor) ? mb_substr((string)($actor['username'] ?? ''), 0, 50) : '';
        $ipMode = setting('log_ip_mode');
        $ip    = $ipMode === 'all' || ($ipMode === 'security' && !empty($o['security'])) ? activityMaskIp(clientIp()) : '';
        $data  = array_filter([
            'p' => (array)($o['params'] ?? []),
            'c' => array_values(array_filter((array)($o['changes'] ?? []))),
            'n' => (string)($o['note'] ?? ''),
        ], fn($v) => $v !== [] && $v !== '');

        [$sType, $sId] = isset($o['subject']) ? [(string)$o['subject'][0], (int)$o['subject'][1]] : [null, null];
        if (!empty($o['merge'])) {
            $data['p']['mk'] = (string)$o['merge']['key'];   // what may be merged with this row later
            if ($uid && activityMerge($uid, $action, $data, $o['merge'], $sType === 'copy' ? $sId : null)) return;
        }
        db()->prepare("INSERT INTO activity_log (user_id, username, category, action, is_admin, is_warning, subject_type, subject_id,
                                                 target_user_id, target_username, ip, data)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$uid, $uname, $category, mb_substr($action, 0, 60), !empty($o['admin']) ? 1 : 0, !empty($o['warn']) ? 1 : 0,
                       $sType, $sId, isset($o['target']['id']) ? (int)$o['target']['id'] : null,
                       isset($o['target']['name']) ? mb_substr((string)$o['target']['name'], 0, 50) : null,
                       $ip !== '' ? $ip : null, $data ? activityEncode($data) : null]);
    } catch (Throwable $e) {
        error_log('game-collection: activity log: '.$e->getMessage());
    }
}

/** The signed-in user (looked up once per request), or null. */
function activityMe(): ?array {
    static $me = false;
    if ($me === false) $me = auth() ?: null;
    return $me;
}

/**
 * Bulk adds: when the same user did the same kind of thing for the same key (e.g. marked games as
 * owned in one system, or added photos to one copy) within the window, the newest such row is
 * updated instead of adding another: its count goes up and the titles / ids are added.
 * The first row of a run is a normal event; the second turns it into $merge['into'] (if given).
 * $merge['sum']: the parts of one job (same key) — numeric params and plain (untyped) numeric changes are added up.
 */
function activityMerge(int $uid, string $action, array $data, array $merge, ?int $copyId): bool {
    $key  = (string)$merge['key'];
    $into = (string)($merge['into'] ?? $action);
    $st = db()->prepare("SELECT id, action, subject_type, subject_id, data FROM activity_log
                         WHERE user_id=? AND action IN (?, ?) AND created_at > NOW() - INTERVAL ? SECOND
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$uid, $action, $into, max(60, (int)($merge['window'] ?? 600))]);
    $row = $st->fetch();
    if (!$row) return false;
    $old = json_decode((string)$row['data'], true) ?: [];
    if ((string)($old['p']['mk'] ?? '') !== $key) return false;

    $p = $old['p'];
    $new = $data['p'] ?? [];
    if (!empty($merge['sum'])) {
        // One job sent in parts (e.g. an import in batches): add up the counts instead of counting rows
        foreach ($new as $k => $v) if ($k !== 'mk' && is_numeric($v)) $p[$k] = (int)($p[$k] ?? 0) + (int)$v;
        $byKey = [];
        foreach ($data['c'] ?? [] as $c) $byKey[$c['k']] = $c;
        foreach ($old['c'] ?? [] as $i => $c) {
            $add = $byKey[$c['k']] ?? null;
            if ($add && !isset($c['ty']) && is_numeric($c['to']) && is_numeric($add['to'])) $old['c'][$i]['to'] = (string)((int)$c['to'] + (int)$add['to']);
            unset($byKey[$c['k']]);
        }
        if ($byKey) $old['c'] = array_merge($old['c'] ?? [], array_values($byKey));
        $out = ['p' => $p] + array_diff_key($old, ['p' => 1]);
        db()->prepare("UPDATE activity_log SET data=?, created_at=NOW() WHERE id=?")->execute([activityEncode($out), (int)$row['id']]);
        return true;
    }
    $p['n'] = (int)($p['n'] ?? 1) + (int)($new['n'] ?? 1);
    if ($into !== $action) {
        // Copies merged into one row keep their ids, so each copy's History still shows when it was added
        $ids = array_merge((array)($p['ids'] ?? ($row['subject_type'] === 'copy' && $row['subject_id'] ? [(int)$row['subject_id']] : [])),
                           $copyId ? [$copyId] : []);
        if ($ids) $p['ids'] = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 500);
        $titles = array_merge((array)($p['titles'] ?? (isset($p['title']) ? [$p['title']] : [])),
                              (array)($new['titles'] ?? (isset($new['title']) ? [$new['title']] : [])));
        if ($titles) $p['titles'] = array_slice($titles, 0, 200);
    }
    $out = ['p' => $p] + array_diff_key($old, ['p' => 1]);
    if ($into !== $action) unset($out['c']);   // one copy's details don't describe the whole run
    // Several copies: the row isn't about one copy any more (their ids are in p.ids)
    $single = $into === $action && $row['subject_type'] !== null;
    db()->prepare("UPDATE activity_log SET action=?, data=?, created_at=NOW(), subject_type=?, subject_id=? WHERE id=?")
        ->execute([$into, activityEncode($out), $single ? $row['subject_type'] : null, $single ? $row['subject_id'] : null, (int)$row['id']]);
    return true;
}

/** JSON for the data column, cut to LOG_DATA_MAX: changes go from the end first, then the note. */
function activityEncode(array $data): string {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    $json = (string)json_encode($data, $flags);
    $cut = 0;
    while (strlen($json) > LOG_DATA_MAX && !empty($data['c'])) {
        array_pop($data['c']); $cut++;
        $data['x'] = $cut;                                   // "and {n} more changes"
        $json = (string)json_encode($data, $flags);
    }
    if (strlen($json) > LOG_DATA_MAX) { unset($data['n']); $json = (string)json_encode($data, $flags); }
    if (strlen($json) > LOG_DATA_MAX) {
        // Long lists in the params (titles, ids): keep the start
        foreach ($data['p'] ?? [] as $k => $v) if (is_array($v)) $data['p'][$k] = array_slice($v, 0, 20);
        $json = (string)json_encode($data, $flags);
    }
    return strlen($json) > LOG_DATA_MAX ? (string)json_encode(['p' => ['cut' => 1]]) : $json;
}

/**
 * One line of the details: label key (log.f.<k>, else shown as is), old and new value.
 * $type: money | bool | enum (log.v.<value>) | text (default). Special values:
 * '__removed', '__cleared', '__changed', '__hidden' (password), '__set' (a text was set, not shown).
 */
function activityChange(string $k, mixed $from, mixed $to, string $type = 'text'): array {
    $norm = fn($v) => $v === null ? null : (is_bool($v) ? ($v ? '1' : '0') : mb_substr((string)$v, 0, 200));
    return ['k' => $k, 'from' => $norm($from), 'to' => $norm($to)] + ($type !== 'text' ? ['ty' => $type] : []);
}

/**
 * The fields of $fields ([column => [key, type]]) whose value differs between $before and $after,
 * as activityChange() lines. Numbers are compared as numbers (8 vs 8.00).
 */
function activityDiff(array $before, array $after, array $fields): array {
    $out = [];
    foreach ($fields as $col => [$key, $type]) {
        if (!array_key_exists($col, $after)) continue;
        $a = $before[$col] ?? null; $b = $after[$col] ?? null;
        $same = $type === 'money' || $type === 'bool'
            ? (($a === null || $a === '') ? null : (float)$a) === (($b === null || $b === '') ? null : (float)$b)
            : (string)($a ?? '') === (string)($b ?? '');
        if (!$same) $out[] = activityChange($key, $a === '' ? null : $a, $b === '' ? null : $b, $type);
    }
    return $out;
}

/** Copy columns the log records: column => [change key, type]. Prices and texts are private (LOG_PRIVATE). */
const LOG_COPY_FIELDS = [
    'owned' => ['owned', 'bool'], 'wishlist' => ['wishlist', 'bool'], 'wishlist_any' => ['any_edition', 'bool'],
    'completeness' => ['completeness', 'text'], 'played_status' => ['played', 'text'], 'variant' => ['variant', 'text'],
    'upgrade' => ['upgrade', 'bool'], 'upgrade_reason' => ['upgrade_reason', 'text'], 'tag' => ['tag', 'text'],
    'notes' => ['notes', 'text'], 'price_paid' => ['paid', 'money'], 'chart_price' => ['personal_price', 'money'],
    'price_min' => ['buy_min', 'money'], 'price_max' => ['buy_max', 'money'], 'value_price_type' => ['value_type', 'enum'],
];

/**
 * What a copy's grading and photo tags look like, to compare before and after a save:
 * ['condition' => "Mint · 92" | "Good" | '', 'defects' => logged defects, 'tags' => tagged photos, 'tags_sig' => hash].
 */
function activityCopyState(int $entryId): array {
    $st = db()->prepare("SELECT grade_method, grade_score, grade_label_id FROM collection_entries WHERE id=?");
    $st->execute([$entryId]);
    $r = $st->fetch() ?: [];
    $labels = gradingConfig()['labels'];
    $cond = '';
    if (($r['grade_method'] ?? null) === 'points' && $r['grade_score'] !== null) {
        $name = ''; $best = -1;   // the label with the highest minimum the score reaches
        foreach ($labels as $l) if ((int)$r['grade_score'] >= (int)$l['min_score'] && (int)$l['min_score'] > $best) { $name = $l['name']; $best = (int)$l['min_score']; }
        $cond = trim($name.' · '.(int)$r['grade_score'], ' ·');
    } elseif (!empty($r['grade_label_id'])) {
        foreach ($labels as $l) if ((int)$l['id'] === (int)$r['grade_label_id']) $cond = $l['name'];
    }
    $st = db()->prepare("SELECT COALESCE(SUM(d.count), 0) FROM entry_defects d JOIN entry_part_units u ON u.id=d.unit_id
                         JOIN entry_parts ep ON ep.id=u.entry_part_id WHERE ep.entry_id=?");
    $st->execute([$entryId]);
    $defects = (int)$st->fetchColumn();
    $st = db()->prepare("SELECT t.photo_id, t.part_ref, t.unit_no, GROUP_CONCAT(td.defect_id ORDER BY td.defect_id) AS d
                         FROM copy_photo_tags t LEFT JOIN copy_photo_tag_defects td ON td.tag_id=t.id
                         WHERE t.entry_id=? GROUP BY t.id ORDER BY t.photo_id, t.id");
    $st->execute([$entryId]);
    $tags = $st->fetchAll();
    return ['condition' => $cond, 'defects' => $defects, 'tags' => count($tags), 'tags_sig' => md5(json_encode($tags))];
}

/** Changes in grading and photo tags between two activityCopyState() results. */
function activityStateDiff(?array $a, array $b): array {
    $a ??= ['condition' => '', 'defects' => 0, 'tags' => 0, 'tags_sig' => md5('[]')];
    $out = [];
    if ($a['condition'] !== $b['condition']) $out[] = activityChange('condition', $a['condition'] ?: null, $b['condition'] ?: '__cleared');
    if ($a['defects'] !== $b['defects'])     $out[] = activityChange('defects', (string)$a['defects'], (string)$b['defects']);
    if ($a['tags_sig'] !== $b['tags_sig'])   $out[] = activityChange('photo_tags', (string)$a['tags'], $a['tags'] === $b['tags'] ? '__changed' : (string)$b['tags']);
    return $out;
}

/**
 * A lock was just set after failed attempts ($keys: login_attempts keys). One Warning event with
 * the number of failures and the lock time; the username only when it belongs to an account.
 */
function activityLogLockout(string $action, array $keys, int $seconds, ?array $user): void {
    try {
        $in = implode(',', array_fill(0, count($keys), '?'));
        $st = db()->prepare("SELECT MAX(fail_count) FROM login_attempts WHERE attempt_key IN ($in)");
        $st->execute($keys);
        $n = (int)$st->fetchColumn();
    } catch (Throwable) { $n = 0; }
    $minutes = (int)ceil($seconds / 60);
    logEvent('accounts', $action, [
        'actor' => $user ?: false, 'warn' => true, 'security' => true,
        'params' => ['n' => $n, 'minutes' => $minutes],
        'changes' => [activityChange('username_tried', null, $user ? (string)$user['username'] : '__unknown'),
                      activityChange('lockout', null, (string)$minutes, 'minutes')],
    ]);
}

/** IPv4 84.2.7.9 → 84.2.•.• · IPv6: the first two groups, then :•:• · anything else → ''. */
function activityMaskIp(string $ip): string {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        return $p[0].'.'.$p[1].'.•.•';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $p = explode(':', $ip);
        return ($p[0] ?: '0').':'.($p[1] ?? '0').':•:•';
    }
    return '';
}

/** An invite code as it may be shown: the first 4 characters, e.g. "a1b2••••". */
function activityMaskCode(string $code): string {
    return mb_substr($code, 0, 4).'••••';
}

/** {title, system, system_id} of a game, for the summary of copy events. */
function activityGame(int $gameId): array {
    try {
        $st = db()->prepare("SELECT g.title, s.short_name, s.id AS sid FROM games g JOIN systems s ON s.id=g.system_id WHERE g.id=?");
        $st->execute([$gameId]);
        $r = $st->fetch();
    } catch (Throwable) { $r = false; }
    return $r ? ['title' => $r['title'], 'system' => $r['short_name'], 'system_id' => (int)$r['sid']] : ['title' => '#'.$gameId, 'system' => ''];
}

/** {title, system, sid, copy} of a copy, for the summary of copy events. */
function activityCopyParams(int $entryId): array {
    try {
        $st = db()->prepare("SELECT ce.copy_number, g.title, s.short_name, s.id AS sid FROM collection_entries ce
                             JOIN games g ON g.id=ce.game_id JOIN systems s ON s.id=g.system_id WHERE ce.id=?");
        $st->execute([$entryId]);
        if ($r = $st->fetch()) return ['title' => $r['title'], 'system' => $r['short_name'], 'sid' => (int)$r['sid'], 'copy' => (int)$r['copy_number']];
    } catch (Throwable) {}
    return ['title' => '#'.$entryId, 'system' => '', 'copy' => 0];
}

// ── Clean-up ─────────────────────────────

/**
 * Once a day (the first signed-in request of the day): removes entries older than the retention.
 * The day is claimed with one UPDATE, so two requests at once don't both run it.
 */
function activityMaybePurge(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $today = date('Y-m-d');
        if (setting('log_last_purge') === $today) return;
        $st = db()->prepare("INSERT INTO app_settings (name, value) VALUES ('log_last_purge', ?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
        $st->execute([$today]);
        if ($st->rowCount() === 0) return;   // another request claimed today already
        $days = (int)setting('log_retention_days');
        if ($days > 0) activityPurgeOlder($days);
    } catch (Throwable $e) {
        error_log('game-collection: activity log clean-up: '.$e->getMessage());
    }
}

/** Deletes entries older than $days, in chunks. Returns how many went. */
function activityPurgeOlder(int $days, int $maxLoops = 10): int {
    $n = 0;
    $st = db()->prepare("DELETE FROM activity_log WHERE created_at < NOW() - INTERVAL ? DAY LIMIT 5000");
    for ($i = 0; $i < $maxLoops; $i++) {
        $st->execute([$days]);
        $n += $st->rowCount();
        if ($st->rowCount() < 5000) break;
    }
    return $n;
}

// ── Reading ──────────────────────────────

/**
 * WHERE clause for the admin log. $f: period (7|30|90|all), user (id), types (list), admin (bool), q, before (id),
 * viewer (the reader's id: their own rows are searched in full).
 * $skip leaves filters out (for the counts per type and the admin count).
 * Returns [sql, args].
 */
function activityWhere(array $f, array $skip = []): array {
    $w = ['1=1']; $a = [];
    $days = LOG_PERIODS[(string)($f['period'] ?? '30')] ?? 30;
    if ($days) { $w[] = 'created_at >= NOW() - INTERVAL ? DAY'; $a[] = $days; }
    if (!empty($f['user'])) { $w[] = 'user_id = ?'; $a[] = (int)$f['user']; }
    if (!in_array('types', $skip, true) && isset($f['types'])) {
        $types = array_values(array_intersect(LOG_CATEGORIES, (array)$f['types']));
        if (!$types) $w[] = '0=1';
        else { $w[] = 'category IN ('.implode(',', array_fill(0, count($types), '?')).')'; array_push($a, ...$types); }
    }
    if (!in_array('admin', $skip, true) && !empty($f['admin'])) $w[] = 'is_admin = 1';
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%'.addcslashes($q, '%_\\').'%';
        // Other users' change values (prices, notes) aren't searchable: only titles / names (p) and the note (n)
        $or = ['username LIKE ?', 'target_username LIKE ?', "JSON_EXTRACT(data, '$.p') LIKE ?", "JSON_EXTRACT(data, '$.n') LIKE ?",
               '(user_id = ? AND data LIKE ?)'];
        array_push($a, $like, $like, $like, $like, (int)($f['viewer'] ?? 0), $like);
        // The summary sentence isn't stored: match the actions whose text (in this language) contains the words
        $acts = [];
        foreach (array_keys(LOG_ACTIONS) as $act) {
            $key = 'log.a.'.str_replace('.', '_', $act);
            foreach (langDict() as $k => $v) {
                if (($k === $key || str_starts_with($k, $key.'_')) && mb_stripos(preg_replace('/\{\w+\}/', '', $v), $q) !== false) { $acts[] = $act; break; }
            }
        }
        if ($acts) { $or[] = 'action IN ('.implode(',', array_fill(0, count($acts), '?')).')'; array_push($a, ...$acts); }
        $w[] = '('.implode(' OR ', $or).')';
    }
    if (!empty($f['before'])) { $w[] = 'id < ?'; $a[] = (int)$f['before']; }
    return [implode(' AND ', $w), $a];
}

/** Raw rows (newest first) with the timestamp as a number. */
function activityFetch(string $where, array $args, int $limit): array {
    $st = db()->prepare("SELECT *, UNIX_TIMESTAMP(created_at) AS ts FROM activity_log WHERE $where ORDER BY id DESC LIMIT ".max(1, min(50000, $limit)));
    $st->execute($args);
    return $st->fetchAll();
}

/** A value of a change, ready to show. */
function activityValue(?string $v, string $type): string {
    if ($v === null || $v === '') return '—';
    $special = ['__removed' => 'log.removed', '__cleared' => 'log.cleared', '__changed' => 'log.changed',
                '__hidden' => 'log.pw_hidden', '__set' => 'log.set', '__unknown' => 'log.unknown_user', '__added' => 'log.added'];
    if (isset($special[$v])) return tRaw($special[$v]);
    return match ($type) {
        'money' => is_numeric($v) ? money((float)$v) : $v,
        'bool'  => tRaw($v === '1' ? 'log.yes' : 'log.no'),
        'enum'  => isset(langDict()['log.v.'.$v]) ? tRaw('log.v.'.$v) : $v,
        'minutes' => tRaw('common.n_minutes', ['n' => fmtNum((int)$v)]),
        'parts' => implode(', ', array_map(fn($p) => tRaw('sbackup.part_'.$p), siteBackupParts($v))),
        'date'  => is_numeric($v) ? fmtDate((int)$v, true) : $v,
        default => $v,
    };
}

/** The sentence for a row, in the reader's language. */
function activitySummary(array $row, array $data): string {
    $p = $data['p'] ?? [];
    $key = 'log.a.'.str_replace('.', '_', (string)$row['action']);
    if (isset($p['v']) && isset(langDict()[$key.'_'.$p['v']])) $key .= '_'.$p['v'];
    if (isset($p['n']) && isset(langDict()[$key.'_one'])) $key .= (int)$p['n'] === 1 ? '_one' : '_other';   // plurals
    $vars = [];
    foreach ($p as $k => $v) if (is_scalar($v)) $vars[$k] = is_int($v) || is_float($v) ? fmtNum($v) : (string)$v;
    $vars['target'] = (string)($row['target_username'] ?? '');
    if (isset($p['status'])) $vars['status'] = activityValue((string)$p['status'], 'enum');
    if (isset($p['mode']))   $vars['mode']   = activityValue((string)$p['mode'], 'enum');
    return isset(langDict()[$key]) ? tRaw($key, $vars) : (string)$row['action'];
}

/**
 * Rows as the log page / drawer / CSV show them. $viewer = the reading user: values of private fields
 * (LOG_PRIVATE) and "Open copy" only show for that user's own rows. Links only for $admin readers
 * (the drawer doesn't need them).
 */
function activityRowsOut(array $rows, array $viewer, bool $admin): array {
    $me = (int)$viewer['id'];
    // Which copies still exist (one query), for the "Open copy" button
    $copyIds = [];
    foreach ($rows as $r) if ($r['subject_type'] === 'copy' && (int)$r['user_id'] === $me) $copyIds[] = (int)$r['subject_id'];
    $alive = [];
    if ($copyIds) {
        $in = implode(',', array_map('intval', array_unique($copyIds)));
        $st = db()->prepare("SELECT id FROM collection_entries WHERE user_id=? AND id IN ($in)");
        $st->execute([$me]);
        $alive = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }
    $today = date('Y-m-d'); $yesterday = date('Y-m-d', time() - 86400);
    $out = [];
    foreach ($rows as $r) {
        $data  = json_decode((string)$r['data'], true) ?: [];
        $own   = (int)$r['user_id'] === $me;
        $ts    = (int)$r['ts'];
        $day   = date('Y-m-d', $ts);
        $changes = [];
        foreach ((array)($data['c'] ?? []) as $c) {
            $k = (string)($c['k'] ?? '');
            $type = (string)($c['ty'] ?? 'text');
            $label = isset(langDict()['log.f.'.$k]) ? tRaw('log.f.'.$k) : $k;
            $from = $c['from'] ?? null; $to = $c['to'] ?? null;
            if (in_array($k, LOG_PRIVATE, true) && !$own) {
                // Someone else's prices and notes: only that something changed, and that the value is hidden
                $changes[] = ['label' => $label, 'from' => null, 'hidden' => true,
                              'to' => tRaw($to === '__removed' ? 'log.hidden_removed' : 'log.hidden_changed')];
                continue;
            }
            $changes[] = ['label' => $label, 'from' => $from === null ? null : activityValue($from, $type), 'to' => activityValue($to, $type)];
        }
        if (!empty($data['x'])) $changes[] = ['label' => '', 'from' => null, 'to' => tRaw('log.more_changes', ['n' => fmtNum($data['x'])])];
        $note = (string)($data['n'] ?? '');
        if (!empty($data['p']['titles']) && is_array($data['p']['titles'])) {
            $t = $data['p']['titles'];
            $note = implode(', ', array_slice($t, 0, 5)).(count($t) > 5 ? ' '.tRaw('log.and_more', ['n' => fmtNum(count($t) - 5)]) : '');
        }
        $auto = !empty($data['p']['auto']);
        $out[] = [
            'id'       => (int)$r['id'],
            'ts'       => $ts,
            'time'     => date('H:i', $ts),
            'day'      => $day,
            'day_label'=> $day === $today ? tRaw('log.day_today') : ($day === $yesterday ? tRaw('log.day_yesterday') : fmtDate($ts)),
            'full'     => fmtDate($ts, true).date(':s', $ts),
            'user'     => $auto ? tRaw('log.automatic') : ((string)$r['username'] !== '' ? (string)$r['username'] : '—'),
            'own'      => $own,
            'category' => $r['category'],
            'admin'    => (bool)$r['is_admin'],
            'warn'     => (bool)$r['is_warning'],
            'summary'  => activitySummary($r, $data),
            'changes'  => $changes,
            'note'     => $note,
            'target'   => $r['target_username'] !== null ? (string)$r['target_username'] : '',
            'ip'       => (string)($r['ip'] ?? ''),
            'link'     => $admin ? activityLink($r, $data, $own, isset($alive[(int)$r['subject_id']])) : null,
        ];
    }
    return $out;
}

/** The "Open …" button of a row: ['url', 'label'], or null when there's nothing (left) to open. */
function activityLink(array $r, array $data, bool $own, bool $copyAlive): ?array {
    $s = fn(string $slug, string $label) => ['url' => BASE_URL.'/settings.php?s='.$slug, 'label' => tRaw('log.open_'.$label)];
    $a = (string)$r['action'];
    if ($r['subject_type'] === 'copy') {
        return $own && $copyAlive ? ['url' => BASE_URL.'/collection.php?entry='.(int)$r['subject_id'], 'label' => tRaw('log.open_copy')] : null;
    }
    if ($a === 'games.add_many') {
        return $own && !empty($data['p']['sid']) ? ['url' => BASE_URL.'/collection.php?s='.(int)$data['p']['sid'], 'label' => tRaw('log.open_collection')] : null;
    }
    return match (true) {
        in_array($a, ['wishlist.public', 'wishlist.token'], true) => $own ? $s('sharing', 'sharing') : null,
        $a === 'labeltpl.share'                     => $own ? $s('labels', 'labels') : null,
        in_array($a, ['data.import', 'backup.generate', 'backup.delete'], true) => $own ? $s('backup', 'backup') : null,
        str_starts_with($a, 'barcode.')             => $s('barcodes', 'barcodes'),
        $r['category'] === 'catalogue'              => $s('catalogue', 'catalogue'),
        in_array($a, ['login.lockout', 'password.lockout', 'user.unlock', 'user.unlock_all'], true) => $s('users', 'lockouts'),
        str_starts_with($a, 'user.') || str_starts_with($a, 'invite.') => $s('users', 'users'),
        $a === 'settings.save'                      => $s('general', 'settings'),
        in_array($a, ['theme.default', 'language.upload', 'language.default'], true) => $s('site-appearance', 'appearance'),
        str_starts_with($a, 'grading.')             => $s('grading-system', 'grading'),
        str_starts_with($a, 'labelsize.')           => $s('label-sizes', 'labelsizes'),
        str_starts_with($a, 'update.') || $a === 'migration.run' => $s('updates', 'updates'),
        str_starts_with($a, 'sitebackup.')          => $s('site-backup', 'sitebackup'),
        default                                     => null,
    };
}

/** Log filters from a request (GET): see activityWhere(). Types come as a comma list; missing = all. */
function activityFiltersFromRequest(array $in): array {
    $f = [
        'period' => isset(LOG_PERIODS[(string)($in['period'] ?? '')]) ? (string)$in['period'] : '30',
        'user'   => (int)($in['user'] ?? 0),
        'admin'  => !empty($in['admin']),
        'q'      => mb_substr(trim((string)($in['q'] ?? '')), 0, 100),
        'before' => (int)($in['before'] ?? 0),
        'limit'  => max(1, min(100, (int)($in['limit'] ?? 50))),
    ];
    if (isset($in['types'])) $f['types'] = array_values(array_filter(explode(',', (string)$in['types'])));
    return $f;
}

/** One page of the admin log with its counts: {rows, total, counts, admin_count, has_more}. */
function activityPage(array $f, array $viewer): array {
    $f['viewer'] = (int)$viewer['id'];
    $noPage = array_diff_key($f, ['before' => 1]);
    [$w, $a] = activityWhere($f);
    $rows = activityFetch($w, $a, $f['limit'] + 1);
    $more = count($rows) > $f['limit'];
    $rows = array_slice($rows, 0, $f['limit']);

    $count = function (array $skip, string $select, string $group = '') use ($noPage) {
        [$w, $a] = activityWhere($noPage, $skip);
        $st = db()->prepare("SELECT $select FROM activity_log WHERE $w $group");
        $st->execute($a);
        return $st;
    };
    $counts = array_fill_keys(LOG_CATEGORIES, 0);
    foreach ($count(['types'], 'category, COUNT(*) AS n', 'GROUP BY category')->fetchAll() as $r) $counts[$r['category']] = (int)$r['n'];
    return [
        'rows'        => activityRowsOut($rows, $viewer, true),
        'total'       => (int)$count([], 'COUNT(*)')->fetchColumn(),
        'counts'      => $counts,                                                   // follow every filter but the types
        'admin_count' => (int)$count(['admin'], 'COALESCE(SUM(is_admin), 0)')->fetchColumn(),   // every filter but "admin only"
        'has_more'    => $more,
    ];
}
