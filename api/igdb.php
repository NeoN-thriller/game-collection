<?php
// Settings › IGDB (admins only): POST JSON {action, …}. See igdb.php.
//   systems                       → every system with its IGDB platform and match counts, plus whether IGDB is set up
//   platforms {q}                 → IGDB platforms found by name
//   set_platform {system_id, platform_id, platform_name} → 0 clears; open matches of that system are looked up again
//   reset_review {system_id}      → that system's open reviews back to not looked up (then Match)
//   match {system_id}             → looks up games never looked up, for about 6 s (the page repeats it) → {auto, review, left}
//   refresh {system_id, offset}   → fetches matched games again → {updated, next}
//   list {system_id, tab, q, offset} → tab review | matched | none | pending | all
//   search {system_id, q, any_platform} → IGDB games by name, igdb.com link or id
//   set {game_id, igdb_id}        → links a game (confirmed) · none {game_id} → not on IGDB · clear {game_id} → look up again
//   set_cover {game_id, source}   → one game's cover (the drawer): '' (site default) | uploaded | igdb → {cover_url}
//   (the site default and clearing every game's choice: Settings › General › Covers, settings/actions_admin.php)
// CSRF is checked for every /api/* POST in core.php.
require_once __DIR__ . '/../boot.php';
$user = requireAuth();
session_write_close();   // long lookups shouldn't block the admin's other tabs
if (($user['role'] ?? '') !== 'admin') jsonOut(['ok' => false, 'error' => tRaw('igdb.err_forbidden')], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok' => false, 'error' => tRaw('common.err_unknown_action')], 405);
@set_time_limit(120);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string)($body['action'] ?? '');
$sysId  = (int)($body['system_id'] ?? 0);
$logA   = fn(string $act, array $o) => logEvent('catalogue', $act, ['actor' => $user, 'admin' => true] + $o);
$needIgdb = fn() => igdbConfigured() ?: jsonOut(['ok' => false, 'error' => tRaw('igdb.err_not_configured')], 400);

