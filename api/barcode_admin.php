<?php
// Settings › Barcodes (admins only): POST JSON {action, …}. Removing rows goes through api/barcode.php "remove".
//   list    {tab: all|flagged|unknown|shared|missing, q, offset, per_page (max 100), system_id} → rows, total and the counts
//   update  {id, variant, note, game_id} → edits one row (a new game_id moves it)
//   keep    {ids} → clears the removal flag of these rows
//   dismiss {barcode} → hides an unknown scan (it is forgotten after BARCODE_DISMISSED_DAYS days)
//   import  {csv, dry_run} → see barcodeImport() below
// CSRF is checked for every /api/* POST in core.php.
require_once __DIR__ . '/../boot.php';
$user = requireAuth();
// The session isn't needed after sign-in: release its lock, so list searches don't queue behind each other
session_write_close();
barcodeApiErrors();
if (($user['role'] ?? '') !== 'admin') jsonOut(['ok' => false, 'error' => tRaw('bc.err_forbidden')], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok' => false, 'error' => tRaw('common.err_unknown_action')], 405);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string)($body['action'] ?? '');
$logA   = fn(string $act, array $o) => logEvent('catalogue', $act, ['actor' => $user, 'admin' => true] + $o);

/** The numbers on the stat tiles and tabs. */
function barcodeAdminCounts(): array {
    $q = fn(string $sql) => (int)db()->query($sql)->fetchColumn();
    return [
        'barcodes'    => $q("SELECT COUNT(DISTINCT barcode) FROM game_barcodes"),
        'rows'        => $q("SELECT COUNT(*) FROM game_barcodes"),
        'games_total' => $q("SELECT COUNT(*) FROM games g JOIN systems s ON s.id = g.system_id AND s.active = 1 WHERE g.active = 1"),
        'games_with'  => $q("SELECT COUNT(DISTINCT b.game_id) FROM game_barcodes b JOIN games g ON g.id = b.game_id AND g.active = 1 JOIN systems s ON s.id = g.system_id AND s.active = 1"),
        'unknown'     => $q("SELECT COUNT(*) FROM barcode_unknown WHERE dismissed = 0"),
        'shared'      => $q("SELECT COUNT(*) FROM (SELECT barcode FROM game_barcodes GROUP BY barcode HAVING COUNT(DISTINCT game_id) > 1) x"),
        'flagged'     => $q("SELECT COUNT(*) FROM game_barcodes WHERE flagged_at IS NOT NULL"),
    ];
}

if ($action === 'list') {
    $tab    = in_array($body['tab'] ?? '', ['all', 'flagged', 'unknown', 'shared', 'missing'], true) ? $body['tab'] : 'all';
    $q      = trim((string)($body['q'] ?? ''));
    $per    = max(10, min(100, (int)($body['per_page'] ?? 50)));
    $offset = max(0, (int)($body['offset'] ?? 0));
    $sysId  = (int)($body['system_id'] ?? 0);
    $like   = '%' . addcslashes($q, '\\%_') . '%';
    $digits = preg_match('/^[\d\s-]+$/', $q) ? preg_replace('/\D+/', '', $q) : '';   // a search for digits looks in the codes
    $rows = []; $total = 0;

    if ($tab === 'all' || $tab === 'flagged') {
        $where = $tab === 'flagged' ? ['b.flagged_at IS NOT NULL'] : ['1=1'];
        $args  = [];
        if ($q !== '') {
            $where[] = '(g.title LIKE ? OR g.edition_label LIKE ?' . ($digits !== '' ? ' OR b.barcode LIKE ?' : '') . ')';
            array_push($args, $like, $like);
            if ($digits !== '') $args[] = '%' . $digits . '%';
        }
        if ($sysId) { $where[] = 'g.system_id = ?'; $args[] = $sysId; }
        $w = implode(' AND ', $where);
        $st = db()->prepare("SELECT COUNT(*) FROM game_barcodes b JOIN games g ON g.id = b.game_id WHERE $w");
        $st->execute($args);
        $total = (int)$st->fetchColumn();
        $st = db()->prepare("
            SELECT b.id, b.barcode, b.game_id, b.variant, b.note, b.source, b.added_username, UNIX_TIMESTAMP(b.created_at) AS created,
                   b.flagged_username, b.flag_reason, UNIX_TIMESTAMP(b.flagged_at) AS flagged,
                   g.title, g.edition_label, g.active, s.short_name AS `system`
            FROM game_barcodes b JOIN games g ON g.id = b.game_id JOIN systems s ON s.id = g.system_id
            WHERE $w
            ORDER BY " . ($tab === 'flagged' ? 'b.flagged_at DESC' : 'b.created_at DESC') . ", b.id DESC
            LIMIT $per OFFSET $offset
        ");
        $st->execute($args);
        foreach ($st->fetchAll() as $r) $rows[] = [
            'id' => (int)$r['id'], 'barcode' => $r['barcode'], 'display' => barcodeFormat($r['barcode']),
            'game_id' => (int)$r['game_id'], 'label' => barcodeGameLabel($r), 'system' => $r['system'], 'game_active' => (bool)$r['active'],
            'variant' => (string)$r['variant'], 'note' => (string)($r['note'] ?? ''), 'source' => $r['source'],
            'added_username' => (string)$r['added_username'], 'created' => fmtDate((int)$r['created']),
            'flagged' => $r['flagged'] ? fmtDate((int)$r['flagged']) : null, 'flagged_username' => (string)$r['flagged_username'],
            'flag_reason' => (string)($r['flag_reason'] ?? ''),
        ];
    } elseif ($tab === 'unknown') {
        $where = 'dismissed = 0' . ($digits !== '' ? ' AND barcode LIKE ?' : '');
        $args  = $digits !== '' ? ['%' . $digits . '%'] : [];
        $st = db()->prepare("SELECT COUNT(*) FROM barcode_unknown WHERE $where");
        $st->execute($args);
        $total = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT barcode, times, first_username, last_username, UNIX_TIMESTAMP(first_seen) AS first_seen, UNIX_TIMESTAMP(last_seen) AS last_seen
                             FROM barcode_unknown WHERE $where ORDER BY last_seen DESC LIMIT $per OFFSET $offset");
        $st->execute($args);
        foreach ($st->fetchAll() as $r) $rows[] = [
            'barcode' => $r['barcode'], 'display' => barcodeFormat($r['barcode']), 'format' => barcodeFormatName($r['barcode']),
            'times' => (int)$r['times'], 'first_username' => $r['first_username'], 'last_username' => $r['last_username'],
            'first_seen' => fmtDate((int)$r['first_seen']), 'last_seen' => fmtDate((int)$r['last_seen']),
        ];
    } elseif ($tab === 'shared') {
        $having = 'COUNT(DISTINCT b.game_id) > 1';
        $args = [];
        $where = '1=1';
        if ($digits !== '') { $where = 'b.barcode LIKE ?'; $args[] = '%' . $digits . '%'; }
        $st = db()->prepare("SELECT COUNT(*) FROM (SELECT b.barcode FROM game_barcodes b WHERE $where GROUP BY b.barcode HAVING $having) x");
        $st->execute($args);
        $total = (int)$st->fetchColumn();
        // Codes on several systems (probably mistakes) first
        $st = db()->prepare("SELECT b.barcode FROM game_barcodes b JOIN games g ON g.id = b.game_id WHERE $where GROUP BY b.barcode HAVING $having
                             ORDER BY COUNT(DISTINCT g.system_id) DESC, MAX(b.created_at) DESC
                             LIMIT $per OFFSET $offset");
        $st->execute($args);
        $codes = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($codes) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            $g = db()->prepare("SELECT b.id, b.barcode, b.game_id, b.variant, g.title, g.edition_label, g.system_id, s.short_name AS `system`
                                FROM game_barcodes b JOIN games g ON g.id = b.game_id JOIN systems s ON s.id = g.system_id
                                WHERE b.barcode IN ($in) ORDER BY s.short_name, g.edition_sort, g.title");
            $g->execute($codes);
            $by = [];
            foreach ($g->fetchAll() as $r) $by[$r['barcode']][] = $r;
            foreach ($codes as $c) {
                $games = $by[$c] ?? [];
                $rows[] = [
                    'barcode' => $c, 'display' => barcodeFormat($c),
                    'cross_system' => count(array_unique(array_column($games, 'system_id'))) > 1,
                    'games' => array_map(fn($r) => ['id' => (int)$r['id'], 'game_id' => (int)$r['game_id'], 'label' => barcodeGameLabel($r),
                                                    'system' => $r['system'], 'variant' => (string)$r['variant']], $games),
                ];
            }
        }
    } else {   // missing
        $where = ['g.active = 1', 'NOT EXISTS (SELECT 1 FROM game_barcodes b WHERE b.game_id = g.id)'];
        $args = [];
        if ($q !== '') { $where[] = '(g.title LIKE ? OR g.edition_label LIKE ?)'; array_push($args, $like, $like); }
        if ($sysId) { $where[] = 'g.system_id = ?'; $args[] = $sysId; }
        $w = implode(' AND ', $where);
        $st = db()->prepare("SELECT COUNT(*) FROM games g JOIN systems s ON s.id = g.system_id AND s.active = 1 WHERE $w");
        $st->execute($args);
        $total = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT g.id, g.title, g.edition_label, s.short_name AS `system` FROM games g JOIN systems s ON s.id = g.system_id AND s.active = 1
                             WHERE $w ORDER BY s.sort_order, s.id, g.sort_title LIMIT $per OFFSET $offset");
        $st->execute($args);
        foreach ($st->fetchAll() as $r) $rows[] = ['game_id' => (int)$r['id'], 'label' => barcodeGameLabel($r), 'system' => $r['system']];
    }
    jsonOut(['ok' => true, 'tab' => $tab, 'rows' => $rows, 'total' => $total, 'offset' => $offset, 'counts' => barcodeAdminCounts()]);
}

if ($action === 'update') {
    $id = (int)($body['id'] ?? 0);
    $st = db()->prepare("SELECT * FROM game_barcodes WHERE id = ?");
    $st->execute([$id]);
    $old = $st->fetch();
    if (!$old) jsonOut(['ok' => false, 'error' => tRaw('api.not_found')], 404);
    $variant = mb_substr(trim((string)($body['variant'] ?? '')), 0, 100);
    $note    = mb_substr(trim((string)($body['note'] ?? '')), 0, 200);
    $gameId  = (int)($body['game_id'] ?? 0) ?: (int)$old['game_id'];
    if ($gameId !== (int)$old['game_id']) {
        $g = db()->prepare("SELECT id FROM games WHERE id = ?");
        $g->execute([$gameId]);
        if (!$g->fetchColumn()) jsonOut(['ok' => false, 'error' => tRaw('api.game_not_found')], 404);
        $d = db()->prepare("SELECT id FROM game_barcodes WHERE barcode = ? AND game_id = ? AND id <> ?");
        $d->execute([$old['barcode'], $gameId, $id]);
        if ($d->fetchColumn()) jsonOut(['ok' => false, 'error' => tRaw('bc.err_already_linked')], 409);
    }
    db()->prepare("UPDATE game_barcodes SET variant = ?, note = ?, game_id = ? WHERE id = ?")
        ->execute([$variant, $note !== '' ? $note : null, $gameId, $id]);
    $changes = activityDiff(['variant' => $old['variant'], 'note' => $old['note']], ['variant' => $variant, 'note' => $note],
                            ['variant' => ['variant', 'text'], 'note' => ['note', 'text']]);
    if ($gameId !== (int)$old['game_id']) $changes[] = activityChange('game', barcodeLogGame((int)$old['game_id'])['title'], barcodeLogGame($gameId)['title']);
    if ($changes) {
        $g = barcodeLogGame($gameId);
        $logA('barcode.edit', ['subject' => ['game', $gameId], 'changes' => $changes,
            'params' => ['code' => barcodeFormat($old['barcode']), 'title' => $g['title'], 'system' => $g['system']]]);
    }
    jsonOut(['ok' => true]);
}

if ($action === 'keep') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($body['ids'] ?? [])))));
    if (!$ids) jsonOut(['ok' => true]);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id, barcode, game_id, flagged_username, flag_reason FROM game_barcodes WHERE id IN ($in) AND flagged_at IS NOT NULL");
    $st->execute($ids);
    $rows = $st->fetchAll();
    if ($rows) {
        db()->prepare("UPDATE game_barcodes SET flagged_by = NULL, flagged_username = '', flag_reason = NULL, flagged_at = NULL WHERE id IN ($in)")->execute($ids);
        $g = barcodeLogGame((int)$rows[0]['game_id']);
        $logA('barcode.keep', ['subject' => ['game', (int)$rows[0]['game_id']],
            'params'  => ['code' => barcodeFormat($rows[0]['barcode']), 'title' => $g['title'], 'system' => $g['system']],
            'changes' => [activityChange('flagged_by', $rows[0]['flagged_username'], '__cleared')]]);
    }
    jsonOut(['ok' => true]);
}

