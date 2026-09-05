<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

// Load systems visible to this user, respecting user-defined order
$sysSt = db()->prepare("
    SELECT s.*, COALESCE(usp.visible,1) AS visible,
           COALESCE(usp.user_sort_order, s.sort_order) AS effective_order,
           COALESCE(usp.count_for_totals, 1) AS count_for_totals
    FROM systems s
    LEFT JOIN user_system_prefs usp ON usp.system_id=s.id AND usp.user_id=?
    WHERE s.active=1
    ORDER BY COALESCE(usp.user_sort_order, s.sort_order)
");
$sysSt->execute([$user['id']]);
$allSystems = $sysSt->fetchAll();
$systems = array_filter($allSystems, fn($s) => $s['visible']);
$systems = array_values($systems);

// Remember last system via cookie, fallback to first visible
$cookieSys = (int)($_COOKIE['last_system'] ?? 0);
$sysId = isset($_GET['s']) ? (int)$_GET['s'] : $cookieSys;
$curSys = null;
foreach ($systems as $s) { if ($s['id'] == $sysId) { $curSys = $s; break; } }
if (!$curSys && $systems) { $curSys = $systems[0]; $sysId = $curSys['id']; }

// Set cookie for last visited system
setcookie('last_system', $sysId, time()+60*60*24*365, '/');

// Load user options
$compOpts = db()->prepare("SELECT label FROM user_completeness_options WHERE user_id=? ORDER BY sort_order");
$compOpts->execute([$user['id']]); $compOpts = $compOpts->fetchAll(PDO::FETCH_COLUMN);

$playedOpts = db()->prepare("SELECT label FROM user_played_options WHERE user_id=? ORDER BY sort_order");
$playedOpts->execute([$user['id']]); $playedOpts = $playedOpts->fetchAll(PDO::FETCH_COLUMN);

$tagOptsQ = db()->prepare("SELECT label FROM user_tag_options WHERE user_id=? ORDER BY sort_order");
$tagOptsQ->execute([$user['id']]); $tagOpts = $tagOptsQ->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($curSys['name'] ?? 'Collection') ?> — Game Collection</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/collection.php" class="site-logo" style="text-decoration:none">Game <span>Collection</span></a>
  <div id="hstats" class="hstats">
    <div class="hstat"><div class="hstat-val blue"  id="st-owned">0</div><div class="hstat-label">Owned</div></div>
    <div class="hstat"><div class="hstat-val"        id="st-pct">0%</div><div class="hstat-label">Complete</div></div>
    <div class="hstat"><div class="hstat-val green"  id="st-copies">0</div><div class="hstat-label">Copies</div></div>
    <div class="hstat"><div class="hstat-val orange" id="st-upgrade">0</div><div class="hstat-label">Upgrade</div></div>
    <div class="hstat"><div class="hstat-val"        id="st-spent">€0</div><div class="hstat-label">Spent</div></div>
    <div class="hstat"><div class="hstat-val blue"   id="st-cib-all">€0</div><div class="hstat-label">CIB All</div></div>
    <div class="hstat"><div class="hstat-val green"  id="st-cib-owned">€0</div><div class="hstat-label">Owned Value</div></div>
  </div>
  <nav class="site-nav">
    <span class="nav-user">👤 <?= htmlspecialchars($user['username']) ?></span>
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link">Dashboard</a>
    <a href="<?= BASE_URL ?>/wishlist.php" class="nav-link">Wishlist</a>
    <?php if (isAdmin()): ?><a href="<?= BASE_URL ?>/admin.php" class="nav-link">Admin</a><?php endif; ?>
    <a href="<?= BASE_URL ?>/settings.php" class="nav-link">Settings</a>
    <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link">Sign Out</a>
  </nav>
</header>

<div class="progress-wrap">
  <div class="progress-track"><div class="progress-fill" id="prog-fill" style="width:0%"></div></div>
  <div class="progress-label"><strong id="prog-text">0 / 0</strong> owned</div>
</div>

<div class="system-bar">
  <?php foreach ($systems as $s): ?>
  <button class="sys-btn <?= $s['id']==$sysId?'active':'' ?>" onclick="switchSystem(<?= $s['id'] ?>)"><?= htmlspecialchars($s['short_name']) ?></button>
  <?php endforeach; ?>
</div>

<div class="toolbar">
  <div class="search-wrap">
    <span class="search-icon">⌕</span>
    <input type="text" id="tb-search" placeholder="Search titles...">
  </div>
  <select id="tb-quality">
    <option value="" disabled selected>— All Conditions —</option>
    <option value="">All Conditions</option>
    <option>Mint</option><option>Good</option><option>Fair</option><option>Poor</option>
  </select>
  <select id="tb-completeness">
    <option value="" disabled selected>— All Completeness —</option>
    <option value="">All Completeness</option>
    <?php foreach ($compOpts as $c): ?><option><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
  </select>
  <select id="tb-played">
    <option value="" disabled selected>— All Played Status —</option>
    <option value="">All Played Status</option>
    <?php foreach ($playedOpts as $p): ?><option><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
  </select>
  <select id="tb-tag">
    <option value="" disabled selected>— All Tags —</option>
    <option value="">All Tags</option>
    <?php foreach ($tagOpts as $t): ?><option><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
  </select>
  <!-- Owned filter — Show All / Owned / Not Owned -->
  <div class="filter-btn-group">
    <button class="filter-btn" id="fbtn-owned-showall" onclick="setOwned('all')"  title="Show all games">All</button>
    <button class="filter-btn" id="fbtn-owned-yes"     onclick="setOwned('1')"   title="Owned only">✓ Own</button>
    <button class="filter-btn" id="fbtn-owned-no"      onclick="setOwned('0')"   title="Not owned">✗ Own</button>
  </div>
  <div class="filter-btn-group">
    <button class="filter-btn" id="fbtn-wish-off"  onclick="setWishlist('')"  title="Any">♥ All</button>
    <button class="filter-btn" id="fbtn-wish-on"   onclick="setWishlist('1')" title="Wishlisted">♥ Yes</button>
  </div>
  <div class="filter-btn-group">
    <button class="filter-btn" id="fbtn-up-off"    onclick="setUpgrade('')"   title="Any">↑ All</button>
    <button class="filter-btn" id="fbtn-up-on"     onclick="setUpgrade('1')"  title="Needs upgrade">↑ Yes</button>
  </div>
  <!-- Hidden inputs to store filter state -->
  <input type="hidden" id="tb-owned"    value="owned">
  <input type="hidden" id="tb-wishlist" value="">
  <input type="hidden" id="tb-upgrade"  value="">
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr id="thead-row">
        <th data-col="img" style="width:36px">IMG</th>
        <th data-col="owned" style="width:28px;text-align:center" data-sort="owned">OWN</th>
        <th data-col="wishlist" style="width:28px;text-align:center" data-sort="wishlist">♥</th>
        <th data-col="upgrade" style="width:28px;text-align:center" data-sort="upgrade">↑</th>
        <th data-col="title" data-sort="title">Title ↕</th>
        <th data-col="quality" data-sort="quality">Cond ↕</th>
        <th data-col="completeness" data-sort="completeness">Complete ↕</th>
        <th data-col="played" data-sort="played_status">Played ↕</th>
        <th data-col="copies" data-sort="copies">Copies ↕</th>
        <th data-col="price_paid" data-sort="price_paid">Paid ↕</th>
        <th data-col="buy_range">Buy Range</th>
        <th data-col="loose_price" data-sort="loose_price">Loose ↕</th>
        <th data-col="cib_price" data-sort="chart_price">CIB Price ↕</th>
        <th data-col="new_price" data-sort="new_price">New ↕</th>
        <th data-col="upgrade_reason">Upgrade Reason</th>
        <th data-col="tag" data-sort="tag">Tag ↕</th>
        <th data-col="notes">Note</th>
        <th data-col="__edit"></th>
      </tr>
    </thead>
    <tbody id="tbody"></tbody>
  </table>
  <div class="empty-state" id="empty-state">
    <p>NO RESULTS</p><p>Try adjusting your filters.</p>
  </div>
</div>

<div class="summary-bar">
  Showing <strong id="sum-show">0</strong> of <strong id="sum-tot">0</strong> &nbsp;·&nbsp;
  Owned: <strong id="sum-own">0</strong> &nbsp;·&nbsp;
  Spend: <strong id="sum-spend">€0</strong> &nbsp;·&nbsp;
  CIB Total: <strong id="sum-chart">—</strong>
</div>

<!-- EDIT DRAWER -->
<div class="drawer-backdrop" id="drawer-backdrop" onclick="handleBdClick(event)">
  <div class="drawer" id="drawer">
    <div class="drawer-header">
      <div class="drawer-header-info">
        <div class="drawer-title" id="d-title">—</div>
        <div class="drawer-subtitle" id="d-system">PAL System</div>
      </div>
      <button class="drawer-close" onclick="closeDrawer()">✕</button>
    </div>
    <div class="drawer-body">

      <div class="drawer-section">
        <div class="section-label">Copies</div>
        <div class="copy-tabs" id="copy-tabs"></div>
      </div>

      <div class="drawer-section">
        <div class="section-label">Ownership</div>
        <div class="toggle-row">
          <label class="toggle"><input type="checkbox" id="d-owned"><span class="toggle-slider"></span></label>
          <span class="toggle-label" id="lbl-owned">Not owned</span>
        </div>
      </div>

      <div class="drawer-section">
        <div class="section-label">Condition</div>
        <div class="field-row">
          <div class="field"><label>Quality</label>
            <select id="d-quality">
              <option value="">— N/A —</option>
              <option>Mint</option><option>Good</option><option>Fair</option><option>Poor</option>
            </select>
          </div>
          <div class="field"><label>Completeness</label>
            <select id="d-completeness">
              <option value="">— N/A —</option>
              <?php foreach ($compOpts as $c): ?><option><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field-row">
          <div class="field"><label>Played Status</label>
            <select id="d-played">
              <option value="">— N/A —</option>
              <?php foreach ($playedOpts as $p): ?><option><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="drawer-section">
        <div class="section-label">Pricing (PriceCharting)</div>
        <div id="d-pc-prices-row" style="display:none;margin-bottom:8px">
          <table style="font-size:.75rem;width:100%;border-collapse:collapse">
            <tr id="d-loose-row" style="display:none"><td style="color:var(--muted);padding:2px 0;width:60px">Loose</td><td><span id="d-loose-price-val" style="color:var(--wiiu);font-family:'Bebas Neue',sans-serif;font-size:1rem"></span></td></tr>
            <tr id="d-cib-row"   style="display:none"><td style="color:var(--muted);padding:2px 0">CIB</td>  <td><span id="d-cib-price-val"   style="color:var(--wiiu);font-family:'Bebas Neue',sans-serif;font-size:1rem"></span></td></tr>
            <tr id="d-new-row"   style="display:none"><td style="color:var(--muted);padding:2px 0">New</td>  <td><span id="d-new-price-val"   style="color:var(--wiiu);font-family:'Bebas Neue',sans-serif;font-size:1rem"></span></td></tr>
          </table>
          <a id="d-pc-link" href="#" target="_blank" style="color:var(--wiiu);font-size:.68rem;display:none">View on PriceCharting ↗</a>
        </div>
        <div class="section-label" style="margin-top:10px;margin-bottom:6px">Use for owned value</div>
        <div style="display:flex;gap:14px;font-size:.75rem;flex-wrap:wrap" id="d-price-type-wrap">
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer"><input type="radio" name="d-value-type" id="d-vtype-loose" value="loose"> Loose</label>
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer"><input type="radio" name="d-value-type" id="d-vtype-cib"   value="cib"   checked> CIB</label>
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer"><input type="radio" name="d-value-type" id="d-vtype-new"   value="new"> New</label>
        </div>
        <div class="field-row">
          <div class="field"><label>Paid (€)</label><input type="number" id="d-price" step="0.01" min="0" placeholder="0.00"></div>
          <div class="field"><label>Personal Price (€)</label><input type="number" id="d-chart" step="0.01" min="0" placeholder="0.00"></div>
        </div>
        <div class="field-row">
          <div class="field"><label>Buy Min (€)</label><input type="number" id="d-min" step="0.01" min="0" placeholder="0.00"></div>
          <div class="field"><label>Buy Max (€)</label><input type="number" id="d-max" step="0.01" min="0" placeholder="0.00"></div>
        </div>
      </div>

      <!-- EXTERNAL LINKS -->
      <div class="drawer-section" id="d-ext-links" style="display:none">
        <div class="section-label">Quick Search</div>
        <div id="d-links-list" style="display:flex;flex-direction:column;gap:5px"></div>
      </div>

      <!-- TAG -->
      <div class="drawer-section">
        <div class="section-label">Tag</div>
        <select id="d-tag" style="width:100%;padding:8px 10px;font-size:.78rem">
          <option value="">— No tag —</option>
          <?php foreach ($tagOpts as $t): ?>
          <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="drawer-section">
        <div class="section-label">Upgrade</div>
        <div class="toggle-row">
          <label class="toggle"><input type="checkbox" id="d-upgrade"><span class="toggle-slider"></span></label>
          <span class="toggle-label" id="lbl-upgrade">No upgrade needed</span>
        </div>
        <div class="field"><label>Upgrade Reason</label><textarea id="d-upgrade-reason" placeholder="e.g. cracked case, want sealed copy..."></textarea></div>
      </div>

      <div class="drawer-section">
        <div class="section-label">Wishlist</div>
        <div class="toggle-row">
          <label class="toggle"><input type="checkbox" id="d-wishlist"><span class="toggle-slider"></span></label>
          <span class="toggle-label" id="lbl-wishlist">Not on wishlist</span>
        </div>
      </div>

      <div class="drawer-section">
        <div class="section-label">Notes</div>
        <div class="field"><textarea id="d-notes" placeholder="Where bought, condition details..."></textarea></div>
      </div>

      <div class="drawer-section">
        <div class="section-label">Photos</div>
        <div class="img-upload-area">
          <input type="file" id="d-photos" accept="image/*" multiple onchange="uploadPhotos(event)">
          <div class="img-upload-text">Click or drag to add photos</div>
        </div>
        <div class="img-preview-grid" id="d-photo-grid"></div>
        <div id="d-primary-wrap" style="display:none;margin-top:10px">
          <div class="section-label" style="margin-bottom:6px">Primary Display Image</div>
          <select id="d-primary" style="font-size:.75rem;padding:6px 10px;width:100%">
            <option value="">— First uploaded photo —</option>
          </select>
        </div>
      </div>

    </div>
    <div class="drawer-footer">
      <button class="btn-ghost" onclick="closeDrawer()">Cancel</button>
      <button class="btn" onclick="saveEntry()">Save</button>
    </div>
  </div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lb-close" onclick="closeLightbox()">✕</button>
  <img id="lb-img" src="" alt="">
  <div class="lb-nav">
    <button class="lb-btn" onclick="lbPrev(event)">← Prev</button>
    <span class="lb-label" id="lb-lbl"></span>
    <button class="lb-btn" onclick="lbNext(event)">Next →</button>
  </div>
  <div class="lb-nav" id="lb-rotate-nav" style="display:none">
    <button class="lb-btn" onclick="lbRotate(event,-90)">↺ Rotate Left</button>
    <button class="lb-btn" onclick="lbRotate(event,90)">↻ Rotate Right</button>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const BASE     = <?= json_encode(BASE_URL) ?>;
const USER_ID  = <?= (int)$user['id'] ?>;
const SYS_ID   = <?= (int)$sysId ?>;
const SYS_NAME = <?= json_encode($curSys['name']       ?? '') ?>;
const SYS_SHORT= <?= json_encode($curSys['short_name'] ?? '') ?>;
const SYS_COUNTS_TOTALS = <?= json_encode((bool)($curSys['count_for_totals'] ?? true)) ?>;
window.USER_AUCTION_SITES = <?= json_encode(json_decode($user['auction_sites'] ?? '[]', true) ?: []) ?>;

let allGames  = [];
let entryMap  = {};
let sortKey   = 'title';
let sortDir   = 1;
let editGameId= null;
let editCopy  = 1;
let lbImages  = [];
let lbRawNames= [];
let lbContext  = null;
let lbIdx     = 0;
let photoTs   = {}; // filename -> latest timestamp after rotation

// Column preferences
const DEFAULT_COL_ORDER = ['img','owned','wishlist','upgrade','title','quality','completeness','played','copies','price_paid','buy_range','loose_price','cib_price','new_price','upgrade_reason','tag','notes'];
let activeCols = new Set(DEFAULT_COL_ORDER);
let colOrder   = [...DEFAULT_COL_ORDER];

async function init() {
  const [gRes, eRes, cRes] = await Promise.all([
    fetch(`${BASE}/api/games.php?system_id=${SYS_ID}`).then(r=>r.json()),
    fetch(`${BASE}/api/entries.php?system_id=${SYS_ID}`).then(r=>r.json()),
    fetch(`${BASE}/api/column_prefs.php`).then(r=>r.json()),
  ]);
  allGames = gRes.games || [];
  buildEntryMap(eRes.entries || []);

  // Apply column prefs from saved cols_collection
  const saved = cRes.ok ? (cRes.cols_collection || null) : null;
  if (saved && saved.length) {
    colOrder   = saved.map(c => c.id);
    activeCols = new Set(saved.filter(c => c.on).map(c => c.id));
    // Add any new columns not in saved prefs (with default on)
    DEFAULT_COL_ORDER.forEach(id => {
      if (!colOrder.includes(id)) { colOrder.push(id); activeCols.add(id); }
    });
  } else {
    colOrder   = [...DEFAULT_COL_ORDER];
    activeCols = new Set(DEFAULT_COL_ORDER);
  }

  applyColumnOrder();
  applyColumnVisibility();
  render();
}

function buildEntryMap(entries) {
  entryMap = {};
  entries.forEach(e => {
    if (!entryMap[e.game_id]) entryMap[e.game_id] = [];
    entryMap[e.game_id].push(e);
  });
}

// Map col id -> 0-based column index in the table
const COL_INDEX = {img:0,owned:1,wishlist:2,upgrade:3,title:4,quality:5,completeness:6,played:7,copies:8,price_paid:9,buy_range:10,loose_price:11,cib_price:12,new_price:13,upgrade_reason:14,tag:15,notes:16};
// edit button is always last col, never hidden

function applyColumnOrder() {} // no-op, handled in applyColumnVisibility

function getOrderedColIds() {
  const known = new Set(Object.keys(COL_INDEX));
  const ordered = colOrder.filter(id => known.has(id));
  // Ensure all known cols appear
  Object.keys(COL_INDEX).forEach(id => { if (!ordered.includes(id)) ordered.push(id); });
  return ordered;
}

function applyColumnVisibility() {
  const table = document.querySelector('.table-wrap table');
  if (!table) return;
  const ordered = getOrderedColIds();

  // 1. Show/hide all cells by data-col
  table.querySelectorAll('[data-col]').forEach(el => {
    const id = el.dataset.col;
    if (id === '__edit') return; // always show edit button
    el.style.display = activeCols.has(id) ? '' : 'none';
  });

  // 2. Reorder header columns
  const thead = table.querySelector('thead tr');
  if (thead) {
    const editTh = thead.querySelector('[data-col="__edit"]');
    ordered.forEach(id => {
      const th = thead.querySelector(`[data-col="${id}"]`);
      if (th) thead.appendChild(th);
    });
    if (editTh) thead.appendChild(editTh);
  }

  // 3. Reorder body rows
  table.querySelectorAll('tbody tr').forEach(row => {
    const editTd = row.querySelector('[data-col="__edit"]');
    ordered.forEach(id => {
      const td = row.querySelector(`[data-col="${id}"]`);
      if (td) row.appendChild(td);
    });
    if (editTd) row.appendChild(editTd);
  });
}

function reorderTableBody() {
  applyColumnVisibility();
}

function getDisplayPhoto(game) {
  const copies = entryMap[game.id] || [];
  for (const c of copies) {
    // __default__ means user explicitly chose the default cover
    if (c.primary_photo === '__default__') {
      return game.default_image ? { src:`${BASE}/uploads/defaults/${game.default_image}`, type:'default' } : null;
    }
    if (c.primary_photo) return { src:`${BASE}/uploads/users/${c.primary_photo}`, type:'user' };
    if (c.photos && c.photos.length) return { src:`${BASE}/uploads/users/${c.photos[0]}`, type:'user' };
  }
  if (game.default_image) return { src:`${BASE}/uploads/defaults/${game.default_image}`, type:'default' };
  return null;
}

function render() {
  const q  = document.getElementById('tb-search').value.toLowerCase();
  const fo = document.getElementById('tb-owned').value;    // 'all'|'owned'|'1'|'0'
  const fq = document.getElementById('tb-quality').value;
  const fc = document.getElementById('tb-completeness').value;
  const fp = document.getElementById('tb-played').value;
  const ftag = document.getElementById('tb-tag').value;
  const fu = document.getElementById('tb-upgrade').value;  // ''|'1'
  const fw = document.getElementById('tb-wishlist').value; // ''|'1'

  let list = allGames.filter(g => {
    const copies = entryMap[g.id] || [];
    const owned  = copies.some(c=>c.owned);

    // Owned filter
    if (fo === 'owned' && !owned) return false;   // default: owned only
    if (fo === '1'     && !owned) return false;   // explicit owned
    if (fo === '0'     && owned)  return false;   // not owned
    // fo === 'all' → show everything

    if (q  && !g.title.toLowerCase().includes(q)) return false;
    if (fq && !copies.some(c=>c.quality===fq)) return false;
    if (fc && !copies.some(c=>c.completeness===fc)) return false;
    if (fp && !copies.some(c=>c.played_status===fp)) return false;
    if (ftag && !copies.some(c=>c.tag===ftag)) return false;
    if (fu === '1' && !copies.some(c=>c.upgrade))  return false;
    if (fw === '1' && !copies.some(c=>c.wishlist)) return false;
    return true;
  });

  const qualOrder = {Mint:0,Good:1,Fair:2,Poor:3,'':4};
  list.sort((a,b) => {
    let va, vb;
    const ca = (entryMap[a.id]||[]).find(c=>c.copy_number==1)||{};
    const cb = (entryMap[b.id]||[]).find(c=>c.copy_number==1)||{};
    switch(sortKey) {
      case 'title':        va=a.sort_title||a.title; vb=b.sort_title||b.title; break;
      case 'owned':        va=(entryMap[a.id]||[]).some(c=>c.owned)?0:1; vb=(entryMap[b.id]||[]).some(c=>c.owned)?0:1; break;
      case 'quality':      va=qualOrder[ca.quality||'']; vb=qualOrder[cb.quality||'']; break;
      case 'completeness': va=ca.completeness||'zzz'; vb=cb.completeness||'zzz'; break;
      case 'played_status':va=ca.played_status||'zzz'; vb=cb.played_status||'zzz'; break;
      case 'copies':       va=(entryMap[a.id]||[]).length; vb=(entryMap[b.id]||[]).length; break;
      case 'wishlist':      va=(entryMap[a.id]||[]).some(c=>c.wishlist)?0:1; vb=(entryMap[b.id]||[]).some(c=>c.wishlist)?0:1; break;
      case 'upgrade':      va=(entryMap[a.id]||[]).some(c=>c.upgrade)?0:1; vb=(entryMap[b.id]||[]).some(c=>c.upgrade)?0:1; break;
      case 'price_paid':   va=parseFloat(ca.price_paid)||0; vb=parseFloat(cb.price_paid)||0; break;
      case 'chart_price':  va=parseFloat(a.cib_price)||0;   vb=parseFloat(b.cib_price)||0;   break;
      case 'loose_price':  va=parseFloat(a.loose_price)||0; vb=parseFloat(b.loose_price)||0; break;
      case 'new_price':    va=parseFloat(a.new_price)||0;   vb=parseFloat(b.new_price)||0;   break;
      case 'tag':          va=(entryMap[a.id]||[]).find(c=>c.tag)?.tag||'zzz'; vb=(entryMap[b.id]||[]).find(c=>c.tag)?.tag||'zzz'; break;
      default: va=a.title; vb=b.title;
    }
    if (typeof va==='string') { va=va.toLowerCase(); vb=vb.toLowerCase(); }
    return va<vb?-sortDir:va>vb?sortDir:0;
  });

  const tbody = document.getElementById('tbody');
  tbody.innerHTML = '';

  list.forEach(g => {
    const copies    = entryMap[g.id] || [];
    const owned     = copies.some(c=>c.owned);
    const ownedCopies = copies.filter(c=>c.owned);
    const c1        = copies.find(c=>c.copy_number==1) || {};
    const wishlist  = copies.some(c=>c.wishlist);
    const upgrade   = copies.some(c=>c.upgrade);
    const played    = ownedCopies.map(c=>c.played_status).filter(Boolean)[0] || '';

    const tr = document.createElement('tr');
    if (owned) tr.classList.add('is-owned');
    if (wishlist && !owned) tr.classList.add('is-wishlist');
    if (wishlist && owned) tr.classList.add('is-owned-wished');

    const photo = getDisplayPhoto(g);
    let imgCell = '';
    if (photo) {
      imgCell = `<img class="img-thumb" src="${photo.src}" onclick="openLightboxGame(${g.id})" alt="">`;
    } else {
      imgCell = `<div class="img-placeholder" onclick="openDrawer(${g.id})">+</div>`;
    }

    const qcls = {Mint:'q-mint',Good:'q-good',Fair:'q-fair',Poor:'q-poor'}[c1.quality||'']||'q-na';

    // For multi-copy display: only show condition/paid/notes for owned copies
    const displayCopies = ownedCopies; // only owned copies shown in detail columns

    // Condition — owned copies only
    const qualCell = displayCopies.length === 0
      ? `<span class="price-na">—</span>`
      : displayCopies.length > 1
        ? `<div style="display:flex;flex-direction:column;gap:2px;align-items:flex-start">${displayCopies.map(c => {
            const cls = {Mint:'q-mint',Good:'q-good',Fair:'q-fair',Poor:'q-poor'}[c.quality||'']||'q-na';
            return `<span class="qbadge ${cls}">${c.quality||'—'}</span>`;
          }).join('')}</div>`
        : `<span class="qbadge ${{Mint:'q-mint',Good:'q-good',Fair:'q-fair',Poor:'q-poor'}[displayCopies[0].quality||'']||'q-na'}">${displayCopies[0].quality||'—'}</span>`;

    // Completeness — owned copies only
    const compCell = displayCopies.length === 0
      ? `<span class="price-na">—</span>`
      : displayCopies.length > 1
        ? displayCopies.map(c => `<span style="font-size:.68rem;color:var(--text2);display:block;line-height:1.6">${esc(c.completeness||'—')}</span>`).join('')
        : `<span style="font-size:.7rem;color:var(--text2)">${esc(displayCopies[0].completeness||'—')}</span>`;

    // Paid — owned copies only
    const paidCell = displayCopies.length === 0
      ? `<span class="price-na">—</span>`
      : displayCopies.length > 1
        ? displayCopies.map(c => c.price_paid != null
            ? `<span class="price" style="display:block;font-size:.85rem">€${parseFloat(c.price_paid).toFixed(2)}</span>`
            : `<span class="price-na" style="display:block">—</span>`).join('')
        : (displayCopies[0].price_paid != null
            ? `<span class="price">€${parseFloat(displayCopies[0].price_paid).toFixed(2)}</span>`
            : `<span class="price-na">—</span>`);

    // Buy range — from copy 1 only (shared reference)
    let rangeCell = '<span class="price-na">—</span>';
    if (c1.price_min!=null && c1.price_max!=null) rangeCell=`<span class="price-range">€${parseFloat(c1.price_min).toFixed(0)}–€${parseFloat(c1.price_max).toFixed(0)}</span>`;
    else if (c1.price_min!=null) rangeCell=`<span class="price-range">≥€${parseFloat(c1.price_min).toFixed(0)}</span>`;
    else if (c1.price_max!=null) rangeCell=`<span class="price-range">≤€${parseFloat(c1.price_max).toFixed(0)}</span>`;

    // CIB price — PC price in blue (linked), personal in red
    const cibTitle = g.cib_price_updated_at ? `PC CIB — last updated ${g.cib_price_updated_at.substring(0,10)}` : 'PC CIB Price';
    let cibParts = [];
    if (g.cib_price != null) {
      const pcAmt = `€${parseFloat(g.cib_price).toFixed(2)}`;
      cibParts.push(g.pc_link
        ? `<a href="${escAttr(g.pc_link)}" target="_blank" class="price price-chart" style="text-decoration:none" title="${escAttr(cibTitle)}">${pcAmt}</a>`
        : `<span class="price price-chart" title="${escAttr(cibTitle)}">${pcAmt}</span>`);
    } else if (g.pc_link) {
      // No price yet but we have a link — show a clickable "—" in blue
      cibParts.push(`<a href="${escAttr(g.pc_link)}" target="_blank" style="color:var(--wiiu);text-decoration:none;font-size:.75rem" title="View on PriceCharting">PC ↗</a>`);
    }
    if (c1.chart_price != null) {
      cibParts.push(`<span class="price" style="color:#e05a7a" title="Personal price">€${parseFloat(c1.chart_price).toFixed(2)}</span>`);
    }
    const chartCell = cibParts.length ? cibParts.join(' <span style="color:var(--border2)">·</span> ') : `<span class="price-na">—</span>`;

    // Notes — owned copies if any, otherwise wishlist entry notes
    const wishCopy = copies.find(c=>c.wishlist);
    const noteCell = displayCopies.length === 0
      ? (wishCopy?.notes ? `<span style="font-size:.66rem;color:var(--muted);font-style:italic">${esc(wishCopy.notes)}</span>` : `<span class="price-na">—</span>`)
      : displayCopies.length > 1
        ? displayCopies.map(c => `<span style="font-size:.65rem;color:var(--muted);font-style:italic;display:block;line-height:1.6">${esc(c.notes||'—')}</span>`).join('')
        : `<span style="font-size:.66rem;color:var(--muted);font-style:italic">${esc(displayCopies[0].notes||'—')}</span>`;

    const noteText = displayCopies[0]?.notes || wishCopy?.notes || '—';
    const copyCnt = ownedCopies.length > 0
      ? `<span class="chip chip-y">${ownedCopies.length}</span>`
      : `<span class="price-na">—</span>`;
    const playCell = played ? `<span style="font-size:.68rem;color:var(--wiiu)">${esc(played)}</span>` : `<span class="price-na">—</span>`;
    const upReason = copies.find(c=>c.upgrade)?.upgrade_reason || '';

    tr.innerHTML = `
      <td data-col="img">${imgCell}</td>
      <td data-col="owned" style="text-align:center"><div class="owned-check ${owned?'checked':''}" onclick="toggleOwned(${g.id})">${owned?'✓':''}</div></td>
      <td data-col="wishlist" style="text-align:center"><div class="wish-check ${wishlist?'checked':''}" onclick="toggleWishlist(${g.id})">${wishlist?'♥':''}</div></td>
      <td data-col="upgrade" style="text-align:center"><div class="upgrade-check ${upgrade?'checked':''}" onclick="toggleUpgrade(${g.id})" title="${escAttr(upReason)}">${upgrade?'↑':''}</div></td>
      <td data-col="title" class="td-title" style="cursor:pointer" onclick="openDrawer(${g.id})"><span class="game-num">#${String(g.sort_order).padStart(3,'0')}</span>${esc(g.title)}</td>
      <td data-col="quality">${qualCell}</td>
      <td data-col="completeness">${compCell}</td>
      <td data-col="played">${playCell}</td>
      <td data-col="copies">${copyCnt}</td>
      <td data-col="price_paid">${paidCell}</td>
      <td data-col="buy_range">${rangeCell}</td>
      <td data-col="loose_price">${g.loose_price != null ? `<span class="price price-chart" style="color:var(--muted)" title="${escAttr(g.loose_price_updated_at?'Last updated: '+g.loose_price_updated_at.substring(0,10):'Loose Price')}">€${parseFloat(g.loose_price).toFixed(2)}</span>` : `<span class="price-na">—</span>`}</td>
      <td data-col="cib_price">${chartCell}</td>
      <td data-col="new_price">${g.new_price != null ? `<span class="price price-chart" style="color:var(--green)" title="${escAttr(g.new_price_updated_at?'Last updated: '+g.new_price_updated_at.substring(0,10):'New Price')}">€${parseFloat(g.new_price).toFixed(2)}</span>` : `<span class="price-na">—</span>`}</td>
      <td data-col="upgrade_reason" class="note-cell" style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.66rem;color:var(--orange);font-style:italic" title="${escAttr(upReason)}">${upReason ? esc(upReason) : '<span style=\'color:var(--border2)\'>—</span>'}</td>
      <td data-col="tag"><span style="font-size:.68rem;color:var(--wiiu)">${esc(copies.find(c=>c.tag)?.tag||'')|| '<span style=\'color:var(--border2)\'>—</span>'}</span></td>
      <td data-col="notes" class="note-cell" style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.66rem;color:var(--muted);font-style:italic" title="${escAttr(noteText)}">${noteCell}</td>
      <td data-col="__edit"><button class="btn-icon" onclick="openDrawer(${g.id})">Edit</button></td>
    `;
    tbody.appendChild(tr);
  });

  document.getElementById('empty-state').style.display = list.length===0?'block':'none';
  updateStats(list);
  applyColumnVisibility(); // reorder + show/hide after each render
}

function updateStats(filtered) {
  const allOwned  = allGames.filter(g=>(entryMap[g.id]||[]).some(c=>c.owned));
  const tot       = allGames.length;
  const allCopies = Object.values(entryMap).flat().filter(c=>c.owned);

  // Only show owned/copies counts if this system counts toward totals
  const ownedDisplay  = SYS_COUNTS_TOTALS ? allOwned.length : '—';
  const pctDisplay    = SYS_COUNTS_TOTALS ? (tot ? Math.round(allOwned.length/tot*100)+'%' : '0%') : '—';
  const copiesDisplay = SYS_COUNTS_TOTALS ? allCopies.length : '—';

  document.getElementById('st-owned').textContent   = ownedDisplay;
  document.getElementById('st-pct').textContent     = pctDisplay;
  document.getElementById('st-copies').textContent  = copiesDisplay;
  document.getElementById('st-upgrade').textContent = allCopies.filter(c=>c.upgrade).length;
  document.getElementById('st-spent').textContent   = '€'+Math.round(allCopies.reduce((s,c)=>s+(parseFloat(c.price_paid)||0),0));
  document.getElementById('prog-fill').style.width  = tot?(allOwned.length/tot*100)+'%':'0%';
  document.getElementById('prog-text').textContent  = allOwned.length+' / '+tot;

  // CIB totals from games table
  const cibAll   = allGames.reduce((s,g)=>s+(parseFloat(g.cib_price)||0),0);
  // Owned value — uses each copy's selected price type
  let ownedValue = 0;
  allGames.forEach(g => {
    (entryMap[g.id]||[]).filter(c=>c.owned).forEach(c => {
      const ptype = c.value_price_type || 'cib';
      const price = ptype === 'loose' ? parseFloat(g.loose_price)||0
                  : ptype === 'new'   ? parseFloat(g.new_price)||0
                  : parseFloat(g.cib_price)||0;
      ownedValue += price;
    });
  });
  document.getElementById('st-cib-all').textContent   = '€'+Math.round(cibAll);
  document.getElementById('st-cib-owned').textContent = '€'+Math.round(ownedValue);

  const fCopies   = filtered.map(g=>entryMap[g.id]||[]).flat();
  const fOwned    = filtered.filter(g=>(entryMap[g.id]||[]).some(c=>c.owned));
  const fSpend    = fCopies.reduce((s,c)=>s+(parseFloat(c.price_paid)||0),0);
  const fCibAll   = filtered.reduce((s,g)=>s+(parseFloat(g.cib_price)||0),0);
  const fCibOwned = fOwned.reduce((s,g)=>s+(parseFloat(g.cib_price)||0),0);
  document.getElementById('sum-show').textContent  = filtered.length;
  document.getElementById('sum-tot').textContent   = allGames.length;
  document.getElementById('sum-own').textContent   = fOwned.length;
  document.getElementById('sum-spend').textContent = '€'+fSpend.toFixed(2);
  document.getElementById('sum-chart').textContent = fCibAll > 0 ? '€'+fCibAll.toFixed(2) : '—';
}

// SORT
document.querySelectorAll('th[data-sort]').forEach(th => {
  th.addEventListener('click', () => {
    const k = th.dataset.sort;
    if (sortKey===k) sortDir*=-1; else {sortKey=k;sortDir=1;}
    document.querySelectorAll('th').forEach(t=>t.classList.remove('sorted'));
    th.classList.add('sorted'); render();
  });
});

// TOGGLE OWNED — independent of wishlist
async function toggleOwned(gameId) {
  const copies   = entryMap[gameId]||[];
  const c1       = copies.find(c=>c.copy_number==1);
  const newOwned = c1 ? !c1.owned : true;
  // Preserve all existing values, only change owned
  const existing = c1 || {};
  const res = await apiFetch('/api/entry_save.php', {
    game_id:        gameId,
    copy_number:    1,
    owned:          newOwned ? 1 : 0,
    wishlist:       existing.wishlist ? 1 : 0,
    upgrade:        existing.upgrade  ? 1 : 0,
    quality:        existing.quality        || '',
    completeness:   existing.completeness   || '',
    played_status:  existing.played_status  || '',
    price_paid:     existing.price_paid     ?? null,
    chart_price:    existing.chart_price    ?? null,
    price_min:      existing.price_min      ?? null,
    price_max:      existing.price_max      ?? null,
    upgrade_reason: existing.upgrade_reason || '',
    notes:          existing.notes          || '',
    primary_photo:  existing.primary_photo  || null,
  });
  if (res.ok) {
    if (!entryMap[gameId]) entryMap[gameId] = [];
    const idx = entryMap[gameId].findIndex(c=>c.copy_number==1);
    if (idx>=0) entryMap[gameId][idx] = {...entryMap[gameId][idx], ...res.entry};
    else entryMap[gameId].push(res.entry);
    render(); toast(newOwned ? '✓ Added to collection' : 'Removed from collection');
  }
}

// TOGGLE WISHLIST — independent of owned
async function toggleWishlist(gameId) {
  const copies  = entryMap[gameId]||[];
  const c1      = copies.find(c=>c.copy_number==1);
  const newWish = c1 ? !c1.wishlist : true;
  const existing = c1 || {};
  const res = await apiFetch('/api/entry_save.php', {
    game_id:        gameId,
    copy_number:    1,
    owned:          existing.owned   ? 1 : 0,
    wishlist:       newWish ? 1 : 0,
    upgrade:        existing.upgrade ? 1 : 0,
    quality:        existing.quality        || '',
    completeness:   existing.completeness   || '',
    played_status:  existing.played_status  || '',
    price_paid:     existing.price_paid     ?? null,
    chart_price:    existing.chart_price    ?? null,
    price_min:      existing.price_min      ?? null,
    price_max:      existing.price_max      ?? null,
    upgrade_reason: existing.upgrade_reason || '',
    notes:          existing.notes          || '',
    primary_photo:  existing.primary_photo  || null,
  });
  if (res.ok) {
    if (!entryMap[gameId]) entryMap[gameId] = [];
    const idx = entryMap[gameId].findIndex(c=>c.copy_number==1);
    if (idx>=0) entryMap[gameId][idx] = {...entryMap[gameId][idx], ...res.entry};
    else entryMap[gameId].push(res.entry);
    render(); toast(newWish ? '♥ Added to wishlist' : 'Removed from wishlist');
  }
}

// TOGGLE UPGRADE — quick toggle from table
async function toggleUpgrade(gameId) {
  const copies     = entryMap[gameId]||[];
  const c1         = copies.find(c=>c.copy_number==1);
  const newUpgrade = c1 ? !c1.upgrade : true;
  const existing   = c1 || {};
  const res = await apiFetch('/api/entry_save.php', {
    game_id:        gameId,
    copy_number:    1,
    owned:          existing.owned    ? 1 : 0,
    wishlist:       existing.wishlist ? 1 : 0,
    upgrade:        newUpgrade ? 1 : 0,
    quality:        existing.quality        || '',
    completeness:   existing.completeness   || '',
    played_status:  existing.played_status  || '',
    price_paid:     existing.price_paid     ?? null,
    chart_price:    existing.chart_price    ?? null,
    price_min:      existing.price_min      ?? null,
    price_max:      existing.price_max      ?? null,
    upgrade_reason: existing.upgrade_reason || '',
    notes:          existing.notes          || '',
    primary_photo:  existing.primary_photo  || null,
  });
  if (res.ok) {
    if (!entryMap[gameId]) entryMap[gameId] = [];
    const idx = entryMap[gameId].findIndex(c=>c.copy_number==1);
    if (idx>=0) entryMap[gameId][idx] = {...entryMap[gameId][idx], ...res.entry};
    else entryMap[gameId].push(res.entry);
    render(); toast(newUpgrade ? '↑ Marked for upgrade' : 'Upgrade removed');
  }
}

// DRAWER
function openDrawer(gameId) {
  editGameId = gameId;
  const g = allGames.find(x=>x.id==gameId); if (!g) return;
  document.getElementById('d-title').textContent  = g.title;
  document.getElementById('d-system').textContent = SYS_NAME;

  // Show CIB price from PriceCharting if available
  // Show PC prices
  const pcRow  = document.getElementById('d-pc-prices-row');
  const pcLinkEl = document.getElementById('d-pc-link');
  const hasAnyPrice = g.loose_price != null || g.cib_price != null || g.new_price != null;

  if (hasAnyPrice || g.pc_link) {
    pcRow.style.display = 'block';

    function setPriceRow(rowId, valId, price, updatedAt) {
      const row = document.getElementById(rowId);
      if (price != null) {
        row.style.display = '';
        const title = updatedAt ? `Last updated: ${updatedAt.substring(0,10)}` : '';
        document.getElementById(valId).innerHTML = title
          ? `<span title="${title}" style="cursor:help;border-bottom:1px dashed var(--muted)">€${parseFloat(price).toFixed(2)}</span>`
          : `€${parseFloat(price).toFixed(2)}`;
      } else { row.style.display = 'none'; }
    }
    setPriceRow('d-loose-row','d-loose-price-val', g.loose_price, g.loose_price_updated_at);
    setPriceRow('d-cib-row',  'd-cib-price-val',   g.cib_price,   g.cib_price_updated_at);
    setPriceRow('d-new-row',  'd-new-price-val',   g.new_price,   g.new_price_updated_at);

    if (g.pc_link) { pcLinkEl.href = g.pc_link; pcLinkEl.style.display = 'inline'; }
    else pcLinkEl.style.display = 'none';
  } else {
    pcRow.style.display = 'none';
  }

  // Build external search links
  const extWrap = document.getElementById('d-ext-links');
  const linksList = document.getElementById('d-links-list');
  linksList.innerHTML = '';
  const sysShort = SYS_SHORT || '';
  const titleEnc = encodeURIComponent(g.title.replace(/['"]/g,''));
  const region   = 'PAL';

  // Wikipedia
  const wikiUrl = `https://wikipedia.org/w/index.php?search=${titleEnc}`;
  linksList.innerHTML += `<a href="${wikiUrl}" target="_blank" style="font-size:.75rem;color:var(--text2);text-decoration:none">🔍 Wikipedia: ${esc(g.title)}</a>`;

  // Auction sites from user prefs
  const auctionSites = window.USER_AUCTION_SITES || [];
  auctionSites.forEach(site => {
    const url = site.url_template
      .replace('{system}', encodeURIComponent(sysShort.toLowerCase()))
      .replace('{title}',  titleEnc)
      .replace('{region}', region);
    linksList.innerHTML += `<a href="${url}" target="_blank" style="font-size:.75rem;color:var(--text2);text-decoration:none">🛒 ${esc(site.label)}: ${esc(g.title)}</a>`;
  });
  extWrap.style.display = 'block';

  editCopy = 1;
  renderCopyTabs(); loadCopyIntoForm(editCopy);
  document.getElementById('drawer-backdrop').classList.add('open');
}

function renderCopyTabs() {
  const copies  = entryMap[editGameId]||[];
  const maxCopy = copies.length?Math.max(...copies.map(c=>c.copy_number)):1;
  const tabs    = document.getElementById('copy-tabs');
  tabs.innerHTML= '';
  for (let i=1;i<=maxCopy;i++) {
    const btn=document.createElement('button');
    btn.className='copy-tab'+(i===editCopy?' active':'');
    btn.textContent='Copy '+i;
    const ii=i;
    btn.onclick=()=>{editCopy=ii;renderCopyTabs();loadCopyIntoForm(ii);};
    tabs.appendChild(btn);
  }
  const add=document.createElement('button');
  add.className='copy-tab-add'; add.textContent='+ Add Copy';
  add.onclick=()=>{const next=maxCopy+1;if(!entryMap[editGameId])entryMap[editGameId]=[];editCopy=next;renderCopyTabs();loadCopyIntoForm(next);};
  tabs.appendChild(add);
}

function loadCopyIntoForm(copyNum) {
  const c=(entryMap[editGameId]||[]).find(x=>x.copy_number==copyNum)||{};
  setTog('d-owned',   c.owned||false,   'lbl-owned',   c.owned?'In collection':'Not owned');
  document.getElementById('d-quality').value      = c.quality       ||'';
  document.getElementById('d-completeness').value = c.completeness  ||'';
  document.getElementById('d-played').value       = c.played_status ||'';
  document.getElementById('d-price').value        = c.price_paid   !=null?c.price_paid:'';
  document.getElementById('d-chart').value        = c.chart_price  !=null?c.chart_price:'';
  document.getElementById('d-min').value          = c.price_min    !=null?c.price_min:'';
  document.getElementById('d-max').value          = c.price_max    !=null?c.price_max:'';
  setTog('d-upgrade', c.upgrade||false, 'lbl-upgrade', c.upgrade?'Upgrade wanted':'No upgrade needed');
  setTog('d-wishlist',c.wishlist||false,'lbl-wishlist',c.wishlist?'On wishlist':'Not on wishlist');
  document.getElementById('d-upgrade-reason').value = c.upgrade_reason||'';
  document.getElementById('d-notes').value           = c.notes||'';
  document.getElementById('d-tag').value = c.tag||'';
  const vtype = c.value_price_type || 'cib';
  document.querySelectorAll('input[name="d-value-type"]').forEach(r => r.checked = r.value === vtype);
  renderPhotoGrid(c.photos||[], c.primary_photo||'', c.id||null);
}

function closeDrawer() { document.getElementById('drawer-backdrop').classList.remove('open'); editGameId=null; }
function handleBdClick(e){if(e.target===document.getElementById('drawer-backdrop'))closeDrawer();}

async function saveEntry() {
  if (!editGameId) return;
  const payload = {
    game_id:editGameId, copy_number:editCopy,
    owned:          document.getElementById('d-owned').checked?1:0,
    quality:        document.getElementById('d-quality').value,
    completeness:   document.getElementById('d-completeness').value,
    played_status:  document.getElementById('d-played').value,
    price_paid:     document.getElementById('d-price').value||null,
    chart_price:    document.getElementById('d-chart').value||null,
    price_min:      document.getElementById('d-min').value||null,
    price_max:      document.getElementById('d-max').value||null,
    wishlist:       document.getElementById('d-wishlist').checked?1:0,
    upgrade:        document.getElementById('d-upgrade').checked?1:0,
    upgrade_reason: document.getElementById('d-upgrade-reason').value,
    notes:          document.getElementById('d-notes').value,
    tag:            document.getElementById('d-tag').value,
    value_price_type: document.querySelector('input[name="d-value-type"]:checked')?.value || 'cib',
    primary_photo:  document.getElementById('d-primary').value||null,
  };
  const res = await apiFetch('/api/entry_save.php', payload);
  if (res.ok) {
    if (!entryMap[editGameId]) entryMap[editGameId]=[];
    const idx=entryMap[editGameId].findIndex(c=>c.copy_number==editCopy);
    if (idx>=0) entryMap[editGameId][idx]={...entryMap[editGameId][idx],...res.entry};
    else entryMap[editGameId].push(res.entry);
    render(); closeDrawer(); toast('Saved');
  } else { toast('Error: '+res.error,true); }
}

// PHOTOS
async function uploadPhotos(e) {
  const files = Array.from(e.target.files);
  const copies = entryMap[editGameId] || [];
  const cp = copies.find(x => x.copy_number == editCopy);
  const entryId = cp?.id;
  if (!entryId) { toast('Save the entry first before adding photos.', true); e.target.value = ''; return; }
  for (const file of files) {
    const fd = new FormData();
    fd.append('entry_id', entryId);
    fd.append('photo', file);
    const res = await fetch(`${BASE}/api/photo_upload.php`, {method:'POST', body:fd}).then(r => r.json());
    if (res.ok) {
      const idx = entryMap[editGameId].findIndex(x => x.copy_number == editCopy);
      if (!entryMap[editGameId][idx].photos) entryMap[editGameId][idx].photos = [];
      entryMap[editGameId][idx].photos.push(res.filename);
      // Show the new photo immediately in the grid
      renderPhotoGrid(
        entryMap[editGameId][idx].photos,
        entryMap[editGameId][idx].primary_photo || '',
        entryMap[editGameId][idx].id || null
      );
    } else {
      toast('Upload failed: ' + (res.error || ''), true);
    }
  }
  e.target.value = '';
  render();
  toast('Photo(s) added');
}

function renderPhotoGrid(photos, primaryPhoto, entryId) {
  const grid = document.getElementById('d-photo-grid');
  grid.innerHTML = '';
  (photos||[]).forEach((fn, i) => {
    const div = document.createElement('div');
    div.className = 'img-preview-item';
    const isPrimary = fn === primaryPhoto;
    const eid = entryId || 'null';
    const cleanFn = fn.split('?')[0];
    const ts = photoTs[cleanFn];
    const src = `${BASE}/uploads/users/${cleanFn}${ts ? '?t='+ts : '?t='+Date.now()}`;
    div.innerHTML = `
      <img src="${escAttr(src)}"
           onclick="openLightboxArr(${JSON.stringify(photos)},${i},${eid})"
           style="${isPrimary ? 'border-color:var(--accent2)' : ''}">
      <div style="display:flex;gap:2px;margin-top:2px">
        <button class="img-del-btn" style="position:static;width:auto;padding:0 5px;font-size:.65rem" onclick="rotatePhoto(${i},-90)">↺</button>
        <button class="img-del-btn" style="position:static;width:auto;padding:0 5px;font-size:.65rem;color:var(--muted)" onclick="rotatePhoto(${i},90)">↻</button>
        <button class="img-del-btn" style="position:static;width:auto;flex:1;font-size:.65rem;color:var(--red)" onclick="deletePhoto(${i})">✕</button>
      </div>`;
    grid.appendChild(div);
  });

  // Primary photo selector
  const wrap = document.getElementById('d-primary-wrap');
  const sel  = document.getElementById('d-primary');
  const g    = allGames.find(x => x.id == editGameId);
  const hasDefault = g && g.default_image;

  if (hasDefault || (photos && photos.length > 1)) {
    wrap.style.display = 'block';
    sel.innerHTML = '<option value="">— First uploaded photo —</option>';
    if (hasDefault) {
      const opt = document.createElement('option');
      opt.value = '__default__';
      opt.textContent = 'Default cover image';
      opt.selected = (primaryPhoto === '__default__');
      sel.appendChild(opt);
    }
    (photos||[]).forEach((fn, i) => {
      const opt = document.createElement('option');
      opt.value = fn;
      opt.textContent = 'My photo ' + (i + 1);
      opt.selected = (fn === primaryPhoto);
      sel.appendChild(opt);
    });
  } else {
    wrap.style.display = 'none';
    sel.value = '';
  }
}

async function rotatePhoto(idx, degrees) {
  const copies = entryMap[editGameId]||[];
  const c = copies.find(x=>x.copy_number==editCopy);
  if (!c||!c.photos) return;
  const fn = c.photos[idx].split('?')[0]; // clean filename
  toast('Rotating...');
  const res = await apiFetch('/api/photo_rotate.php', {entry_id:c.id, filename:fn, degrees});
  if (res.ok) {
    photoTs[fn] = res.ts; // store so lightbox also picks up new version
    renderPhotoGrid(c.photos, c.primary_photo||'', c.id||null);
    render();
    toast('Photo rotated');
  } else { toast('Rotation failed: '+(res.error||''), true); }
}

async function deletePhoto(idx) {
  const copies=entryMap[editGameId]||[];
  const c=copies.find(x=>x.copy_number==editCopy);
  if (!c||!c.photos) return;
  const fn=c.photos[idx];
  const res=await apiFetch('/api/photo_delete.php',{entry_id:c.id,filename:fn});
  if (res.ok) {
    c.photos.splice(idx,1);
    if (c.primary_photo===fn) c.primary_photo='';
    renderPhotoGrid(c.photos, c.primary_photo||'', c.id||null);
    render(); toast('Photo removed');
  }
}

// LIGHTBOX

function openLightboxGame(gameId) {
  const copies = (entryMap[gameId]||[]);
  const photos = copies.map(c=>c.photos||[]).flat();
  if (!photos.length) return;
  // Find entry that has these photos
  const c = copies.find(c=>c.photos&&c.photos.length);
  lbContext = c ? {entryId: c.id, photos: c.photos} : null;
  openLightboxArr(photos, 0);
}

function openLightboxArr(photos, startIdx, entryId=null) {
  // Build URLs using cached timestamps so rotated images show correctly
  lbRawNames = photos.map(fn => fn.split('?')[0]); // always clean filenames
  lbImages   = lbRawNames.map(fn => {
    const ts = photoTs[fn];
    return `${BASE}/uploads/users/${fn}${ts ? '?t='+ts : ''}`;
  });
  lbIdx = startIdx;
  if (entryId) lbContext = {entryId, photos};
  document.getElementById('lb-rotate-nav').style.display = entryId || lbContext ? 'flex' : 'none';
  showLbImg();
  document.getElementById('lightbox').classList.add('open');
}

function showLbImg(){
  document.getElementById('lb-img').src = lbImages[lbIdx];
  document.getElementById('lb-lbl').textContent=(lbIdx+1)+' / '+lbImages.length;
}
function lbPrev(e){e.stopPropagation();lbIdx=(lbIdx-1+lbImages.length)%lbImages.length;showLbImg();}
function lbNext(e){e.stopPropagation();lbIdx=(lbIdx+1)%lbImages.length;showLbImg();}
function closeLightbox(){document.getElementById('lightbox').classList.remove('open');}

async function lbRotate(e, degrees) {
  e.stopPropagation();
  if (!lbContext) return;
  const fn = lbRawNames[lbIdx].split('?')[0]; // clean filename
  if (!fn) return;
  toast('Rotating...');
  const res = await apiFetch('/api/photo_rotate.php', {entry_id: lbContext.entryId, filename: fn, degrees});
  if (res.ok) {
    // Store timestamp so future openLightbox calls use the rotated version
    photoTs[fn] = res.ts;
    const newSrc = `${BASE}/uploads/users/${fn}?t=${res.ts}`;
    lbImages[lbIdx]   = newSrc;
    lbRawNames[lbIdx] = fn;
    document.getElementById('lb-img').src = newSrc;
    // Also update photo grid thumbnails if drawer is open
    if (editGameId) {
      const copies = entryMap[editGameId] || [];
      const c = copies.find(x => x.id == lbContext.entryId);
      if (c) renderPhotoGrid(c.photos, c.primary_photo || '', c.id || null);
    }
    // Update table thumbnail
    render();
    toast('Photo rotated');
  } else {
    toast('Rotation failed', true);
  }
}


// SYSTEM SWITCH
function switchSystem(id) {
  document.cookie=`last_system=${id};path=/;max-age=${60*60*24*365}`;
  window.location=`${BASE}/collection.php?s=${id}`;
}

// SHOW ALL
// HELPERS
function setTog(inputId,val,labelId,text){document.getElementById(inputId).checked=val;document.getElementById(labelId).textContent=text;}
document.getElementById('d-owned').addEventListener('change',function(){document.getElementById('lbl-owned').textContent=this.checked?'In collection':'Not owned';});
document.getElementById('d-upgrade').addEventListener('change',function(){document.getElementById('lbl-upgrade').textContent=this.checked?'Upgrade wanted':'No upgrade needed';});
document.getElementById('d-wishlist').addEventListener('change',function(){document.getElementById('lbl-wishlist').textContent=this.checked?'On wishlist':'Not on wishlist';});
async function apiFetch(path,body){return fetch(BASE+path,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(r=>r.json());}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function escAttr(s){return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function toast(msg,err=false){const t=document.getElementById('toast');t.textContent=msg;t.style.borderColor=err?'var(--red)':'var(--accent2)';t.style.color=err?'var(--red)':'var(--accent)';t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2500);}

// ── FILTER BUTTON HELPERS ──
function setOwned(val) {
  document.getElementById('tb-owned').value = val;
  document.getElementById('fbtn-owned-showall').className = 'filter-btn' + (val==='all'   ? ' active-notown'  : '');
  document.getElementById('fbtn-owned-yes').className     = 'filter-btn' + (val==='1'||val==='owned' ? ' active-owned' : '');
  document.getElementById('fbtn-owned-no').className      = 'filter-btn' + (val==='0'     ? ' active-notown'  : '');
  render();
}

function setWishlist(val) {
  document.getElementById('tb-wishlist').value = val;
  document.getElementById('fbtn-wish-off').className = 'filter-btn' + (val===''  ? ' active-wish' : '');
  document.getElementById('fbtn-wish-on').className  = 'filter-btn' + (val==='1' ? ' active-wish' : '');
  render();
}

function setUpgrade(val) {
  document.getElementById('tb-upgrade').value = val;
  document.getElementById('fbtn-up-off').className = 'filter-btn' + (val===''  ? ' active-upgrade' : '');
  document.getElementById('fbtn-up-on').className  = 'filter-btn' + (val==='1' ? ' active-upgrade' : '');
  render();
}

// Init button states — default: show owned only
setOwned('owned'); setWishlist(''); setUpgrade('');

// Dropdown filter listeners
['tb-search','tb-quality','tb-completeness','tb-played','tb-tag'].forEach(id=>{
  document.getElementById(id)?.addEventListener('input',render);
  document.getElementById(id)?.addEventListener('change',render);
});
document.addEventListener('keydown',e=>{
  if(e.key==='Escape'){if(document.getElementById('lightbox').classList.contains('open'))closeLightbox();else closeDrawer();}
  if(document.getElementById('lightbox').classList.contains('open')){
    if(e.key==='ArrowLeft')lbPrev({stopPropagation:()=>{}});
    if(e.key==='ArrowRight')lbNext({stopPropagation:()=>{}});
  }
});

init();
</script>
</body>
</html>
