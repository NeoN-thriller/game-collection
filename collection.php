<?php
require_once __DIR__ . '/boot.php';
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

$gradeLabels = gradingConfig()['labels'];

// Editions and print variants (per user)
$editionMode   = ($user['edition_mode'] ?? 'one') === 'every' ? 'every' : 'one';
$trackVariants = !empty($user['track_variants']);
$variantOpts   = [];
if ($trackVariants) {
    $vq = db()->prepare("SELECT label FROM user_variant_options WHERE user_id=? ORDER BY sort_order");
    $vq->execute([$user['id']]); $variantOpts = $vq->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pageTitle($curSys['name'] ?? tRaw('common.nav.collection')) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<?= themeHead($user) ?>
<?= csrfScript() ?>
<?= appScript(['coll', 'drawer', 'grading', 'ed']) ?>
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/collection.php" class="site-logo" style="text-decoration:none"><?= siteLogoHtml() ?></a>
  <div id="hstats" class="hstats">
    <div class="hstat"><div class="hstat-val blue"  id="st-owned">0</div><div class="hstat-label"><?= t('dashboard.owned') ?></div></div>
    <div class="hstat"><div class="hstat-val"        id="st-pct">0%</div><div class="hstat-label"><?= t('coll.complete') ?></div></div>
    <div class="hstat"><div class="hstat-val green"  id="st-copies">0</div><div class="hstat-label"><?= t('dashboard.copies') ?></div></div>
    <div class="hstat"><div class="hstat-val orange" id="st-upgrade">0</div><div class="hstat-label"><?= t('dashboard.upgrade') ?></div></div>
    <div class="hstat"><div class="hstat-val"        id="st-spent"><?= money(0, 0) ?></div><div class="hstat-label"><?= t('dashboard.spent') ?></div></div>
    <div class="hstat"><div class="hstat-val blue"   id="st-cib-all"><?= money(0, 0) ?></div><div class="hstat-label"><?= t('dashboard.cib_all') ?></div></div>
    <div class="hstat"><div class="hstat-val green"  id="st-cib-owned"><?= money(0, 0) ?></div><div class="hstat-label"><?= t('dashboard.owned_value') ?></div></div>
  </div>
  <nav class="site-nav">
    <span class="nav-user">👤 <?= htmlspecialchars($user['username']) ?></span>
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link"><?= t('common.nav.dashboard') ?></a>
    <a href="<?= BASE_URL ?>/wishlist.php" class="nav-link"><?= t('common.nav.wishlist') ?></a>
    <a href="<?= BASE_URL ?>/settings.php" class="nav-link"><?= t('common.nav.settings') ?></a>
    <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link"><?= t('common.nav.sign_out') ?></a>
  </nav>
</header>

<div class="progress-wrap">
  <div class="progress-track"><div class="progress-fill" id="prog-fill" style="width:0%"></div></div>
  <div class="progress-label"><strong id="prog-text">0 / 0</strong> <?= t('coll.owned_lc') ?></div>
</div>

<div class="system-bar">
  <?php foreach ($systems as $s): ?>
  <button class="sys-btn <?= $s['id']==$sysId?'active':'' ?>" onclick="switchSystem(<?= $s['id'] ?>)"><?= htmlspecialchars($s['short_name']) ?></button>
  <?php endforeach; ?>
</div>

<div class="toolbar">
  <div class="search-wrap">
    <span class="search-icon">⌕</span>
    <input type="text" id="tb-search" placeholder="<?= t('coll.search') ?>">
  </div>
  <select id="tb-quality">
    <option value="" disabled selected>— <?= t('coll.all_conditions') ?> —</option>
    <option value=""><?= t('coll.all_conditions') ?></option>
    <?php foreach ($gradeLabels as $l): ?><option value="label:<?= $l['id'] ?>"><?= htmlspecialchars($l['name']) ?> (<?= $l['min_score'] ?>+)</option><?php endforeach; ?>
    <option value="m:points"><?= t('coll.point_grades') ?></option>
    <option value="m:simple"><?= t('coll.simple_grades') ?></option>
    <option value="m:none"><?= t('coll.not_graded') ?></option>
  </select>
  <select id="tb-minscore" style="max-width:150px">
    <option value="" disabled selected>— <?= t('coll.min_score') ?> —</option>
    <option value=""><?= t('coll.any_score') ?></option>
    <?php foreach ([50,60,70,80,85,90,95] as $m): ?><option value="<?= $m ?>"><?= t('coll.score_min', ['n' => $m]) ?></option><?php endforeach; ?>
  </select>
  <select id="tb-completeness">
    <option value="" disabled selected>— <?= t('coll.all_completeness') ?> —</option>
    <option value=""><?= t('coll.all_completeness') ?></option>
    <?php foreach ($compOpts as $c): ?><option><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
  </select>
  <select id="tb-played">
    <option value="" disabled selected>— <?= t('coll.all_played') ?> —</option>
    <option value=""><?= t('coll.all_played') ?></option>
    <?php foreach ($playedOpts as $p): ?><option><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
  </select>
  <select id="tb-edition" hidden aria-label="<?= t('ed.edition') ?>">
    <option value=""><?= t('ed.all_editions') ?></option>
  </select>
  <?php if ($trackVariants && $variantOpts): ?>
  <select id="tb-variant" aria-label="<?= t('ed.variant') ?>">
    <option value=""><?= t('ed.all_variants') ?></option>
    <?php foreach ($variantOpts as $v): ?><option><?= htmlspecialchars($v) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select id="tb-tag">
    <option value="" disabled selected>— <?= t('coll.all_tags') ?> —</option>
    <option value=""><?= t('coll.all_tags') ?></option>
    <?php foreach ($tagOpts as $t): ?><option><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
  </select>
  <!-- Owned filter — Show All / Owned / Not Owned -->
  <div class="filter-btn-group">
    <button class="filter-btn" id="fbtn-owned-showall" onclick="setOwned('all')"  title="<?= t('coll.f_all_title') ?>"><?= t('coll.f_all') ?></button>
    <button class="filter-btn" id="fbtn-owned-yes"     onclick="setOwned('1')"   title="<?= t('coll.f_owned_title') ?>">✓ <?= t('coll.f_own') ?></button>
    <button class="filter-btn" id="fbtn-owned-no"      onclick="setOwned('0')"   title="<?= t('coll.f_notowned_title') ?>">✗ <?= t('coll.f_own') ?></button>
  </div>
  <div class="filter-btn-group">
    <button class="filter-btn" id="fbtn-wish-off"  onclick="setWishlist('')"  title="<?= t('coll.f_any') ?>">♥ <?= t('coll.f_all') ?></button>
    <button class="filter-btn" id="fbtn-wish-on"   onclick="setWishlist('1')" title="<?= t('dashboard.wishlisted') ?>">♥ <?= t('coll.f_yes') ?></button>
  </div>
  <div class="filter-btn-group">
    <button class="filter-btn" id="fbtn-up-off"    onclick="setUpgrade('')"   title="<?= t('coll.f_any') ?>">↑ <?= t('coll.f_all') ?></button>
    <button class="filter-btn" id="fbtn-up-on"     onclick="setUpgrade('1')"  title="<?= t('coll.f_upgrade_title') ?>">↑ <?= t('coll.f_yes') ?></button>
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
        <th data-col="img" style="width:36px"><?= t('coll.th_img') ?></th>
        <th data-col="owned" style="width:28px;text-align:center" data-sort="owned"><?= t('coll.th_own') ?></th>
        <th data-col="wishlist" style="width:28px;text-align:center" data-sort="wishlist">♥</th>
        <th data-col="upgrade" style="width:28px;text-align:center" data-sort="upgrade">↑</th>
        <th data-col="title" data-sort="title"><?= t('common.col.title') ?> ↕</th>
        <th data-col="edition" data-sort="edition"><?= t('ed.edition') ?> ↕</th>
        <th data-col="variant" data-sort="variant"><?= t('ed.variant') ?> ↕</th>
        <th data-col="quality" data-sort="quality"><?= t('coll.th_cond') ?> ↕</th>
        <th data-col="completeness" data-sort="completeness"><?= t('coll.complete') ?> ↕</th>
        <th data-col="played" data-sort="played_status"><?= t('common.col.played') ?> ↕</th>
        <th data-col="copies" data-sort="copies"><?= t('common.col.copies') ?> ↕</th>
        <th data-col="price_paid" data-sort="price_paid"><?= t('common.col.price_paid') ?> ↕</th>
        <th data-col="buy_range"><?= t('common.col.buy_range') ?></th>
        <th data-col="loose_price" data-sort="loose_price"><?= t('common.price.loose') ?> ↕</th>
        <th data-col="cib_price" data-sort="chart_price"><?= t('common.col.cib_price') ?> ↕</th>
        <th data-col="new_price" data-sort="new_price"><?= t('common.price.new') ?> ↕</th>
        <th data-col="upgrade_reason"><?= t('common.col.upgrade_reason') ?></th>
        <th data-col="tag" data-sort="tag"><?= t('common.col.tag') ?> ↕</th>
        <th data-col="notes"><?= t('coll.th_note') ?></th>
        <th data-col="__edit"></th>
      </tr>
    </thead>
    <tbody id="tbody"></tbody>
  </table>
  <div class="empty-state" id="empty-state">
    <p><?= t('coll.no_results') ?></p><p><?= t('coll.no_results_hint') ?></p>
  </div>
</div>

<div class="summary-bar">
  <?= t('coll.sum_showing') ?> <strong id="sum-show">0</strong> <?= t('coll.sum_of') ?> <strong id="sum-tot">0</strong> &nbsp;·&nbsp;
  <?= t('dashboard.owned') ?>: <strong id="sum-own">0</strong> &nbsp;·&nbsp;
  <?= t('coll.sum_spend') ?>: <strong id="sum-spend"><?= money(0) ?></strong> &nbsp;·&nbsp;
  <?= t('coll.sum_cib') ?>: <strong id="sum-chart">—</strong>
</div>

<!-- EDIT DRAWER -->
<div class="drawer-backdrop" id="drawer-backdrop" onclick="handleBdClick(event)">
  <div class="drawer" id="drawer">
    <div class="drawer-header">
      <div class="drawer-header-info">
        <div class="drawer-title" id="d-title">—</div>
        <div class="drawer-subtitle" id="d-system"></div>
      </div>
      <button class="drawer-close" onclick="closeDrawer()">✕</button>
    </div>
    <div class="drawer-body">

      <div class="drawer-section">
        <div class="section-label"><?= t('common.col.copies') ?></div>
        <div class="copy-tabs" id="copy-tabs"></div>
      </div>

      <!-- Editions + print variant (assets/js/editions-drawer.js) -->
      <div class="drawer-section" id="d-ed-edition" hidden></div>
      <div class="drawer-section" id="d-ed-variant" hidden></div>

      <div class="drawer-section">
        <div class="section-label"><?= t('drawer.ownership') ?></div>
        <div class="toggle-row">
          <label class="toggle"><input type="checkbox" id="d-owned"><span class="toggle-slider"></span></label>
          <span class="toggle-label" id="lbl-owned"><?= t('drawer.not_owned') ?></span>
        </div>
      </div>

      <div class="drawer-section">
        <!-- Condition grading (assets/js/grading.js) — takes the Completeness field into its top row -->
        <div id="d-grading"></div>
        <div class="field" id="d-completeness-field"><label><?= t('common.col.completeness') ?></label>
          <select id="d-completeness">
            <option value="">— <?= t('drawer.na') ?> —</option>
            <?php foreach ($compOpts as $c): ?><option><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field-row">
          <div class="field"><label><?= t('drawer.played_status') ?></label>
            <select id="d-played">
              <option value="">— <?= t('drawer.na') ?> —</option>
              <?php foreach ($playedOpts as $p): ?><option><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="drawer-section">
        <div class="section-label"><?= t('drawer.pricing') ?></div>
        <div id="d-pc-prices-row" style="display:none;margin-bottom:8px">
          <table style="font-size:.75rem;width:100%;border-collapse:collapse">
            <tr id="d-loose-row" style="display:none"><td style="color:var(--muted);padding:2px 0;width:60px"><?= t('common.price.loose') ?></td><td><span id="d-loose-price-val" style="color:var(--wiiu);font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case);font-size:1rem"></span></td></tr>
            <tr id="d-cib-row"   style="display:none"><td style="color:var(--muted);padding:2px 0"><?= t('common.price.cib') ?></td>  <td><span id="d-cib-price-val"   style="color:var(--wiiu);font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case);font-size:1rem"></span></td></tr>
            <tr id="d-new-row"   style="display:none"><td style="color:var(--muted);padding:2px 0"><?= t('common.price.new') ?></td>  <td><span id="d-new-price-val"   style="color:var(--wiiu);font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case);font-size:1rem"></span></td></tr>
          </table>
          <a id="d-pc-link" href="#" target="_blank" style="color:var(--wiiu);font-size:.68rem;display:none"><?= t('drawer.view_pc') ?> ↗</a>
        </div>
        <div id="d-ed-prices-of" hidden></div>
        <div class="section-label" style="margin-top:10px;margin-bottom:6px"><?= t('drawer.value_type') ?></div>
        <div style="display:flex;gap:14px;font-size:.75rem;flex-wrap:wrap" id="d-price-type-wrap">
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer"><input type="radio" name="d-value-type" id="d-vtype-loose" value="loose"> <?= t('common.price.loose') ?></label>
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer"><input type="radio" name="d-value-type" id="d-vtype-cib"   value="cib"   checked> <?= t('common.price.cib') ?></label>
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer"><input type="radio" name="d-value-type" id="d-vtype-new"   value="new"> <?= t('common.price.new') ?></label>
        </div>
        <div class="field-row">
          <div class="field"><label><?= t('drawer.paid', ['sym' => setting('currency_symbol')]) ?></label><input type="number" id="d-price" step="0.01" min="0" placeholder="0.00"></div>
          <div class="field"><label><?= t('drawer.personal_price', ['sym' => setting('currency_symbol')]) ?></label><input type="number" id="d-chart" step="0.01" min="0" placeholder="0.00"></div>
        </div>
        <div class="field-row">
          <div class="field"><label><?= t('drawer.buy_min', ['sym' => setting('currency_symbol')]) ?></label><input type="number" id="d-min" step="0.01" min="0" placeholder="0.00"></div>
          <div class="field"><label><?= t('drawer.buy_max', ['sym' => setting('currency_symbol')]) ?></label><input type="number" id="d-max" step="0.01" min="0" placeholder="0.00"></div>
        </div>
      </div>

      <!-- EXTERNAL LINKS -->
      <div class="drawer-section" id="d-ext-links" style="display:none">
        <div class="section-label"><?= t('drawer.quick_search') ?></div>
        <div id="d-links-list" style="display:flex;flex-direction:column;gap:5px"></div>
      </div>

      <!-- TAG -->
      <div class="drawer-section">
        <div class="section-label"><?= t('common.col.tag') ?></div>
        <select id="d-tag" style="width:100%;padding:8px 10px;font-size:.78rem">
          <option value="">— <?= t('drawer.no_tag') ?> —</option>
          <?php foreach ($tagOpts as $t): ?>
          <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="drawer-section">
        <div class="section-label"><?= t('common.col.upgrade') ?></div>
        <div class="toggle-row">
          <label class="toggle"><input type="checkbox" id="d-upgrade"><span class="toggle-slider"></span></label>
          <span class="toggle-label" id="lbl-upgrade"><?= t('drawer.no_upgrade') ?></span>
        </div>
        <div class="field"><label><?= t('common.col.upgrade_reason') ?></label><textarea id="d-upgrade-reason" placeholder="<?= t('drawer.upgrade_ph') ?>"></textarea></div>
      </div>

      <div class="drawer-section">
        <div class="section-label"><?= t('common.nav.wishlist') ?></div>
        <div class="toggle-row">
          <label class="toggle"><input type="checkbox" id="d-wishlist"><span class="toggle-slider"></span></label>
          <span class="toggle-label" id="lbl-wishlist"><?= t('drawer.not_wished') ?></span>
        </div>
        <div id="d-ed-wish-any" hidden></div>
      </div>

      <div class="drawer-section" id="d-ed-others" hidden></div>

      <div class="drawer-section">
        <div class="section-label"><?= t('common.col.notes') ?></div>
        <div class="field"><textarea id="d-notes" placeholder="<?= t('drawer.notes_ph') ?>"></textarea></div>
      </div>

      <div class="drawer-section">
        <div class="section-label"><?= t('settings.photos') ?></div>
        <div class="img-upload-area">
          <input type="file" id="d-photos" accept="image/*" multiple onchange="uploadPhotos(event)">
          <div class="img-upload-text"><?= t('drawer.add_photos') ?></div>
        </div>
        <div class="img-preview-grid" id="d-photo-grid"></div>
        <div id="d-primary-wrap" style="display:none;margin-top:10px">
          <div class="section-label" style="margin-bottom:6px"><?= t('drawer.primary') ?></div>
          <select id="d-primary" style="font-size:.75rem;padding:6px 10px;width:100%">
            <option value="">— <?= t('drawer.first_photo') ?> —</option>
          </select>
        </div>
      </div>

    </div>
    <div class="drawer-footer">
      <button class="btn-ghost" onclick="closeDrawer()"><?= t('common.cancel') ?></button>
      <button class="btn" onclick="saveEntry()"><?= t('common.save') ?></button>
    </div>
  </div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lb-close" onclick="closeLightbox()">✕</button>
  <img id="lb-img" src="" alt="">
  <div class="lb-nav">
    <button class="lb-btn" onclick="lbPrev(event)">← <?= t('drawer.prev') ?></button>
    <span class="lb-label" id="lb-lbl"></span>
    <button class="lb-btn" onclick="lbNext(event)"><?= t('drawer.next') ?> →</button>
  </div>
  <div class="lb-nav" id="lb-rotate-nav" style="display:none">
    <button class="lb-btn" onclick="lbRotate(event,-90)">↺ <?= t('drawer.rotate_left') ?></button>
    <button class="lb-btn" onclick="lbRotate(event,90)">↻ <?= t('drawer.rotate_right') ?></button>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>window.GRADING = <?= gradingClientJson($user) ?>;</script>
<script src="<?= BASE_URL ?>/assets/js/grading.js?v=<?= @filemtime(__DIR__.'/assets/js/grading.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/editions-drawer.js?v=<?= @filemtime(__DIR__.'/assets/js/editions-drawer.js') ?>"></script>
<script>
const BASE     = <?= json_encode(BASE_URL) ?>;
const USER_ID  = <?= (int)$user['id'] ?>;
const SYS_ID   = <?= (int)$sysId ?>;
const SYS_NAME = <?= json_encode($curSys['name']       ?? '') ?>;
const SYS_SHORT= <?= json_encode($curSys['short_name'] ?? '') ?>;
const SYS_REGION = <?= json_encode($curSys ? systemRegion($curSys) : setting('default_region')) ?>;
const SYS_COUNTS_TOTALS = <?= json_encode((bool)($curSys['count_for_totals'] ?? true)) ?>;
const EDITION_MODE   = <?= json_encode($editionMode) ?>;   // 'one' = editions folded under the game · 'every' = one row per edition
const TRACK_VARIANTS = <?= json_encode($trackVariants) ?>;
const VARIANT_OPTS   = <?= json_encode($variantOpts, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const EDITION_WISHLIST = <?= json_encode(($user['edition_wishlist'] ?? 'any') === 'exact' ? 'exact' : 'any') ?>;
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
const gradeEditor = new GradingUI.Editor({
  root:       document.getElementById('d-grading'),
  compField:  document.getElementById('d-completeness-field'),
  compSelect: document.getElementById('d-completeness'),
});

// Column preferences
const DEFAULT_COL_ORDER = ['img','owned','wishlist','upgrade','title','edition','variant','quality','completeness','played','copies','price_paid','buy_range','loose_price','cib_price','new_price','upgrade_reason','tag','notes'];
const DEFAULT_COL_OFF   = new Set(['variant']);   // hidden until the user turns it on
let activeCols = new Set(DEFAULT_COL_ORDER.filter(id => !DEFAULT_COL_OFF.has(id)));
let colOrder   = [...DEFAULT_COL_ORDER];

async function init() {
  const [gRes, eRes, cRes] = await Promise.all([
    fetch(`${BASE}/api/games.php?system_id=${SYS_ID}`).then(r=>r.json()),
    fetch(`${BASE}/api/entries.php?system_id=${SYS_ID}`).then(r=>r.json()),
    fetch(`${BASE}/api/column_prefs.php`).then(r=>r.json()),
  ]);
  allGames = gRes.games || [];
  buildGroups(gRes.groups || []);
  buildEntryMap(eRes.entries || []);

  // Apply column prefs from saved cols_collection
  const saved = cRes.ok ? (cRes.cols_collection || null) : null;
  if (saved && saved.length) {
    colOrder   = saved.map(c => c.id);
    activeCols = new Set(saved.filter(c => c.on).map(c => c.id));
    // Add any new columns not in saved prefs (with default on)
    DEFAULT_COL_ORDER.forEach(id => {
      if (!colOrder.includes(id)) { colOrder.push(id); if (!DEFAULT_COL_OFF.has(id)) activeCols.add(id); }
    });
  } else {
    colOrder   = [...DEFAULT_COL_ORDER];
    activeCols = new Set(DEFAULT_COL_ORDER.filter(id => !DEFAULT_COL_OFF.has(id)));
  }
  if (!TRACK_VARIANTS) activeCols.delete('variant');   // variants switched off: no column

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
const COL_INDEX = {img:0,owned:1,wishlist:2,upgrade:3,title:4,edition:5,variant:6,quality:7,completeness:8,played:9,copies:10,price_paid:11,buy_range:12,loose_price:13,cib_price:14,new_price:15,upgrade_reason:16,tag:17,notes:18};
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

// ── EDITIONS ──
// groupMap: group id → {id, title, sort_title, main_game_id, members:[games by edition_sort]}.
// Only groups with at least two active games count; a lone edition is shown as a normal game.
let groupMap   = {};
let openGroups = new Set();
const OPEN_KEY = `ed-open-${USER_ID}-${SYS_ID}`;

function buildGroups(groups) {
  const byId = {};
  (groups || []).forEach(gr => { byId[gr.id] = { ...gr, members: [] }; });
  allGames.forEach(g => { if (g.group_id && byId[g.group_id]) byId[g.group_id].members.push(g); });
  groupMap = {};
  Object.values(byId).forEach(gr => {
    if (gr.members.length < 2) return;
    gr.members.sort((a, b) => (+a.edition_sort - +b.edition_sort) || (+a.id - +b.id));
    groupMap[gr.id] = gr;
  });
  allGames.forEach(g => { g._group = groupMap[g.group_id] || null; });
  try { openGroups = new Set(JSON.parse(localStorage.getItem(OPEN_KEY) || '[]').map(String)); } catch { openGroups = new Set(); }

  // Edition filter: the labels present in this system
  const labels = [...new Set(allGames.filter(g => g._group && g.edition_label).map(g => g.edition_label))]
    .sort((a, b) => a.localeCompare(b));
  const sel = document.getElementById('tb-edition');
  if (labels.length) {
    sel.insertAdjacentHTML('beforeend', labels.map(l => `<option value="${escAttr(l)}">${esc(l)}</option>`).join(''));
    sel.hidden = false;
  }
}

function toggleGroup(id) {
  id = String(id);
  openGroups.has(id) ? openGroups.delete(id) : openGroups.add(id);
  try { localStorage.setItem(OPEN_KEY, JSON.stringify([...openGroups])); } catch { /* not remembered */ }
  render();
}

// An item is one table row at the top level: {game} or, in "one per game" mode, {group}
const itemGames  = it => it.group ? it.group.members : [it.game];
const itemCopies = it => itemGames(it).flatMap(g => entryMap[g.id] || []);
const itemOwned  = it => itemCopies(it).some(c => c.owned);
/** Lowest price of the item's games (a group costs its cheapest edition); null when none has one. */
function itemPrice(it, field) {
  const v = itemGames(it).map(g => parseFloat(g[field])).filter(n => !isNaN(n));
  return v.length ? Math.min(...v) : null;
}
function itemSortTitle(it) {
  if (it.group) return it.group.sort_title || it.group.title;
  const g = it.game;
  return g._group ? `${g._group.sort_title || g._group.title}\u0000${String(g.edition_sort).padStart(5, '0')}` : (g.sort_title || g.title);
}
/** All top-level items for the user's mode, unfiltered. */
function allItems() {
  if (EDITION_MODE !== 'one') return allGames.map(game => ({ game }));
  const items = allGames.filter(g => !g._group).map(game => ({ game }));
  Object.values(groupMap).forEach(group => items.push({ group }));
  return items;
}

function readFilters() {
  return {
    q:    document.getElementById('tb-search').value.toLowerCase(),
    fo:   document.getElementById('tb-owned').value,        // 'all'|'owned'|'1'|'0'
    fq:   document.getElementById('tb-quality').value,      // ''|'label:<id>'|'m:points'|'m:simple'|'m:none'
    fmin: parseInt(document.getElementById('tb-minscore').value, 10),
    fc:   document.getElementById('tb-completeness').value,
    fp:   document.getElementById('tb-played').value,
    ftag: document.getElementById('tb-tag').value,
    fu:   document.getElementById('tb-upgrade').value,      // ''|'1'
    fw:   document.getElementById('tb-wishlist').value,     // ''|'1'
    fe:   document.getElementById('tb-edition').value,      // '' | edition label
    fv:   document.getElementById('tb-variant')?.value || '',
  };
}

/** Filters on the copies (any copy may match each filter). */
function copiesMatch(copies, f) {
  if (f.fq && !copies.some(c=>matchesCondition(c, f.fq))) return false;
  if (f.fmin && !copies.some(c=>{ const e=GradingCore.effective(c); return e.score!==null && e.score>=f.fmin; })) return false;
  if (f.fc && !copies.some(c=>c.completeness===f.fc)) return false;
  if (f.fp && !copies.some(c=>c.played_status===f.fp)) return false;
  if (f.ftag && !copies.some(c=>c.tag===f.ftag)) return false;
  if (f.fu === '1' && !copies.some(c=>c.upgrade))  return false;
  if (f.fw === '1' && !copies.some(c=>c.wishlist)) return false;
  if (f.fv && !copies.some(c=>c.variant===f.fv)) return false;
  return true;
}

function itemMatches(it, f) {
  const owned = itemOwned(it);
  if ((f.fo === 'owned' || f.fo === '1') && !owned) return false;   // default: owned only
  if (f.fo === '0' && owned) return false;
  const games = itemGames(it);
  if (f.q) {
    const names = games.map(g => g.title).concat(it.group ? [it.group.title] : it.game._group ? [it.game._group.title] : []);
    if (!names.some(n => n.toLowerCase().includes(f.q))) return false;
  }
  if (f.fe && !games.some(g => g._group && g.edition_label === f.fe)) return false;
  return copiesMatch(itemCopies(it), f);
}

function render() {
  const f = readFilters();
  const list = allItems().filter(it => itemMatches(it, f));

  // Best condition among the copies (owned ones first): higher score / label = higher
  const condValue = cs => { const own=cs.filter(c=>c.owned); return Math.max(-1, ...(own.length?own:cs).map(c=>GradingCore.sortValue(c))); };
  const firstCopy = it => { const cs=itemCopies(it); return cs.find(c=>c.owned&&c.copy_number==1) || cs.find(c=>c.copy_number==1) || {}; };
  list.sort((a,b) => {
    let va, vb;
    const ca = firstCopy(a), cb = firstCopy(b);
    const csa = itemCopies(a), csb = itemCopies(b);
    switch(sortKey) {
      case 'title':        va=itemSortTitle(a); vb=itemSortTitle(b); break;
      case 'owned':        va=csa.some(c=>c.owned)?0:1; vb=csb.some(c=>c.owned)?0:1; break;
      case 'quality':      va=-condValue(csa); vb=-condValue(csb); break;
      case 'completeness': va=ca.completeness||'zzz'; vb=cb.completeness||'zzz'; break;
      case 'played_status':va=ca.played_status||'zzz'; vb=cb.played_status||'zzz'; break;
      case 'copies':       va=csa.length; vb=csb.length; break;
      case 'wishlist':     va=csa.some(c=>c.wishlist)?0:1; vb=csb.some(c=>c.wishlist)?0:1; break;
      case 'upgrade':      va=csa.some(c=>c.upgrade)?0:1; vb=csb.some(c=>c.upgrade)?0:1; break;
      case 'price_paid':   va=parseFloat(ca.price_paid)||0; vb=parseFloat(cb.price_paid)||0; break;
      case 'chart_price':  va=itemPrice(a,'cib_price')||0;   vb=itemPrice(b,'cib_price')||0;   break;
      case 'loose_price':  va=itemPrice(a,'loose_price')||0; vb=itemPrice(b,'loose_price')||0; break;
      case 'new_price':    va=itemPrice(a,'new_price')||0;   vb=itemPrice(b,'new_price')||0;   break;
      case 'tag':          va=csa.find(c=>c.tag)?.tag||'zzz'; vb=csb.find(c=>c.tag)?.tag||'zzz'; break;
      case 'edition':      va=a.game?.edition_label||''; vb=b.game?.edition_label||''; break;
      case 'variant':      va=csa.find(c=>c.owned&&c.variant)?.variant||'zzz'; vb=csb.find(c=>c.owned&&c.variant)?.variant||'zzz'; break;
      default:             va=itemSortTitle(a); vb=itemSortTitle(b);
    }
    if (typeof va==='string') { va=va.toLowerCase(); vb=vb.toLowerCase(); }
    return va<vb?-sortDir:va>vb?sortDir:0;
  });

  const tbody = document.getElementById('tbody');
  tbody.innerHTML = '';
  GradingUI.beginRender();

  list.forEach(it => {
    if (it.game) {
      const g = it.game;
      // "Every edition": the game name with the edition in its own column
      tbody.appendChild(gameRow(g, { title: g._group ? g._group.title : g.title, edition: g._group ? g.edition_label : '' }));
      return;
    }
    const grp = it.group, open = openGroups.has(String(grp.id));
    tbody.appendChild(groupRow(grp, open));
    if (open) grp.members
      .filter(m => !f.fe || m.edition_label === f.fe)
      .forEach(m => tbody.appendChild(gameRow(m, { child: true, title: m.edition_label || m.title, edition: '' })));
  });

  document.getElementById('empty-state').style.display = list.length===0?'block':'none';
  updateStats(list);
  applyColumnVisibility(); // reorder + show/hide after each render
}

const DASH = '<span class="price-na">—</span>';

/** Owned copies' variants, e.g. "UK, Benelux (HOL)". */
function variantText(copies) {
  return [...new Set(copies.filter(c=>c.owned && c.variant).map(c=>c.variant))].join(', ');
}

/** One game (or one edition) row. opts: {title, edition, child} */
function gameRow(g, opts) {
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
    if (opts.child) tr.classList.add('ed-child-row');

    const photo = getDisplayPhoto(g);
    let imgCell = '';
    if (photo) {
      imgCell = `<img class="img-thumb" src="${photo.src}" onclick="openLightboxGame(${g.id})" alt="">`;
    } else {
      imgCell = `<div class="img-placeholder" onclick="openDrawer(${g.id})">+</div>`;
    }

    // For multi-copy display: only show condition/paid/notes for owned copies
    const displayCopies = ownedCopies; // only owned copies shown in detail columns

    // Condition — owned copies only (score + label for point grades, label for simple grades)
    const qualCell = GradingUI.cellHtml(displayCopies, {switchLink:true});

    // Completeness — owned copies only
    const compCell = displayCopies.length === 0
      ? DASH
      : displayCopies.length > 1
        ? displayCopies.map(c => `<span style="font-size:.68rem;color:var(--text2);display:block;line-height:1.6">${esc(c.completeness||'—')}</span>`).join('')
        : `<span style="font-size:.7rem;color:var(--text2)">${esc(displayCopies[0].completeness||'—')}</span>`;

    // Paid — owned copies only
    const paidCell = displayCopies.length === 0
      ? DASH
      : displayCopies.length > 1
        ? displayCopies.map(c => c.price_paid != null
            ? `<span class="price" style="display:block;font-size:.85rem">${money(c.price_paid)}</span>`
            : `<span class="price-na" style="display:block">—</span>`).join('')
        : (displayCopies[0].price_paid != null
            ? `<span class="price">${money(displayCopies[0].price_paid)}</span>`
            : DASH);

    // Buy range — from copy 1 only (shared reference)
    let rangeCell = DASH;
    if (c1.price_min!=null && c1.price_max!=null) rangeCell=`<span class="price-range">${money(c1.price_min, 0)}–${money(c1.price_max, 0)}</span>`;
    else if (c1.price_min!=null) rangeCell=`<span class="price-range">≥${money(c1.price_min, 0)}</span>`;
    else if (c1.price_max!=null) rangeCell=`<span class="price-range">≤${money(c1.price_max, 0)}</span>`;

    // CIB price — PC price in blue (linked), personal in red
    const cibTitle = g.cib_price_updated_at ? tRaw('coll.pc_cib_updated', {date: fmtDate(g.cib_price_updated_at)}) : tRaw('coll.pc_cib');
    let cibParts = [];
    if (g.cib_price != null) {
      const pcAmt = money(g.cib_price);
      cibParts.push(g.pc_link
        ? `<a href="${escAttr(g.pc_link)}" target="_blank" class="price price-chart" style="text-decoration:none" title="${escAttr(cibTitle)}">${pcAmt}</a>`
        : `<span class="price price-chart" title="${escAttr(cibTitle)}">${pcAmt}</span>`);
    } else if (g.pc_link) {
      // No price yet but we have a link — show a clickable "—" in blue
      cibParts.push(`<a href="${escAttr(g.pc_link)}" target="_blank" style="color:var(--wiiu);text-decoration:none;font-size:.75rem" title="${t('drawer.view_pc')}">PC ↗</a>`);
    }
    if (c1.chart_price != null) {
      cibParts.push(`<span class="price" style="color:var(--personal-price)" title="${t('coll.personal_price')}">${money(c1.chart_price)}</span>`);
    }
    const chartCell = cibParts.length ? cibParts.join(' <span style="color:var(--border2)">·</span> ') : DASH;

    // Notes — owned copies if any, otherwise wishlist entry notes
    const wishCopy = copies.find(c=>c.wishlist);
    const noteCell = displayCopies.length === 0
      ? (wishCopy?.notes ? `<span style="font-size:.66rem;color:var(--muted);font-style:italic">${esc(wishCopy.notes)}</span>` : DASH)
      : displayCopies.length > 1
        ? displayCopies.map(c => `<span style="font-size:.65rem;color:var(--muted);font-style:italic;display:block;line-height:1.6">${esc(c.notes||'—')}</span>`).join('')
        : `<span style="font-size:.66rem;color:var(--muted);font-style:italic">${esc(displayCopies[0].notes||'—')}</span>`;

    const noteText = displayCopies[0]?.notes || wishCopy?.notes || '—';
    const copyCnt = ownedCopies.length > 0
      ? `<span class="chip chip-y">${ownedCopies.length}</span>`
      : DASH;
    const playCell = played ? `<span style="font-size:.68rem;color:var(--wiiu)">${esc(played)}</span>` : DASH;
    const upReason = copies.find(c=>c.upgrade)?.upgrade_reason || '';
    const variants = variantText(copies);
    const titleHtml = opts.child
      ? `<span class="ed-child-title"><span aria-hidden="true">└ </span>${esc(opts.title)}</span>`
      : `<span class="game-num">#${String(g.sort_order).padStart(3,'0')}</span>${esc(opts.title)}`;

    tr.innerHTML = `
      <td data-col="img">${imgCell}</td>
      <td data-col="owned" style="text-align:center"><div class="owned-check ${owned?'checked':''}" onclick="toggleOwned(${g.id})">${owned?'✓':''}</div></td>
      <td data-col="wishlist" style="text-align:center"><div class="wish-check ${wishlist?'checked':''}" onclick="toggleWishlist(${g.id})">${wishlist?'♥':''}</div></td>
      <td data-col="upgrade" style="text-align:center"><div class="upgrade-check ${upgrade?'checked':''}" onclick="toggleUpgrade(${g.id})" title="${escAttr(upReason)}">${upgrade?'↑':''}</div></td>
      <td data-col="title" class="td-title" style="cursor:pointer" onclick="openDrawer(${g.id})">${titleHtml}</td>
      <td data-col="edition"><span class="ed-label">${opts.edition ? esc(opts.edition) : DASH}</span></td>
      <td data-col="variant"><span class="ed-label">${variants ? esc(variants) : DASH}</span></td>
      <td data-col="quality">${qualCell}</td>
      <td data-col="completeness">${compCell}</td>
      <td data-col="played">${playCell}</td>
      <td data-col="copies">${copyCnt}</td>
      <td data-col="price_paid">${paidCell}</td>
      <td data-col="buy_range">${rangeCell}</td>
      <td data-col="loose_price">${g.loose_price != null ? `<span class="price price-chart" style="color:var(--muted)" title="${escAttr(g.loose_price_updated_at?tRaw('coll.last_updated', {date: fmtDate(g.loose_price_updated_at)}):tRaw('common.col.loose_price'))}">${money(g.loose_price)}</span>` : DASH}</td>
      <td data-col="cib_price">${chartCell}</td>
      <td data-col="new_price">${g.new_price != null ? `<span class="price price-chart" style="color:var(--green)" title="${escAttr(g.new_price_updated_at?tRaw('coll.last_updated', {date: fmtDate(g.new_price_updated_at)}):tRaw('common.col.new_price'))}">${money(g.new_price)}</span>` : DASH}</td>
      <td data-col="upgrade_reason" class="note-cell" style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.66rem;color:var(--orange);font-style:italic" title="${escAttr(upReason)}">${upReason ? esc(upReason) : '<span style=\'color:var(--border2)\'>—</span>'}</td>
      <td data-col="tag"><span style="font-size:.68rem;color:var(--wiiu)">${esc(copies.find(c=>c.tag)?.tag||'')|| '<span style=\'color:var(--border2)\'>—</span>'}</span></td>
      <td data-col="notes" class="note-cell" style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.66rem;color:var(--muted);font-style:italic" title="${escAttr(noteText)}">${noteCell}</td>
      <td data-col="__edit"><button class="btn-icon" onclick="openDrawer(${g.id})">${t('common.edit')}</button></td>
    `;
    return tr;
}

/** "€ 4,50 – € 9,80" over the group's editions (one price when they're equal). */
function priceRange(games, field, style) {
  const v = games.map(g => parseFloat(g[field])).filter(n => !isNaN(n));
  if (!v.length) return DASH;
  const lo = Math.min(...v), hi = Math.max(...v);
  return `<span class="price price-chart" style="white-space:nowrap;${style||''}">${money(lo)}${hi > lo ? ' – ' + money(hi) : ''}</span>`;
}

/** The folded row of an edition group ("one per game" mode). Clicking it opens / closes the editions. */
function groupRow(grp, open) {
  const games  = grp.members;
  const copies = games.flatMap(g => entryMap[g.id] || []);
  const ownedCopies = copies.filter(c => c.owned);
  const owned    = ownedCopies.length > 0;
  const wishlist = copies.some(c => c.wishlist);
  const upgrade  = copies.some(c => c.upgrade);

  const tr = document.createElement('tr');
  tr.className = 'ed-grp';
  if (owned) tr.classList.add('is-owned');
  if (wishlist && !owned) tr.classList.add('is-wishlist');
  if (wishlist && owned) tr.classList.add('is-owned-wished');
  tr.addEventListener('click', e => { if (!e.target.closest('a, img')) toggleGroup(grp.id); });

  // Photo of the first edition that has one (the main release first)
  let imgCell = '';
  for (const g of games) {
    const p = getDisplayPhoto(g);
    if (p) { imgCell = `<img class="img-thumb" src="${p.src}" onclick="openLightboxGame(${g.id})" alt="">`; break; }
  }
  // Best grade among the owned copies
  const best = ownedCopies.slice().sort((a, b) => GradingCore.sortValue(b) - GradingCore.sortValue(a))[0];
  const ownedLabels = games.filter(g => (entryMap[g.id] || []).some(c => c.owned)).map(g => g.edition_label).filter(Boolean);
  const variants = variantText(copies);
  const n = games.length;

  tr.innerHTML = `
    <td data-col="img">${imgCell}</td>
    <td data-col="owned" style="text-align:center"><div class="owned-check ed-ro ${owned?'checked':''}" aria-hidden="true">${owned?'✓':''}</div></td>
    <td data-col="wishlist" style="text-align:center"><div class="wish-check ed-ro ${wishlist?'checked':''}" aria-hidden="true">${wishlist?'♥':''}</div></td>
    <td data-col="upgrade" style="text-align:center"><div class="upgrade-check ed-ro ${upgrade?'checked':''}" aria-hidden="true">${upgrade?'↑':''}</div></td>
    <td data-col="title" class="td-title">
      <button type="button" class="ed-toggle" aria-expanded="${open}" title="${escAttr(tRaw('ed.expand'))}">
        <span>${esc(grp.title)}</span>
        <span class="chip chip-blue">${t('ed.n_editions', {n: fmtNum(n)})} ${open ? '▾' : '▸'}</span>
      </button>
    </td>
    <td data-col="edition"><span class="ed-label">${ownedLabels.length ? esc(ownedLabels.join(', ')) : DASH}</span></td>
    <td data-col="variant"><span class="ed-label">${variants ? esc(variants) : DASH}</span></td>
    <td data-col="quality">${best ? GradingUI.cellHtml([best], {}) : DASH}</td>
    <td data-col="completeness"></td>
    <td data-col="played"></td>
    <td data-col="copies">${ownedCopies.length ? `<span class="chip chip-y">${ownedCopies.length}</span>` : DASH}</td>
    <td data-col="price_paid"></td>
    <td data-col="buy_range"></td>
    <td data-col="loose_price">${priceRange(games, 'loose_price', 'color:var(--muted)')}</td>
    <td data-col="cib_price">${priceRange(games, 'cib_price')}</td>
    <td data-col="new_price">${priceRange(games, 'new_price', 'color:var(--green)')}</td>
    <td data-col="upgrade_reason"></td>
    <td data-col="tag"></td>
    <td data-col="notes"></td>
    <td data-col="__edit"></td>
  `;
  return tr;
}

function updateStats(filtered) {
  const units     = allItems();
  const ownedN    = units.filter(itemOwned).length;
  const tot       = units.length;
  const allCopies = Object.values(entryMap).flat().filter(c=>c.owned);

  // Only show owned/copies counts if this system counts toward totals
  const ownedDisplay  = SYS_COUNTS_TOTALS ? fmtNum(ownedN) : '—';
  const pctDisplay    = SYS_COUNTS_TOTALS ? (tot ? Math.round(ownedN/tot*100)+'%' : '0%') : '—';
  const copiesDisplay = SYS_COUNTS_TOTALS ? fmtNum(allCopies.length) : '—';

  document.getElementById('st-owned').textContent   = ownedDisplay;
  document.getElementById('st-pct').textContent     = pctDisplay;
  document.getElementById('st-copies').textContent  = copiesDisplay;
  document.getElementById('st-upgrade').textContent = allCopies.filter(c=>c.upgrade).length;
  document.getElementById('st-spent').textContent   = money(allCopies.reduce((s,c)=>s+(parseFloat(c.price_paid)||0),0), 0);
  document.getElementById('prog-fill').style.width  = tot?(ownedN/tot*100)+'%':'0%';
  // "84 / 250 games" vs "97 / 312 editions" once this system has linked editions
  const unit = Object.keys(groupMap).length ? ' ' + tRaw(EDITION_MODE === 'one' ? 'ed.unit_games' : 'ed.unit_editions') : '';
  document.getElementById('prog-text').textContent  = fmtNum(ownedN)+' / '+fmtNum(tot)+unit;

  // CIB totals (a group counts its cheapest edition in "one per game" mode)
  const cibAll   = units.reduce((s,it)=>s+(itemPrice(it,'cib_price')||0),0);
  // Owned value — uses each copy's selected price type
  let ownedValue = 0;
  allGames.forEach(g => {
    (entryMap[g.id]||[]).filter(c=>c.owned).forEach(c => {
      const ptype = c.value_price_type || FMT.valueType;
      const price = ptype === 'loose' ? parseFloat(g.loose_price)||0
                  : ptype === 'new'   ? parseFloat(g.new_price)||0
                  : parseFloat(g.cib_price)||0;
      ownedValue += price;
    });
  });
  document.getElementById('st-cib-all').textContent   = money(cibAll, 0);
  document.getElementById('st-cib-owned').textContent = money(ownedValue, 0);

  const fCopies   = filtered.flatMap(itemCopies);
  const fOwned    = filtered.filter(itemOwned);
  const fSpend    = fCopies.reduce((s,c)=>s+(parseFloat(c.price_paid)||0),0);
  const fCibAll   = filtered.reduce((s,it)=>s+(itemPrice(it,'cib_price')||0),0);
  document.getElementById('sum-show').textContent  = fmtNum(filtered.length);
  document.getElementById('sum-tot').textContent   = fmtNum(tot);
  document.getElementById('sum-own').textContent   = fmtNum(fOwned.length);
  document.getElementById('sum-spend').textContent = money(fSpend);
  document.getElementById('sum-chart').textContent = fCibAll > 0 ? money(fCibAll) : '—';
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

// TOGGLE OWNED — independent of wishlist (only the toggled field is sent; the server keeps the rest)
async function toggleOwned(gameId) {
  const copies   = entryMap[gameId]||[];
  const c1       = copies.find(c=>c.copy_number==1);
  const newOwned = c1 ? !c1.owned : true;
  const res = await apiFetch('/api/entry_save.php', {
    game_id:     gameId,
    copy_number: 1,
    owned:       newOwned ? 1 : 0,
  });
  if (res.ok) {
    if (!entryMap[gameId]) entryMap[gameId] = [];
    const idx = entryMap[gameId].findIndex(c=>c.copy_number==1);
    if (idx>=0) entryMap[gameId][idx] = {...entryMap[gameId][idx], ...res.entry};
    else entryMap[gameId].push(res.entry);
    render(); toast(newOwned ? '✓ '+tRaw('coll.added') : tRaw('coll.removed'));
  }
}

// TOGGLE WISHLIST — independent of owned
async function toggleWishlist(gameId) {
  const copies  = entryMap[gameId]||[];
  const c1      = copies.find(c=>c.copy_number==1);
  const newWish = c1 ? !c1.wishlist : true;
  const res = await apiFetch('/api/entry_save.php', {
    game_id:     gameId,
    copy_number: 1,
    wishlist:    newWish ? 1 : 0,
  });
  if (res.ok) {
    if (!entryMap[gameId]) entryMap[gameId] = [];
    const idx = entryMap[gameId].findIndex(c=>c.copy_number==1);
    if (idx>=0) entryMap[gameId][idx] = {...entryMap[gameId][idx], ...res.entry};
    else entryMap[gameId].push(res.entry);
    render(); toast(newWish ? '♥ '+tRaw('coll.wish_added') : tRaw('coll.wish_removed'));
  }
}

// TOGGLE UPGRADE — quick toggle from table
async function toggleUpgrade(gameId) {
  const copies     = entryMap[gameId]||[];
  const c1         = copies.find(c=>c.copy_number==1);
  const newUpgrade = c1 ? !c1.upgrade : true;
  const res = await apiFetch('/api/entry_save.php', {
    game_id:     gameId,
    copy_number: 1,
    upgrade:     newUpgrade ? 1 : 0,
  });
  if (res.ok) {
    if (!entryMap[gameId]) entryMap[gameId] = [];
    const idx = entryMap[gameId].findIndex(c=>c.copy_number==1);
    if (idx>=0) entryMap[gameId][idx] = {...entryMap[gameId][idx], ...res.entry};
    else entryMap[gameId].push(res.entry);
    render(); toast(newUpgrade ? '↑ '+tRaw('coll.upgrade_on') : tRaw('coll.upgrade_off'));
  }
}

// DRAWER
function openDrawer(gameId) {
  editGameId = gameId;
  const g = allGames.find(x=>x.id==gameId); if (!g) return;
  document.getElementById('d-title').textContent  = g._group ? g._group.title : g.title;
  document.getElementById('d-system').textContent = EdDrawer.subtitle(SYS_NAME + (SYS_REGION && SYS_REGION !== 'Mixed' ? ' · ' + SYS_REGION : ''), g, g._group);

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
        const title = updatedAt ? t('coll.last_updated', {date: fmtDate(updatedAt)}) : '';
        document.getElementById(valId).innerHTML = title
          ? `<span title="${title}" style="cursor:help;border-bottom:1px dashed var(--muted)">${money(price)}</span>`
          : money(price);
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
  const region   = SYS_REGION === 'Mixed' ? '' : SYS_REGION;

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
    btn.textContent=tRaw('drawer.copy_n', {n: i});
    const ii=i;
    btn.onclick=()=>{editCopy=ii;renderCopyTabs();loadCopyIntoForm(ii);};
    tabs.appendChild(btn);
  }
  const add=document.createElement('button');
  add.className='copy-tab-add'; add.textContent='+ '+tRaw('drawer.add_copy');
  add.onclick=()=>{const next=maxCopy+1;if(!entryMap[editGameId])entryMap[editGameId]=[];editCopy=next;renderCopyTabs();loadCopyIntoForm(next);};
  tabs.appendChild(add);
}

function loadCopyIntoForm(copyNum) {
  const c=(entryMap[editGameId]||[]).find(x=>x.copy_number==copyNum)||{};
  setTog('d-owned',   c.owned||false,   'lbl-owned',   tRaw(c.owned?'drawer.owned':'drawer.not_owned'));
  document.getElementById('d-completeness').value = c.completeness  ||'';
  gradeEditor.load(c, {systemId: SYS_ID});
  document.getElementById('d-played').value       = c.played_status ||'';
  document.getElementById('d-price').value        = c.price_paid   !=null?c.price_paid:'';
  document.getElementById('d-chart').value        = c.chart_price  !=null?c.chart_price:'';
  document.getElementById('d-min').value          = c.price_min    !=null?c.price_min:'';
  document.getElementById('d-max').value          = c.price_max    !=null?c.price_max:'';
  setTog('d-upgrade', c.upgrade||false, 'lbl-upgrade', tRaw(c.upgrade?'drawer.upgrade_wanted':'drawer.no_upgrade'));
  setTog('d-wishlist',c.wishlist||false,'lbl-wishlist',tRaw(c.wishlist?'drawer.wished':'drawer.not_wished'));
  document.getElementById('d-upgrade-reason').value = c.upgrade_reason||'';
  document.getElementById('d-notes').value           = c.notes||'';
  document.getElementById('d-tag').value = c.tag||'';
  const vtype = c.value_price_type || FMT.valueType;
  document.querySelectorAll('input[name="d-value-type"]').forEach(r => r.checked = r.value === vtype);
  renderPhotoGrid(c.photos||[], c.primary_photo||'', c.id||null);
  const g = allGames.find(x=>x.id==editGameId);
  EdDrawer.load({
    group: g?._group || null, game: g, copy: c,
    trackVariants: TRACK_VARIANTS, variants: VARIANT_OPTS, wishDefault: EDITION_WISHLIST, canEdit: true,
    status: id => { const cs = entryMap[id] || [], m = allGames.find(x=>x.id==id);
      return { owned: cs.some(x=>x.owned), wished: cs.some(x=>x.wishlist), price: m && m.cib_price != null ? m.cib_price : null }; },
    onOpen: id => openDrawer(id),
    onWish: id => toggleWishlist(id),
  });
}

function closeDrawer() { document.getElementById('drawer-backdrop').classList.remove('open'); editGameId=null; }
function handleBdClick(e){if(e.target===document.getElementById('drawer-backdrop'))closeDrawer();}

async function saveEntry() {
  if (!editGameId) return;
  const payload = {
    game_id:editGameId, copy_number:editCopy,
    owned:          document.getElementById('d-owned').checked?1:0,
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
    value_price_type: document.querySelector('input[name="d-value-type"]:checked')?.value || FMT.valueType,
    primary_photo:  document.getElementById('d-primary').value||null,
  };
  const grading = gradeEditor.getPayload();
  if (grading) payload.grading = grading;
  Object.assign(payload, EdDrawer.payload());
  const res = await apiFetch('/api/entry_save.php', payload);
  if (res.ok) {
    if (!entryMap[editGameId]) entryMap[editGameId]=[];
    const idx=entryMap[editGameId].findIndex(c=>c.copy_number==editCopy);
    const merged = idx>=0 ? {...entryMap[editGameId][idx],...res.entry} : res.entry;
    if (res.moved_from) {
      // The copy now belongs to another edition (same entry id, new copy number)
      if (idx>=0) entryMap[editGameId].splice(idx,1);
      (entryMap[res.entry.game_id] = entryMap[res.entry.game_id] || []).push(merged);
    } else if (idx>=0) entryMap[editGameId][idx]=merged;
    else entryMap[editGameId].push(merged);
    const movedTo = res.moved_from ? allGames.find(x=>x.id==res.entry.game_id) : null;
    render(); closeDrawer();
    toast(movedTo ? tRaw('ed.moved', {label: movedTo.edition_label || movedTo.title}) : tRaw('common.saved'));
  } else { toast(tRaw('common.err_prefix', {error: res.error}),true); }
}

// PHOTOS
async function uploadPhotos(e) {
  const files = Array.from(e.target.files);
  const copies = entryMap[editGameId] || [];
  const cp = copies.find(x => x.copy_number == editCopy);
  const entryId = cp?.id;
  if (!entryId) { toast(tRaw('drawer.save_first'), true); e.target.value = ''; return; }
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
      toast(tRaw('drawer.upload_failed', {error: res.error || ''}), true);
    }
  }
  e.target.value = '';
  render();
  toast(tRaw('drawer.photos_added'));
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
    sel.innerHTML = `<option value="">— ${t('drawer.first_photo')} —</option>`;
    if (hasDefault) {
      const opt = document.createElement('option');
      opt.value = '__default__';
      opt.textContent = tRaw('drawer.default_cover');
      opt.selected = (primaryPhoto === '__default__');
      sel.appendChild(opt);
    }
    (photos||[]).forEach((fn, i) => {
      const opt = document.createElement('option');
      opt.value = fn;
      opt.textContent = tRaw('drawer.my_photo', {n: i + 1});
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
  toast(tRaw('drawer.rotating'));
  const res = await apiFetch('/api/photo_rotate.php', {entry_id:c.id, filename:fn, degrees});
  if (res.ok) {
    photoTs[fn] = res.ts; // store so lightbox also picks up new version
    renderPhotoGrid(c.photos, c.primary_photo||'', c.id||null);
    render();
    toast(tRaw('drawer.rotated'));
  } else { toast(tRaw('drawer.rotate_failed', {error: res.error||''}), true); }
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
    render(); toast(tRaw('drawer.photo_removed'));
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
  toast(tRaw('drawer.rotating'));
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
    toast(tRaw('drawer.rotated'));
  } else {
    toast(tRaw('drawer.rotate_failed', {error: res.error || ''}), true);
  }
}


// CONDITION FILTER — fq: 'label:<id>' | 'm:points' | 'm:simple' | 'm:none'
function matchesCondition(c, fq) {
  const e = GradingCore.effective(c);
  if (fq.startsWith('label:')) return !!e.label && String(e.label.id) === fq.slice(6);
  if (fq === 'm:points') return e.score !== null;
  if (fq === 'm:simple') return e.score === null && !!e.label;
  if (fq === 'm:none')   return !e.label;
  return true;
}

// "Simple grade · switch to points" in the Condition column
window.gradingSwitchToPoints = (gameId, copyNo) => {
  openDrawer(gameId);
  if (copyNo && copyNo !== editCopy) { editCopy = copyNo; renderCopyTabs(); loadCopyIntoForm(copyNo); }
  gradeEditor.showPoints();
};

// SYSTEM SWITCH
function switchSystem(id) {
  document.cookie=`last_system=${id};path=/;max-age=${60*60*24*365}`;
  window.location=`${BASE}/collection.php?s=${id}`;
}

// SHOW ALL
// HELPERS
function setTog(inputId,val,labelId,text){document.getElementById(inputId).checked=val;document.getElementById(labelId).textContent=text;}
document.getElementById('d-owned').addEventListener('change',function(){document.getElementById('lbl-owned').textContent=tRaw(this.checked?'drawer.owned':'drawer.not_owned');});
document.getElementById('d-upgrade').addEventListener('change',function(){document.getElementById('lbl-upgrade').textContent=tRaw(this.checked?'drawer.upgrade_wanted':'drawer.no_upgrade');});
document.getElementById('d-wishlist').addEventListener('change',function(){document.getElementById('lbl-wishlist').textContent=tRaw(this.checked?'drawer.wished':'drawer.not_wished');});
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
['tb-search','tb-quality','tb-minscore','tb-completeness','tb-played','tb-tag','tb-edition','tb-variant'].forEach(id=>{
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