try {
    if ($action === 'systems') {
        $counts = [];
        foreach (db()->query("SELECT g.system_id, COUNT(*) AS total,
                                     SUM(i.status IN ('auto', 'confirmed')) AS matched, SUM(i.status = 'review') AS review,
                                     SUM(i.status = 'none') AS `none`, SUM(i.game_id IS NULL) AS pending
                              FROM games g LEFT JOIN game_igdb i ON i.game_id = g.id GROUP BY g.system_id")->fetchAll() as $r)
            $counts[(int)$r['system_id']] = array_map('intval', array_diff_key($r, ['system_id' => 1]));
        $names = json_decode(setting('igdb_platform_names'), true) ?: [];
        $out = [];
        foreach (db()->query("SELECT id, name, short_name, region, igdb_platform_id FROM systems WHERE active = 1 ORDER BY sort_order, id")->fetchAll() as $s) {
            $pid = (int)$s['igdb_platform_id'];
            $out[] = ['id' => (int)$s['id'], 'name' => $s['name'], 'short_name' => $s['short_name'], 'region' => $s['region'],
                      'platform_id' => $pid ?: null, 'platform_name' => $pid ? ($names[$pid] ?? null) : null,
                      'counts' => $counts[(int)$s['id']] ?? ['total' => 0, 'matched' => 0, 'review' => 0, 'none' => 0, 'pending' => 0]];
        }
        jsonOut(['ok' => true, 'configured' => igdbConfigured(), 'systems' => $out]);
    }

    if ($action === 'platforms') {
        $needIgdb();
        $q = trim((string)($body['q'] ?? ''));
        if ($q === '') jsonOut(['ok' => true, 'platforms' => []]);
        $where = ctype_digit($q) ? 'where id = ' . (int)$q . ';' : 'search ' . igdbStr($q) . ';';
        $rows = igdbQuery('platforms', $where . ' fields id,name,abbreviation,alternative_name; limit 20;');
        jsonOut(['ok' => true, 'platforms' => array_map(fn($p) => ['id' => (int)$p['id'], 'name' => (string)$p['name'],
            'extra' => trim(implode(' · ', array_filter([(string)($p['abbreviation'] ?? ''), (string)($p['alternative_name'] ?? '')])))], $rows)]);
    }

    if ($action === 'set_platform') {
        $pid  = max(0, (int)($body['platform_id'] ?? 0));
        $st = db()->prepare("SELECT igdb_platform_id FROM systems WHERE id = ?");
        $st->execute([$sysId]);
        $old = $st->fetchColumn();
        if ($old === false) jsonOut(['ok' => false, 'error' => tRaw('api.system_not_found')], 404);
        if ((int)$old !== $pid) {
            db()->prepare("UPDATE systems SET igdb_platform_id = ? WHERE id = ?")->execute([$pid ?: null, $sysId]);
            // Automatic matches and open reviews were found on the old platform: look them up again. Admin choices stay.
            db()->prepare("DELETE i FROM game_igdb i JOIN games g ON g.id = i.game_id WHERE g.system_id = ? AND i.status IN ('auto', 'review')")
                ->execute([$sysId]);
            $names = json_decode(setting('igdb_platform_names'), true) ?: [];
            $pname = mb_substr(trim((string)($body['platform_name'] ?? '')), 0, 100);
            if ($pid && $pname !== '') { $names[$pid] = $pname; setSetting('igdb_platform_names', json_encode($names, JSON_UNESCAPED_UNICODE)); }
            $sn = db()->prepare("SELECT short_name FROM systems WHERE id = ?");
            $sn->execute([$sysId]);
            $logA('igdb.platform', ['params' => ['system' => (string)$sn->fetchColumn()],
                'changes' => [activityChange('igdb_platform', $old ? (string)$old : null, $pid ? ($pname !== '' ? "$pname ($pid)" : (string)$pid) : null)]]);
        }
        jsonOut(['ok' => true]);
    }

    if ($action === 'set_cover') {
        $gid = (int)($body['game_id'] ?? 0);
        $src = in_array($body['source'] ?? '', ['uploaded', 'igdb'], true) ? $body['source'] : null;
        $st = db()->prepare("UPDATE games SET cover_source = ? WHERE id = ?");
        $st->execute([$src, $gid]);
        $st = db()->prepare("SELECT id, default_image FROM games WHERE id = ?");
        $st->execute([$gid]);
        $g = $st->fetch();
        if (!$g) jsonOut(['ok' => false, 'error' => tRaw('admin.games.err_game')], 404);
        $logA('igdb.cover', ['subject' => ['game', $gid], 'params' => activityGame($gid) + ['v' => $src ?? 'default']]);
        jsonOut(['ok' => true] + array_intersect_key(withCovers([$g])[0], array_flip(['cover_url', 'cover_uploaded', 'cover_igdb', 'cover_source'])));
    }

    if ($action === 'reset_review') {   // "Look up these again": the open reviews of a system go back to not looked up
        $st = db()->prepare("DELETE i FROM game_igdb i JOIN games g ON g.id = i.game_id WHERE g.system_id = ? AND i.status = 'review'");
        $st->execute([$sysId]);
        jsonOut(['ok' => true, 'n' => $st->rowCount()]);
    }

    if ($action === 'match') {
        $needIgdb();
        if (!igdbSystem($sysId)) jsonOut(['ok' => false, 'error' => tRaw('igdb.err_no_platform')], 400);
        jsonOut(['ok' => true] + igdbMatchPending($sysId, 6.0));
    }

    if ($action === 'refresh') {
        $needIgdb();
        [$n, $next] = igdbRefresh($sysId, (int)($body['offset'] ?? 0));
        if ($next === null && !empty($body['log'])) {
            $sn = db()->prepare("SELECT short_name FROM systems WHERE id = ?");
            $sn->execute([$sysId]);
            $logA('igdb.refresh', ['params' => ['system' => (string)$sn->fetchColumn(), 'n' => (int)($body['total'] ?? 0) + $n]]);
        }
        jsonOut(['ok' => true, 'updated' => $n, 'next' => $next]);
    }

    if ($action === 'list') {
        $tab = in_array($body['tab'] ?? '', ['review', 'matched', 'none', 'pending', 'all'], true) ? $body['tab'] : 'review';
        $q   = trim((string)($body['q'] ?? ''));
        $per = 50;
        $off = max(0, (int)($body['offset'] ?? 0));
        $where = ['g.system_id = ?'];
        $args  = [$sysId];
        $where[] = match ($tab) {
            'review'  => "i.status = 'review'",
            'matched' => "i.status IN ('auto', 'confirmed')",
            'none'    => "i.status = 'none'",
            'pending' => 'i.game_id IS NULL',
            'all'     => '1 = 1',
        };
        if ($q !== '') {
            $where[] = '(g.title LIKE ? OR i.name LIKE ?)';
            $like = '%' . addcslashes($q, '\\%_') . '%';
            array_push($args, $like, $like);
        }
        $w = implode(' AND ', $where);
        $st = db()->prepare("SELECT COUNT(*) FROM games g LEFT JOIN game_igdb i ON i.game_id = g.id WHERE $w");
        $st->execute($args);
        $total = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT g.id, g.title, g.edition_label, g.active, i.status, i.igdb_id, i.slug, i.name, i.candidates,
                                    i.developers, i.release_date, i.release_prec
                             FROM games g LEFT JOIN game_igdb i ON i.game_id = g.id
                             WHERE $w ORDER BY g.sort_title, g.id LIMIT $per OFFSET $off");
        $st->execute($args);
        $rows = array_map(fn($r) => [
            'game_id' => (int)$r['id'], 'title' => $r['title'], 'edition' => (string)$r['edition_label'], 'active' => (bool)$r['active'],
            'status' => $r['status'], 'igdb_id' => $r['igdb_id'] ? (int)$r['igdb_id'] : null, 'name' => $r['name'],
            'url' => $r['slug'] ? 'https://www.igdb.com/games/' . rawurlencode($r['slug']) : null,
            'developers' => $r['developers'], 'year' => $r['release_date'] ? (int)substr($r['release_date'], 0, 4) : null,
            'candidates' => array_map(fn($c) => $c + ['url' => 'https://www.igdb.com/games/' . rawurlencode((string)($c['slug'] ?? ''))],
                                      json_decode((string)$r['candidates'], true) ?: []),
        ], $st->fetchAll());
        jsonOut(['ok' => true, 'rows' => $rows, 'total' => $total, 'per_page' => $per]);
    }

    if ($action === 'search') {
        $needIgdb();
        $q = trim((string)($body['q'] ?? ''));
        if ($q === '') jsonOut(['ok' => true, 'results' => []]);
        $sys = igdbSystem($sysId);
        if (preg_match('~igdb\.com/games/([a-z0-9-]+)~i', $q, $m)) $query = 'where slug = ' . igdbStr(strtolower($m[1])) . ';';
        elseif (ctype_digit($q))                                  $query = 'where id = ' . (int)$q . ';';
        else $query = 'search ' . igdbStr($q) . ';'
                    . ($sys && empty($body['any_platform']) ? ' where platforms = (' . (int)$sys['igdb_platform_id'] . ');' : '');
        $fields = 'name,slug,release_dates.date,platforms.abbreviation';
        $hits = igdbQuery('games', $query . ' fields ' . $fields . '; limit 20;');
        if (!str_starts_with($query, 'where')) {   // a name: also games known under it (alternative / regional titles)
            $known = array_column($hits, 'id');
            foreach (igdbByOtherName($q, $sys && empty($body['any_platform']) ? (int)$sys['igdb_platform_id'] : 0, $fields) as $a)
                if (!in_array($a['id'], $known)) array_unshift($hits, $a);
        }
        $plats = [];
        foreach ($hits as $h) $plats[(int)$h['id']] = implode(', ', array_filter(array_map(fn($p) => (string)($p['abbreviation'] ?? ''), $h['platforms'] ?? [])));
        jsonOut(['ok' => true, 'results' => array_map(fn($c) => $c + [
            'url' => 'https://www.igdb.com/games/' . rawurlencode($c['slug']), 'platforms' => $plats[$c['id']] ?? '',
        ], igdbCandidates($hits))]);
    }

    if (in_array($action, ['set', 'none', 'clear'], true)) {
        $gid = (int)($body['game_id'] ?? 0);
        $st = db()->prepare("SELECT g.id, i.name FROM games g LEFT JOIN game_igdb i ON i.game_id = g.id WHERE g.id = ?");
        $st->execute([$gid]);
        $g = $st->fetch();
        if (!$g) jsonOut(['ok' => false, 'error' => tRaw('admin.games.err_game')], 404);
        $oldName = $g['name'];
        if ($action === 'set') {
            $needIgdb();
            $row = igdbConfirm($gid, (int)($body['igdb_id'] ?? 0));
            $logA('igdb.link', ['subject' => ['game', $gid], 'params' => activityGame($gid) + ['v' => 'set', 'name' => $row['name']],
                'changes' => [activityChange('igdb_game', $oldName, $row['name'])]]);
        } elseif ($action === 'none') {
            igdbSave($gid, 'none');
            $logA('igdb.link', ['subject' => ['game', $gid], 'params' => activityGame($gid) + ['v' => 'none'],
                'changes' => [activityChange('igdb_game', $oldName, null)]]);
        } else {
            db()->prepare("DELETE FROM game_igdb WHERE game_id = ?")->execute([$gid]);
            if (igdbConfigured()) igdbMatchNew([$gid], 10.0);   // look it up again straight away
        }
        jsonOut(['ok' => true]);
    }

    jsonOut(['ok' => false, 'error' => tRaw('common.err_unknown_action')], 400);
} catch (PDOException $e) {
    error_log('game-collection: IGDB API: ' . $e->getMessage());
    $missing = in_array((string)$e->getCode(), ['42S02', '42S22'], true);
    jsonOut(['ok' => false, 'error' => tRaw($missing ? 'igdb.err_no_tables' : 'common.err_unknown')], 500);
} catch (Throwable $e) {
    jsonOut(['ok' => false, 'error' => $e->getMessage()], 502);
}
