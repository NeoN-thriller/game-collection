<?php
require_once __DIR__ . '/config.php';

// Support public wishlist viewing via ?token= (random token)
$shareToken   = $_GET['token'] ?? '';
$isPublicView = false;

if ($shareToken) {
    $stUser = db()->prepare("SELECT * FROM users WHERE wishlist_token=? AND wishlist_public=1 AND status='active'");
    $stUser->execute([$shareToken]);
    $viewUser = $stUser->fetch();
    if (!$viewUser) {
        $currentUser = auth();
        if (!$currentUser) { header('Location: '.BASE_URL.'/index.php'); exit; }
        die('<p style="font:1rem DM Mono,monospace;color:#c94f3a;padding:40px">This wishlist is not public or does not exist.</p>');
    }
    $isPublicView = true;
    $user     = $viewUser;
    $loggedIn = auth();
} else {
    $loggedIn = requireAuth();
    $user     = $loggedIn;
}

// Load all wishlisted entries across all systems
$st = db()->prepare("
    SELECT ce.*, g.title, g.sort_title, g.sort_order AS game_sort, g.default_image,
           g.cib_price, g.cib_price_updated_at, g.pc_link,
           g.loose_price, g.loose_price_updated_at,
           g.new_price, g.new_price_updated_at,
           s.name AS system_name, s.short_name, s.id AS system_id,
           GROUP_CONCAT(cp.filename ORDER BY cp.sort_order SEPARATOR '||') AS photos_raw
    FROM collection_entries ce
    JOIN games g   ON g.id  = ce.game_id
    JOIN systems s ON s.id  = g.system_id
    LEFT JOIN copy_photos cp ON cp.entry_id = ce.id
    WHERE ce.user_id=? AND ce.wishlist=1
    GROUP BY ce.id
    ORDER BY s.sort_order, g.sort_title
");
$st->execute([$user['id']]);
$entries = $st->fetchAll();

// User tag options for filter
$tagOptsSt = db()->prepare("SELECT label FROM user_tag_options WHERE user_id=? ORDER BY sort_order");
$tagOptsSt->execute([$user['id']]); $tagOpts = $tagOptsSt->fetchAll(PDO::FETCH_COLUMN);

// Group by system — load ALL systems that have wishlisted entries
$bySystem = [];
foreach ($entries as $e) {
    $sid = (int)$e['system_id'];
    if (!isset($bySystem[$sid])) {
        $bySystem[$sid] = [
            'name'   => $e['system_name'],
            'short'  => $e['short_name'],
            'sys_id' => $sid,
            'count'  => 0,
        ];
    }
    $bySystem[$sid]['count']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isPublicView ? htmlspecialchars($user['username'])."'s Wishlist" : 'Wishlist' ?> — Game Collection</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
  .sys-badge { display:inline-block; padding:2px 8px; font-size:.6rem; letter-spacing:.1em; text-transform:uppercase; background:rgba(0,154,199,.1); color:var(--wiiu); border:1px solid rgba(0,154,199,.3); white-space:nowrap; }
  .wish-filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding:10px 32px; background:var(--surface2); border-bottom:1px solid var(--border); }
</style>
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/collection.php" class="site-logo" style="text-decoration:none">Game <span>Collection</span></a>
  <div class="hstats">
    <div class="hstat"><div class="hstat-val blue"><?= count($entries) ?></div><div class="hstat-label">Wishlisted</div></div>
    <div class="hstat"><div class="hstat-val"><?= count($bySystem) ?></div><div class="hstat-label">Systems</div></div>
    <?php if ($isPublicView): ?>
    <div class="hstat"><div class="hstat-val" style="font-size:1rem;color:var(--muted)"><?= htmlspecialchars($user['username']) ?></div><div class="hstat-label">Wishlist</div></div>
    <?php endif; ?>
  </div>
  <nav class="site-nav">
    <?php if ($isPublicView && $loggedIn): ?>
      <span class="nav-user">👤 <?= htmlspecialchars($loggedIn['username']) ?></span>
      <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link">Dashboard</a>
      <a href="<?= BASE_URL ?>/collection.php" class="nav-link">Collection</a>
      <a href="<?= BASE_URL ?>/wishlist.php" class="nav-link">My Wishlist</a>
    <?php elseif (!$isPublicView): ?>
      <span class="nav-user">👤 <?= htmlspecialchars($user['username']) ?></span>
      <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link">Dashboard</a>
      <a href="<?= BASE_URL ?>/collection.php" class="nav-link">Collection</a>
      <?php if (isAdmin()): ?><a href="<?= BASE_URL ?>/admin.php" class="nav-link">Admin</a><?php endif; ?>
      <a href="<?= BASE_URL ?>/settings.php" class="nav-link">Settings</a>
      <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link">Sign Out</a>
    <?php endif; ?>
  </nav>
</header>

<!-- FILTERS -->
<div class="toolbar">
  <div class="search-wrap">
    <span class="search-icon">⌕</span>
    <input type="text" id="tb-search" placeholder="Search wishlist...">
  </div>
  <?php if ($tagOpts): ?>
  <select id="tb-tag" onchange="filterTable()">
    <option value="">All Tags</option>
    <?php foreach ($tagOpts as $t): ?>
    <option value="<?= htmlspecialchars(strtolower($t)) ?>"><?= htmlspecialchars($t) ?></option>
    <?php endforeach; ?>
  </select>
  <?php else: ?>
  <input type="hidden" id="tb-tag" value="">
  <?php endif; ?>
  <div class="filter-btn-group">
    <button class="filter-btn" id="fbtn-own-all" onclick="setWLOwned('')">All</button>
    <button class="filter-btn" id="fbtn-own-yes" onclick="setWLOwned('1')">✓ Owned</button>
    <button class="filter-btn" id="fbtn-own-no"  onclick="setWLOwned('0')">✗ Owned</button>
  </div>
  <div class="filter-btn-group">
    <button class="filter-btn" id="fbtn-upg-all" onclick="setWLUpgrade('')">↑ All</button>
    <button class="filter-btn" id="fbtn-upg-yes" onclick="setWLUpgrade('1')">↑ Yes</button>
  </div>
  <input type="hidden" id="tb-owned" value="">
  <input type="hidden" id="tb-upgrade" value="">
</div>

<!-- SYSTEM TOGGLE BUTTONS (multi-select, wrapping) -->
<?php if ($bySystem): ?>
<div style="background:var(--surface2);border-bottom:1px solid var(--border);padding:8px 32px">
  <div class="sys-toggle-wrap">
    <button class="sys-toggle-btn utility" onclick="selectAllSystems()">All</button>
    <button class="sys-toggle-btn utility" onclick="selectNoSystems()">None</button>
    <?php foreach ($bySystem as $sid => $s): ?>
    <button class="sys-toggle-btn active" data-sysid="<?= (int)$s['sys_id'] ?>" onclick="toggleSystem(this)"><?= htmlspecialchars($s['short']) ?> <span style="opacity:.5;font-size:.55rem">(<?= $s['count'] ?>)</span></button>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- TABLE -->
<div class="table-wrap">
  <table id="wish-table">
    <thead>
      <tr>
        <th data-col="img" style="width:36px">IMG</th>
        <th data-col="owned" style="width:28px;text-align:center">OWN</th>
        <th data-col="upgrade" style="width:28px;text-align:center">↑</th>
        <th data-col="system" onclick="sortWish('system')" style="cursor:pointer">System ↕</th>
        <th data-col="title" onclick="sortWish('title')" style="cursor:pointer">Title ↕</th>
        <th data-col="upgrade_reason">Upgrade Reason</th>
        <th data-col="quality" onclick="sortWish('quality')" style="cursor:pointer">Condition ↕</th>
        <th data-col="completeness">Completeness</th>
        <th data-col="price_paid" onclick="sortWish('price_paid')" style="cursor:pointer">Paid ↕</th>
        <th data-col="buy_range">Buy Range</th>
        <th data-col="loose_price" onclick="sortWish('loose_price')" style="cursor:pointer">Loose ↕</th>
        <th data-col="cib_price" onclick="sortWish('cib_price')" style="cursor:pointer">CIB Price ↕</th>
        <th data-col="new_price" onclick="sortWish('new_price')" style="cursor:pointer">New ↕</th>
        <th data-col="tag" onclick="sortWish('tag')" style="cursor:pointer">Tag ↕</th>
        <th data-col="notes">Notes</th>
        <th data-col="__edit"></th>
      </tr>
    </thead>
    <tbody id="tbody">
    <?php foreach ($entries as $e):
      $photos = $e['photos_raw'] ? explode('||', $e['photos_raw']) : [];
      $primary = $e['primary_photo'] ?? '';
      $displayPhoto = $primary ?: ($photos[0] ?? null);

      $imgCell = '';
      if ($displayPhoto) {
        $imgCell = '<img class="img-thumb" src="'.BASE_URL.'/uploads/users/'.htmlspecialchars($displayPhoto).'" alt="">';
      } elseif ($e['default_image']) {
        $imgCell = '<img class="img-thumb" src="'.BASE_URL.'/uploads/defaults/'.htmlspecialchars($e['default_image']).'" alt="">';
      } else {
        $imgCell = '<div class="img-placeholder">—</div>';
      }

      $qcls     = ['Mint'=>'q-mint','Good'=>'q-good','Fair'=>'q-fair','Poor'=>'q-poor'][$e['quality']??''] ?? 'q-na';
      $paidCell = $e['price_paid'] !== null ? '<span class="price">€'.number_format($e['price_paid'],2).'</span>' : '<span class="price-na">—</span>';

      $rangeCell = '<span class="price-na">—</span>';
      if ($e['price_min']!==null && $e['price_max']!==null) $rangeCell='<span class="price-range">€'.number_format($e['price_min'],0).'–€'.number_format($e['price_max'],0).'</span>';
      elseif ($e['price_min']!==null) $rangeCell='<span class="price-range">≥€'.number_format($e['price_min'],0).'</span>';
      elseif ($e['price_max']!==null) $rangeCell='<span class="price-range">≤€'.number_format($e['price_max'],0).'</span>';

      // Dual CIB price: PC in blue (linked), personal in red
      $cibParts = [];
      if ($e['cib_price'] !== null) {
        $cibTitle = $e['cib_price_updated_at'] ? 'PC CIB — last updated '.substr($e['cib_price_updated_at'],0,10) : 'PC CIB Price';
        $cibAmt   = '€'.number_format($e['cib_price'],2);
        $cibParts[] = $e['pc_link']
          ? '<a href="'.htmlspecialchars($e['pc_link']).'" target="_blank" class="price price-chart" style="text-decoration:none" title="'.htmlspecialchars($cibTitle).'">'.$cibAmt.'</a>'
          : '<span class="price price-chart" title="'.htmlspecialchars($cibTitle).'">'.$cibAmt.'</span>';
      } elseif ($e['pc_link']) {
        $cibParts[] = '<a href="'.htmlspecialchars($e['pc_link']).'" target="_blank" style="color:var(--wiiu);text-decoration:none;font-size:.75rem" title="View on PriceCharting">PC ↗</a>';
      }
      if ($e['chart_price'] !== null) {
        $cibParts[] = '<span class="price" style="color:#e05a7a" title="Personal price">€'.number_format($e['chart_price'],2).'</span>';
      }
      $cibCell = $cibParts ? implode(' <span style="color:var(--border2)">·</span> ', $cibParts) : '<span class="price-na">—</span>';

      $noteText     = ($e['notes'] ?? '') ?: ($e['upgrade'] && ($e['upgrade_reason'] ?? '') ? '↑ '.$e['upgrade_reason'] : '—');
      $upgradeClass = $e['upgrade'] ? 'checked' : '';
      $upgradeLabel = $e['upgrade'] ? '↑' : '';
      $tagLabel     = $e['tag'] ?? '';
    ?>
    <tr data-system="<?= $e['system_id'] ?>"
        data-owned="<?= $e['owned'] ?>"
        data-upgrade="<?= $e['upgrade'] ?>"
        data-title="<?= htmlspecialchars(strtolower($e['title'])) ?>"
        data-tag="<?= htmlspecialchars(strtolower($tagLabel)) ?>"
        data-system-name="<?= htmlspecialchars(strtolower($e['short_name'])) ?>"
        data-quality="<?= htmlspecialchars(strtolower($e['quality']??'')) ?>"
        data-paid="<?= (float)($e['price_paid']??0) ?>"
        data-cib="<?= (float)($e['cib_price']??0) ?>"
        data-loose="<?= (float)($e['loose_price']??0) ?>"
        data-newp="<?= (float)($e['new_price']??0) ?>">
      <td data-col="img"><?= $imgCell ?></td>
      <td data-col="owned" style="text-align:center">
        <div class="owned-check <?= $e['owned']?'checked':'' ?>" onclick="wlToggleOwned(this,<?= $e['id'] ?>,<?= $e['game_id'] ?>)" style="cursor:pointer"><?= $e['owned']?'✓':'' ?></div>
      </td>
      <td data-col="upgrade" style="text-align:center">
        <div class="upgrade-check <?= $upgradeClass ?>" onclick="wlToggleUpgrade(this,<?= $e['id'] ?>,<?= $e['game_id'] ?>)" style="cursor:pointer"><?= $upgradeLabel ?></div>
      </td>
      <td data-col="system"><span class="sys-badge"><?= htmlspecialchars($e['short_name']) ?></span></td>
      <td data-col="title" class="td-title"><?= htmlspecialchars($e['title']) ?></td>
      <td data-col="upgrade_reason" style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.68rem;color:var(--orange);font-style:italic" title="<?= htmlspecialchars($e['upgrade_reason']??'') ?>"><?= $e['upgrade'] && ($e['upgrade_reason']??'') ? htmlspecialchars($e['upgrade_reason']) : '<span style="color:var(--border2)">—</span>' ?></td>
      <td data-col="quality"><span class="qbadge <?= $qcls ?>"><?= htmlspecialchars($e['quality']??'—') ?: '—' ?></span></td>
      <td data-col="completeness" style="font-size:.7rem;color:var(--text2)"><?= htmlspecialchars($e['completeness']??'—') ?: '—' ?></td>
      <td data-col="price_paid"><?= $paidCell ?></td>
      <td data-col="buy_range"><?= $rangeCell ?></td>
      <td data-col="loose_price"><?= $e['loose_price'] !== null ? '<span class="price price-chart" style="color:var(--muted)" title="'.($e['loose_price_updated_at']?'Last updated: '.substr($e['loose_price_updated_at'],0,10):'Loose Price').'">€'.number_format($e['loose_price'],2).'</span>' : '<span class="price-na">—</span>' ?></td>
      <td data-col="cib_price"><?= $cibCell ?></td>
      <td data-col="new_price"><?= $e['new_price'] !== null ? '<span class="price price-chart" style="color:var(--green)" title="'.($e['new_price_updated_at']?'Last updated: '.substr($e['new_price_updated_at'],0,10):'New Price').'">€'.number_format($e['new_price'],2).'</span>' : '<span class="price-na">—</span>' ?></td>
      <td data-col="tag" style="font-size:.68rem;color:var(--wiiu)"><?= $tagLabel ? htmlspecialchars($tagLabel) : '<span style="color:var(--border2)">—</span>' ?></td>
      <td data-col="notes" class="note-cell" style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.66rem;color:var(--muted);font-style:italic" title="<?= htmlspecialchars($noteText) ?>"><?= htmlspecialchars($noteText) ?></td>
      <td data-col="__edit"><a href="<?= BASE_URL ?>/collection.php?s=<?= $e['system_id'] ?>" class="btn-icon" style="text-decoration:none">Go →</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (empty($entries)): ?>
  <div class="empty-state" style="display:block">
    <p>WISHLIST IS EMPTY</p>
    <p>Mark games as wishlisted from the collection page using the ♥ button.</p>
  </div>
  <?php endif; ?>
</div>

<div class="summary-bar">
  Showing <strong id="sum-show"><?= count($entries) ?></strong> of <strong><?= count($entries) ?></strong> wishlisted games
</div>

<script>
const BASE = <?= json_encode(BASE_URL) ?>;

// ── WISHLIST COLUMN PREFS ──
const WISH_COL_DEFAULTS = ['img','owned','upgrade','system','title','upgrade_reason','quality','completeness','price_paid','buy_range','loose_price','cib_price','new_price','tag','notes'];
let wishActiveCols = new Set(WISH_COL_DEFAULTS);
let wishColOrder   = [...WISH_COL_DEFAULTS];

async function initWishCols() {
  try {
    const res = await fetch(`${BASE}/api/column_prefs.php`).then(r=>r.json());
    const saved = res.ok ? (res.cols_wishlist || null) : null;
    if (saved && saved.length) {
      wishColOrder   = saved.map(c => c.id);
      wishActiveCols = new Set(saved.filter(c => c.on).map(c => c.id));
      WISH_COL_DEFAULTS.forEach(id => {
        if (!wishColOrder.includes(id)) { wishColOrder.push(id); wishActiveCols.add(id); }
      });
    }
  } catch(e) {}
  applyWishCols();
}

function applyWishCols() {
  const table = document.getElementById('wish-table');
  if (!table) return;

  // 1. Show/hide by data-col
  table.querySelectorAll('[data-col]').forEach(el => {
    const id = el.dataset.col;
    if (id === '__edit') return;
    el.style.display = wishActiveCols.has(id) ? '' : 'none';
  });

  // 2. Reorder header
  const thead = table.querySelector('thead tr');
  if (thead) {
    const editTh = thead.querySelector('[data-col="__edit"]');
    wishColOrder.forEach(id => {
      const th = thead.querySelector(`[data-col="${id}"]`);
      if (th) thead.appendChild(th);
    });
    if (editTh) thead.appendChild(editTh);
  }

  // 3. Reorder body rows
  table.querySelectorAll('tbody tr').forEach(row => {
    const editTd = row.querySelector('[data-col="__edit"]');
    wishColOrder.forEach(id => {
      const td = row.querySelector(`[data-col="${id}"]`);
      if (td) row.appendChild(td);
    });
    if (editTd) row.appendChild(editTd);
  });
}

initWishCols();

function setWLOwned(val) {
  document.getElementById('tb-owned').value = val;
  document.getElementById('fbtn-own-all').className = 'filter-btn' + (val===''  ? ' active-notown' : '');
  document.getElementById('fbtn-own-yes').className = 'filter-btn' + (val==='1' ? ' active-owned'  : '');
  document.getElementById('fbtn-own-no').className  = 'filter-btn' + (val==='0' ? ' active-notown' : '');
  filterTable();
}

function setWLUpgrade(val) {
  document.getElementById('tb-upgrade').value = val;
  document.getElementById('fbtn-upg-all').className = 'filter-btn' + (val===''  ? ' active-upgrade' : '');
  document.getElementById('fbtn-upg-yes').className = 'filter-btn' + (val==='1' ? ' active-upgrade' : '');
  filterTable();
}

// ── INLINE TOGGLES ──
async function wlSave(entryId, gameId, patch) {
  // Fetch current entry state, merge patch, save
  const res = await fetch(`${BASE}/api/entries.php?game_id=${gameId}`).then(r=>r.json());
  const entry = (res.entries||[]).find(e => e.id == entryId);
  if (!entry) return;
  const payload = {
    game_id:       gameId,
    copy_number:   entry.copy_number || 1,
    owned:         entry.owned       ? 1 : 0,
    wishlist:      entry.wishlist    ? 1 : 0,
    upgrade:       entry.upgrade     ? 1 : 0,
    upgrade_reason:entry.upgrade_reason || '',
    quality:       entry.quality        || '',
    completeness:  entry.completeness   || '',
    played_status: entry.played_status  || '',
    price_paid:    entry.price_paid,
    chart_price:   entry.chart_price,
    price_min:     entry.price_min,
    price_max:     entry.price_max,
    notes:         entry.notes          || '',
    tag:           entry.tag            || '',
    value_price_type: entry.value_price_type || 'cib',
    primary_photo: entry.primary_photo  || '',
    ...patch
  };
  return fetch(`${BASE}/api/entry_save.php`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  }).then(r=>r.json());
}

