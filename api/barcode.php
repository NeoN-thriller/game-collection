<?php
// Barcodes for every signed-in user (scan.php, the edit drawer, Settings › Barcodes): POST JSON {action, …}.
//   lookup        {code, source: camera|typed|scanner|search} → the games it is linked to, with the user's copies.
//                 A valid unknown code is recorded for the admins, except for source "search".
//   search_games  {q} → games to link a code to (all systems, title or edition)
//   game_barcodes {game_id} → the codes of a game and of the other editions in its group
//   link          {code, game_ids, variant, note, confirm_cross_system, ask_other} (needs barcodeCanEdit)
//                 ask_other: a code already on any other game answers 409 linked_elsewhere until confirmed
//   system_games  {system_id} → every active game of a system with its codes (Add barcodes page, needs barcodeCanEdit)
//   undo_own      {ids} → takes back codes this user added in the last BARCODE_UNDO_HOURS hours
//   flag          {ids, reason} → asks the admins to remove these rows (any user)
//   remove        {ids} (admins only)
//   show_system   {system_id} → shows a system the user had hidden again
// CSRF is checked for every /api/* POST in core.php.
require_once __DIR__ . '/../boot.php';
$user = requireAuth();
// The session isn't needed after sign-in: release its lock, so searches typed in quick succession don't queue behind each other
session_write_close();
barcodeApiErrors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok' => false, 'error' => tRaw('common.err_unknown_action')], 405);

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = (string)($body['action'] ?? '');
$isAdmin = ($user['role'] ?? '') === 'admin';
// Switched off: only admins (Settings › Barcodes) can still use these
if (!barcodeEnabled() && !$isAdmin) jsonOut(['ok' => false, 'error' => tRaw('bc.off_notice')], 403);
$logB    = fn(string $act, array $o) => logEvent($act === 'barcode.settings' ? 'system' : 'catalogue', $act, ['actor' => $user] + $o);

/** The row ids of $body['ids'] that exist: [id => row]. */
$rowsById = function (array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT b.*, g.title, g.edition_label, g.system_id FROM game_barcodes b JOIN games g ON g.id = b.game_id WHERE b.id IN ($in)");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['id']] = $r;
    return $out;
};
/** Edition labels (or titles) of rows, for the log. */
$labels = fn(array $rows) => implode(', ', array_unique(array_map(fn($r) => (string)($r['edition_label'] ?: $r['title']), $rows)));

if ($action === 'lookup') {
    $raw  = (string)($body['code'] ?? '');
    $code = barcodeNormalize($raw);
    if ($code === null) jsonOut(['ok' => false, 'invalid' => true, 'error' => tRaw('bc.err_invalid')], 400);
    $matches = barcodeLookup($code, $user);
    $source  = in_array($body['source'] ?? '', ['camera', 'typed', 'scanner', 'search'], true) ? $body['source'] : 'typed';
    if (!$matches && $source !== 'search') barcodeRecordUnknown($code, $user);
    jsonOut(['ok' => true, 'code' => $code, 'display' => barcodeFormat($code), 'format' => barcodeFormatName($code),
             'matches' => $matches, 'unknown' => !$matches, 'can_edit' => barcodeCanEdit($user)]);
}

