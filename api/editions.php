<?php
// Admin: edition groups (link games that PriceCharting lists separately into one game).
// POST JSON {action, …}; CSRF is checked for every /api/* POST in core.php.
require_once __DIR__ . '/../boot.php';
$admin = requireAuth();
if ($admin['role'] !== 'admin') jsonOut(['ok'=>false,'error'=>tRaw('gapi.admins_only')], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok'=>false,'error'=>tRaw('ed.err_action')], 405);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string)($body['action'] ?? '');
$pdo    = db();

/** Game ids from the body as a unique int list. */
function idList(mixed $v): array {
    return array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []))));
}

/** Runs $fn in a transaction and sends its result; errors become {ok:false}. */
function editionTx(callable $fn): never {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $out = $fn();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonOut(['ok'=>false,'error'=>$e->getMessage()], $e instanceof DomainException ? 400 : 500);
    }
    jsonOut(['ok'=>true] + $out);
}

/** A group with its members, main release first. */
function groupOut(int $groupId): ?array {
    $st = db()->prepare("SELECT id, system_id, title, sort_title, main_game_id FROM game_groups WHERE id=?");
    $st->execute([$groupId]);
    $g = $st->fetch();
    if (!$g) return null;
    $st = db()->prepare("SELECT id AS game_id, title, edition_label, edition_sort, active FROM games WHERE group_id=? ORDER BY edition_sort, id");
    $st->execute([$groupId]);
    $g['members'] = $st->fetchAll();
    return $g;
}

function cleanLabel(mixed $v, string $title): string {
    $l = trim(preg_replace('/\s+/u', ' ', (string)$v));
    return mb_substr($l !== '' ? $l : editionDefaultLabel($title), 0, 100);
}

switch ($action) {

// ── Suggestions ──────────────────────────
case 'suggest':
    $sid = (int)($body['system_id'] ?? 0);
    $list = editionSuggestions($sid ?: null);
    jsonOut(['ok'=>true, 'suggestions'=>$list, 'count'=>count($list)]);

// ── Number of suggestions for some systems (notice after an import) ──
case 'count':
    $n = 0;
    foreach (idList($body['system_ids'] ?? []) as $sid) $n += editionSuggestionCount($sid);
    jsonOut(['ok'=>true, 'count'=>$n]);

// ── Create a group, or add games to one ──
// {system_id, group_id|null, title, main_game_id, members:[{game_id, edition_label}]}
case 'link':
    editionTx(function () use ($body, $pdo) {
        $sysId   = (int)($body['system_id'] ?? 0);
        $groupId = (int)($body['group_id'] ?? 0) ?: null;
        $title   = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($body['title'] ?? ''))), 0, 255);
        $mainId  = (int)($body['main_game_id'] ?? 0);
        $members = [];
        foreach ((array)($body['members'] ?? []) as $m) {
            $id = (int)($m['game_id'] ?? 0);
            if ($id) $members[$id] = $m['edition_label'] ?? '';
        }
        if ($title === '') throw new DomainException(tRaw('ed.err_title'));

        // Lock and check the games: same system, unlinked or already in this group
        $ids = array_keys($members);
        if (!$ids) throw new DomainException(tRaw('ed.err_min'));
        $st = $pdo->prepare("SELECT id, system_id, title, group_id FROM games WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") FOR UPDATE");
        $st->execute($ids);
        $rows = [];
        foreach ($st->fetchAll() as $r) $rows[(int)$r['id']] = $r;
        if (count($rows) !== count($ids)) throw new DomainException(tRaw('api.game_not_found'));
        foreach ($rows as $r) {
            if ((int)$r['system_id'] !== $sysId) throw new DomainException(tRaw('ed.err_system'));
            if ($r['group_id'] !== null && (int)$r['group_id'] !== $groupId) throw new DomainException(tRaw('ed.err_linked', ['title' => $r['title']]));
        }

        if ($groupId) {
            $st = $pdo->prepare("SELECT id FROM game_groups WHERE id=? AND system_id=? FOR UPDATE");
            $st->execute([$groupId, $sysId]);
            if (!$st->fetch()) throw new DomainException(tRaw('ed.err_group'));
            $st = $pdo->prepare("SELECT id FROM games WHERE group_id=?");
            $st->execute([$groupId]);
            $existing = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $existing = [];
        }
        $all = array_values(array_unique(array_merge($existing, $ids)));
        if (count($all) < 2) throw new DomainException(tRaw('ed.err_min'));
        if (!in_array($mainId, $all, true)) throw new DomainException(tRaw('ed.err_main'));

        if ($groupId) {
            $pdo->prepare("UPDATE game_groups SET title=?, sort_title=?, main_game_id=? WHERE id=?")
                ->execute([$title, gameSortTitle($title), $mainId, $groupId]);
        } else {
            $pdo->prepare("INSERT INTO game_groups (system_id, title, sort_title, main_game_id) VALUES (?,?,?,?)")
                ->execute([$sysId, $title, gameSortTitle($title), $mainId]);
            $groupId = (int)$pdo->lastInsertId();
        }

        // New members go after the existing ones, in the order sent; editionNormaliseGroup puts main first
        $st = $pdo->prepare("SELECT COALESCE(MAX(edition_sort), 0) FROM games WHERE group_id=?");
        $st->execute([$groupId]);
        $next = (int)$st->fetchColumn() + 1;
        $upd = $pdo->prepare("UPDATE games SET group_id=?, edition_label=?, edition_sort=? WHERE id=?");
        $keep = $pdo->prepare("UPDATE games SET edition_label=? WHERE id=?");
        foreach ($members as $id => $label) {
            $label = cleanLabel($label, $rows[$id]['title']);
            if (in_array($id, $existing, true)) $keep->execute([$label, $id]);
            else $upd->execute([$groupId, $label, $next++, $id]);
        }
        editionNormaliseGroup($groupId);
        return ['group' => groupOut($groupId)];
    });