if ($action === 'dismiss') {
    $code = preg_replace('/\D+/', '', (string)($body['barcode'] ?? ''));
    db()->prepare("UPDATE barcode_unknown SET dismissed = 1 WHERE barcode = ?")->execute([$code]);
    jsonOut(['ok' => true]);
}

if ($action === 'import') {
    $csv = (string)($body['csv'] ?? '');
    if (trim($csv) === '') jsonOut(['ok' => false, 'error' => tRaw('bc.import_err_empty')], 400);
    $res = barcodeImport($csv, !empty($body['dry_run']), $user);
    if (isset($res['error'])) jsonOut(['ok' => false, 'error' => $res['error']], 400);
    if (empty($body['dry_run']) && $res['added']) {
        $logA('barcode.import', ['params' => ['n' => $res['added']], 'changes' => [
            activityChange('added', null, (string)$res['added']),
            activityChange('skipped', null, (string)$res['skipped']),
            activityChange('problems', null, (string)$res['problem_count']),
        ]]);
    }
    jsonOut(['ok' => true] + $res + ['counts' => barcodeAdminCounts()]);
}

jsonOut(['ok' => false, 'error' => tRaw('common.err_unknown_action')], 400);

/**
 * CSV import. Header row required, comma or semicolon. Columns: barcode, and game_id or (system = short name, title,
 * optional edition), plus optional variant and note. A title that matches several games without an edition, an unknown
 * system or game and a bad check digit are problems, never guesses. Rows that exist already are skipped.
 * Returns {added, skipped, problem_count, problems:[{line, code, reason}] (first 20), lines} or {error}.
 */
