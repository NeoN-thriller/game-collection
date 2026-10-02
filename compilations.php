<?php
/* ═══════════════════════════════════════════
   COMPILATIONS — catalogue games that contain other games of the same system
   (e.g. "Dishonored Prey: The Arkane Collection" → Dishonored [Definitive Edition], Prey).
   Admin-managed in compilation_items; a game can be in any number of compilations.
   Loaded by core.php.

   Per user (users.compilation_mode):
     own      — a compilation is its own game (nothing else changes)
     contents — owning a compilation counts the games inside it as owned, and the
                compilation itself is left out of the game totals (copies, spent and value stay per copy)
   ═══════════════════════════════════════════ */

/** Words in a title that mark a likely compilation. */
const COMP_KEYWORDS = '/\b(collection|trilogy|duology|quadrilogy|anthology|compilation|bundle|double\s*pack|triple\s*pack|twin\s*pack|dual\s*pack|combo\s*pack|[23]\s*-?\s*in\s*-?\s*1|[23]\s*games?\s*in\s*1)\b/iu';

function compilationMode(array $user): string {
    return ($user['compilation_mode'] ?? 'own') === 'contents' ? 'contents' : 'own';
}

/**
 * SQL pieces for the dashboard counts. $userParam is the placeholder for the user id (bound by the caller).
 * join:  adds comp.compilation_id (non-null = this game is a compilation) and via.game_id
 *        (non-null = the user owns a compilation containing this game).
 */
function compilationCountJoins(): string {
    return "
        LEFT JOIN (SELECT DISTINCT ci.compilation_id FROM compilation_items ci
                   JOIN games cg ON cg.id = ci.game_id AND cg.active = 1) comp ON comp.compilation_id = g.id
        LEFT JOIN (SELECT DISTINCT ci.game_id FROM compilation_items ci
                   JOIN games cg ON cg.id = ci.compilation_id AND cg.active = 1
                   JOIN collection_entries cce ON cce.game_id = ci.compilation_id AND cce.user_id = ? AND cce.owned = 1) via ON via.game_id = g.id";
}

