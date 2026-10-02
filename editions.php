<?php
/* ═══════════════════════════════════════════
   EDITIONS — games that PriceCharting lists separately (original, [Platinum], …)
   linked into one group (game_groups). Loaded by core.php.
   Each edition keeps its own games row; games.group_id links it to the group.
   ═══════════════════════════════════════════ */

/** Title without one trailing "[…]" group, whitespace collapsed. */
function editionBase(string $title): string {
    $t = preg_replace('/\s*\[[^\[\]]*\]\s*$/u', '', $title);
    return trim(preg_replace('/\s+/u', ' ', $t));
}

/** The trailing bracket text ("Platinum"), or null when the title has none. */
function editionBracket(string $title): ?string {
    return preg_match('/\[([^\[\]]*)\]\s*$/u', $title, $m) && trim($m[1]) !== '' ? trim($m[1]) : null;
}

/** Default edition label for a title: the bracket text, else "Original". */
function editionDefaultLabel(string $title): string {
    return editionBracket($title) ?? tRaw('ed.original');
}

/** Lower case, letters and digits only ("WipEout 2097" → "wipeout2097"). */
function editionNorm(string $s): string {
    return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s, 'UTF-8'));
}

/** sort_title for a name, same rules as the PriceCharting import. */
function gameSortTitle(string $title): string {
    $s = preg_replace('/^(the |a |an )/i', '', $title);
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/i', ' ', $s)));
}

/**
 * SQL for the counting unit in "one per game" mode: a linked game counts as its group.
 * $a = alias of the games table.
 */
function editionUnitSql(string $a = 'g'): string {
    return "COALESCE(CONCAT('g', $a.group_id), CONCAT('i', $a.id))";
}

/** True when the longer normalised name only adds a sequel number to the shorter one ("tekken" → "tekken3"). */
function editionIsSequel(string $short, string $long): bool {
    $pos = strpos($long, $short);
    $rest = substr($long, 0, $pos) . substr($long, $pos + strlen($short));
    return (bool)preg_match('/^(\d+|i{1,3}|iv|vi{0,3}|ix|x)$/', $rest);
}

/** Partial match: one normalised name contains the other (shorter ≥ 5 chars) and it isn't just a sequel number. */
function editionPartial(string $a, string $b): bool {
    if ($a === $b) return false;
    [$s, $l] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];
    return strlen($s) >= 5 && str_contains($l, $s) && !editionIsSequel($s, $l);
}

/**
 * Rejected pairs ("gameA-gameB" with A < B) for the given systems.
 * @return array<string,true>
 */
function editionIgnoredPairs(array $systemIds): array {
    if (!$systemIds) return [];
    $st = db()->query("SELECT game_a, game_b FROM edition_ignores WHERE system_id IN (" . implode(',', array_map('intval', $systemIds)) . ")");
    $out = [];
    foreach ($st as $r) $out[$r['game_a'] . '-' . $r['game_b']] = true;
    return $out;
}

function editionPairKey(int $a, int $b): string {
    return $a < $b ? "$a-$b" : "$b-$a";
}

/**
 * Splits games into clusters without rejected pairs: each game joins the first
 * cluster it has no rejected pair with. Clusters of one are dropped.
 */
function editionSplitIgnored(array $games, array $ignored): array {
    $clusters = [];
    foreach ($games as $g) {
        foreach ($clusters as &$c) {
            $ok = true;
            foreach ($c as $o) if (isset($ignored[editionPairKey($g['id'], $o['id'])])) { $ok = false; break; }
            if ($ok) { $c[] = $g; continue 2; }
        }
        unset($c);
        $clusters[] = [$g];
    }
    unset($c);
    return array_values(array_filter($clusters, fn($c) => count($c) > 1));
}

/**
 * Edition suggestions for one system, or all systems when $systemId is null.
 * Nothing is linked here; the admin accepts, adjusts or rejects each suggestion.
 *
 * Each suggestion: key, system_id, system_name, level (exact|similar|partial),
 * group_id (null = new group), group_title, title (default game name), main_game_id,
 * members: [{game_id, title, edition_label, cib_price, pc_link, copies, existing}] (existing = already in the group).
 */
