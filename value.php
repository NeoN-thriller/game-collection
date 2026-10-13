<?php
// Collection value over time — loaded by core.php. Safe to overwrite on updates.
//
// One value rule for the dashboard's Collection value tile and the value graphs: owned copies of active
// games, each at its own price type (loose / CIB / new), a missing price counts as 0, all systems.
// value_history keeps one row per user and system each time that value changes; a system's value at
// time T is its latest row at or before T ("latest" = highest id), and 0 before its first row.
if (!defined('DB_HOST')) { http_response_code(403); exit; }

/** The value of one copy (collection_entries ce JOIN games g). Change the rule here only. */
const VALUE_EXPR = "CASE ce.value_price_type WHEN 'loose' THEN g.loose_price WHEN 'new' THEN g.new_price ELSE g.cib_price END";

/** Owned value per system for one user: [system_id => value] (only systems with owned copies). */
function valueBySystem(int $uid, ?array $systemIds = null): array {
    $ids = $systemIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $systemIds))));
    if ($ids === []) return [];
    $st = db()->prepare("
        SELECT g.system_id, COALESCE(SUM(" . VALUE_EXPR . "), 0) AS v
        FROM collection_entries ce JOIN games g ON g.id = ce.game_id
        WHERE ce.user_id = ? AND ce.owned = 1 AND g.active = 1"
        . ($ids ? " AND g.system_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")" : "") . "
        GROUP BY g.system_id");
    $st->execute(array_merge([$uid], $ids ?? []));
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['system_id']] = round((float)$r['v'], 2);
    return $out;
}

/**
 * Record the current owned value for the given users (null = every user) and systems.
 * $source: 'import' | 'collection' | 'price'.  $run: import run id (all batches of one import share it), or null.
 * Only writes when a value changed. Never throws: failures go to error_log(), the caller's work must not fail.
 */
function valueSnapshot(?array $userIds, array $systemIds, string $source, ?string $run = null): void {
    $sys = array_values(array_unique(array_filter(array_map('intval', $systemIds))));
    if (!$sys || !in_array($source, ['import', 'collection', 'price'], true)) return;
    if ($userIds !== null) {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) return;
    }
    $run = ($run !== null && $run !== '') ? mb_substr($run, 0, 40) : null;
    $pdo = db();
    $own = false;
    try {
        $in   = fn(array $l) => implode(',', array_fill(0, count($l), '?'));
        $uSql = fn(string $col) => $userIds !== null ? " AND $col IN (" . $in($userIds) . ")" : '';
        $uArg = $userIds ?? [];
        $cents = fn($v) => (int)round((float)$v * 100);

        // The values now, per user and system
        $st = $pdo->prepare("
            SELECT ce.user_id, g.system_id, COALESCE(SUM(" . VALUE_EXPR . "), 0) AS v
            FROM collection_entries ce JOIN games g ON g.id = ce.game_id
            WHERE ce.owned = 1 AND g.active = 1 AND g.system_id IN (" . $in($sys) . ")" . $uSql('ce.user_id') . "
            GROUP BY ce.user_id, g.system_id");
        $st->execute(array_merge($sys, $uArg));
        $now = [];
        foreach ($st->fetchAll() as $r) $now[$r['user_id'] . ':' . $r['system_id']] = round((float)$r['v'], 2);

        // The latest row of each user and system (for an import: the latest one of another run)
        $st = $pdo->prepare("
            SELECT vh.id, vh.user_id, vh.system_id, vh.recorded_at, vh.value, vh.source, vh.run
            FROM value_history vh
            JOIN (SELECT MAX(id) AS mid FROM value_history
                  WHERE system_id IN (" . $in($sys) . ")" . $uSql('user_id') . ($run !== null ? " AND (run IS NULL OR run <> ?)" : "") . "
                  GROUP BY user_id, system_id) x ON x.mid = vh.id");
        $st->execute(array_merge($sys, $uArg, $run !== null ? [$run] : []));
        $last = [];
        foreach ($st->fetchAll() as $r) $last[$r['user_id'] . ':' . $r['system_id']] = $r;

        // This run's own rows (earlier batches of the same import)
        $runRows = [];
        if ($run !== null) {
            $st = $pdo->prepare("SELECT id, user_id, system_id FROM value_history
                                 WHERE run = ? AND system_id IN (" . $in($sys) . ")" . $uSql('user_id'));
            $st->execute(array_merge([$run], $sys, $uArg));
            foreach ($st->fetchAll() as $r) $runRows[$r['user_id'] . ':' . $r['system_id']] = (int)$r['id'];
        }

        // Users who own something now, and users with a row whose value may have dropped to 0
        $pairs = array_unique(array_merge(array_keys($now), array_keys($last), array_keys($runRows)));
        if (!$pairs) return;

        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        $today = date('Y-m-d');
        $ins = $pdo->prepare("INSERT INTO value_history (user_id, system_id, recorded_at, value, source, run)
                              VALUES (?, ?, NOW(), ?, ?, ?)" . ($run !== null ? " ON DUPLICATE KEY UPDATE value = VALUES(value)" : ""));
        $del = $pdo->prepare("DELETE FROM value_history WHERE id = ?");
        $upd = $pdo->prepare("UPDATE value_history SET value = ?, recorded_at = NOW() WHERE id = ?");
        $before = $pdo->prepare("SELECT value FROM value_history WHERE user_id = ? AND system_id = ? AND id < ? ORDER BY id DESC LIMIT 1");

        foreach ($pairs as $key) {
            [$uid, $sid] = array_map('intval', explode(':', $key));
            $v = $now[$key] ?? 0.0;
            $l = $last[$key] ?? null;
            $base = $l ? $cents($l['value']) : 0;

            if ($run !== null) {
                // Import: one row per run, however many batches it took; none when nothing changed overall
                if ($cents($v) === $base) {
                    if (isset($runRows[$key])) $del->execute([$runRows[$key]]);
                } else {
                    $ins->execute([$uid, $sid, $v, $source, $run]);
                }
                continue;
            }

            if ($cents($v) === $base) continue;   // no change
            if ($l && $l['source'] === $source && $l['run'] === null && substr((string)$l['recorded_at'], 0, 10) === $today) {
                // Several changes of the same kind on one day collapse into one point; changes that cancel out leave none
                $before->execute([$uid, $sid, (int)$l['id']]);
                $prev = $before->fetchColumn();
                if ($cents($v) === ($prev === false ? 0 : $cents($prev))) $del->execute([(int)$l['id']]);
                else $upd->execute([$v, (int)$l['id']]);
            } else {
                $ins->execute([$uid, $sid, $v, $source, null]);
            }
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable) {} }
        error_log('valueSnapshot: ' . $e->getMessage());
    }
}

/** The system of each given game: [system_id, …] (for the triggers). */
function valueSystemsOfGames(array $gameIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $gameIds))));
    if (!$ids) return [];
    try {
        $st = db()->prepare("SELECT DISTINCT system_id FROM games WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $st->execute($ids);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('valueSystemsOfGames: ' . $e->getMessage());
        return [];
    }
}