async function wlToggleOwned(el, entryId, gameId) {
  const nowOwned = el.classList.contains('checked') ? 0 : 1;
  el.classList.toggle('checked', nowOwned === 1);
  el.textContent = nowOwned ? '✓' : '';
  // Update data-owned on the parent row for filter
  el.closest('tr').dataset.owned = String(nowOwned);
  await wlSave(entryId, gameId, {owned: nowOwned});
  filterTable();
}

async function wlToggleUpgrade(el, entryId, gameId) {
  const nowUpgrade = el.classList.contains('checked') ? 0 : 1;
  el.classList.toggle('checked', nowUpgrade === 1);
  el.textContent = nowUpgrade ? '↑' : '';
  el.closest('tr').dataset.upgrade = String(nowUpgrade);
  await wlSave(entryId, gameId, {upgrade: nowUpgrade});
  filterTable();
}
function toggleSystem(btn) {
  btn.classList.toggle('active');
  filterTable();
}

function selectAllSystems() {
  document.querySelectorAll('.sys-toggle-btn[data-sysid]').forEach(b => b.classList.add('active'));
  filterTable();
}

function selectNoSystems() {
  document.querySelectorAll('.sys-toggle-btn[data-sysid]').forEach(b => b.classList.remove('active'));
  filterTable();
}

