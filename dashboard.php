<?php
require_once __DIR__ . '/boot.php';
$user = requireAuth();

// Load all systems — including count_for_totals flag
$st = db()->prepare("
    SELECT s.*, COALESCE(usp.visible,1) AS visible,
           COALESCE(usp.user_sort_order, s.sort_order) AS effective_order,
           COALESCE(usp.count_for_totals, 1) AS count_for_totals
    FROM systems s
    LEFT JOIN user_system_prefs usp ON usp.system_id=s.id AND usp.user_id=?
    WHERE s.active=1
    ORDER BY COALESCE(usp.user_sort_order, s.sort_order)
");
$st->execute([$user['id']]);
$systems   = $st->fetchAll();
$showIcons = (bool)($user['show_system_icons'] ?? 1);

// IDs of systems that count toward global totals
$countSystemIds = array_map(fn($s) => (int)$s['id'],
    array_filter($systems, fn($s) => (bool)$s['count_for_totals']));

// Editions: in "one per game" mode linked editions count once (the unit key); in "every edition" mode per game row.
// Copies, spent, value and condition stay per copy.
$editionMode = ($user['edition_mode'] ?? 'one') === 'every' ? 'every' : 'one';
$unit = $editionMode === 'one' ? editionUnitSql('g') : 'g.id';
$nGamesKey = $editionMode === 'one' ? 'dashboard.n_games' : 'ed.n_editions';

// Compilations, "count the games inside" mode: a game counts as owned when the user owns a compilation
// containing it, and compilations themselves are left out of the game totals. Otherwise nothing changes.
$compContents = compilationMode($user) === 'contents';
$gameUnit  = $compContents ? "CASE WHEN comp.compilation_id IS NULL THEN $unit END" : $unit;
$ownedCond = $compContents ? '(ce.owned=1 OR via.game_id IS NOT NULL)' : 'ce.owned=1';
$cibCond   = $compContents ? 'comp.compilation_id IS NULL' : '1';

// Stats per system (all systems — cards always show their own %)
$statsSt = db()->prepare("
    SELECT
        g.system_id,
        COUNT(DISTINCT $gameUnit)                                    AS total_games,
        COUNT(DISTINCT CASE WHEN $ownedCond THEN $gameUnit END)     AS owned,
        COUNT(DISTINCT CASE WHEN ce.wishlist=1 THEN $gameUnit END)  AS wishlisted,
        COUNT(CASE WHEN ce.owned=1 THEN ce.id END)                  AS total_copies,
        COUNT(CASE WHEN ce.owned=1 AND ce.upgrade=1 THEN ce.id END) AS upgrades,
        COALESCE(SUM(CASE WHEN ce.owned=1 THEN ce.price_paid END),0) AS total_spent,
        COALESCE(SUM(CASE WHEN $cibCond THEN g.cib_price END),0)    AS cib_total
    FROM games g
    LEFT JOIN collection_entries ce ON ce.game_id=g.id AND ce.user_id=?
    ".($compContents ? compilationCountJoins() : '')."
    WHERE g.active=1
    GROUP BY g.system_id
");
$statsSt->execute($compContents ? [$user['id'], $user['id']] : [$user['id']]);
$statsRaw = $statsSt->fetchAll();
$stats = [];
foreach ($statsRaw as $r) $stats[$r['system_id']] = $r + ['owned_value'=>0, 'labels'=>[], 'avg_score'=>null, 'scored'=>0];
// Owned value: the one value rule (value.php), the same numbers the value graphs record
$valueBySys = valueBySystem((int)$user['id']);
foreach ($valueBySys as $sid => $v) if (isset($stats[$sid])) $stats[$sid]['owned_value'] = $v;

// Condition per system: owned copies per effective label (simple label, or the label derived from the point score)
$gradeLabels = gradingConfig()['labels'];
$condSt = db()->prepare("
    SELECT g.system_id, ".gradeLabelSql('ce')." AS label_id, COUNT(*) AS n
    FROM collection_entries ce JOIN games g ON g.id=ce.game_id
    WHERE ce.user_id=? AND ce.owned=1 AND g.active=1
    GROUP BY g.system_id, label_id
");
$condSt->execute([$user['id']]);
foreach ($condSt->fetchAll() as $r) {
    if ($r['label_id'] !== null && isset($stats[$r['system_id']])) $stats[$r['system_id']]['labels'][(int)$r['label_id']] = (int)$r['n'];
}
// Average score over point-graded owned copies
$avgSt = db()->prepare("
    SELECT g.system_id, AVG(ce.grade_score) AS avg_score, COUNT(*) AS scored
    FROM collection_entries ce JOIN games g ON g.id=ce.game_id
    WHERE ce.user_id=? AND ce.owned=1 AND g.active=1 AND ce.grade_method='points' AND ce.grade_score IS NOT NULL
    GROUP BY g.system_id
");
$avgSt->execute([$user['id']]);
$scoreSum = 0; $scoredTotal = 0;
foreach ($avgSt->fetchAll() as $r) {
    if (!isset($stats[$r['system_id']])) continue;
    $stats[$r['system_id']]['avg_score'] = (int)round((float)$r['avg_score']);
    $stats[$r['system_id']]['scored']    = (int)$r['scored'];
    $scoreSum += (float)$r['avg_score'] * (int)$r['scored'];
    $scoredTotal += (int)$r['scored'];
}
$avgScoreAll = $scoredTotal ? (int)round($scoreSum / $scoredTotal) : null;

// Global totals: games/owned/copies only from count_for_totals systems
// Value/spent/wishlist include ALL systems
$countStats   = array_filter($statsRaw, fn($r) => in_array((int)$r['system_id'], $countSystemIds));
$totalGames   = array_sum(array_column($countStats, 'total_games'));
$totalOwned   = array_sum(array_column($countStats, 'owned'));
$totalCopies  = array_sum(array_column($countStats, 'total_copies'));
$totalSpent   = array_sum(array_column($statsRaw,   'total_spent'));
$totalUpgrade = array_sum(array_column($countStats, 'upgrades'));
$totalWish    = array_sum(array_column($statsRaw,   'wishlisted'));
$totalCibAll  = array_sum(array_column($statsRaw,   'cib_total'));
$totalOwnedVal= array_sum($valueBySys);

// Finished / Started (played statuses), when the user shows them: per system, and over the systems that count toward totals
$showPlayed = showPlayedCounters($user);
$play = $showPlayed ? playedCountersBySystem($user, $unit, $compContents) : [];
$playTot = ['finished' => 0, 'started' => 0, 'base' => 0];
foreach ($countSystemIds as $sid) foreach ($playTot as $k => $_) $playTot[$k] += $play[$sid][$k] ?? 0;
$playPct = fn(array $p, string $k) => ($p['base'] ?? 0) > 0 ? (int)round(($p[$k] ?? 0) / $p['base'] * 100) : 0;
$showGraphs = showValueGraphs($user);   // collection value graphs (assets/js/value-graph.js); off = nothing of them on the page
$graphsOpen = $showGraphs && !valueGraphsCollapsed($user);   // folded away with the card's Hide graphs button
$playTitle = fn(string $k) => t('dashboard.' . $k . '_title' . (($user['played_pct'] ?? 'all') === 'owned' ? '_owned' : ''));
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pageTitle(tRaw('common.nav.dashboard')) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<?= themeHead($user) ?>
<style>
  .dash-wrap { padding:28px 32px 60px; }
  .dash-title { font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case); font-size:2.2rem; color:var(--accent); letter-spacing:.06em; margin-bottom:6px; }
  .dash-sub   { font-size:.65rem; color:var(--muted); letter-spacing:.15em; text-transform:uppercase; margin-bottom:28px; }

  /* Overall stats */
  /* Boxes are at least 110px and grow to fit a wide value; each row fills the width */
  .overall-grid {
    display:flex; flex-wrap:wrap;
    gap:1px;
    background:var(--border);
    border:1px solid var(--border);
    margin-bottom:32px;
  }
  .overall-stat {
    background:var(--surface);
    padding:14px 10px;
    text-align:center;
    flex:1 1 110px;
    min-width:max-content;
  }
  .overall-val {
    font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case);
    font-size:calc(1.7rem * var(--stat-scale)); line-height:1;
    color:var(--accent2);
    white-space:nowrap;
  }
  .overall-val.blue   { color:var(--wiiu); }
  .overall-val.green  { color:var(--green); }
  .overall-val.orange { color:var(--orange); }
  .overall-val.red    { color:var(--red); }
  .overall-label { font-size:.58rem; color:var(--muted); letter-spacing:.18em; text-transform:uppercase; margin-top:4px; }

  /* System cards grid */
  .systems-grid {
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(320px,1fr));
    gap:12px;
  }

  .sys-card {
    background:var(--surface);
    border:1px solid var(--border2);
    padding:18px 20px;
    transition:border-color .15s;
    text-decoration:none;
    display:block;
    color:var(--text);
  }
  .sys-card:hover { border-color:var(--accent2); }
  .sys-card { position:relative; }
  .sys-card.dragging { opacity:.55; border-color:var(--accent2); }
  /* The whole card can be dragged to reorder (see the script at the bottom) */
  #sys-grid .sys-card { -webkit-user-drag:none; user-select:none; -webkit-touch-callout:none; }   /* no link preview on a long press */
  #sys-grid .sys-card img { -webkit-user-drag:none; pointer-events:none; }
  .sys-card.dragging { cursor:grabbing; box-shadow:0 10px 28px var(--shadow-color); z-index:3; }
  .overall-pct, .sys-stat-pct { font-family:var(--font-body); font-size:.7rem; color:var(--muted); margin-left:4px; letter-spacing:0; }
  .sys-card.hidden-sys { opacity:.4; }

  .sys-card-header {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    margin-bottom:12px;
  }
  .sys-name { font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case); font-size:1.3rem; color:var(--accent); letter-spacing:.06em; line-height:1; }
  .sys-short { font-size:.62rem; color:var(--muted); letter-spacing:.15em; text-transform:uppercase; margin-top:2px; }
  .sys-pct { font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case); font-size:2rem; color:var(--wiiu); line-height:1; }
  .sys-pct-label { font-size:.55rem; color:var(--muted); letter-spacing:.12em; text-transform:uppercase; text-align:right; }

  /* Progress bar */
  .sys-progress { height:3px; background:var(--border2); margin-bottom:14px; overflow:hidden; }
  .sys-progress-fill { height:100%; background:linear-gradient(90deg,var(--wiiu),var(--wiiu2)); transition:width .4s; }

  /* Stat row */
  .sys-stats { border-top:1px solid var(--border); padding-top:12px; display:flex; flex-direction:column; gap:0; }
  .sys-stat-row { display:flex; gap:0; }
  .sys-stat { flex:1; min-width:40px; text-align:center; padding:0 4px; border-right:1px solid var(--border); }
  .sys-stat:last-child { border-right:none; }
  .sys-stat-val { font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case); font-size:1.2rem; color:var(--text2); line-height:1; }
  .sys-stat-val.g { color:var(--green); }
  .sys-stat-val.o { color:var(--orange); }
  .sys-stat-val.b { color:var(--wiiu); }
  .sys-stat-label { font-size:.5rem; color:var(--muted); letter-spacing:.1em; text-transform:uppercase; margin-top:2px; }

  /* Condition bar: one segment per grade label, in the label's colour */
  .qual-bar { display:flex; height:4px; gap:1px; margin-bottom:14px; }
  .qual-seg { height:100%; transition:width .4s; }

  .section-head {
    font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case);
    font-size:1.1rem; color:var(--muted);
    letter-spacing:.1em; margin-bottom:12px;
    border-bottom:1px solid var(--border);
    padding-bottom:6px;
  }

  .hidden-toggle { display:flex; align-items:center; gap:8px; margin-bottom:16px; }
  .hidden-toggle label { font-size:.72rem; color:var(--muted); cursor:pointer; }

  /* Collection value over time (assets/js/value-graph.js) */
  .vg-card {
    background:var(--surface); border:1px solid var(--border2); padding:18px 20px; margin:32px 0 0;
    display:grid; grid-template-columns:1fr auto auto; grid-template-areas:"head views toggle" "body body body";
    gap:12px; align-items:start;
  }
  .vg-head   { grid-area:head; min-width:0; }
  .vg-views  { grid-area:views; }
  .vg-toggle { grid-area:toggle; }
  .vg-body   { grid-area:body; min-width:0; }
  .vg-views[hidden] { display:none; }
  .vg-label { font-size:.58rem; color:var(--muted); letter-spacing:.18em; text-transform:uppercase; }
  .vg-numrow { display:flex; flex-wrap:wrap; align-items:baseline; gap:4px 14px; margin-top:4px; }
  .vg-big { font-family:var(--font-display); font-weight:var(--display-weight); text-transform:var(--display-case); font-size:calc(2rem * var(--stat-scale)); line-height:1; color:var(--accent2); white-space:nowrap; }
  .vg-delta { font-size:.8rem; font-weight:600; white-space:nowrap; }
  .vg-delta.up, .vg-tip-ch.up { color:var(--green); }
  .vg-delta.down, .vg-tip-ch.down { color:var(--red); }
  .vg-delta.flat, .vg-tip-ch.flat { color:var(--muted); }
  .vg-range, .vg-split { font-size:.7rem; color:var(--muted); }
  .vg-split { margin-top:6px; }
  .vg-delta[hidden], .vg-range[hidden], .vg-split[hidden], .vg-since-line[hidden] { display:none; }

  .vg-seg { display:inline-flex; border:1px solid var(--border2); }
  .vg-segbtn, .vg-btn {
    min-height:44px; min-width:44px; padding:0 14px; background:var(--surface2); color:var(--text2);
    border:none; font:inherit; font-size:.72rem; letter-spacing:.06em; cursor:pointer;
  }
  .vg-segbtn + .vg-segbtn { border-left:1px solid var(--border2); }
  .vg-segbtn[aria-pressed="true"] { background:var(--accent2); color:var(--surface); font-weight:600; }
  .vg-btn { border:1px solid var(--border2); }
  .vg-segbtn:hover, .vg-btn:hover { color:var(--accent); }
  .vg-segbtn[aria-pressed="true"]:hover { color:var(--surface); }
  .vg-segbtn:focus-visible, .vg-btn:focus-visible, .vg-dot:focus-visible, .vg-leg:focus-visible { outline:2px solid var(--accent2); outline-offset:2px; }

  .vg-note { font-size:.75rem; color:var(--muted); margin:0; }
  .vg-empty { display:flex; align-items:center; gap:14px; border:1px dashed var(--border2); padding:22px 18px; font-size:.75rem; color:var(--muted); }
  .vg-empty p { margin:0; line-height:1.5; }
  .vg-empty strong { color:var(--text2); }
  .vg-empty-dot { flex:none; width:10px; height:10px; border-radius:50%; background:var(--accent2); }

  .vg-chart { position:relative; height:240px; margin-top:20px; }
  .vg-svg { position:absolute; inset:0; width:100%; height:100%; overflow:visible; }
  .vg-grid { stroke:var(--border); stroke-width:1; }
  .vg-line { fill:none; stroke:var(--accent2); stroke-width:2; stroke-linejoin:round; }
  .vg-area { fill:var(--accent2); opacity:.14; }
  .vg-band { stroke:var(--surface); stroke-width:1; }
  .vg-ylabels, .vg-dots { position:absolute; inset:0; pointer-events:none; }
  .vg-ylab { position:absolute; left:0; transform:translateY(-100%); font-size:.6rem; color:var(--muted); padding-bottom:1px; white-space:nowrap; }
  .vg-dot { position:absolute; width:44px; height:44px; transform:translate(-50%,-50%); background:none; border:none; padding:0; cursor:pointer; pointer-events:auto; display:flex; align-items:center; justify-content:center; }
  .vg-dot-mark { width:8px; height:8px; border-radius:50%; background:var(--accent2); box-shadow:0 0 0 2px var(--surface); }
  .vg-dot.on .vg-dot-mark, .vg-dot:hover .vg-dot-mark, .vg-dot:focus-visible .vg-dot-mark { width:12px; height:12px; }
  .vg-tip {
    position:absolute; top:8px; z-index:4; margin-left:14px; min-width:160px; max-width:280px; pointer-events:none;
    background:var(--surface2); border:1px solid var(--border2); box-shadow:0 6px 18px var(--shadow-color); padding:8px 10px; font-size:.7rem; color:var(--text2);
  }
  .vg-tip[hidden] { display:none; }
  .vg-tip.left { transform:translateX(-100%); margin-left:-14px; }
  .vg-tip-date { color:var(--muted); }
  .vg-tip-total { font-family:var(--font-display); font-weight:var(--display-weight); font-size:1.1rem; color:var(--accent2); margin:2px 0; }
  .vg-tip-list { margin-top:6px; border-top:1px solid var(--border); padding-top:5px; display:flex; flex-direction:column; gap:2px; }
  .vg-tip-list > div { display:flex; align-items:center; gap:6px; }
  .vg-tip-n { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .vg-tip-pct { color:var(--muted); min-width:34px; text-align:right; }
  .vg-sw { flex:none; display:inline-block; width:10px; height:10px; border:1px solid var(--surface); }
  .vg-xlabels { position:relative; height:20px; margin-top:4px; }
  .vg-xlab { position:absolute; top:0; transform:translateX(-50%); font-size:.6rem; color:var(--muted); white-space:nowrap; }
  .vg-legend { display:flex; flex-wrap:wrap; gap:2px 6px; margin-top:6px; }
  .vg-legend[hidden] { display:none; }
  .vg-leg { min-height:44px; display:inline-flex; align-items:center; gap:6px; background:none; border:none; color:var(--text2); font:inherit; font-size:.7rem; padding:0 6px; cursor:pointer; }
  .vg-leg[aria-pressed="true"] { color:var(--accent); text-decoration:underline; }

  .vg-presets-row { display:flex; flex-wrap:wrap; align-items:center; gap:8px 14px; margin-top:12px; }
  .vg-hint { font-size:.68rem; color:var(--muted); }
  .vg-strip { position:relative; height:56px; margin-top:10px; }
  .vg-strip-plot { position:absolute; top:0; bottom:0; left:22px; right:22px; background:var(--surface2); border:1px solid var(--border); box-sizing:border-box; }
  .vg-strip-svg { position:absolute; inset:0; width:100%; height:100%; }
  .vg-strip-line { fill:none; stroke:var(--muted); stroke-width:1; opacity:.8; }
  .vg-strip-area { fill:var(--muted); opacity:.12; }
  .vg-dim { position:absolute; top:0; bottom:0; background:var(--surface); opacity:.6; }
  .vg-dim-l { left:0; }
  .vg-dim-r { right:0; }
  .vg-win { position:absolute; top:-1px; bottom:-1px; border-top:2px solid var(--accent2); border-bottom:2px solid var(--accent2); box-sizing:border-box; }
  .vg-handle { position:absolute; inset:0; width:100%; height:56px; margin:0; background:none; -webkit-appearance:none; appearance:none; pointer-events:none; z-index:2; }
  .vg-handle:focus { outline:none; }
  .vg-handle::-webkit-slider-runnable-track { height:56px; background:transparent; border:none; }
  .vg-handle::-moz-range-track { height:56px; background:transparent; border:none; }
  .vg-handle::-webkit-slider-thumb {
    -webkit-appearance:none; appearance:none; width:44px; height:56px; border:none; cursor:ew-resize; pointer-events:auto;
    background:linear-gradient(90deg, transparent 15px, var(--accent2) 15px, var(--accent2) 29px, transparent 29px);
  }
  .vg-handle::-moz-range-thumb {
    width:44px; height:56px; border:none; border-radius:0; cursor:ew-resize; pointer-events:auto;
    background:linear-gradient(90deg, transparent 15px, var(--accent2) 15px, var(--accent2) 29px, transparent 29px);
  }
  .vg-handle:focus-visible::-webkit-slider-thumb { box-shadow:inset 0 0 0 2px var(--accent); }
  .vg-handle:focus-visible::-moz-range-thumb { box-shadow:inset 0 0 0 2px var(--accent); }
  .vg-strip-dates { display:flex; justify-content:space-between; padding:4px 22px 0; font-size:.6rem; color:var(--muted); }
  .vg-since-line { font-size:.68rem; color:var(--muted); margin:-4px 0 12px; }

  /* Small trend line in a system card: display-only (the card is the link and the drag handle) */
  .vg-trend { border-top:1px solid var(--border); padding-top:8px; margin-top:8px; pointer-events:none; }
  .vg-trend-cap { display:flex; justify-content:space-between; gap:8px; font-size:.55rem; color:var(--muted); letter-spacing:.1em; text-transform:uppercase; }
  .vg-trend-cap .vg-delta { font-size:.65rem; letter-spacing:0; text-transform:none; }
  .vg-trend-note { font-size:.65rem; color:var(--muted); margin-top:4px; }
  .vg-spark { position:relative; height:44px; margin-top:4px; }
  .vg-spark svg { position:absolute; inset:0; width:100%; height:100%; overflow:visible; }
  .vg-spark path { fill:none; stroke:var(--wiiu); stroke-width:2; stroke-linejoin:round; }
  .vg-spark-dot { position:absolute; right:0; width:6px; height:6px; border-radius:50%; background:var(--wiiu); transform:translate(50%,-50%); }

  @media(max-width:700px) {
    .dash-wrap { padding:16px 14px 40px; }
    .systems-grid { grid-template-columns:1fr; }
    .vg-card { grid-template-columns:1fr; grid-template-areas:"head" "views" "body" "toggle"; padding:14px; }
    .vg-toggle { width:100%; }
    .vg-views, .vg-presets { display:flex; width:100%; }
    .vg-views .vg-segbtn, .vg-presets .vg-segbtn { flex:1; padding:0 4px; }
    .vg-chart { height:190px; }
    .vg-y-1, .vg-y-3 { display:none; }
    .vg-presets-row { flex-direction:column; align-items:stretch; }
  }
</style>
<?= csrfScript() ?>
<?= updateRefreshScript($user) ?>
<?= siteBackupAutoScript($user) ?>
<?= appScript(['dashboard', 'val']) ?>
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/collection.php" class="site-logo" style="text-decoration:none"><?= siteLogoHtml() ?></a>
  <?= siteNav($user, 'dashboard') ?>
</header>

<div class="dash-wrap">
  <div class="dash-title"><?= t('common.nav.dashboard') ?></div>
  <div class="dash-sub"><?= t('dashboard.subtitle', ['user' => $user['username']]) ?></div>
  <?= updateNoticeHtml($user) ?>
  <?= igdbNoticeHtml($user) ?>

  <!-- OVERALL STATS -->
  <div class="overall-grid">
    <div class="overall-stat">
      <div class="overall-val"><?= count($systems) ?></div>
      <div class="overall-label"><?= t('dashboard.systems') ?></div>
    </div>
    <div class="overall-stat">
      <div class="overall-val orange"><?= fmtNum($totalGames) ?></div>
      <div class="overall-label"><?= t($editionMode === 'one' ? 'dashboard.total_games' : 'ed.total_editions') ?></div>
    </div>
    <div class="overall-stat">
      <div class="overall-val blue"><?= fmtNum($totalOwned) ?></div>
      <div class="overall-label"><?= t('dashboard.owned') ?></div>
    </div>
    <div class="overall-stat">
      <div class="overall-val"><?= $totalGames > 0 ? round($totalOwned/$totalGames*100) : 0 ?>%</div>
      <div class="overall-label"><?= t('dashboard.completion') ?></div>
    </div>
    <div class="overall-stat">
      <div class="overall-val green"><?= fmtNum($totalCopies) ?></div>
      <div class="overall-label"><?= t('dashboard.total_copies') ?></div>
    </div>
    <div class="overall-stat">
      <div class="overall-val orange"><?= fmtNum($totalUpgrade) ?></div>
      <div class="overall-label"><?= t('dashboard.upgrades') ?></div>
    </div>
    <div class="overall-stat">
      <div class="overall-val blue"><?= fmtNum($totalWish) ?></div>
      <div class="overall-label"><?= t('dashboard.wishlisted') ?></div>
    </div>
    <div class="overall-stat">
      <div class="overall-val"><?= money($totalSpent, 0) ?></div>
      <div class="overall-label"><?= t('dashboard.total_spent') ?></div>
    </div>
    <div class="overall-stat">
      <div class="overall-val blue"><?= money($totalCibAll, 0) ?></div>
      <div class="overall-label"><?= t('dashboard.cib_all') ?></div>
    </div>
    <div class="overall-stat">
      <div class="overall-val"><?= money($totalOwnedVal, 0) ?></div>
      <div class="overall-label"><?= t('dashboard.owned_value') ?></div>
    </div>
    <?php if ($showPlayed): ?>
    <div class="overall-stat" title="<?= $playTitle('finished') ?>">
      <div class="overall-val green"><?= fmtNum($playTot['finished']) ?><span class="overall-pct"><?= $playPct($playTot, 'finished') ?>%</span></div>
      <div class="overall-label"><?= t('coll.finished') ?></div>
    </div>
    <div class="overall-stat" title="<?= $playTitle('started') ?>">
      <div class="overall-val orange"><?= fmtNum($playTot['started']) ?><span class="overall-pct"><?= $playPct($playTot, 'started') ?>%</span></div>
      <div class="overall-label"><?= t('coll.started') ?></div>
    </div>
    <?php endif; ?>
    <?php if ($avgScoreAll !== null): $al = gradeLabelForScore($avgScoreAll); ?>
    <div class="overall-stat" title="<?= t('dashboard.avg_title', ['n' => $scoredTotal]) ?>">
      <div class="overall-val" style="color:<?= htmlspecialchars($al['color'] ?? 'var(--wiiu2)') ?>"><?= $avgScoreAll ?></div>
      <div class="overall-label"><?= t('dashboard.avg_score') ?></div>
    </div>
    <?php endif; ?>
  </div>


  <!-- VISIBLE SYSTEMS -->
  <?php
  $visibleSystems = array_values(array_filter($systems, fn($s) => $s['visible']));
  $hiddenSystems  = array_values(array_filter($systems, fn($s) => !$s['visible']));

  // Helper to render sys-stats rows
  function sysStatsHtml(array $st, int $sysId): string {
    global $gradeLabels, $graphsOpen;
    $qualTotal = array_sum($st['labels']);
    $html  = '<div class="sys-stats">';
    $html .= '<div class="sys-stat-row">';
    $html .= '<div class="sys-stat"><div class="sys-stat-val">'.money($st['total_spent'], 0).'</div><div class="sys-stat-label">'.t('dashboard.spent').'</div></div>';
    $html .= '<div class="sys-stat"><div class="sys-stat-val blue">'.money($st['owned_value'] ?? 0, 0).'</div><div class="sys-stat-label">'.t('dashboard.value_short').'</div></div>';
    $html .= '<div class="sys-stat"><div class="sys-stat-val" style="color:var(--muted)">'.money($st['cib_total'] ?? 0, 0).'</div><div class="sys-stat-label">'.t('dashboard.cib_all').'</div></div>';
    if ($st['avg_score'] !== null) {
      $al = gradeLabelForScore($st['avg_score']);
      $html .= '<div class="sys-stat" title="'.t('dashboard.avg_title', ['n' => (int)$st['scored']]).'"><div class="sys-stat-val" style="color:'.htmlspecialchars($al['color'] ?? 'var(--wiiu2)').'">'.(int)$st['avg_score'].'</div><div class="sys-stat-label">'.t('dashboard.avg_score').'</div></div>';
    }
    $html .= '</div>';
    $html .= '<div class="sys-stat-row" style="border-top:1px solid var(--border);padding-top:8px;margin-top:8px">';
    if ($qualTotal > 0) {
      $html .= '<div class="sys-stat"><div class="sys-stat-val" style="font-size:.7rem;display:flex;gap:3px;justify-content:center;flex-wrap:wrap;line-height:1.4">';
      foreach ($gradeLabels as $l) {
        $n = $st['labels'][$l['id']] ?? 0;
        if ($n > 0) $html .= '<span style="color:'.htmlspecialchars($l['color']).'" title="'.htmlspecialchars($l['name']).': '.$n.'">'.$n.htmlspecialchars($l['short'] !== '' ? $l['short'] : mb_substr($l['name'], 0, 1)).'</span>';
      }
      $html .= '</div><div class="sys-stat-label">'.t('dashboard.condition').'</div></div>';
    }
    $html .= '<div class="sys-stat"><div class="sys-stat-val g">'.(int)$st['total_copies'].'</div><div class="sys-stat-label">'.t('dashboard.copies').'</div></div>';
    $html .= '<div class="sys-stat"><div class="sys-stat-val b">'.(int)$st['wishlisted'].'</div><div class="sys-stat-label">'.t('common.nav.wishlist').'</div></div>';
    $html .= '<div class="sys-stat"><div class="sys-stat-val o">'.(int)$st['upgrades'].'</div><div class="sys-stat-label">'.t('dashboard.upgrade').'</div></div>';
    $html .= '</div>';
    // Value trend (filled by value-graph.js; display-only, the card stays one link / drag handle)
    if ($graphsOpen) {
      $html .= '<div class="vg-trend" data-sys="'.(int)$sysId.'"><div class="vg-trend-cap"><span>'.t('val.trend').'</span></div><div class="vg-spark"></div></div>';
    }
    $html .= '</div>';
    return $html;
  }

  function sysCardHeader(array $s, array $st, bool $showIcons): string {
    global $nGamesKey;
    $icon = '';
    global $user;
    $iconFile = $showIcons ? systemIcon($s, $user) : null;
    if ($iconFile) {
      $icon = '<img src="'.BASE_URL.'/uploads/icons/'.htmlspecialchars($iconFile).'" alt="" style="width:28px;height:28px;object-fit:contain;flex-shrink:0;margin-right:8px">';
    }
    $pct = $st['total_games'] > 0 ? round($st['owned']/$st['total_games']*100) : 0;
    $html  = '<div class="sys-card-header">';
    $html .= '<div style="display:flex;align-items:center">'.$icon.'<div>';
    $html .= '<div class="sys-name">'.htmlspecialchars($s['name']).'</div>';
    $html .= '<div class="sys-short">'.htmlspecialchars(systemRegion($s)).' · '.t($nGamesKey, ['n' => fmtNum($st['total_games'])]).'</div>';
    $html .= '</div></div>';
    $html .= '<div style="text-align:right"><div class="sys-pct">'.$pct.'%</div>';
    $html .= '<div class="sys-pct-label">'.(int)$st['owned'].' / '.(int)$st['total_games'].'</div></div>';
    $html .= '</div>';
    return $html;
  }

  function qualBarHtml(array $st): string {
    global $gradeLabels;
    $qualTotal = array_sum($st['labels']);
    if ($qualTotal === 0) return '<div style="height:4px;margin-bottom:14px"></div>';
    $html = '<div class="qual-bar">';
    foreach ($gradeLabels as $l) {
      $n = $st['labels'][$l['id']] ?? 0;
      $w = round($n/$qualTotal*100, 2);
      if ($w > 0) $html .= '<div class="qual-seg" style="width:'.$w.'%;background:'.htmlspecialchars($l['color']).'" title="'.htmlspecialchars($l['name']).': '.$n.'"></div>';
    }
    return $html.'</div>';
  }

  function progressHtml(array $st): string {
    $pct = $st['total_games'] > 0 ? round($st['owned']/$st['total_games']*100) : 0;
    return '<div class="sys-progress"><div class="sys-progress-fill" style="width:'.$pct.'%"></div></div>';
  }
  ?>

  <?php if ($visibleSystems): ?>
  <div class="section-head"><?= t('dashboard.active_systems') ?></div>
  <?php if ($showGraphs): ?><p class="vg-since-line" id="vg-since-line" hidden></p><?php endif; ?>
  <div class="systems-grid" id="sys-grid" style="margin-bottom:32px">
    <?php foreach ($visibleSystems as $s):
      $st = $stats[$s['id']] ?? ['total_games'=>0,'owned'=>0,'total_copies'=>0,'upgrades'=>0,'wishlisted'=>0,'total_spent'=>0,'owned_value'=>0,'cib_total'=>0,'labels'=>[],'avg_score'=>null,'scored'=>0];
    ?>
    <a href="<?= BASE_URL ?>/collection.php?s=<?= $s['id'] ?>" class="sys-card" data-sys="<?= (int)$s['id'] ?>" draggable="false">
      <?= sysCardHeader($s, $st, $showIcons) ?>
      <?= progressHtml($st) ?>
      <?= qualBarHtml($st) ?>
      <?= sysStatsHtml($st, (int)$s['id']) ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- HIDDEN SYSTEMS -->
  <?php if ($hiddenSystems): ?>
  <div class="hidden-toggle">
    <input type="checkbox" id="show-hidden" onchange="toggleHidden(this)">
    <label for="show-hidden"><?= t('dashboard.show_hidden', ['n' => count($hiddenSystems)]) ?></label>
  </div>
  <div class="systems-grid" id="hidden-systems" style="display:none">
    <?php foreach ($hiddenSystems as $s):
      $st = $stats[$s['id']] ?? ['total_games'=>0,'owned'=>0,'total_copies'=>0,'upgrades'=>0,'wishlisted'=>0,'total_spent'=>0,'owned_value'=>0,'cib_total'=>0,'labels'=>[],'avg_score'=>null,'scored'=>0];
    ?>
    <div class="sys-card hidden-sys" data-sys="<?= (int)$s['id'] ?>" onclick="activateSystem(<?= $s['id'] ?>)" style="cursor:pointer" title="<?= t('dashboard.click_to_show') ?>">
      <?= sysCardHeader($s, $st, $showIcons) ?>
      <?= progressHtml($st) ?>
      <?= qualBarHtml($st) ?>
      <?= sysStatsHtml($st, (int)$s['id']) ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- COLLECTION VALUE OVER TIME (assets/js/value-graph.js): switched off in Settings › Language & appearance = not on the page at all;
       Hide graphs only folds the card away (users.value_graph_collapsed) -->
  <?php if ($showGraphs): ?>
  <section class="vg-card" id="vg" aria-label="<?= t('val.title') ?>" data-base="<?= htmlspecialchars(BASE_URL) ?>"
           data-open="<?= $graphsOpen ? '1' : '0' ?>" data-total="<?= round((float)$totalOwnedVal, 2) ?>">
    <div class="vg-head">
      <div class="vg-label"><?= t('val.title') ?></div>
      <div class="vg-numrow">
        <span class="vg-big" id="vg-big"><?= money($totalOwnedVal, 0) ?></span>
        <span class="vg-delta" id="vg-delta" hidden></span>
        <span class="vg-range" id="vg-range" hidden></span>
      </div>
      <div class="vg-split" id="vg-split" hidden></div>
    </div>
    <div class="vg-seg vg-views" id="vg-views" role="group" aria-label="<?= t('val.view_group') ?>" hidden>
      <button type="button" class="vg-segbtn" data-view="total" aria-pressed="true"><?= t('val.view_total') ?></button>
      <button type="button" class="vg-segbtn" data-view="system" aria-pressed="false"><?= t('val.view_system') ?></button>
      <button type="button" class="vg-segbtn" data-view="share" aria-pressed="false"><?= t('val.view_share') ?></button>
    </div>
    <button type="button" class="vg-btn vg-toggle" id="vg-toggle" aria-controls="vg-body" aria-expanded="<?= $graphsOpen ? 'true' : 'false' ?>"><?= t($graphsOpen ? 'val.hide' : 'val.show') ?></button>
    <div class="vg-body" id="vg-body"><?= $graphsOpen ? '' : '<p class="vg-note">'.t('val.hidden_note').'</p>' ?></div>
  </section>
  <?php endif; ?>

</div>

<script>
const DASH_BASE = <?= json_encode(BASE_URL) ?>;

function toggleHidden(cb) {
  document.getElementById('hidden-systems').style.display = cb.checked ? 'grid' : 'none';
}

async function activateSystem(systemId) {
  if (!confirm(tRaw('dashboard.confirm_show'))) return;
  // Get current prefs, set this one to visible
  const res = await fetch(`${DASH_BASE}/api/system_prefs.php`).then(r=>r.json());
  if (!res.ok) return;
  const prefs = res.systems.map((s,i) => ({
    system_id:  s.id,
    visible:    s.id == systemId ? true : s.visible,
    sort_order: s.user_sort_order ?? i,
    count_for_totals: s.count_for_totals,   // keep it (the API would reset it to "counts")
  }));
  await fetch(`${DASH_BASE}/api/system_prefs.php`, {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({prefs})
  });
  window.location.reload();
}

// ── Reorder systems by dragging a whole card (saved straight away). A plain click still opens the system.
// Mouse: the drag starts once the pointer moves a few pixels. Touch: hold the card briefly, then drag
// (a quick swipe still scrolls the page).
(() => {
  const grid = document.getElementById('sys-grid');
  if (!grid) return;
  let pending = null, card = null, holdTimer = null, justDragged = false;

  const start = () => {
    if (!pending) return;
    card = pending.card;
    card.classList.add('dragging');
    try { card.setPointerCapture(pending.id); } catch { /* the pointer may be gone */ }
  };
  const cancel = () => { clearTimeout(holdTimer); pending = null; };

  grid.addEventListener('pointerdown', e => {
    const c = e.target.closest('.sys-card');
    if (!c || e.button > 0) return;
    pending = { card: c, id: e.pointerId, x: e.clientX, y: e.clientY, touch: e.pointerType !== 'mouse' };
    if (pending.touch) holdTimer = setTimeout(start, 350);
  });
  grid.addEventListener('pointermove', e => {
    if (!card) {
      if (!pending) return;
      const moved = Math.hypot(e.clientX - pending.x, e.clientY - pending.y);
      if (pending.touch) { if (moved > 8) cancel(); return; }   // moved before the hold: it's a scroll
      if (moved > 6) start();
      if (!card) return;
    }
    const over = document.elementFromPoint(e.clientX, e.clientY)?.closest('#sys-grid .sys-card');
    if (!over || over === card) return;
    const cards = [...grid.querySelectorAll('.sys-card')];
    grid.insertBefore(card, cards.indexOf(over) > cards.indexOf(card) ? over.nextSibling : over);
  });
  // While dragging on a touch screen the page must not scroll
  grid.addEventListener('touchmove', e => { if (card) e.preventDefault(); }, { passive: false });
  grid.addEventListener('contextmenu', e => { if (card || pending?.touch) e.preventDefault(); });
  // The click that ends a drag must not open the system
  grid.addEventListener('click', e => { if (justDragged) { e.preventDefault(); e.stopPropagation(); justDragged = false; } }, true);

  const drop = async () => {
    cancel();
    if (!card) return;
    card.classList.remove('dragging');
    card = null;
    justDragged = true;
    setTimeout(() => { justDragged = false; }, 400);
    // Visible systems in their new order, then the hidden ones as they were
    const order = [...grid.querySelectorAll('.sys-card')].map(c => +c.dataset.sys);
    const res = await fetch(`${DASH_BASE}/api/system_prefs.php`).then(r => r.json()).catch(() => ({ ok: false }));
    if (!res.ok) return;
    const rest = res.systems.filter(s => !order.includes(+s.id));
    const prefs = [...order.map(id => res.systems.find(s => +s.id === id)).filter(Boolean), ...rest].map((s, i) => ({
      system_id: s.id, visible: s.visible, sort_order: i, count_for_totals: s.count_for_totals,
    }));
    const ok = (await fetch(`${DASH_BASE}/api/system_prefs.php`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ prefs }) })
      .then(r => r.json()).catch(() => ({ ok: false }))).ok;
    const tst = document.getElementById('dash-toast');
    if (tst) { tst.textContent = tRaw(ok ? 'dashboard.order_saved' : 'common.error'); tst.classList.add('show'); setTimeout(() => tst.classList.remove('show'), 1800); }
  };
  grid.addEventListener('pointerup', drop);
  grid.addEventListener('pointercancel', drop);
})();
</script>
<?php if ($showGraphs): ?><script src="<?= BASE_URL ?>/assets/js/value-graph.js?v=<?= @filemtime(__DIR__.'/assets/js/value-graph.js') ?>"></script><?php endif; ?>
<div class="toast" id="dash-toast" role="status"></div>
</body>
</html>