if ($action === 'search_games') {
    $q = trim((string)($body['q'] ?? ''));
    if (mb_strlen($q) < 2) jsonOut(['ok' => true, 'games' => []]);
    $words = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [$q], 0, 6);
    $where = []; $args = [];
    foreach ($words as $w) {
        $like = '%' . addcslashes($w, '\\%_') . '%';
        $where[] = "(g.title LIKE ? OR g.edition_label LIKE ? OR gg.title LIKE ?)";
        array_push($args, $like, $like, $like);
    }
    $st = db()->prepare("
        SELECT g.id, g.title, g.edition_label, g.group_id, gg.title AS group_title, s.id AS system_id, s.short_name AS `system`, s.name AS system_name
        FROM games g
        JOIN systems s ON s.id = g.system_id AND s.active = 1
        LEFT JOIN game_groups gg ON gg.id = g.group_id
        WHERE g.active = 1 AND " . implode(' AND ', $where) . "
        ORDER BY (g.title LIKE ?) DESC, s.sort_order, s.id, g.sort_title
        LIMIT 20
    ");
    $args[] = addcslashes($q, '\\%_') . '%';   // titles that start with the search first
    $st->execute($args);
    $rows = $st->fetchAll();
    // The other editions of each group, so the link form can offer them
    $groupIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['group_id'], $rows))));
    $members = [];
    if ($groupIds) {
        $in = implode(',', array_fill(0, count($groupIds), '?'));
        $m = db()->prepare("SELECT id, group_id, title, edition_label FROM games WHERE active = 1 AND group_id IN ($in) ORDER BY edition_sort, id");
        $m->execute($groupIds);
        foreach ($m->fetchAll() as $r) $members[(int)$r['group_id']][] = ['game_id' => (int)$r['id'], 'label' => (string)($r['edition_label'] ?: $r['title'])];
    }
    // Barcode counts of the 20 results and their editions only (one query)
    $countIds = array_map(fn($r) => (int)$r['id'], $rows);
    foreach ($members as $ms) foreach ($ms as $m) $countIds[] = $m['game_id'];
    $counts = barcodeCounts(array_values(array_unique($countIds)));
    jsonOut(['ok' => true, 'games' => array_map(fn($r) => [
        'game_id'       => (int)$r['id'],
        'title'         => $r['group_title'] ?: $r['title'],
        'label'         => barcodeGameLabel($r),
        'edition_label' => (string)($r['edition_label'] ?? ''),
        'system_id'     => (int)$r['system_id'],
        'system'        => $r['system'],
        'system_name'   => $r['system_name'],
        'barcode_count' => $r['group_id'] !== null
            ? array_sum(array_map(fn($m) => $counts[$m['game_id']] ?? 0, $members[(int)$r['group_id']] ?? []))
            : ($counts[(int)$r['id']] ?? 0),
        'editions'      => $r['group_id'] !== null ? ($members[(int)$r['group_id']] ?? []) : [],
    ], $rows)]);
}

if ($action === 'game_barcodes') {
    $gid = (int)($body['game_id'] ?? 0);
    $st = db()->prepare("SELECT id, title, edition_label, group_id FROM games WHERE id = ?");
    $st->execute([$gid]);
    $g = $st->fetch();
    if (!$g) jsonOut(['ok' => false, 'error' => tRaw('api.game_not_found')], 404);
    $editions = [['game_id' => (int)$g['id'], 'label' => (string)($g['edition_label'] ?: $g['title'])]];
    if ($g['group_id'] !== null) {
        $m = db()->prepare("SELECT id, title, edition_label FROM games WHERE group_id = ? AND active = 1 ORDER BY edition_sort, id");
        $m->execute([$g['group_id']]);
        $editions = array_map(fn($r) => ['game_id' => (int)$r['id'], 'label' => (string)($r['edition_label'] ?: $r['title'])], $m->fetchAll());
    }
    jsonOut(['ok' => true, 'codes' => barcodeGroupCodes($gid), 'editions' => $editions,
             'can_edit' => barcodeCanEdit($user), 'can_remove' => $isAdmin]);
}