function getActiveSystems() {
  const active = [...document.querySelectorAll('.sys-toggle-btn[data-sysid].active')].map(b => String(b.dataset.sysid));
  const total  = document.querySelectorAll('.sys-toggle-btn[data-sysid]').length;
  // If all active or none exist, treat as "show all"
  if (active.length === total) return null; // null = all systems
  return new Set(active);
}

function filterTable() {
  const q   = document.getElementById('tb-search').value.toLowerCase();
  const own = document.getElementById('tb-owned').value;
  const upg = document.getElementById('tb-upgrade').value;
  const tag = document.getElementById('tb-tag')?.value.toLowerCase() || '';
  const activeSys = getActiveSystems(); // null = all, Set = specific ones
  let showing = 0;

  document.querySelectorAll('#tbody tr').forEach(tr => {
    const title   = tr.dataset.title   || '';
    const system  = tr.dataset.system  || '';
    const owned   = tr.dataset.owned   || '0';
    const upgrade = tr.dataset.upgrade || '0';
    const trTag   = tr.dataset.tag     || '';

    let show = true;
    if (q   && !title.includes(q))                      show = false;
    if (activeSys && !activeSys.has(String(system)))     show = false;
    if (own === '0' && owned === '1')                    show = false;
    if (own === '1' && owned !== '1')                    show = false;
    if (upg === '1' && upgrade !== '1')                  show = false;
    if (upg === '0' && upgrade === '1')                  show = false;
    if (tag && trTag !== tag)                            show = false;

    tr.style.display = show ? '' : 'none';
    if (show) showing++;
  });
  document.getElementById('sum-show').textContent = showing;
}