function editionSuggestions(?int $systemId = null): array {
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

    $games = $pdo->query("SELECT g.id, g.system_id, g.title, g.group_id, g.edition_label, g.edition_sort, g.cib_price, g.pc_link,
                                 (SELECT COUNT(*) FROM collection_entries ce WHERE ce.game_id = g.id) AS copies
                          FROM games g WHERE g.active=1 AND g.system_id IN ($in)
                            AND NOT EXISTS (SELECT 1 FROM compilation_items ci WHERE ci.compilation_id = g.id)   -- compilations aren't editions
                          ORDER BY g.id")->fetchAll();
    $groups = [];
    foreach ($pdo->query("SELECT id, system_id, title, main_game_id FROM game_groups WHERE system_id IN ($in)") as $gr) {
        $groups[(int)$gr['id']] = $gr + ['members' => []];
    }
    $ignored = editionIgnoredPairs(array_keys($systems));

    // Per system: unlinked games bucketed by normalised base, and the match keys of existing groups
    $bySys = [];
    foreach ($games as $g) {
        $g['id'] = (int)$g['id'];
        $g['base'] = editionBase($g['title']);
        $g['norm'] = editionNorm($g['base']);
        $sid = (int)$g['system_id'];
        if ($g['group_id'] !== null && isset($groups[(int)$g['group_id']])) {
            $groups[(int)$g['group_id']]['members'][] = $g;
        } elseif ($g['norm'] !== '') {
            $bySys[$sid]['buckets'][$g['norm']][] = $g;
        }
    }
    foreach ($groups as $gid => $gr) {
        if (!$gr['members']) continue;
        $keys = [editionNorm($gr['title']) => true];
        foreach ($gr['members'] as $m) $keys[$m['norm']] = true;
        unset($keys['']);
        $bySys[(int)$gr['system_id']]['groups'][$gid] = array_keys($keys);
    }

    $out = [];
    foreach ($bySys as $sid => $data) {
        $buckets    = $data['buckets'] ?? [];
        $groupKeys  = $data['groups'] ?? [];
        $sysName    = $systems[$sid] ?? '';

        // 1. Unlinked games that match an existing group → "Add to <group>"
        $adds = [];   // group id => [level, games]
        foreach ($buckets as $norm => $list) {
            $norm = (string)$norm;   // numeric names ("1943") become int array keys
            foreach ($groupKeys as $gid => $keys) {
                $level = null;
                foreach ($keys as $k) {
                    $k = (string)$k;
                    if ($k === $norm) { $level = 'similar'; break; }
                    if (!$level && editionPartial($k, $norm)) $level = 'partial';
                }
                if (!$level) continue;
                if ($level === 'similar') {
                    // Exact when a member's base (or the group title) is identical, case included
                    foreach ($list as $g) {
                        if ($g['base'] === editionBase($groups[$gid]['title'])) { $level = 'exact'; break; }
                        foreach ($groups[$gid]['members'] as $m) if ($m['base'] === $g['base']) { $level = 'exact'; break 2; }
                    }
                }
                // Drop games rejected with any member of the group
                $ok = array_values(array_filter($list, function ($g) use ($groups, $gid, $ignored) {
                    foreach ($groups[$gid]['members'] as $m) if (isset($ignored[editionPairKey($g['id'], $m['id'])])) return false;
                    return true;
                }));
                if (!$ok) continue;
                $rank = ['exact' => 0, 'similar' => 1, 'partial' => 2];
                if (!isset($adds[$gid])) $adds[$gid] = [$level, []];
                elseif ($rank[$level] > $rank[$adds[$gid][0]]) $adds[$gid][0] = $level;
                array_push($adds[$gid][1], ...$ok);
                if ($level !== 'partial') { unset($buckets[$norm]); continue 2; }   // a close match to a group wins
            }
        }
        foreach ($adds as $gid => [$level, $new]) {
            $gr = $groups[$gid];
            usort($gr['members'], fn($a, $b) => [$a['edition_sort'], $a['id']] <=> [$b['edition_sort'], $b['id']]);
            $members = [];
            foreach ($gr['members'] as $m) $members[] = editionMemberOut($m, true);
            $seen = [];
            foreach ($new as $g) if (!isset($seen[$g['id']])) { $seen[$g['id']] = 1; $members[] = editionMemberOut($g, false); }
            $out[] = [
                'system_id' => $sid, 'system_name' => $sysName, 'level' => $level,
                'group_id' => $gid, 'group_title' => $gr['title'], 'title' => $gr['title'],
                'main_game_id' => in_array((int)$gr['main_game_id'], array_column($members, 'game_id'), true) ? (int)$gr['main_game_id'] : $members[0]['game_id'],
                'members' => $members,
            ];
        }

        // 2. New groups: games whose normalised base is equal (exact or similar)
        $multi = [];
        foreach ($buckets as $norm => $list) {
            if (count($list) < 2) continue;
            $multi[$norm] = true;
            $level = count(array_unique(array_column($list, 'base'))) === 1 ? 'exact' : 'similar';
            foreach (editionSplitIgnored($list, $ignored) as $cluster) {
                $out[] = editionNewSuggestion($sid, $sysName, $level, $cluster);
            }
        }

        // 3. Partial: one name contains the other. Only between single games; a bucket that
        //    already forms a group above comes back as "Add to" once that group is linked.
        $singles = array_values(array_filter($buckets, fn($l, $n) => !isset($multi[$n]), ARRAY_FILTER_USE_BOTH));
        $n = count($singles);
        for ($i = 0; $i < $n; $i++) {
            $a = $singles[$i][0];
            for ($j = $i + 1; $j < $n; $j++) {
                $b = $singles[$j][0];
                if (!editionPartial($a['norm'], $b['norm'])) continue;
                if (editionBracket($a['title']) === null && editionBracket($b['title']) === null) continue;
                if (isset($ignored[editionPairKey($a['id'], $b['id'])])) continue;
                $out[] = editionNewSuggestion($sid, $sysName, 'partial', [$a, $b]);
            }
        }
    }

    $rank = ['exact' => 0, 'similar' => 1, 'partial' => 2];
    usort($out, fn($x, $y) => [$rank[$x['level']], $x['system_name'], mb_strtolower($x['title'])]
                           <=> [$rank[$y['level']], $y['system_name'], mb_strtolower($y['title'])]);
    foreach ($out as &$s) {
        $ids = array_column($s['members'], 'game_id');
        sort($ids);
        $s['key'] = $s['system_id'] . ':' . ($s['group_id'] ?? 'new') . ':' . implode(',', $ids);
    }
    unset($s);
    return $out;
}

/** A game's PriceCharting page (set by the CSV import) when it's an http(s) URL, else null. */
function pcLinkSafe(?string $link): ?string {
    return $link !== null && preg_match('~^https?://~i', $link) ? $link : null;
}

function editionMemberOut(array $g, bool $existing): array {
    return [
        'game_id'       => (int)$g['id'],
        'title'         => $g['title'],
        'edition_label' => $existing && ($g['edition_label'] ?? '') !== '' ? $g['edition_label'] : editionDefaultLabel($g['title']),
        'cib_price'     => $g['cib_price'] !== null ? (float)$g['cib_price'] : null,
        'pc_link'       => pcLinkSafe($g['pc_link'] ?? null),
        'copies'        => (int)($g['copies'] ?? 0),   // rows in users' collections (owned or wishlisted)
        'existing'      => $existing,
    ];
}

/** A suggestion for a new group. Main release: the member without a bracket, else the lowest id. */
function editionNewSuggestion(int $sid, string $sysName, string $level, array $games): array {
    usort($games, fn($a, $b) => [editionBracket($a['title']) !== null, $a['id']] <=> [editionBracket($b['title']) !== null, $b['id']]);
    return [
        'system_id' => $sid, 'system_name' => $sysName, 'level' => $level,
        'group_id' => null, 'group_title' => null,
        'title' => $games[0]['base'],
        'main_game_id' => $games[0]['id'],
        'members' => array_map(fn($g) => editionMemberOut($g, false), $games),
    ];
}

/** Number of suggestions for a system (or all systems); used for the notice after an import. */
function editionSuggestionCount(?int $systemId = null): int {
    return count(editionSuggestions($systemId));
}

/**
 * Renumbers a group's editions (main first, then the current order) and repairs its main release.
 * Dissolves the group when fewer than two members are left. Returns false when dissolved.
 */
function editionNormaliseGroup(int $groupId): bool {
    $pdo = db();
    $st = $pdo->prepare("SELECT main_game_id FROM game_groups WHERE id=?");
    $st->execute([$groupId]);
    $main = $st->fetchColumn();
    if ($main === false) return false;

    $st = $pdo->prepare("SELECT id FROM games WHERE group_id=? ORDER BY edition_sort, id");
    $st->execute([$groupId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) < 2) {
        editionDissolveGroup($groupId);
        return false;
    }
    $main = in_array((int)$main, $ids, true) ? (int)$main : $ids[0];
    $ids  = array_merge([$main], array_values(array_diff($ids, [$main])));
    $upd  = $pdo->prepare("UPDATE games SET edition_sort=? WHERE id=?");
    foreach ($ids as $i => $id) $upd->execute([$i, $id]);
    $pdo->prepare("UPDATE game_groups SET main_game_id=? WHERE id=?")->execute([$main, $groupId]);
    return true;
}

/** Unlinks every member and deletes the group. */
function editionDissolveGroup(int $groupId): void {
    db()->prepare("UPDATE games SET group_id=NULL, edition_label=NULL, edition_sort=0 WHERE group_id=?")->execute([$groupId]);
    db()->prepare("DELETE FROM game_groups WHERE id=?")->execute([$groupId]);
}