function barcodeImport(string $csv, bool $dryRun, array $user): array {
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $first = strtok($csv, "\n");
    $delim = substr_count((string)$first, ';') > substr_count((string)$first, ',') ? ';' : ',';
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    $head = fgetcsv($fh, 0, $delim, '"', '');
    $cols = array_flip(array_map(fn($h) => strtolower(trim((string)$h)), $head ?: []));
    if (!isset($cols['barcode']) || (!isset($cols['game_id']) && !(isset($cols['system']) && isset($cols['title'])))) {
        return ['error' => tRaw('bc.import_err_header')];
    }
    $systems = [];
    foreach (db()->query("SELECT id, short_name FROM systems")->fetchAll() as $s) $systems[mb_strtolower($s['short_name'])] = (int)$s['id'];
    $byId    = db()->prepare("SELECT id FROM games WHERE id = ?");
    $byTitle = db()->prepare("SELECT id, edition_label FROM games WHERE system_id = ? AND LOWER(title) = LOWER(?)");
    $exists  = db()->prepare("SELECT 1 FROM game_barcodes WHERE barcode = ? AND game_id = ?");
    $ins     = db()->prepare("INSERT IGNORE INTO game_barcodes (barcode, game_id, variant, note, added_by, added_username, source) VALUES (?,?,?,?,?,?,'import')");
    $get = fn(array $row, string $k) => isset($cols[$k]) ? trim((string)($row[$cols[$k]] ?? '')) : '';

    $added = 0; $skipped = 0; $problems = []; $problemCount = 0; $line = 1; $seen = [];
    $problem = function (string $reason, string $code) use (&$problems, &$problemCount, &$line) {
        $problemCount++;
        if (count($problems) < 20) $problems[] = ['line' => $line, 'code' => $code, 'reason' => tRaw('bc.import_p_' . $reason)];
    };
    while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $line++;
        if ($line > 20001) return ['error' => tRaw('bc.import_err_lines', ['n' => fmtNum(20000)])];
        if ($row === [null] || !array_filter($row, fn($v) => trim((string)$v) !== '')) continue;
        $raw  = $get($row, 'barcode');
        $code = barcodeNormalize($raw);
        if ($code === null) { $problem('check', $raw); continue; }
        $gid = 0;
        if ($get($row, 'game_id') !== '') {
            $byId->execute([(int)$get($row, 'game_id')]);
            $gid = (int)$byId->fetchColumn();
            if (!$gid) { $problem('no_game', $raw); continue; }
        } else {
            $sid = $systems[mb_strtolower($get($row, 'system'))] ?? 0;
            if (!$sid) { $problem('no_system', $raw); continue; }
            $byTitle->execute([$sid, $get($row, 'title')]);
            $found = $byTitle->fetchAll();
            $ed = mb_strtolower($get($row, 'edition'));
            if ($ed !== '') $found = array_values(array_filter($found, fn($g) => mb_strtolower((string)$g['edition_label']) === $ed));
            if (!$found)          { $problem('no_game', $raw); continue; }
            if (count($found) > 1) { $problem('ambiguous', $raw); continue; }
            $gid = (int)$found[0]['id'];
        }
        $key = $code . ':' . $gid;
        $exists->execute([$code, $gid]);
        if ($exists->fetchColumn() || isset($seen[$key])) { $skipped++; continue; }
        $seen[$key] = true;
        if (!$dryRun) {
            $note = mb_substr($get($row, 'note'), 0, 200);
            $ins->execute([$code, $gid, mb_substr($get($row, 'variant'), 0, 100), $note !== '' ? $note : null,
                           (int)$user['id'], mb_substr((string)$user['username'], 0, 50)]);
            barcodeForgetUnknown($code);
        }
        $added++;
    }
    fclose($fh);
    return ['added' => $added, 'skipped' => $skipped, 'problem_count' => $problemCount, 'problems' => $problems, 'lines' => $line - 1, 'dry_run' => $dryRun];
}