// ── SORTING ──
let wishSortKey = 'title', wishSortDir = 1;

function sortWish(key) {
  if (wishSortKey === key) wishSortDir *= -1;
  else { wishSortKey = key; wishSortDir = 1; }
  const tbody = document.getElementById('tbody');
  const rows = [...tbody.querySelectorAll('tr')];
  rows.sort((a, b) => {
    let va, vb;
    switch(key) {
      case 'system':    va=a.dataset.systemName||''; vb=b.dataset.systemName||''; break;
      case 'title':     va=a.dataset.title||'';      vb=b.dataset.title||'';      break;
      case 'quality':   va=a.dataset.quality||'';    vb=b.dataset.quality||'';    break;
      case 'price_paid':va=parseFloat(a.dataset.paid)||0;      vb=parseFloat(b.dataset.paid)||0;      break;
      case 'cib_price': va=parseFloat(a.dataset.cib)||0;       vb=parseFloat(b.dataset.cib)||0;       break;
      case 'loose_price':va=parseFloat(a.dataset.loose||0)||0; vb=parseFloat(b.dataset.loose||0)||0;  break;
      case 'new_price': va=parseFloat(a.dataset.newp||0)||0;   vb=parseFloat(b.dataset.newp||0)||0;   break;
      case 'tag':       va=a.dataset.tag||'zzz';     vb=b.dataset.tag||'zzz';     break;
      default:          va=a.dataset.title||'';      vb=b.dataset.title||'';
    }
    if (typeof va==='string') { va=va.toLowerCase(); vb=vb.toLowerCase(); }
    return va<vb ? -wishSortDir : va>vb ? wishSortDir : 0;
  });
  rows.forEach(r => tbody.appendChild(r));
}

setWLOwned(''); setWLUpgrade('');

['tb-search'].forEach(id => {
  document.getElementById(id)?.addEventListener('input',  filterTable);
  document.getElementById(id)?.addEventListener('change', filterTable);
});
</script>
</body>
</html>