// ── Reject a suggestion: store every pair (except pairs inside an existing group) ──
// {system_id, game_ids[], with_ids[]?}  with_ids = members already in the group ("Add to" suggestions)
case 'ignore':
case 'unignore':
    editionTx(function () use ($body, $pdo, $action) {
        $sysId = (int)($body['system_id'] ?? 0);
        $new   = idList($body['game_ids'] ?? []);
        $with  = array_values(array_diff(idList($body['with_ids'] ?? []), $new));
        $ids   = array_merge($new, $with);
        if (!$new || count($ids) < 2) throw new DomainException(tRaw('ed.err_min'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM games WHERE system_id=? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $st->execute([$sysId, ...$ids]);
        if ((int)$st->fetchColumn() !== count($ids)) throw new DomainException(tRaw('ed.err_system'));

        $pairs = [];
        foreach ($new as $i => $a) {
            foreach (array_slice($new, $i + 1) as $b) $pairs[] = [min($a, $b), max($a, $b)];
            foreach ($with as $b)                     $pairs[] = [min($a, $b), max($a, $b)];
        }
        $sql = $action === 'ignore'
            ? "INSERT IGNORE INTO edition_ignores (system_id, game_a, game_b) VALUES (?,?,?)"
            : "DELETE FROM edition_ignores WHERE system_id=? AND game_a=? AND game_b=?";
        $st = $pdo->prepare($sql);
        foreach ($pairs as [$a, $b]) $st->execute([$sysId, $a, $b]);
        return ['pairs' => count($pairs)];
    });

// ── Switch a game off (e.g. a duplicate spelled differently) or back on: {game_id, active} ──
// Same as Disable / Enable in the game list: an inactive game is hidden from the collection, wishlist and suggestions.
case 'set_game_active':
    $gid = (int)($body['game_id'] ?? 0);
    $st = $pdo->prepare("UPDATE games SET active=? WHERE id=?");
    $st->execute([!empty($body['active']) ? 1 : 0, $gid]);
    if (!$st->rowCount()) {
        $chk = $pdo->prepare("SELECT 1 FROM games WHERE id=?");
        $chk->execute([$gid]);
        if (!$chk->fetchColumn()) jsonOut(['ok'=>false,'error'=>tRaw('api.invalid_game')], 404);
    }
    jsonOut(['ok'=>true]);

// ── Dissolve a group ─────────────────────
case 'unlink_group':
    editionTx(function () use ($body) {
        $groupId = (int)($body['group_id'] ?? 0);
        if (!groupOut($groupId)) throw new DomainException(tRaw('ed.err_group'));
        editionDissolveGroup($groupId);
        return [];
    });

// ── Take games out of their group ({game_id} or {game_ids[]}); a group left with one game is dissolved ──
case 'remove_member':
    editionTx(function () use ($body, $pdo) {
        $ids = idList(array_merge([(int)($body['game_id'] ?? 0)], (array)($body['game_ids'] ?? [])));
        if (!$ids) throw new DomainException(tRaw('api.invalid_game'));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT DISTINCT group_id FROM games WHERE id IN ($in) AND group_id IS NOT NULL");
        $st->execute($ids);
        $groups = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $pdo->prepare("UPDATE games SET group_id=NULL, edition_label=NULL, edition_sort=0 WHERE id IN ($in)")->execute($ids);
        $left = [];
        foreach ($groups as $gid) $left[$gid] = editionNormaliseGroup($gid) ? groupOut($gid) : null;
        return ['groups' => $left];
    });

// ── Rename, change the main release, relabel ──
// {group_id, title?, main_game_id?, labels?:[{game_id, edition_label}]}
case 'update_group':
    editionTx(function () use ($body, $pdo) {
        $groupId = (int)($body['group_id'] ?? 0);
        $g = groupOut($groupId);
        if (!$g) throw new DomainException(tRaw('ed.err_group'));
        $memberIds = array_map(fn($m) => (int)$m['game_id'], $g['members']);

        if (array_key_exists('title', $body)) {
            $title = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$body['title'])), 0, 255);
            if ($title === '') throw new DomainException(tRaw('ed.err_title'));
            $pdo->prepare("UPDATE game_groups SET title=?, sort_title=? WHERE id=?")->execute([$title, gameSortTitle($title), $groupId]);
        }
        if (array_key_exists('main_game_id', $body)) {
            $mainId = (int)$body['main_game_id'];
            if (!in_array($mainId, $memberIds, true)) throw new DomainException(tRaw('ed.err_main'));
            $pdo->prepare("UPDATE game_groups SET main_game_id=? WHERE id=?")->execute([$mainId, $groupId]);
        }
        $titles = array_column($g['members'], 'title', 'game_id');
        $upd = $pdo->prepare("UPDATE games SET edition_label=? WHERE id=? AND group_id=?");
        foreach ((array)($body['labels'] ?? []) as $l) {
            $id = (int)($l['game_id'] ?? 0);
            if (!in_array($id, $memberIds, true)) continue;
            $upd->execute([cleanLabel($l['edition_label'] ?? '', $titles[$id]), $id, $groupId]);
        }
        editionNormaliseGroup($groupId);
        return ['group' => groupOut($groupId)];
    });

default:
    jsonOut(['ok'=>false,'error'=>tRaw('ed.err_action')], 400);
}
