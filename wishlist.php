<?php
require_once __DIR__ . '/boot.php';

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
        die('<p style="font:1rem monospace;color:#c94f3a;padding:40px">'.t('wish.not_public').'</p>');
    }
    $isPublicView = true;
    $user     = $viewUser;
    $loggedIn = auth();
} else {
    $loggedIn = requireAuth();
    $user     = $loggedIn;
}

// Editing is only allowed when viewing your own wishlist
$canEdit = !$isPublicView;

// Load all wishlisted entries across all systems (defines row order)
$st = db()->prepare("
    SELECT ce.id, ce.game_id,
           g.title, g.sort_title, g.sort_order AS game_sort, g.default_image,
           g.cib_price, g.cib_price_updated_at, g.pc_link,
           g.loose_price, g.loose_price_updated_at,
           g.new_price, g.new_price_updated_at, g.group_id, g.edition_label,
           s.name AS system_name, s.short_name, s.id AS system_id, s.region AS system_region
    FROM collection_entries ce
    JOIN games g   ON g.id  = ce.game_id
    JOIN systems s ON s.id  = g.system_id
    WHERE ce.user_id=? AND ce.wishlist=1
    ORDER BY s.sort_order, g.sort_title, ce.copy_number
");
$st->execute([$user['id']]);
$entries = $st->fetchAll();

// Game info keyed by game id (one per game, even with several wishlisted copies)
$games = [];
foreach ($entries as $e) {
    $gid = (int)$e['game_id'];
    if (isset($games[$gid])) continue;
    $games[$gid] = [
        'id'                     => $gid,
        'title'                  => $e['title'],
        'sort_order'             => $e['game_sort'],
        'default_image'          => $e['default_image'],
        'pc_link'                => $e['pc_link'],
        'cib_price'              => $e['cib_price'],
        'cib_price_updated_at'   => $e['cib_price_updated_at'],
        'loose_price'            => $e['loose_price'],
        'loose_price_updated_at' => $e['loose_price_updated_at'],
        'new_price'              => $e['new_price'],
        'new_price_updated_at'   => $e['new_price_updated_at'],
        'system_id'              => (int)$e['system_id'],
        'system_name'            => $e['system_name'],
        'short_name'             => $e['short_name'],
        'region'                 => systemRegion(['region' => $e['system_region']]),
        'group_id'               => $e['group_id'] !== null ? (int)$e['group_id'] : null,
        'edition_label'          => $e['edition_label'],
        'copies'                 => [],
    ];
}

// Editions: the groups of the wishlisted games, with the owner's status per edition.
// A group needs at least two active editions; otherwise the game is shown as a normal game.
$editionGroups = [];
$groupIds = array_values(array_unique(array_filter(array_column($games, 'group_id'))));
if ($groupIds) {
    $in = implode(',', array_map('intval', $groupIds));
    foreach (db()->query("SELECT id, title, main_game_id FROM game_groups WHERE id IN ($in)") as $gr) {
        $editionGroups[(int)$gr['id']] = ['id' => (int)$gr['id'], 'title' => $gr['title'], 'main_game_id' => (int)$gr['main_game_id'], 'members' => []];
    }
    $ms = db()->prepare("
        SELECT g.id, g.title, g.group_id, g.edition_label, g.cib_price,
               COALESCE(MAX(ce.owned), 0) AS owned, COALESCE(MAX(ce.wishlist), 0) AS wished
        FROM games g
        LEFT JOIN collection_entries ce ON ce.game_id = g.id AND ce.user_id = ?
        WHERE g.group_id IN ($in) AND g.active = 1
        GROUP BY g.id, g.title, g.group_id, g.edition_label, g.cib_price, g.edition_sort
        ORDER BY g.edition_sort, g.id
    ");
    $ms->execute([$user['id']]);
    foreach ($ms->fetchAll() as $m) {
        if (!isset($editionGroups[(int)$m['group_id']])) continue;
        $editionGroups[(int)$m['group_id']]['members'][] = [
            'id' => (int)$m['id'], 'title' => $m['title'], 'edition_label' => $m['edition_label'],
            'cib_price' => $m['cib_price'], 'owned' => (bool)$m['owned'], 'wished' => (bool)$m['wished'],
        ];
    }
    $editionGroups = array_filter($editionGroups, fn($gr) => count($gr['members']) >= 2);
    foreach ($games as &$g) if ($g['group_id'] && !isset($editionGroups[$g['group_id']])) $g['group_id'] = null;
    unset($g);
}

// Compilations: what each wishlisted game contains and which compilations it's in, with the owner's status.
// A wished game stays on the wishlist when the owner has it in a compilation; the row only gets a mark.
$compLinks = compilationLinksFor(array_keys($games), (int)$user['id']);
foreach ($games as $gid => &$g) $g['comp'] = $compLinks[$gid] ?? ['contains' => [], 'also_in' => []];
unset($g);

// Print variants (the wishlist owner's)
$trackVariants = !empty($user['track_variants']);
$variantOpts   = [];
if ($trackVariants) {
    $vq = db()->prepare("SELECT label FROM user_variant_options WHERE user_id=? ORDER BY sort_order");
    $vq->execute([$user['id']]); $variantOpts = $vq->fetchAll(PDO::FETCH_COLUMN);
}

// All copies (not just the wishlisted one) of every wishlisted game, with photos
if ($games) {
    $cpSt = db()->prepare("
        SELECT ce.*, GROUP_CONCAT(cp.filename ORDER BY cp.sort_order SEPARATOR '||') AS photos_raw
        FROM collection_entries ce
        LEFT JOIN copy_photos cp ON cp.entry_id = ce.id
        WHERE ce.user_id=? AND ce.game_id IN (SELECT game_id FROM collection_entries WHERE user_id=? AND wishlist=1)
        GROUP BY ce.id
        ORDER BY ce.copy_number
    ");
    $cpSt->execute([$user['id'], $user['id']]);
    foreach ($cpSt->fetchAll() as $c) {
        $gid = (int)$c['game_id'];
        if (!isset($games[$gid])) continue;
        $c['owned']    = (bool)$c['owned'];
        $c['upgrade']  = (bool)$c['upgrade'];
        $c['wishlist'] = (bool)$c['wishlist'];
        $c['wishlist_any'] = (bool)($c['wishlist_any'] ?? false);
        $c['photos']   = $c['photos_raw'] ? explode('||', $c['photos_raw']) : [];
        unset($c['photos_raw'], $c['user_id']);
        $games[$gid]['copies'][] = $c;
    }
    // Point-grading parts per copy
    $allCopyIds = [];
    foreach ($games as $g) foreach ($g['copies'] as $c) $allCopyIds[] = (int)$c['id'];
    $gradingMap = loadEntryGrading($allCopyIds);
    foreach ($games as &$g) foreach ($g['copies'] as &$c) $c['grading'] = $gradingMap[(int)$c['id']] ?? null;
    unset($g, $c);
}

$rowList = array_map(fn($e) => ['entry_id' => (int)$e['id'], 'game_id' => (int)$e['game_id']], $entries);

// Option lists (the wishlist owner's)
$tagOptsSt = db()->prepare("SELECT label FROM user_tag_options WHERE user_id=? ORDER BY sort_order");
$tagOptsSt->execute([$user['id']]); $tagOpts = $tagOptsSt->fetchAll(PDO::FETCH_COLUMN);

$compOptsSt = db()->prepare("SELECT label FROM user_completeness_options WHERE user_id=? ORDER BY sort_order");
$compOptsSt->execute([$user['id']]); $compOpts = $compOptsSt->fetchAll(PDO::FETCH_COLUMN);

$playedOptsSt = db()->prepare("SELECT label FROM user_played_options WHERE user_id=? ORDER BY sort_order");
$playedOptsSt->execute([$user['id']]); $playedOpts = $playedOptsSt->fetchAll(PDO::FETCH_COLUMN);

// Auction sites for quick-search links: the viewer's own if logged in, otherwise the owner's
$auctionSource = $loggedIn ?: $user;
$auctionSites  = json_decode($auctionSource['auction_sites'] ?? '[]', true) ?: [];

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
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pageTitle($isPublicView ? tRaw('wish.users_wishlist', ['user' => $user['username']]) : tRaw('common.nav.wishlist')) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<?= themeHead($user) ?>
<style>
  .sys-badge { display:inline-block; padding:2px 8px; font-size:.6rem; letter-spacing:.1em; text-transform:uppercase; background:color-mix(in srgb,var(--wiiu) 10%,transparent); color:var(--wiiu); border:1px solid color-mix(in srgb,var(--wiiu) 30%,transparent); white-space:nowrap; }
  .wish-filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding:10px 32px; background:var(--surface2); border-bottom:1px solid var(--border); }
  td.td-title { cursor:pointer; }
  .img-thumb { cursor:pointer; }
  .drawer.readonly input:disabled,
  .drawer.readonly select:disabled,
  .drawer.readonly textarea:disabled { opacity:1; cursor:default; color:var(--text); }
  .drawer.readonly .toggle { pointer-events:none; }
  .d-cover { width:100%; max-height:220px; object-fit:contain; border:1px solid var(--border2); background:var(--surface2); cursor:pointer; }
  .d-empty { font-size:.7rem; color:var(--muted); font-style:italic; }
</style>
<?= csrfScript() ?>
<?= appScript(['coll', 'drawer', 'grading', 'wish', 'ed', 'cr', 'comp']) ?>
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/collection.php" class="site-logo" style="text-decoration:none"><?= siteLogoHtml() ?></a>
  <div class="hstats">
    <div class="hstat"><div class="hstat-val blue" id="hs-wish"><?= count($entries) ?></div><div class="hstat-label"><?= t('dashboard.wishlisted') ?></div></div>
    <div class="hstat"><div class="hstat-val"><?= count($bySystem) ?></div><div class="hstat-label"><?= t('dashboard.systems') ?></div></div>
    <?php if ($isPublicView): ?>
    <div class="hstat"><div class="hstat-val" style="font-size:1rem;color:var(--muted)"><?= htmlspecialchars($user['username']) ?></div><div class="hstat-label"><?= t('common.nav.wishlist') ?></div></div>
    <?php endif; ?>
  </div>
  <nav class="site-nav">
    <?php if ($isPublicView && $loggedIn): ?>
      <span class="nav-user">👤 <?= htmlspecialchars($loggedIn['username']) ?></span>
      <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link"><?= t('common.nav.dashboard') ?></a>
      <a href="<?= BASE_URL ?>/collection.php" class="nav-link"><?= t('common.nav.collection') ?></a>
      <a href="<?= BASE_URL ?>/wishlist.php" class="nav-link"><?= t('wish.my_wishlist') ?></a>
    <?php elseif (!$isPublicView): ?>
      <span class="nav-user">👤 <?= htmlspecialchars($user['username']) ?></span>
      <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link"><?= t('common.nav.dashboard') ?></a>
      <a href="<?= BASE_URL ?>/collection.php" class="nav-link"><?= t('common.nav.collection') ?></a>
      <a href="<?= BASE_URL ?>/settings.php" class="nav-link"><?= t('common.nav.settings') ?></a>
      <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link"><?= t('common.nav.sign_out') ?></a>
    <?php endif; ?>
  </nav>
</header>

<!-- FILTERS -->
<div class="toolbar">
  <div class="search-wrap">
    <span class="search-icon">⌕</span>
    <input type="text" id="tb-search" placeholder="<?= t('wish.search') ?>">
  </div>
  <?php if ($tagOpts): ?>
  <select id="tb-tag" onchange="filterTable()">
    <option value=""><?= t('coll.all_tags') ?></option>
    <?php foreach ($tagOpts as $t): ?>
    <option value="<?= htmlspecialchars(strtolower($t)) ?>"><?= htmlspecialchars($t) ?></option>
    <?php endforeach; ?>
  </select>
  <?php else: ?>
  <input type="hidden" id="tb-tag" value="">
  <?php endif; ?>
  <div class="filter-btn-group">
    <button class="filter-btn" id="fbtn-own-all" onclick="setWLOwned('')"><?= t('coll.f_all') ?></button>
    <button class="filter-btn" id="fbtn-own-yes" onclick="setWLOwned('1')">✓ <?= t('dashboard.owned') ?></button>
    <button class="filter-btn" id="fbtn-own-no"  onclick="setWLOwned('0')">✗ <?= t('dashboard.owned') ?></button>
  </div>
  <div class="filter-btn-group">
    <button class="filter-btn" id="fbtn-upg-all" onclick="setWLUpgrade('')">↑ <?= t('coll.f_all') ?></button>
    <button class="filter-btn" id="fbtn-upg-yes" onclick="setWLUpgrade('1')">↑ <?= t('coll.f_yes') ?></button>
  </div>
  <input type="hidden" id="tb-owned" value="">
  <input type="hidden" id="tb-upgrade" value="">
</div>

<!-- SYSTEM TOGGLE BUTTONS (multi-select, wrapping) -->
<?php if ($bySystem): ?>
<div style="background:var(--surface2);border-bottom:1px solid var(--border);padding:8px 32px">
  <div class="sys-toggle-wrap">
    <button class="sys-toggle-btn utility" onclick="selectAllSystems()"><?= t('coll.f_all') ?></button>
    <button class="sys-toggle-btn utility" onclick="selectNoSystems()"><?= t('wish.none') ?></button>
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
        <th data-col="img" style="width:36px"><?= t('coll.th_img') ?></th>
        <th data-col="owned" style="width:28px;text-align:center"><?= t('coll.th_own') ?></th>
        <th data-col="upgrade" style="width:28px;text-align:center">↑</th>
        <th data-col="system" onclick="sortWish('system')" style="cursor:pointer"><?= t('common.col.system') ?> ↕</th>
        <th data-col="title" onclick="sortWish('title')" style="cursor:pointer"><?= t('common.col.title') ?> ↕</th>
        <th data-col="upgrade_reason"><?= t('common.col.upgrade_reason') ?></th>
        <th data-col="quality" onclick="sortWish('quality')" style="cursor:pointer"><?= t('common.col.quality') ?> ↕</th>
        <th data-col="completeness"><?= t('common.col.completeness') ?></th>
        <th data-col="price_paid" onclick="sortWish('price_paid')" style="cursor:pointer"><?= t('common.col.price_paid') ?> ↕</th>
        <th data-col="buy_range"><?= t('common.col.buy_range') ?></th>
        <th data-col="loose_price" onclick="sortWish('loose_price')" style="cursor:pointer"><?= t('common.price.loose') ?> ↕</th>
        <th data-col="cib_price" onclick="sortWish('cib_price')" style="cursor:pointer"><?= t('common.col.cib_price') ?> ↕</th>
        <th data-col="new_price" onclick="sortWish('new_price')" style="cursor:pointer"><?= t('common.price.new') ?> ↕</th>
        <th data-col="tag" onclick="sortWish('tag')" style="cursor:pointer"><?= t('common.col.tag') ?> ↕</th>
        <th data-col="notes"><?= t('common.col.notes') ?></th>
        <th data-col="__edit"></th>
      </tr>
    </thead>
    <tbody id="tbody"></tbody>
  </table>
  <div class="empty-state" id="empty-state" style="display:<?= empty($entries) ? 'block' : 'none' ?>">
    <p><?= t('wish.empty') ?></p>
    <p><?= t('wish.empty_hint') ?></p>
  </div>
</div>

<div class="summary-bar">
  <?= t('coll.sum_showing') ?> <strong id="sum-show"><?= count($entries) ?></strong> <?= t('coll.sum_of') ?> <strong id="sum-tot"><?= count($entries) ?></strong> <?= t('wish.wishlisted_games') ?>
</div>

<!-- DETAIL / EDIT DRAWER -->
<div class="drawer-backdrop" id="drawer-backdrop" onclick="handleBdClick(event)">
  <div class="drawer<?= $canEdit ? '' : ' readonly' ?>" id="drawer">
    <div class="drawer-header">
      <div class="drawer-header-info">
        <div class="drawer-title" id="d-title">—</div>
        <div class="drawer-subtitle" id="d-system">—</div>
      </div>
      <button class="drawer-close" onclick="closeDrawer()">✕</button>
    </div>
    <div class="drawer-body">

      <div class="drawer-section" id="d-cover-wrap" style="display:none">
        <img class="d-cover" id="d-cover" src="" alt="" onclick="openCoverLightbox()">
      </div>

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

      <!-- Compilations: what this game contains / which compilations it's in (assets/js/compilations-drawer.js) -->
      <div class="drawer-section" id="d-comp" hidden></div>

      <div class="drawer-section">
        <div class="section-label"><?= t('common.col.notes') ?></div>
        <div class="field"><textarea id="d-notes" placeholder="<?= t('drawer.notes_ph') ?>"></textarea></div>
      </div>

      <div class="drawer-section">
        <div class="section-label"><?= t('settings.photos') ?></div>
        <?php if ($canEdit): ?>
        <div class="img-upload-area">
          <input type="file" id="d-photos" accept="image/*" multiple onchange="uploadPhotos(event)">
          <div class="img-upload-text"><?= t('drawer.add_photos') ?></div>
        </div>
        <?php endif; ?>
        <div class="d-empty" id="d-no-photos" style="display:none"><?= t('wish.no_photos') ?></div>
        <div class="img-preview-grid" id="d-photo-grid"></div>
        <div id="d-primary-wrap" style="display:none;margin-top:10px">
          <div class="section-label" style="margin-bottom:6px"><?= t('drawer.primary') ?></div>
          <select id="d-primary" style="font-size:.75rem;padding:6px 10px;width:100%">
            <option value="">— <?= t('drawer.first_photo') ?> —</option>
          </select>
        </div>
      </div>

      <!-- Share condition report (assets/js/share-drawer.js) -->
      <div class="drawer-section" id="d-share" hidden></div>

    </div>
    <div class="drawer-footer">
      <?php if ($canEdit): ?>
      <a class="btn-ghost" id="d-goto" href="#" style="text-decoration:none;margin-right:auto"><?= t('common.nav.collection') ?> →</a>
      <button class="btn-ghost" onclick="closeDrawer()"><?= t('common.cancel') ?></button>
      <button class="btn" onclick="saveEntry()"><?= t('common.save') ?></button>
      <?php else: ?>
      <button class="btn-ghost" onclick="closeDrawer()"><?= t('common.close') ?></button>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>window.GRADING = <?= gradingClientJson($canEdit ? $user : null) ?>;</script>
<script src="<?= BASE_URL ?>/assets/js/grading.js?v=<?= @filemtime(__DIR__.'/assets/js/grading.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/editions-drawer.js?v=<?= @filemtime(__DIR__.'/assets/js/editions-drawer.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/compilations-drawer.js?v=<?= @filemtime(__DIR__.'/assets/js/compilations-drawer.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/lightbox.js?v=<?= @filemtime(__DIR__.'/assets/js/lightbox.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/vendor/qrcode.js?v=<?= @filemtime(__DIR__.'/assets/js/vendor/qrcode.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/labels.js?v=<?= @filemtime(__DIR__.'/assets/js/labels.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/share-drawer.js?v=<?= @filemtime(__DIR__.'/assets/js/share-drawer.js') ?>"></script>
<script>
const BASE     = <?= json_encode(BASE_URL) ?>;
const CAN_EDIT = <?= json_encode($canEdit) ?>;
const GAMES    = <?= json_encode((object)$games, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const ROWS     = <?= json_encode($rowList) ?>;
const AUCTION_SITES = <?= json_encode($auctionSites, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const EDITION_GROUPS = <?= json_encode((object)$editionGroups, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const EDITION_WISHLIST = <?= json_encode(($user['edition_wishlist'] ?? 'any') === 'exact' ? 'exact' : 'any') ?>;
const TRACK_VARIANTS = <?= json_encode($trackVariants) ?>;
const VARIANT_OPTS   = <?= json_encode($variantOpts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let reloadOnClose = false;   // another edition was put on / taken off the wishlist from the drawer

let editGameId = null;
let editCopy   = 1;
let photoTs    = {}; // filename -> latest timestamp after rotation
const gradeEditor = new GradingUI.Editor({
  root:       document.getElementById('d-grading'),
  compField:  document.getElementById('d-completeness-field'),
  compSelect: document.getElementById('d-completeness'),
  readOnly:   !CAN_EDIT,
});

// ── HELPERS ──
function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function isSet(v){return v !== null && v !== undefined && v !== '';}
async function apiFetch(path,body){return fetch(BASE+path,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(r=>r.json());}
function toast(msg,err=false){const t=document.getElementById('toast');t.textContent=msg;t.style.borderColor=err?'var(--red)':'var(--accent2)';t.style.color=err?'var(--red)':'var(--accent)';t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2500);}
function photoUrl(fn){const clean=fn.split('?')[0];const ts=photoTs[clean];return `${BASE}/uploads/users/${clean}${ts?'?t='+ts:''}`;}
function findCopy(gameId, pred){return (GAMES[gameId]?.copies||[]).find(pred);}

function entryDisplayImage(g, e) {
  if (e.primary_photo === '__default__') return g.default_image ? `${BASE}/uploads/defaults/${g.default_image}` : null;
  if (e.primary_photo) return photoUrl(e.primary_photo);
  if (e.photos && e.photos.length) return photoUrl(e.photos[0]);
  if (g.default_image) return `${BASE}/uploads/defaults/${g.default_image}`;
  return null;
}

// ── EDITIONS ──
/** Whether the owner owns an edition (games on this wishlist use their loaded copies). */
function editionOwned(grp, id) {
  if (GAMES[id]) return GAMES[id].copies.some(c => c.owned);
  return !!grp.members.find(m => m.id == id)?.owned;
}
/**
 * Edition info for a wishlisted copy: {grp, any, fulfilledBy, cheapest}.
 * "Any edition" wishes are found once the owner has another edition (the wished copy itself is not owned).
 */
function wishEdition(gameId, e) {
  const g = GAMES[gameId];
  const grp = EDITION_GROUPS[g.group_id] || null;
  if (!grp) return { grp: null, any: false, fulfilledBy: null, cheapest: null };
  const any = !!e.wishlist_any;
  const fulfilledBy = any && !e.owned ? grp.members.find(m => m.id != gameId && editionOwned(grp, m.id)) || null : null;
  const prices = grp.members.map(m => parseFloat(m.cib_price)).filter(n => !isNaN(n));
  return { grp, any, fulfilledBy, cheapest: prices.length ? Math.min(...prices) : null };
}

/** Own wishlist only: "In your Arkane Collection" when the owner has this game in a compilation. */
function compMark(g) {
  const via = CAN_EDIT ? (g.comp?.also_in || []).filter(c => c.owned) : [];
  if (!via.length) return '';
  return ` <span class="chip chip-y comp-via" title="${esc(via.map(c => c.title).join(', '))}">${t('comp.in_your', {title: via[0].title})}${via.length > 1 ? ' +' + (via.length - 1) : ''}</span>`;
}

// ── ROW RENDERING ──
function buildRow(entryId, gameId) {
  const g = GAMES[gameId];
  const e = g && g.copies.find(c => c.id == entryId);
  if (!e) return null;
  const ed = wishEdition(gameId, e);

  const imgSrc = entryDisplayImage(g, e);
  const imgCell = imgSrc
    ? `<img class="img-thumb" src="${esc(imgSrc)}" alt="" onclick="openRowImage(${gameId},${entryId})">`
    : `<div class="img-placeholder" style="cursor:pointer" onclick="openDrawer(${gameId},${entryId})">—</div>`;

  const paidCell = isSet(e.price_paid) ? `<span class="price">${money(e.price_paid)}</span>` : '<span class="price-na">—</span>';

  let rangeCell = '<span class="price-na">—</span>';
  if (isSet(e.price_min) && isSet(e.price_max)) rangeCell = `<span class="price-range">${money(e.price_min,0)}–${money(e.price_max,0)}</span>`;
  else if (isSet(e.price_min)) rangeCell = `<span class="price-range">≥${money(e.price_min,0)}</span>`;
  else if (isSet(e.price_max)) rangeCell = `<span class="price-range">≤${money(e.price_max,0)}</span>`;

  // Dual CIB price: PC in blue (linked), personal in red
  const cibParts = [];
  if (ed.any && ed.cheapest !== null) {
    // "Any edition": the cheapest edition's price
    cibParts.push(`<span class="price price-chart" title="${esc(tRaw('ed.wish_any'))}">${money(ed.cheapest)}</span>`);
  } else if (isSet(g.cib_price)) {
    const cibTitle = g.cib_price_updated_at ? tRaw('coll.pc_cib_updated', {date: fmtDate(g.cib_price_updated_at)}) : tRaw('coll.pc_cib');
    const amt = money(g.cib_price);
    cibParts.push(g.pc_link
      ? `<a href="${esc(g.pc_link)}" target="_blank" class="price price-chart" style="text-decoration:none" title="${esc(cibTitle)}">${amt}</a>`
      : `<span class="price price-chart" title="${esc(cibTitle)}">${amt}</span>`);
  } else if (g.pc_link) {
    cibParts.push(`<a href="${esc(g.pc_link)}" target="_blank" style="color:var(--wiiu);text-decoration:none;font-size:.75rem" title="${t('drawer.view_pc')}">PC ↗</a>`);
  }
  if (isSet(e.chart_price)) cibParts.push(`<span class="price" style="color:var(--personal-price)" title="${t('coll.personal_price')}">${money(e.chart_price)}</span>`);
  const cibCell = cibParts.length ? cibParts.join(' <span style="color:var(--border2)">·</span> ') : '<span class="price-na">—</span>';

  const looseCell = isSet(g.loose_price)
    ? `<span class="price price-chart" style="color:var(--muted)" title="${esc(g.loose_price_updated_at?tRaw('coll.last_updated', {date: fmtDate(g.loose_price_updated_at)}):tRaw('common.col.loose_price'))}">${money(g.loose_price)}</span>`
    : '<span class="price-na">—</span>';
  const newCell = isSet(g.new_price)
    ? `<span class="price price-chart" style="color:var(--green)" title="${esc(g.new_price_updated_at?tRaw('coll.last_updated', {date: fmtDate(g.new_price_updated_at)}):tRaw('common.col.new_price'))}">${money(g.new_price)}</span>`
    : '<span class="price-na">—</span>';

  const upReason = e.upgrade && e.upgrade_reason ? e.upgrade_reason : '';
  // Title: the game name with its edition ("any edition" or the label), and "owned (Platinum)" once found
  const titleHtml = !ed.grp ? esc(g.title)
    : `${esc(ed.grp.title)} <span class="chip chip-blue">${ed.any ? t('ed.any_edition') : esc(g.edition_label || '')}</span>`
      + (ed.fulfilledBy ? ` <span class="chip chip-y">${t('ed.owned_as', {label: ed.fulfilledBy.edition_label || ed.fulfilledBy.title})}</span>` : '')
      + compMark(g);
  const noteText = e.notes || (upReason ? '↑ '+upReason : '—');
  const tagLabel = e.tag || '';

  const ownedHandler   = CAN_EDIT ? `onclick="wlToggleOwned(this,${entryId},${gameId})" style="cursor:pointer"` : '';
  const upgradeHandler = CAN_EDIT ? `onclick="wlToggleUpgrade(this,${entryId},${gameId})" style="cursor:pointer"` : '';
  const actionCell = CAN_EDIT
    ? `<a href="${BASE}/collection.php?s=${g.system_id}" class="btn-icon" style="text-decoration:none">${t('wish.go')} →</a>`
    : `<button class="btn-icon" onclick="openDrawer(${gameId},${entryId})">${t('wish.view')}</button>`;

  const tr = document.createElement('tr');
  if (ed.fulfilledBy) tr.classList.add('ed-fulfilled');
  Object.assign(tr.dataset, {
    entryId: entryId, gameId: gameId,
    system: g.system_id,
    owned: e.owned ? '1' : '0',
    upgrade: e.upgrade ? '1' : '0',
    title: (ed.grp ? ed.grp.title + ' ' + g.title : g.title).toLowerCase(),
    tag: tagLabel.toLowerCase(),
    systemName: (g.short_name||'').toLowerCase(),
    quality: GradingCore.sortValue(e),
    paid: parseFloat(e.price_paid)||0,
    cib: (ed.any ? ed.cheapest : parseFloat(g.cib_price))||0,
    loose: parseFloat(g.loose_price)||0,
    newp: parseFloat(g.new_price)||0,
  });

  tr.innerHTML = `
    <td data-col="img">${imgCell}</td>
    <td data-col="owned" style="text-align:center"><div class="owned-check ${e.owned?'checked':''}" ${ownedHandler}>${e.owned?'✓':''}</div></td>
    <td data-col="upgrade" style="text-align:center"><div class="upgrade-check ${e.upgrade?'checked':''}" ${upgradeHandler}>${e.upgrade?'↑':''}</div></td>
    <td data-col="system"><span class="sys-badge">${esc(g.short_name)}</span></td>
    <td data-col="title" class="td-title" onclick="openDrawer(${gameId},${entryId})" title="${t('wish.show_details')}">${titleHtml}</td>
    <td data-col="upgrade_reason" style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.68rem;color:var(--orange);font-style:italic" title="${esc(e.upgrade_reason||'')}">${upReason ? esc(upReason) : '<span style="color:var(--border2)">—</span>'}</td>
    <td data-col="quality">${GradingUI.cellHtml([e])}</td>
    <td data-col="completeness" style="font-size:.7rem;color:var(--text2)">${esc(e.completeness||'—')}</td>
    <td data-col="price_paid">${paidCell}</td>
    <td data-col="buy_range">${rangeCell}</td>
    <td data-col="loose_price">${looseCell}</td>
    <td data-col="cib_price">${cibCell}</td>
    <td data-col="new_price">${newCell}</td>
    <td data-col="tag" style="font-size:.68rem;color:var(--wiiu)">${tagLabel ? esc(tagLabel) : '<span style="color:var(--border2)">—</span>'}</td>
    <td data-col="notes" class="note-cell" style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.66rem;color:var(--muted);font-style:italic" title="${esc(noteText)}">${esc(noteText)}</td>
    <td data-col="__edit">${actionCell}</td>`;
  return tr;
}

function renderAllRows() {
  const tbody = document.getElementById('tbody');
  tbody.innerHTML = '';
  GradingUI.beginRender();
  const anyShown = new Set();   // an "any edition" wish is shown once per group
  ROWS.forEach(r => {
    const e = findCopy(r.game_id, c => c.id == r.entry_id);
    if (e) {
      const ed = wishEdition(r.game_id, e);
      if (ed.any) {
        if (anyShown.has(ed.grp.id)) return;
        anyShown.add(ed.grp.id);
      }
      if (ed.fulfilledBy && !CAN_EDIT) return;   // found: the public wishlist leaves it out
    }
    const tr = buildRow(r.entry_id, r.game_id);
    if (tr) tbody.appendChild(tr);
  });
}

// Re-sync all rows of one game after its data changed
function refreshGameRows(gameId) {
  const tbody = document.getElementById('tbody');
  const g = GAMES[gameId];
  const rows = [...tbody.querySelectorAll(`tr[data-game-id="${gameId}"]`)];
  let removed = 0;

  rows.forEach(tr => {
    const e = g.copies.find(c => c.id == tr.dataset.entryId);
    if (e && e.wishlist) {
      const fresh = buildRow(e.id, gameId);
      fresh.style.display = tr.style.display;
      tr.replaceWith(fresh);
    } else {
      tr.remove(); removed++;
    }
  });

  // Copies newly put on the wishlist get a row next to the game's other rows
  g.copies.filter(c => c.wishlist && !tbody.querySelector(`tr[data-entry-id="${c.id}"]`)).forEach(c => {
    const fresh = buildRow(c.id, gameId);
    const last = [...tbody.querySelectorAll(`tr[data-game-id="${gameId}"]`)].pop();
    if (last) last.after(fresh); else tbody.appendChild(fresh);
  });

  applyWishCols();
  filterTable();
  updateCounts();
  if (removed) toast(tRaw('coll.wish_removed'));
}

function updateCounts() {
  const total = document.querySelectorAll('#tbody tr').length;
  document.getElementById('hs-wish').textContent = fmtNum(total);
  document.getElementById('sum-tot').textContent = fmtNum(total);
  document.getElementById('empty-state').style.display = total ? 'none' : 'block';
}

// ── WISHLIST COLUMN PREFS ──
const WISH_COL_DEFAULTS = ['img','owned','upgrade','system','title','upgrade_reason','quality','completeness','price_paid','buy_range','loose_price','cib_price','new_price','tag','notes'];
let wishActiveCols = new Set(WISH_COL_DEFAULTS);
let wishColOrder   = [...WISH_COL_DEFAULTS];

async function initWishCols() {
  if (CAN_EDIT) {
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
  }
  applyWishCols();
}

function applyWishCols() {
  const table = document.getElementById('wish-table');
  if (!table) return;

  table.querySelectorAll('[data-col]').forEach(el => {
    const id = el.dataset.col;
    if (id === '__edit') return;
    el.style.display = wishActiveCols.has(id) ? '' : 'none';
  });

  const thead = table.querySelector('thead tr');
  if (thead) {
    const editTh = thead.querySelector('[data-col="__edit"]');
    wishColOrder.forEach(id => {
      const th = thead.querySelector(`[data-col="${id}"]`);
      if (th) thead.appendChild(th);
    });
    if (editTh) thead.appendChild(editTh);
  }

  table.querySelectorAll('tbody tr').forEach(row => {
    const editTd = row.querySelector('[data-col="__edit"]');
    wishColOrder.forEach(id => {
      const td = row.querySelector(`[data-col="${id}"]`);
      if (td) row.appendChild(td);
    });
    if (editTd) row.appendChild(editTd);
  });
}

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

// ── SAVING ──
// Quick toggles send only the toggled field; entry_save.php keeps everything else
function togglePayload(c, field, val) {
  return { game_id: c.game_id, copy_number: c.copy_number || 1, [field]: val };
}

function mergeSavedEntry(gameId, entry) {
  const g = GAMES[gameId];
  const idx = g.copies.findIndex(c => c.copy_number == entry.copy_number);
  if (idx >= 0) g.copies[idx] = {...g.copies[idx], ...entry};
  else { g.copies.push(entry); g.copies.sort((a,b)=>a.copy_number-b.copy_number); }
}

async function wlToggleOwned(el, entryId, gameId) {
  if (!CAN_EDIT) return;
  const c = findCopy(gameId, x => x.id == entryId); if (!c) return;
  const res = await apiFetch('/api/entry_save.php', togglePayload(c, 'owned', c.owned ? 0 : 1));
  if (res.ok) { mergeSavedEntry(gameId, res.entry); refreshGameRows(gameId); }
  else toast(tRaw('common.err_prefix', {error: res.error||''}), true);
}

async function wlToggleUpgrade(el, entryId, gameId) {
  if (!CAN_EDIT) return;
  const c = findCopy(gameId, x => x.id == entryId); if (!c) return;
  const res = await apiFetch('/api/entry_save.php', togglePayload(c, 'upgrade', c.upgrade ? 0 : 1));
  if (res.ok) { mergeSavedEntry(gameId, res.entry); refreshGameRows(gameId); }
  else toast(tRaw('common.err_prefix', {error: res.error||''}), true);
}

// ── SYSTEM FILTER ──
function toggleSystem(btn) { btn.classList.toggle('active'); filterTable(); }
function selectAllSystems() { document.querySelectorAll('.sys-toggle-btn[data-sysid]').forEach(b => b.classList.add('active')); filterTable(); }
function selectNoSystems()  { document.querySelectorAll('.sys-toggle-btn[data-sysid]').forEach(b => b.classList.remove('active')); filterTable(); }

function getActiveSystems() {
  const active = [...document.querySelectorAll('.sys-toggle-btn[data-sysid].active')].map(b => String(b.dataset.sysid));
  const total  = document.querySelectorAll('.sys-toggle-btn[data-sysid]').length;
  if (active.length === total) return null; // null = all systems
  return new Set(active);
}

function filterTable() {
  const q   = document.getElementById('tb-search').value.toLowerCase();
  const own = document.getElementById('tb-owned').value;
  const upg = document.getElementById('tb-upgrade').value;
  const tag = document.getElementById('tb-tag')?.value.toLowerCase() || '';
  const activeSys = getActiveSystems();
  let showing = 0;

  document.querySelectorAll('#tbody tr').forEach(tr => {
    const title   = tr.dataset.title   || '';
    const system  = tr.dataset.system  || '';
    const owned   = tr.dataset.owned   || '0';
    const upgrade = tr.dataset.upgrade || '0';
    const trTag   = tr.dataset.tag     || '';

    let show = true;
    if (q   && !title.includes(q))                  show = false;
    if (activeSys && !activeSys.has(String(system))) show = false;
    if (own === '0' && owned === '1')                show = false;
    if (own === '1' && owned !== '1')                show = false;
    if (upg === '1' && upgrade !== '1')              show = false;
    if (upg === '0' && upgrade === '1')              show = false;
    if (tag && trTag !== tag)                        show = false;

    tr.style.display = show ? '' : 'none';
    if (show) showing++;
  });
  document.getElementById('sum-show').textContent = fmtNum(showing);
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
      case 'system':     va=a.dataset.systemName||''; vb=b.dataset.systemName||''; break;
      case 'title':      va=a.dataset.title||'';      vb=b.dataset.title||'';      break;
      case 'quality':    va=-parseFloat(a.dataset.quality); vb=-parseFloat(b.dataset.quality); break; // best condition first
      case 'price_paid': va=parseFloat(a.dataset.paid)||0;  vb=parseFloat(b.dataset.paid)||0;  break;
      case 'cib_price':  va=parseFloat(a.dataset.cib)||0;   vb=parseFloat(b.dataset.cib)||0;   break;
      case 'loose_price':va=parseFloat(a.dataset.loose)||0; vb=parseFloat(b.dataset.loose)||0; break;
      case 'new_price':  va=parseFloat(a.dataset.newp)||0;  vb=parseFloat(b.dataset.newp)||0;  break;
      case 'tag':        va=a.dataset.tag||'zzz';     vb=b.dataset.tag||'zzz';     break;
      default:           va=a.dataset.title||'';      vb=b.dataset.title||'';
    }
    if (typeof va==='string') { va=va.toLowerCase(); vb=vb.toLowerCase(); }
    return va<vb ? -wishSortDir : va>vb ? wishSortDir : 0;
  });
  rows.forEach(r => tbody.appendChild(r));
}

// ── DRAWER ──
function openDrawer(gameId, entryId=null) {
  const g = GAMES[gameId]; if (!g) return;
  editGameId = gameId;

  const grp = EDITION_GROUPS[g.group_id] || null;
  document.getElementById('d-title').textContent  = grp ? grp.title : g.title;
  document.getElementById('d-system').textContent = EdDrawer.subtitle(`${g.system_name}${g.sort_order!=null ? ' · #'+String(g.sort_order).padStart(3,'0') : ''}`, g, grp);
  const goto = document.getElementById('d-goto');
  if (goto) goto.href = `${BASE}/collection.php?s=${g.system_id}`;

  // Cover image (default box art)
  const coverWrap = document.getElementById('d-cover-wrap');
  if (g.default_image) {
    document.getElementById('d-cover').src = `${BASE}/uploads/defaults/${g.default_image}`;
    coverWrap.style.display = '';
  } else coverWrap.style.display = 'none';

  // PriceCharting prices
  const pcRow = document.getElementById('d-pc-prices-row');
  const pcLinkEl = document.getElementById('d-pc-link');
  const hasAnyPrice = isSet(g.loose_price) || isSet(g.cib_price) || isSet(g.new_price);
  if (hasAnyPrice || g.pc_link) {
    pcRow.style.display = 'block';
    const setPriceRow = (rowId, valId, price, updatedAt) => {
      const row = document.getElementById(rowId);
      if (isSet(price)) {
        row.style.display = '';
        const title = updatedAt ? tRaw('coll.last_updated', {date: fmtDate(updatedAt)}) : '';
        document.getElementById(valId).innerHTML = title
          ? `<span title="${esc(title)}" style="cursor:help;border-bottom:1px dashed var(--muted)">${money(price)}</span>`
          : money(price);
      } else row.style.display = 'none';
    };
    setPriceRow('d-loose-row','d-loose-price-val', g.loose_price, g.loose_price_updated_at);
    setPriceRow('d-cib-row',  'd-cib-price-val',   g.cib_price,   g.cib_price_updated_at);
    setPriceRow('d-new-row',  'd-new-price-val',   g.new_price,   g.new_price_updated_at);
    if (g.pc_link) { pcLinkEl.href = g.pc_link; pcLinkEl.style.display = 'inline'; }
    else pcLinkEl.style.display = 'none';
  } else pcRow.style.display = 'none';

  // Quick search links
  const linksList = document.getElementById('d-links-list');
  // "Any edition" wishes search for the game name without the edition bracket
  const clicked   = entryId ? g.copies.find(c => c.id == entryId) : null;
  const searchTitle = grp && clicked?.wishlist_any ? grp.title : g.title;
  const titleEnc  = encodeURIComponent(searchTitle.replace(/['"]/g,''));
  let links = `<a href="https://wikipedia.org/w/index.php?search=${titleEnc}" target="_blank" style="font-size:.75rem;color:var(--text2);text-decoration:none">🔍 Wikipedia: ${esc(searchTitle)}</a>`;
  (AUCTION_SITES||[]).forEach(site => {
    if (!site || !site.url_template) return;
    const url = site.url_template
      .replace('{system}', encodeURIComponent((g.short_name||'').toLowerCase()))
      .replace('{title}',  titleEnc)
      .replace('{region}', g.region === 'Mixed' ? '' : (g.region || ''));
    links += `<a href="${esc(url)}" target="_blank" style="font-size:.75rem;color:var(--text2);text-decoration:none">🛒 ${esc(site.label)}: ${esc(searchTitle)}</a>`;
  });
  linksList.innerHTML = links;
  document.getElementById('d-ext-links').style.display = 'block';

  // Compilations: what this game contains, and which compilations it's in (open only games on this wishlist)
  CompDrawer.load({ contains: g.comp?.contains || [], alsoIn: g.comp?.also_in || [], onOpen: id => openDrawer(id), canOpen: id => !!GAMES[id] });

  // Start on the copy whose row was clicked
  const start = entryId ? g.copies.find(c => c.id == entryId) : null;
  editCopy = start ? Number(start.copy_number) : (g.copies[0] ? Number(g.copies[0].copy_number) : 1);

  renderCopyTabs();
  loadCopyIntoForm(editCopy);
  setReadOnly(!CAN_EDIT);
  document.getElementById('drawer-backdrop').classList.add('open');
}

function setReadOnly(ro) {
  document.querySelectorAll('#drawer .drawer-body input, #drawer .drawer-body select, #drawer .drawer-body textarea')
    .forEach(el => { if (el.type !== 'file') el.disabled = ro; });
}

function renderCopyTabs() {
  const copies  = GAMES[editGameId]?.copies || [];
  const nums    = copies.map(c => Number(c.copy_number));
  const maxCopy = Math.max(1, editCopy, ...nums);
  const tabs    = document.getElementById('copy-tabs');
  tabs.innerHTML = '';
  for (let i = 1; i <= maxCopy; i++) {
    const c = copies.find(x => x.copy_number == i);
    if (!CAN_EDIT && !c) continue; // read-only: only copies that exist
    const btn = document.createElement('button');
    btn.className = 'copy-tab' + (i === editCopy ? ' active' : '');
    btn.textContent = tRaw('drawer.copy_n', {n: i}) + (c?.owned ? ' ✓' : '') + (c?.wishlist ? ' ♥' : '');
    btn.onclick = () => { editCopy = i; renderCopyTabs(); loadCopyIntoForm(i); setReadOnly(!CAN_EDIT); };
    tabs.appendChild(btn);
  }
  if (CAN_EDIT) {
    const add = document.createElement('button');
    add.className = 'copy-tab-add'; add.textContent = '+ ' + tRaw('drawer.add_copy');
    add.onclick = () => { editCopy = maxCopy + 1; renderCopyTabs(); loadCopyIntoForm(editCopy); };
    tabs.appendChild(add);
  }
}

function setSelect(id, val) {
  const sel = document.getElementById(id);
  val = val || '';
  if (val && ![...sel.options].some(o => o.value === val)) {
    const opt = document.createElement('option'); opt.value = val; opt.textContent = val; sel.appendChild(opt);
  }
  sel.value = val;
}

function setTog(inputId,val,labelId,text){document.getElementById(inputId).checked=!!val;document.getElementById(labelId).textContent=text;}

function loadCopyIntoForm(copyNum) {
  const c = findCopy(editGameId, x => x.copy_number == copyNum) || {};
  setTog('d-owned',   c.owned,   'lbl-owned',   tRaw(c.owned?'drawer.owned':'drawer.not_owned'));
  setSelect('d-completeness', c.completeness);
  gradeEditor.load(c, {systemId: GAMES[editGameId]?.system_id});
  setSelect('d-played',       c.played_status);
  setSelect('d-tag',          c.tag);
  document.getElementById('d-price').value = isSet(c.price_paid)  ? c.price_paid  : '';
  document.getElementById('d-chart').value = isSet(c.chart_price) ? c.chart_price : '';
  document.getElementById('d-min').value   = isSet(c.price_min)   ? c.price_min   : '';
  document.getElementById('d-max').value   = isSet(c.price_max)   ? c.price_max   : '';
  setTog('d-upgrade', c.upgrade, 'lbl-upgrade', tRaw(c.upgrade?'drawer.upgrade_wanted':'drawer.no_upgrade'));
  setTog('d-wishlist',c.wishlist,'lbl-wishlist',tRaw(c.wishlist?'drawer.wished':'drawer.not_wished'));
  document.getElementById('d-upgrade-reason').value = c.upgrade_reason || '';
  document.getElementById('d-notes').value          = c.notes || '';
  const vtype = c.value_price_type || FMT.valueType;
  document.querySelectorAll('input[name="d-value-type"]').forEach(r => r.checked = r.value === vtype);
  renderPhotoGrid(c.photos || [], c.primary_photo || '', c.id || null);
  const g = GAMES[editGameId];
  const grp = EDITION_GROUPS[g?.group_id] || null;
  EdDrawer.load({
    group: grp, game: g, copy: c,
    trackVariants: TRACK_VARIANTS, variants: VARIANT_OPTS, wishDefault: EDITION_WISHLIST, canEdit: CAN_EDIT,
    status: id => {
      const m = grp?.members.find(x => x.id == id) || {};
      const cs = GAMES[id]?.copies;
      return cs ? { owned: cs.some(x=>x.owned), wished: cs.some(x=>x.wishlist), price: m.cib_price ?? null }
                : { owned: m.owned, wished: m.wished, price: m.cib_price ?? null };
    },
    onOpen: id => openDrawer(id),
    canOpen: id => !!GAMES[id],   // editions that are not on this wishlist have no row here
    onWish: CAN_EDIT ? async id => {
      const m = grp.members.find(x => x.id == id);
      const res = await apiFetch('/api/entry_save.php', { game_id: id, copy_number: 1, wishlist: m.wished ? 0 : 1 });
      if (!res.ok) { toast(tRaw('common.err_prefix', {error: res.error||''}), true); return; }
      m.wished = !!res.entry.wishlist;
      if (GAMES[id]) mergeSavedEntry(id, res.entry);
      reloadOnClose = true;
      toast(m.wished ? '♥ '+tRaw('coll.wish_added') : tRaw('coll.wish_removed'));
    } : null,
  });
  ShareDrawer.load({ entryId: c.id || null, owned: !!c.owned, canEdit: CAN_EDIT });
}

function closeDrawer() {
  document.getElementById('drawer-backdrop').classList.remove('open'); editGameId = null;
  if (reloadOnClose) window.location.reload();
}
function handleBdClick(e){ if (e.target === document.getElementById('drawer-backdrop')) closeDrawer(); }

async function saveEntry() {
  if (!CAN_EDIT || !editGameId) return;
  const gameId = editGameId;
  const payload = {
    game_id: gameId, copy_number: editCopy,
    owned:            document.getElementById('d-owned').checked?1:0,
    completeness:     document.getElementById('d-completeness').value,
    played_status:    document.getElementById('d-played').value,
    price_paid:       document.getElementById('d-price').value||null,
    chart_price:      document.getElementById('d-chart').value||null,
    price_min:        document.getElementById('d-min').value||null,
    price_max:        document.getElementById('d-max').value||null,
    wishlist:         document.getElementById('d-wishlist').checked?1:0,
    upgrade:          document.getElementById('d-upgrade').checked?1:0,
    upgrade_reason:   document.getElementById('d-upgrade-reason').value,
    notes:            document.getElementById('d-notes').value,
    tag:              document.getElementById('d-tag').value,
    value_price_type: document.querySelector('input[name="d-value-type"]:checked')?.value || FMT.valueType,
    primary_photo:    document.getElementById('d-primary').value||null,
  };
  const grading = gradeEditor.getPayload();
  if (grading) payload.grading = grading;
  Object.assign(payload, EdDrawer.payload());
  const res = await apiFetch('/api/entry_save.php', payload);
  if (res.ok && res.moved_from) {
    // Moved to another edition: the rows change, so show the fresh list
    toast(tRaw('common.saved'));
    reloadOnClose = true;
    closeDrawer();
    return;
  }
  if (res.ok) {
    mergeSavedEntry(gameId, res.entry);
    closeDrawer();
    refreshGameRows(gameId);
    toast(tRaw('common.saved'));
  } else toast(tRaw('common.err_prefix', {error: res.error||''}), true);
}

// ── PHOTOS ──
async function uploadPhotos(e) {
  if (!CAN_EDIT) return;
  const files = Array.from(e.target.files);
  const c = findCopy(editGameId, x => x.copy_number == editCopy);
  if (!c?.id) { toast(tRaw('drawer.save_first'), true); e.target.value = ''; return; }
  for (const file of files) {
    const fd = new FormData();
    fd.append('entry_id', c.id);
    fd.append('photo', file);
    const res = await fetch(`${BASE}/api/photo_upload.php`, {method:'POST', body:fd}).then(r => r.json());
    if (res.ok) {
      if (!c.photos) c.photos = [];
      c.photos.push(res.filename);
      renderPhotoGrid(c.photos, c.primary_photo || '', c.id);
    } else toast(tRaw('drawer.upload_failed', {error: res.error || ''}), true);
  }
  e.target.value = '';
  refreshGameRows(editGameId);
  toast(tRaw('drawer.photos_added'));
}

function renderPhotoGrid(photos, primaryPhoto, entryId) {
  const grid = document.getElementById('d-photo-grid');
  grid.innerHTML = '';
  document.getElementById('d-no-photos').style.display = (!CAN_EDIT && !(photos||[]).length) ? '' : 'none';

  (photos||[]).forEach((fn, i) => {
    const div = document.createElement('div');
    div.className = 'img-preview-item';
    const isPrimary = fn === primaryPhoto;
    const img = document.createElement('img');
    img.src = photoUrl(fn) + (photoTs[fn.split('?')[0]] ? '' : '?t=' + Date.now());
    if (isPrimary) img.style.borderColor = 'var(--accent2)';
    img.onclick = () => openLightboxArr(photos, i, entryId);
    div.appendChild(img);
    if (CAN_EDIT) {
      const btns = document.createElement('div');
      btns.style.cssText = 'display:flex;gap:2px;margin-top:2px';
      btns.innerHTML = `
        <button class="img-del-btn" style="position:static;width:auto;padding:0 5px;font-size:.65rem" onclick="rotatePhoto(${i},-90)">↺</button>
        <button class="img-del-btn" style="position:static;width:auto;padding:0 5px;font-size:.65rem;color:var(--muted)" onclick="rotatePhoto(${i},90)">↻</button>
        <button class="img-del-btn" style="position:static;width:auto;flex:1;font-size:.65rem;color:var(--red)" onclick="deletePhoto(${i})">✕</button>`;
      div.appendChild(btns);
    }
    grid.appendChild(div);
  });

  // Primary photo selector
  const wrap = document.getElementById('d-primary-wrap');
  const sel  = document.getElementById('d-primary');
  const g    = GAMES[editGameId];
  const hasDefault = g && g.default_image;
  if (hasDefault || (photos && photos.length > 1)) {
    wrap.style.display = 'block';
    sel.innerHTML = `<option value="">— ${t('drawer.first_photo')} —</option>`;
    if (hasDefault) {
      const opt = document.createElement('option');
      opt.value = '__default__'; opt.textContent = tRaw('drawer.default_cover');
      opt.selected = (primaryPhoto === '__default__');
      sel.appendChild(opt);
    }
    (photos||[]).forEach((fn, i) => {
      const opt = document.createElement('option');
      opt.value = fn; opt.textContent = tRaw('drawer.my_photo', {n: i + 1});
      opt.selected = (fn === primaryPhoto);
      sel.appendChild(opt);
    });
  } else {
    wrap.style.display = 'none';
    sel.innerHTML = `<option value="">— ${t('drawer.first_photo')} —</option>`;
    sel.value = '';
  }
  sel.disabled = !CAN_EDIT;
}

async function rotatePhoto(idx, degrees) {
  if (!CAN_EDIT) return;
  const c = findCopy(editGameId, x => x.copy_number == editCopy);
  if (!c || !c.photos) return;
  const fn = c.photos[idx].split('?')[0];
  toast(tRaw('drawer.rotating'));
  const res = await apiFetch('/api/photo_rotate.php', {entry_id:c.id, filename:fn, degrees});
  if (res.ok) {
    photoTs[fn] = res.ts;
    renderPhotoGrid(c.photos, c.primary_photo||'', c.id);
    refreshGameRows(editGameId);
    toast(tRaw('drawer.rotated'));
  } else toast(tRaw('drawer.rotate_failed', {error: res.error||''}), true);
}

async function deletePhoto(idx) {
  if (!CAN_EDIT) return;
  const c = findCopy(editGameId, x => x.copy_number == editCopy);
  if (!c || !c.photos) return;
  const fn = c.photos[idx];
  const res = await apiFetch('/api/photo_delete.php', {entry_id:c.id, filename:fn});
  if (res.ok) {
    c.photos.splice(idx, 1);
    if (c.primary_photo === fn) c.primary_photo = '';
    renderPhotoGrid(c.photos, c.primary_photo||'', c.id);
    refreshGameRows(editGameId);
    toast(tRaw('drawer.photo_removed'));
  } else toast(tRaw('wish.delete_failed', {error: res.error||''}), true);
}

// ── LIGHTBOX ──
// Thumbnail in the table: show the game's photos, or open the details if there are none
function openRowImage(gameId, entryId) {
  const g = GAMES[gameId];
  const e = g.copies.find(c => c.id == entryId);
  const own = (e?.photos||[]).length ? e : g.copies.find(c => (c.photos||[]).length);
  if (own) openLightboxArr(own.photos, 0, own.id);
  else openDrawer(gameId, entryId);
}

function openCoverLightbox() {
  const g = GAMES[editGameId]; if (!g?.default_image) return;
  Lightbox.open([{ src: `${BASE}/uploads/defaults/${g.default_image}` }], 0);
}

/** Opens the shared lightbox (assets/js/lightbox.js); photos of the owner's copy can be rotated. */
function openLightboxArr(photos, startIdx, entryId=null) {
  const names = (photos||[]).map(fn => fn.split('?')[0]);
  Lightbox.open(names.map(fn => ({ src: photoUrl(fn) })), startIdx, {
    onRotate: CAN_EDIT && entryId ? async (i, degrees) => {
      toast(tRaw('drawer.rotating'));
      const res = await apiFetch('/api/photo_rotate.php', {entry_id: entryId, filename: names[i], degrees});
      if (!res.ok) { toast(tRaw('drawer.rotate_failed', {error: res.error || ''}), true); return null; }
      photoTs[names[i]] = res.ts;
      // Refresh drawer grid + table row for the game that owns this entry
      const gid = Object.keys(GAMES).find(id => GAMES[id].copies.some(c => c.id == entryId));
      if (gid) {
        if (editGameId == gid) {
          const c = findCopy(gid, x => x.copy_number == editCopy);
          if (c) renderPhotoGrid(c.photos, c.primary_photo||'', c.id);
        }
        refreshGameRows(gid);
      }
      toast(tRaw('drawer.rotated'));
      return photoUrl(names[i]);
    } : null,
  });
}

// ── DRAWER TOGGLE LABELS ──
document.getElementById('d-owned').addEventListener('change',function(){document.getElementById('lbl-owned').textContent=tRaw(this.checked?'drawer.owned':'drawer.not_owned');});
document.getElementById('d-upgrade').addEventListener('change',function(){document.getElementById('lbl-upgrade').textContent=tRaw(this.checked?'drawer.upgrade_wanted':'drawer.no_upgrade');});
document.getElementById('d-wishlist').addEventListener('change',function(){document.getElementById('lbl-wishlist').textContent=tRaw(this.checked?'drawer.wished':'drawer.not_wished');});

// Escape closes the drawer (the lightbox handles its own keys while it is open)
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });

// ── INIT ──
renderAllRows();
updateCounts();
initWishCols();
setWLOwned(''); setWLUpgrade('');

['tb-search'].forEach(id => {
  document.getElementById(id)?.addEventListener('input',  filterTable);
  document.getElementById(id)?.addEventListener('change', filterTable);
});
</script>
</body>
</html>