if ($action === 'link') {
    if (!barcodeCanEdit($user)) jsonOut(['ok' => false, 'error' => tRaw('bc.err_forbidden')], 403);
    $code = barcodeNormalize((string)($body['code'] ?? ''));
    if ($code === null) jsonOut(['ok' => false, 'invalid' => true, 'error' => tRaw('bc.err_invalid')], 400);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($body['game_ids'] ?? [])))));
    if (!$ids || count($ids) > 30) jsonOut(['ok' => false, 'error' => tRaw('bc.err_pick_game')], 400);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT g.id, g.title, g.edition_label, g.system_id, s.short_name FROM games g JOIN systems s ON s.id = g.system_id WHERE g.id IN ($in)");
    $st->execute($ids);
    $games = $st->fetchAll();
    if (count($games) !== count($ids)) jsonOut(['ok' => false, 'error' => tRaw('api.game_not_found')], 404);
    $variant = mb_substr(trim((string)($body['variant'] ?? '')), 0, 100);
    $note    = mb_substr(trim((string)($body['note'] ?? '')), 0, 200);

    // Add barcodes page (ask_other): any other game with this code is asked about first, same system or not
    if (!empty($body['ask_other']) && empty($body['confirm_cross_system'])) {
        $ex = db()->prepare("SELECT g.id, g.title, g.edition_label, s.short_name FROM game_barcodes b JOIN games g ON g.id = b.game_id
                             JOIN systems s ON s.id = g.system_id WHERE b.barcode = ? AND b.game_id NOT IN ($in)");
        $ex->execute(array_merge([$code], $ids));
        if ($other = $ex->fetchAll()) {
            jsonOut(['ok' => false, 'conflict' => 'linked_elsewhere', 'games' => array_map(fn($g) => [
                'game_id' => (int)$g['id'], 'label' => barcodeGameLabel($g), 'system' => $g['short_name']], $other)], 409);
        }
    }

    // A code on games of different systems is almost always a mistake: ask first
    $systems = array_unique(array_map(fn($g) => (int)$g['system_id'], $games));
    if (empty($body['confirm_cross_system'])) {
        $ex = db()->prepare("SELECT g.id, g.title, g.edition_label, g.system_id, s.short_name FROM game_barcodes b JOIN games g ON g.id = b.game_id
                             JOIN systems s ON s.id = g.system_id WHERE b.barcode = ?");
        $ex->execute([$code]);
        $other = array_values(array_filter($ex->fetchAll(), fn($g) => !in_array((int)$g['system_id'], $systems, true)));
        if ($other || count($systems) > 1) {
            jsonOut(['ok' => false, 'conflict' => 'cross_system', 'games' => array_map(fn($g) => [
                'game_id' => (int)$g['id'], 'label' => barcodeGameLabel($g), 'system' => $g['short_name']], $other ?: $games)], 409);
        }
    }

    $ins = db()->prepare("INSERT IGNORE INTO game_barcodes (barcode, game_id, variant, note, added_by, added_username) VALUES (?,?,?,?,?,?)");
    $added = []; $existing = []; $newRows = [];
    foreach ($games as $g) {
        $ins->execute([$code, (int)$g['id'], $variant, $note !== '' ? $note : null, (int)$user['id'], mb_substr((string)$user['username'], 0, 50)]);
        if ($ins->rowCount()) { $added[] = $g; $newRows[] = ['id' => (int)db()->lastInsertId(), 'game_id' => (int)$g['id']]; }
        else $existing[] = (int)$g['id'];
    }
    barcodeForgetUnknown($code);
    if ($added) {
        $first = $added[0];
        $logB('barcode.add', [
            'admin'   => $isAdmin && !empty($body['from_admin']),
            'subject' => ['game', (int)$first['id']],
            'params'  => ['code' => barcodeFormat($code), 'title' => barcodeGameLabel($first), 'system' => $first['short_name']],
            'changes' => [
                activityChange('barcode', null, barcodeFormat($code)),
                count($added) > 1 ? activityChange('editions', null, $labels($added)) : null,
                $variant !== '' ? activityChange('variant', null, $variant) : null,
                $note !== '' ? activityChange('note', null, $note) : null,
            ],
        ]);
    }
    jsonOut(['ok' => true, 'code' => $code, 'display' => barcodeFormat($code),
             'added' => array_map(fn($g) => (int)$g['id'], $added), 'existing' => $existing, 'rows' => $newRows]);
}

if ($action === 'system_games') {
    // Add barcodes page: every active game of a system with its codes ([{id, game_id, barcode, display, mine}])
    if (!barcodeCanEdit($user)) jsonOut(['ok' => false, 'error' => tRaw('bc.err_forbidden')], 403);
    $sid = (int)($body['system_id'] ?? 0);
    $st = db()->prepare("SELECT g.id, g.title, g.edition_label, g.sort_order, g.price_link FROM games g WHERE g.system_id = ? AND g.active = 1 ORDER BY g.sort_title, g.edition_sort, g.id");
    $st->execute([$sid]);
    $games = $st->fetchAll();
    $codes = [];
    $bq = db()->prepare("SELECT b.id, b.game_id, b.barcode, b.added_by, b.created_at > NOW() - INTERVAL " . BARCODE_UNDO_HOURS . " HOUR AS recent
                         FROM game_barcodes b JOIN games g ON g.id = b.game_id WHERE g.system_id = ? ORDER BY b.created_at, b.id");
    $bq->execute([$sid]);
    foreach ($bq->fetchAll() as $r) $codes[(int)$r['game_id']][] = [
        'id' => (int)$r['id'], 'barcode' => $r['barcode'], 'display' => barcodeFormat($r['barcode']),
        'mine' => (int)$r['added_by'] === (int)$user['id'] && (int)$r['recent'] === 1,   // may still be taken back (undo_own)
    ];
    jsonOut(['ok' => true, 'games' => array_map(fn($g) => [
        'id' => (int)$g['id'], 'label' => barcodeGameLabel($g), 'title' => $g['title'], 'num' => (int)$g['sort_order'],
        'price_link' => priceLinkSafe($g['price_link']), 'codes' => $codes[(int)$g['id']] ?? [],
    ], $games)]);
}

if ($action === 'undo_own') {
    // Take back codes this user added in the last BARCODE_UNDO_HOURS hours (a wrong scan); older ones: report / admins remove
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($body['ids'] ?? [])))));
    $rows = [];
    if ($ids) {
        // The age is checked by the database (its clock wrote created_at)
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT id, barcode, game_id FROM game_barcodes WHERE id IN ($in) AND added_by = ?
                             AND created_at > NOW() - INTERVAL " . BARCODE_UNDO_HOURS . " HOUR");
        $st->execute(array_merge($ids, [(int)$user['id']]));
        foreach ($st->fetchAll() as $r) $rows[(int)$r['id']] = $r;
    }
    if (!$rows) jsonOut(['ok' => false, 'error' => tRaw('bc.undo_too_old')], 403);
    $in = implode(',', array_fill(0, count($rows), '?'));
    db()->prepare("DELETE FROM game_barcodes WHERE id IN ($in)")->execute(array_keys($rows));
    foreach ($rows as $r) {
        $g = barcodeLogGame((int)$r['game_id']);
        $logB('barcode.remove', [
            'subject' => ['game', (int)$r['game_id']],
            'params'  => ['code' => barcodeFormat($r['barcode']), 'title' => $g['title'], 'system' => $g['system']],
            'changes' => [activityChange('barcode', barcodeFormat($r['barcode']), '__removed')],
        ]);
    }
    jsonOut(['ok' => true, 'removed' => count($rows)]);
}

