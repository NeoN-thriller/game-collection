<?php
require_once __DIR__ . '/config.php';
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

// Stats per system (all systems — cards always show their own %)
$statsSt = db()->prepare("
    SELECT
        g.system_id,
        COUNT(DISTINCT g.id)                                         AS total_games,
        COUNT(DISTINCT CASE WHEN ce.owned=1 THEN g.id END)          AS owned,
        COUNT(DISTINCT CASE WHEN ce.wishlist=1 THEN g.id END)       AS wishlisted,
        COUNT(CASE WHEN ce.owned=1 THEN ce.id END)                  AS total_copies,
        COUNT(CASE WHEN ce.owned=1 AND ce.upgrade=1 THEN ce.id END) AS upgrades,
        COALESCE(SUM(CASE WHEN ce.owned=1 THEN ce.price_paid END),0) AS total_spent,
        COALESCE(SUM(g.cib_price),0)                                      AS cib_total,
        COALESCE(SUM(CASE WHEN ce.owned=1 THEN
            CASE ce.value_price_type
                WHEN 'loose' THEN g.loose_price
                WHEN 'new'   THEN g.new_price
                ELSE g.cib_price END
            END),0) AS owned_value
    FROM games g
    LEFT JOIN collection_entries ce ON ce.game_id=g.id AND ce.user_id=?
    WHERE g.active=1
    GROUP BY g.system_id
");
$statsSt->execute([$user['id']]);
$statsRaw = $statsSt->fetchAll();
$stats = [];
foreach ($statsRaw as $r) $stats[$r['system_id']] = $r + ['labels'=>[], 'avg_score'=>null, 'scored'=>0];

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
$totalOwnedVal= array_sum(array_column($statsRaw,   'owned_value'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Game Collection</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<style>
  .dash-wrap { padding:28px 32px 60px; }
  .dash-title { font-family:'Bebas Neue',sans-serif; font-size:2.2rem; color:var(--accent); letter-spacing:.06em; margin-bottom:6px; }
  .dash-sub   { font-size:.65rem; color:var(--muted); letter-spacing:.15em; text-transform:uppercase; margin-bottom:28px; }

  /* Overall stats */
  .overall-grid {
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(110px,1fr));
    gap:1px;
    background:var(--border);
    border:1px solid var(--border);
    margin-bottom:32px;
  }
  .overall-stat {
    background:var(--surface);
    padding:14px 6px;
    text-align:center;
    overflow:hidden;
    min-width:0;
  }
  .overall-val {
    font-family:'Bebas Neue',sans-serif;
    font-size:1.7rem; line-height:1;
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
  .sys-card.hidden-sys { opacity:.4; }

  .sys-card-header {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    margin-bottom:12px;
  }
  .sys-name { font-family:'Bebas Neue',sans-serif; font-size:1.3rem; color:var(--accent); letter-spacing:.06em; line-height:1; }
  .sys-short { font-size:.62rem; color:var(--muted); letter-spacing:.15em; text-transform:uppercase; margin-top:2px; }
  .sys-pct { font-family:'Bebas Neue',sans-serif; font-size:2rem; color:var(--wiiu); line-height:1; }
  .sys-pct-label { font-size:.55rem; color:var(--muted); letter-spacing:.12em; text-transform:uppercase; text-align:right; }

  /* Progress bar */
  .sys-progress { height:3px; background:var(--border2); margin-bottom:14px; overflow:hidden; }
  .sys-progress-fill { height:100%; background:linear-gradient(90deg,var(--wiiu),var(--wiiu2)); transition:width .4s; }

  /* Stat row */
  .sys-stats { border-top:1px solid var(--border); padding-top:12px; display:flex; flex-direction:column; gap:0; }
  .sys-stat-row { display:flex; gap:0; }
  .sys-stat { flex:1; min-width:40px; text-align:center; padding:0 4px; border-right:1px solid var(--border); }
  .sys-stat:last-child { border-right:none; }
  .sys-stat-val { font-family:'Bebas Neue',sans-serif; font-size:1.2rem; color:var(--text2); line-height:1; }
  .sys-stat-val.g { color:var(--green); }
  .sys-stat-val.o { color:var(--orange); }
  .sys-stat-val.b { color:var(--wiiu); }
  .sys-stat-label { font-size:.5rem; color:var(--muted); letter-spacing:.1em; text-transform:uppercase; margin-top:2px; }

  /* Condition bar: one segment per grade label, in the label's colour */
  .qual-bar { display:flex; height:4px; gap:1px; margin-bottom:14px; }
  .qual-seg { height:100%; transition:width .4s; }

  .section-head {
    font-family:'Bebas Neue',sans-serif;
    font-size:1.1rem; color:var(--muted);
    letter-spacing:.1em; margin-bottom:12px;
    border-bottom:1px solid var(--border);
    padding-bottom:6px;
  }

  .hidden-toggle { display:flex; align-items:center; gap:8px; margin-bottom:16px; }
  .hidden-toggle label { font-size:.72rem; color:var(--muted); cursor:pointer; }

  @media(max-width:700px) {
    .dash-wrap { padding:16px 14px 40px; }
    .systems-grid { grid-template-columns:1fr; }
  }
</style>
<?= csrfScript() ?>
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/collection.php" class="site-logo" style="text-decoration:none">Game <span>Collection</span></a>
  <nav class="site-nav">
    <span class="nav-user">👤 <?= htmlspecialchars($user['username']) ?></span>
    <a href="<?= BASE_URL ?>/wishlist.php" class="nav-link">Wishlist</a>
    <a href="<?= BASE_URL ?>/collection.php" class="nav-link">Collection</a>
    <?php if (isAdmin()): ?>
    <a href="<?= BASE_URL ?>/admin.php" class="nav-link">Admin</a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/settings.php" class="nav-link">Settings</a>
    <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link">Sign Out</a>
  </nav>
</header>

<div class="dash-wrap">
  <div class="dash-title">Dashboard</div>
  <div class="dash-sub">Overall collection overview — <?= htmlspecialchars($user['username']) ?></div>

  <!-- OVERALL STATS -->
  <div class="overall-grid">
    <div class="overall-stat">
      <div class="overall-val"><?= count($systems) ?></div>
      <div class="overall-label">Systems</div>
    </div>
    <div class="overall-stat">
      <div class="overall-val"><?= number_format($totalGames) ?></div>
      <div class="overall-label">Total Games</div>
    </div>
    <div class="overall-stat">
      <div class="overall-val blue"><?= number_format($totalOwned) ?></div>
      <div class="overall-label">Owned</div>
    </div>
    <div class="overall-stat">
      <div class="overall-val"><?= $totalGames > 0 ? round($totalOwned/$totalGames*100) : 0 ?>%</div>
      <div class="overall-label">Completion</div>
    </div>
    <div class="overall-stat">
      <div class="overall-val green"><?= number_format($totalCopies) ?></div>
      <div class="overall-label">Total Copies</div>
    </div>
    <div class="overall-stat">
      <div class="overall-val orange"><?= number_format($totalUpgrade) ?></div>
      <div class="overall-label">Upgrades</div>
    </div>
    <div class="overall-stat">
      <div class="overall-val blue"><?= number_format($totalWish) ?></div>
      <div class="overall-label">Wishlisted</div>
    </div>
    <div class="overall-stat">
      <div class="overall-val">€<?= number_format($totalSpent, 0) ?></div>
      <div class="overall-label">Total Spent</div>
    </div>
    <div class="overall-stat">
      <div class="overall-val blue">€<?= number_format($totalCibAll, 0) ?></div>
      <div class="overall-label">CIB All</div>
    </div>
    <div class="overall-stat">
      <div class="overall-val green">€<?= number_format($totalOwnedVal, 0) ?></div>
      <div class="overall-label">Owned Value</div>
    </div>
    <?php if ($avgScoreAll !== null): $al = gradeLabelForScore($avgScoreAll); ?>
    <div class="overall-stat" title="Average over <?= $scoredTotal ?> point-graded copies">
      <div class="overall-val" style="color:<?= htmlspecialchars($al['color'] ?? 'var(--wiiu2)') ?>"><?= $avgScoreAll ?></div>
      <div class="overall-label">Avg Score</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- VISIBLE SYSTEMS -->
  <?php
  $visibleSystems = array_values(array_filter($systems, fn($s) => $s['visible']));
  $hiddenSystems  = array_values(array_filter($systems, fn($s) => !$s['visible']));

  // Helper to render sys-stats rows
  function sysStatsHtml(array $st): string {
    global $gradeLabels;
    $qualTotal = array_sum($st['labels']);
    $html  = '<div class="sys-stats">';
    $html .= '<div class="sys-stat-row">';
    $html .= '<div class="sys-stat"><div class="sys-stat-val">€'.number_format((float)$st['total_spent'],0).'</div><div class="sys-stat-label">Spent</div></div>';
    $html .= '<div class="sys-stat"><div class="sys-stat-val blue">€'.number_format((float)($st['owned_value']??0),0).'</div><div class="sys-stat-label">Val</div></div>';
    $html .= '<div class="sys-stat"><div class="sys-stat-val" style="color:var(--muted)">€'.number_format((float)($st['cib_total']??0),0).'</div><div class="sys-stat-label">CIB All</div></div>';
    if ($st['avg_score'] !== null) {
      $al = gradeLabelForScore($st['avg_score']);
      $html .= '<div class="sys-stat" title="Average over '.(int)$st['scored'].' point-graded copies"><div class="sys-stat-val" style="color:'.htmlspecialchars($al['color'] ?? 'var(--wiiu2)').'">'.(int)$st['avg_score'].'</div><div class="sys-stat-label">Avg Score</div></div>';
    }
    $html .= '</div>';
    $html .= '<div class="sys-stat-row" style="border-top:1px solid var(--border);padding-top:8px;margin-top:8px">';
    if ($qualTotal > 0) {
      $html .= '<div class="sys-stat"><div class="sys-stat-val" style="font-size:.7rem;display:flex;gap:3px;justify-content:center;flex-wrap:wrap;line-height:1.4">';
      foreach ($gradeLabels as $l) {
        $n = $st['labels'][$l['id']] ?? 0;
        if ($n > 0) $html .= '<span style="color:'.htmlspecialchars($l['color']).'" title="'.htmlspecialchars($l['name']).': '.$n.'">'.$n.htmlspecialchars($l['short'] !== '' ? $l['short'] : mb_substr($l['name'], 0, 1)).'</span>';
      }
      $html .= '</div><div class="sys-stat-label">Condition</div></div>';
    }
    $html .= '<div class="sys-stat"><div class="sys-stat-val g">'.(int)$st['total_copies'].'</div><div class="sys-stat-label">Copies</div></div>';
    $html .= '<div class="sys-stat"><div class="sys-stat-val b">'.(int)$st['wishlisted'].'</div><div class="sys-stat-label">Wishlist</div></div>';
    $html .= '<div class="sys-stat"><div class="sys-stat-val o">'.(int)$st['upgrades'].'</div><div class="sys-stat-label">Upgrade</div></div>';
    $html .= '</div></div>';
    return $html;
  }

  function sysCardHeader(array $s, array $st, bool $showIcons): string {
    $icon = '';
    if ($showIcons && !empty($s['icon_image'])) {
      $icon = '<img src="'.BASE_URL.'/uploads/icons/'.htmlspecialchars($s['icon_image']).'" alt="" style="width:28px;height:28px;object-fit:contain;flex-shrink:0;margin-right:8px">';
    }
    $pct = $st['total_games'] > 0 ? round($st['owned']/$st['total_games']*100) : 0;
    $html  = '<div class="sys-card-header">';
    $html .= '<div style="display:flex;align-items:center">'.$icon.'<div>';
    $html .= '<div class="sys-name">'.htmlspecialchars($s['name']).'</div>';
    $html .= '<div class="sys-short">'.htmlspecialchars($s['region']).' · '.(int)$st['total_games'].' games</div>';
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
  <div class="section-head">Active Systems</div>
  <div class="systems-grid" style="margin-bottom:32px">
    <?php foreach ($visibleSystems as $s):
      $st = $stats[$s['id']] ?? ['total_games'=>0,'owned'=>0,'total_copies'=>0,'upgrades'=>0,'wishlisted'=>0,'total_spent'=>0,'owned_value'=>0,'cib_total'=>0,'labels'=>[],'avg_score'=>null,'scored'=>0];
    ?>
    <a href="<?= BASE_URL ?>/collection.php?s=<?= $s['id'] ?>" class="sys-card">
      <?= sysCardHeader($s, $st, $showIcons) ?>
      <?= progressHtml($st) ?>
      <?= qualBarHtml($st) ?>
      <?= sysStatsHtml($st) ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- HIDDEN SYSTEMS -->
  <?php if ($hiddenSystems): ?>
  <div class="hidden-toggle">
    <input type="checkbox" id="show-hidden" onchange="toggleHidden(this)">
    <label for="show-hidden">Show hidden systems (<?= count($hiddenSystems) ?>)</label>
  </div>
  <div class="systems-grid" id="hidden-systems" style="display:none">
    <?php foreach ($hiddenSystems as $s):
      $st = $stats[$s['id']] ?? ['total_games'=>0,'owned'=>0,'total_copies'=>0,'upgrades'=>0,'wishlisted'=>0,'total_spent'=>0,'owned_value'=>0,'cib_total'=>0,'labels'=>[],'avg_score'=>null,'scored'=>0];
    ?>
    <div class="sys-card hidden-sys" onclick="activateSystem(<?= $s['id'] ?>)" style="cursor:pointer" title="Click to make this system visible">
      <?= sysCardHeader($s, $st, $showIcons) ?>
      <?= progressHtml($st) ?>
      <?= qualBarHtml($st) ?>
      <?= sysStatsHtml($st) ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<script>
const DASH_BASE = <?= json_encode(BASE_URL) ?>;

function toggleHidden(cb) {
  document.getElementById('hidden-systems').style.display = cb.checked ? 'grid' : 'none';
}

async function activateSystem(systemId) {
  if (!confirm('Make this system visible in your collection?')) return;
  // Get current prefs, set this one to visible
  const res = await fetch(`${DASH_BASE}/api/system_prefs.php`).then(r=>r.json());
  if (!res.ok) return;
  const prefs = res.systems.map((s,i) => ({
    system_id:  s.id,
    visible:    s.id == systemId ? true : s.visible,
    sort_order: s.user_sort_order ?? i,
  }));
  await fetch(`${DASH_BASE}/api/system_prefs.php`, {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({prefs})
  });
  window.location.reload();
}
</script>
</body>
</html>