/** Compilations of one system with their contents: [{id, items:[game ids]}] (active games only). */
function compilationsOfSystem(int $systemId): array {
    $st = db()->prepare("
        SELECT ci.compilation_id, ci.game_id
        FROM compilation_items ci
        JOIN games c ON c.id = ci.compilation_id AND c.active = 1
        JOIN games g ON g.id = ci.game_id AND g.active = 1
        WHERE c.system_id = ?
        ORDER BY ci.compilation_id, ci.sort_order, ci.game_id
    ");
    $st->execute([$systemId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['compilation_id']][] = (int)$r['game_id'];
    return array_map(fn($id, $items) => ['id' => $id, 'items' => $items], array_keys($out), array_values($out));
}

/**
 * Compilation links of some games for the drawer and the wishlist:
 * [gameId => ['contains' => [{id, title, owned}], 'also_in' => [{id, title, owned}]]].
 * owned = the user owns a copy of that game.
 */
function compilationLinksFor(array $gameIds, int $userId): array {
    $gameIds = array_values(array_unique(array_filter(array_map('intval', $gameIds))));
    if (!$gameIds) return [];
    $in = implode(',', $gameIds);
    $st = db()->prepare("
        SELECT ci.compilation_id, c.title AS c_title, ci.game_id, g.title AS g_title,
               EXISTS(SELECT 1 FROM collection_entries x WHERE x.game_id = ci.compilation_id AND x.user_id = ? AND x.owned = 1) AS c_owned,
               EXISTS(SELECT 1 FROM collection_entries x WHERE x.game_id = ci.game_id AND x.user_id = ? AND x.owned = 1) AS g_owned
        FROM compilation_items ci
        JOIN games c ON c.id = ci.compilation_id AND c.active = 1
        JOIN games g ON g.id = ci.game_id AND g.active = 1
        WHERE ci.compilation_id IN ($in) OR ci.game_id IN ($in)
        ORDER BY ci.sort_order, g.sort_title
    ");
    $st->execute([$userId, $userId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $cid = (int)$r['compilation_id']; $gid = (int)$r['game_id'];
        if (in_array($cid, $gameIds, true)) $out[$cid]['contains'][] = ['id' => $gid, 'title' => $r['g_title'], 'owned' => (bool)$r['g_owned']];
        if (in_array($gid, $gameIds, true)) $out[$gid]['also_in'][]  = ['id' => $cid, 'title' => $r['c_title'], 'owned' => (bool)$r['c_owned']];
    }
    foreach ($out as &$o) $o += ['contains' => [], 'also_in' => []];
    unset($o);
    return $out;
}

/** "Dishonored: Definitive Edition" → "dishonored definitive edition" (letters and digits, single spaces). */
function compWords(string $s): string {
    preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($s, 'UTF-8'), $m);
    return implode(' ', $m[0]);
}

/**
 * Compilation suggestions for one system, or all systems when $systemId is null.
 * A candidate is an active game that isn't a compilation yet and wasn't dismissed, whose title has a
 * compilation word (Collection, Trilogy, Bundle, …) or contains the names of at least two other games.
 * Its suggested contents: other games of the system whose name (without an edition bracket) appears
 * as whole words in the candidate's title.
 * Each: {system_id, system_name, game_id, title, pc_link, keyword, items:[{game_id, title, pc_link}]}
 */
function compilationSuggestions(?int $systemId = null): array {
    $pdo = db();
    if ($systemId) {
        $st = $pdo->prepare("SELECT id, name FROM systems WHERE id=?");
        $st->execute([$systemId]);
    } else {
        $st = $pdo->query("SELECT id, name FROM systems ORDER BY sort_order, name");
    }
    $systems = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$systems) return [];
    $in = implode(',', array_map('intval', array_keys($systems)));
    $games = $pdo->query("
        SELECT g.id, g.system_id, g.title, g.pc_link, g.comp_dismissed,
               EXISTS(SELECT 1 FROM compilation_items ci WHERE ci.compilation_id = g.id) AS is_comp
        FROM games g WHERE g.active = 1 AND g.system_id IN ($in) ORDER BY g.sort_title, g.id
    ")->fetchAll();

    $bySys = [];
    foreach ($games as $g) {
        $g['words']   = compWords($g['title']);
        $g['base']    = compWords(editionBase($g['title']));
        $g['keyword'] = (bool)preg_match(COMP_KEYWORDS, $g['title']);
        $bySys[(int)$g['system_id']][] = $g;
    }

    $out = [];
    foreach ($bySys as $sid => $list) {
        // Possible contents by name: base name → games (compilations themselves aren't contents)
        $byBase = [];
        foreach ($list as $g) if (!$g['keyword'] && $g['base'] !== '') $byBase[$g['base']][] = $g;
        foreach ($list as $c) {
            if ($c['is_comp'] || $c['comp_dismissed']) continue;
            // Every run of whole words in the title is a name that could be inside it
            $w = explode(' ', $c['words']);
            $found = [];
            for ($i = 0, $n = count($w); $i < $n; $i++) {
                for ($j = $i; $j < $n; $j++) {
                    $name = implode(' ', array_slice($w, $i, $j - $i + 1));
                    if ($name === $c['base']) continue;   // its own editions
                    foreach ($byBase[$name] ?? [] as $g) if ($g['id'] !== $c['id']) $found[(int)$g['id']] = $g;
                }
            }
            $items = array_map(fn($g) => ['game_id' => (int)$g['id'], 'title' => $g['title'], 'pc_link' => pcLinkSafe($g['pc_link'])], array_values($found));
            $bases = count(array_unique(array_map(fn($i) => compWords(editionBase($i['title'])), $items)));
            if (!$c['keyword'] && $bases < 2) continue;
            $out[] = [
                'system_id' => $sid, 'system_name' => $systems[$sid], 'game_id' => (int)$c['id'],
                'title' => $c['title'], 'pc_link' => pcLinkSafe($c['pc_link']), 'keyword' => $c['keyword'], 'items' => $items,
            ];
        }
    }
    return $out;
}

/**
 * Replaces what a compilation contains. Games must be active, of the compilation's system, and not the
 * compilation itself. An empty list makes it a normal game again. Throws DomainException on bad input.
 */
function compilationSave(int $compId, array $itemIds): array {
    $pdo = db();
    $st = $pdo->prepare("SELECT id, system_id, title FROM games WHERE id=?");
    $st->execute([$compId]);
    $comp = $st->fetch();
    if (!$comp) throw new DomainException(tRaw('api.invalid_game'));
    $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn($id) => $id !== $compId)));
    if ($itemIds) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM games WHERE system_id=? AND id IN (" . implode(',', array_fill(0, count($itemIds), '?')) . ")");
        $st->execute([(int)$comp['system_id'], ...$itemIds]);
        if ((int)$st->fetchColumn() !== count($itemIds)) throw new DomainException(tRaw('comp.err_system'));
    }
    $pdo->prepare("DELETE FROM compilation_items WHERE compilation_id=?")->execute([$compId]);
    $ins = $pdo->prepare("INSERT INTO compilation_items (compilation_id, game_id, sort_order) VALUES (?,?,?)");
    foreach ($itemIds as $i => $id) $ins->execute([$compId, $id, $i]);
    if ($itemIds) $pdo->prepare("UPDATE games SET comp_dismissed=0 WHERE id=?")->execute([$compId]);
    return ['id' => (int)$comp['id'], 'title' => $comp['title'], 'items' => $itemIds];
}