if ($action === 'flag') {
    $rows = $rowsById((array)($body['ids'] ?? []));
    $rows = array_filter($rows, fn($r) => $r['flagged_at'] === null);
    if (!$rows) jsonOut(['ok' => true, 'flagged' => 0]);
    $reason = mb_substr(trim((string)($body['reason'] ?? '')), 0, 200);
    $in = implode(',', array_fill(0, count($rows), '?'));
    db()->prepare("UPDATE game_barcodes SET flagged_by = ?, flagged_username = ?, flag_reason = ?, flagged_at = NOW() WHERE id IN ($in) AND flagged_at IS NULL")
        ->execute(array_merge([(int)$user['id'], mb_substr((string)$user['username'], 0, 50), $reason !== '' ? $reason : null], array_keys($rows)));
    $first = reset($rows);
    $g = barcodeLogGame((int)$first['game_id']);
    $logB('barcode.flag', [
        'subject' => ['game', (int)$first['game_id']],
        'params'  => ['code' => barcodeFormat($first['barcode']), 'title' => $g['title'], 'system' => $g['system']],
        'changes' => [count($rows) > 1 ? activityChange('editions', null, $labels($rows)) : null,
                      $reason !== '' ? activityChange('reason', null, $reason) : null],
    ]);
    jsonOut(['ok' => true, 'flagged' => count($rows)]);
}

if ($action === 'remove') {
    if (!$isAdmin) jsonOut(['ok' => false, 'error' => tRaw('bc.err_forbidden')], 403);
    $rows = $rowsById((array)($body['ids'] ?? []));
    if (!$rows) jsonOut(['ok' => true, 'removed' => 0]);
    $in = implode(',', array_fill(0, count($rows), '?'));
    db()->prepare("DELETE FROM game_barcodes WHERE id IN ($in)")->execute(array_keys($rows));
    // One event per code
    $byCode = [];
    foreach ($rows as $r) $byCode[$r['barcode']][] = $r;
    foreach ($byCode as $c => $rs) {
        $g = barcodeLogGame((int)$rs[0]['game_id']);
        $logB('barcode.remove', [
            'admin'   => true,
            'subject' => ['game', (int)$rs[0]['game_id']],
            'params'  => ['code' => barcodeFormat((string)$c), 'title' => $g['title'], 'system' => $g['system']],
            'changes' => [activityChange('barcode', barcodeFormat((string)$c), '__removed'),
                          count($rs) > 1 ? activityChange('editions', $labels($rs), '__removed') : null],
        ]);
    }
    jsonOut(['ok' => true, 'removed' => count($rows)]);
}

if ($action === 'show_system') {
    $sid = (int)($body['system_id'] ?? 0);
    $st = db()->prepare("SELECT id FROM systems WHERE id = ? AND active = 1");
    $st->execute([$sid]);
    if (!$st->fetchColumn()) jsonOut(['ok' => false, 'error' => tRaw('api.not_found')], 404);
    db()->prepare("UPDATE user_system_prefs SET visible = 1 WHERE user_id = ? AND system_id = ?")->execute([(int)$user['id'], $sid]);
    jsonOut(['ok' => true]);
}

jsonOut(['ok' => false, 'error' => tRaw('common.err_unknown_action')], 400);
