<?php
// The signed-in user's collection value over time, for the dashboard's value graphs (assets/js/value-graph.js).
// GET, own data only (never a user id parameter). value_history keeps a row per system each time its value
// changed; here they become points: all imports of one day form one point (however many runs, e.g. one
// system at a time), other rows one point per day and source. Each point carries every system's value so far
// (carried forward) and their total.
// {ok, systems:[{id, name, short, active}], points:[{ts, source, total, v:{system_id: value}}]}
require_once __DIR__ . '/../boot.php';
$user = requireAuth();

const VALUE_MAX_POINTS = 1500;
const VALUE_SOURCE_ORDER = ['start' => 0, 'price' => 1, 'import' => 2, 'collection' => 3];

try {
    $st = db()->prepare("SELECT id, system_id, recorded_at, value, source, run FROM value_history
                         WHERE user_id=? ORDER BY recorded_at, id");
    $st->execute([(int)$user['id']]);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    error_log('value_history: ' . $e->getMessage());
    jsonOut(['ok'=>false, 'error'=>tRaw('val.err_load')], 500);
}

// 1. Rows → points. Imports: one point per day, a system imported twice that day keeps its latest value.
//    Other sources: a system that changes twice in one (day, source) group starts a new point, so its steps stay in order.
$groups = [];   // key => ['ts', 'source', 'rows' => [...]]
$open   = [];   // group base key => current group key
foreach ($rows as $r) {
    $ts = strtotime((string)$r['recorded_at']);
    if ($r['run'] !== null || $r['source'] === 'import') {
        $key = 'i:' . substr((string)$r['recorded_at'], 0, 10);
    } else {
        $base = 'd:' . substr((string)$r['recorded_at'], 0, 10) . ':' . $r['source'];
        $key  = $open[$base] ?? $base;
        if (isset($groups[$key]['sys'][(int)$r['system_id']])) { $key = $base . ':' . $r['id']; }
        $open[$base] = $key;
    }
    if (!isset($groups[$key])) $groups[$key] = ['ts' => $ts, 'source' => $r['source'], 'rows' => [], 'sys' => [], 'first' => (int)$r['id']];
    $groups[$key]['ts'] = min($groups[$key]['ts'], $ts);
    $groups[$key]['rows'][] = $r;
    $groups[$key]['sys'][(int)$r['system_id']] = true;
}
$groups = array_values($groups);
usort($groups, fn($a, $b) => [$a['ts'], VALUE_SOURCE_ORDER[$a['source']] ?? 9, $a['first']]
                           <=> [$b['ts'], VALUE_SOURCE_ORDER[$b['source']] ?? 9, $b['first']]);

// 2. Walk the points, carrying each system's latest value forward. A row older than one already applied for its
//    system is skipped: a day's import point comes before that day's other changes, but its rows may be newer
$cur = [];
$curId = [];   // system => id of the row its value in $cur came from
$points = [];
foreach ($groups as $g) {
    usort($g['rows'], fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    foreach ($g['rows'] as $r) {
        $sk = (string)(int)$r['system_id'];
        if ((int)$r['id'] < ($curId[$sk] ?? 0)) continue;
        $cur[$sk] = round((float)$r['value'], 2);
        $curId[$sk] = (int)$r['id'];
    }
    $points[] = ['ts' => $g['ts'], 'source' => $g['source'], 'total' => round(array_sum($cur), 2), 'v' => (object)$cur];
}

// 4. Never reached in practice: keep the first point (so "All" has a start) and the most recent ones
if (count($points) > VALUE_MAX_POINTS) {
    $points = array_merge([$points[0]], array_slice($points, -(VALUE_MAX_POINTS - 1)));
}

// 3. Every system that appears in the history (inactive ones too: their history still counts in the total)
$systems = [];
$ids = array_values(array_unique(array_map(fn($r) => (int)$r['system_id'], $rows)));
if ($ids) {
    $st = db()->prepare("SELECT id, name, short_name, active FROM systems WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $s) {
        $systems[] = ['id' => (int)$s['id'], 'name' => (string)$s['name'], 'short' => (string)$s['short_name'], 'active' => (bool)$s['active']];
    }
}

jsonOut(['ok'=>true, 'systems'=>$systems, 'points'=>$points]);
