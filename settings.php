<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();
$msg  = '';
$err  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_display_prefs') {
        $showIcons = !empty($_POST['show_icons']) ? 1 : 0;
        db()->prepare("UPDATE users SET show_system_icons=? WHERE id=?")->execute([$showIcons, $user['id']]);
        $st = db()->prepare("SELECT * FROM users WHERE id=?"); $st->execute([$user['id']]); $user = $st->fetch();
        $msg = tRaw('settings.msg_display_saved');
    }

    if ($action === 'save_wishlist_public') {
        $public = !empty($_POST['wishlist_public']) ? 1 : 0;
        // Generate token if enabling and none exists
        $token = $user['wishlist_token'] ?? '';
        if ($public && !$token) {
            $token = bin2hex(random_bytes(12)); // 24-char hex token
            db()->prepare("UPDATE users SET wishlist_public=?, wishlist_token=? WHERE id=?")->execute([$public, $token, $user['id']]);
        } else {
            db()->prepare("UPDATE users SET wishlist_public=? WHERE id=?")->execute([$public, $user['id']]);
        }
        $st = db()->prepare("SELECT * FROM users WHERE id=?"); $st->execute([$user['id']]); $user = $st->fetch();
        $msg = tRaw($public ? 'settings.sharing_on' : 'settings.sharing_off');
    }

    if ($action === 'regenerate_token') {
        $token = bin2hex(random_bytes(12));
        db()->prepare("UPDATE users SET wishlist_token=? WHERE id=?")->execute([$token, $user['id']]);
        $st = db()->prepare("SELECT * FROM users WHERE id=?"); $st->execute([$user['id']]); $user = $st->fetch();
        $msg = tRaw('settings.link_new');
    }

    if ($action === 'save_completeness') {
        $labels = array_values(array_filter(array_map('trim', $_POST['labels'] ?? [])));
        db()->prepare("DELETE FROM user_completeness_options WHERE user_id=?")->execute([$user['id']]);
        $ins = db()->prepare("INSERT INTO user_completeness_options (user_id, label, sort_order) VALUES (?,?,?)");
        foreach ($labels as $i => $label) { if (strlen($label)<=100) $ins->execute([$user['id'], $label, $i]); }
        $msg = tRaw('settings.comp_saved');
    }

    if ($action === 'save_played') {
        $labels = array_values(array_filter(array_map('trim', $_POST['labels'] ?? [])));
        db()->prepare("DELETE FROM user_played_options WHERE user_id=?")->execute([$user['id']]);
        $ins = db()->prepare("INSERT INTO user_played_options (user_id, label, sort_order) VALUES (?,?,?)");
        foreach ($labels as $i => $label) { if (strlen($label)<=100) $ins->execute([$user['id'], $label, $i]); }
        $msg = tRaw('settings.played_saved');
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        // Re-fetch fresh user for password check
        $fu = db()->prepare("SELECT password FROM users WHERE id=?"); $fu->execute([$user['id']]); $fu = $fu->fetch();
        $pwKeys = ['pw:'.$user['id']];
        if ($locked = lockRemaining($pwKeys)) { $err = lockMessage($locked); }
        elseif (!password_verify($current, $fu['password'])) { recordFailure($pwKeys); $err = tRaw('settings.err_current_pw'); }
        elseif (strlen($new) < MIN_PASSWORD_LENGTH) { $err = tRaw('common.err_password_length', ['n' => MIN_PASSWORD_LENGTH]); }
        elseif ($new !== $confirm)  { $err = tRaw('common.err_password_match'); }
        else {
            clearFailures($pwKeys);
            setPassword($user['id'], $new);
            $msg = tRaw('settings.pw_changed');
        }
    }
}

// Load data
$compOpts   = db()->prepare("SELECT label FROM user_completeness_options WHERE user_id=? ORDER BY sort_order");
$compOpts->execute([$user['id']]); $compOpts = $compOpts->fetchAll(PDO::FETCH_COLUMN);

$playedOpts = db()->prepare("SELECT label FROM user_played_options WHERE user_id=? ORDER BY sort_order");
$playedOpts->execute([$user['id']]); $playedOpts = $playedOpts->fetchAll(PDO::FETCH_COLUMN);

$gradeLabels  = gradingConfig()['labels'];
$gradingMode  = $user['grading_mode']    ?? 'simple';
$gradingDef   = $user['grading_default'] ?? 'simple';
$previewLabel = gradeLabelForScore(93);

$systems = db()->prepare("SELECT s.*, COALESCE(usp.visible,1) AS visible FROM systems s LEFT JOIN user_system_prefs usp ON usp.system_id=s.id AND usp.user_id=? WHERE s.active=1 ORDER BY s.sort_order");
$systems->execute([$user['id']]); $systems = $systems->fetchAll();

// Systems this user has photos for, with their current backup zip (if any)
purgeExpiredBackups();
$backupSysSt = db()->prepare("
    SELECT s.id, s.name, s.short_name, pc.photo_count,
           ub.token, ub.status, ub.file_size, ub.created_at, ub.expires_at,
           (ub.status = 'ready' AND ub.expires_at > NOW()) AS has_backup
    FROM systems s
    JOIN (SELECT g.system_id, COUNT(*) AS photo_count
          FROM copy_photos cp
          JOIN collection_entries ce ON ce.id = cp.entry_id
          JOIN games g ON g.id = ce.game_id
          WHERE ce.user_id = ?
          GROUP BY g.system_id) pc ON pc.system_id = s.id
    LEFT JOIN user_backups ub ON ub.system_id = s.id AND ub.user_id = ?
    WHERE s.active = 1
    ORDER BY s.sort_order
");
$backupSysSt->execute([$user['id'], $user['id']]);
$backupSystems = $backupSysSt->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pageTitle(tRaw('common.nav.settings')) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<?= themeHead($user) ?>
<style>
  .settings-wrap { max-width:640px; margin:36px auto; padding:0 20px 60px; }
  .settings-section { margin-bottom:40px; }
  .settings-section h2 { font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case); font-size:1.4rem; color:var(--accent); letter-spacing:.06em; margin-bottom:14px; border-bottom:1px solid var(--border); padding-bottom:8px; }
  .comp-list { display:flex; flex-direction:column; gap:8px; margin-bottom:12px; }
  .comp-item { display:flex; gap:8px; align-items:center; }
  .comp-item input { flex:1; }
  .drag-handle { color:var(--muted); cursor:grab; font-size:.9rem; padding:0 4px; user-select:none; }
  .sys-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:8px; }
  .sys-toggle { display:flex; align-items:center; gap:10px; padding:8px 12px; background:var(--surface2); border:1px solid var(--border2); }
  .sys-toggle label { font-size:.75rem; color:var(--text2); cursor:pointer; flex:1; }
  .export-row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  .export-desc { font-size:.73rem; color:var(--muted); margin-bottom:12px; }
  .gm-cards { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }
  .gm-card { display:flex; flex-direction:column; gap:6px; padding:14px 14px; background:var(--surface2); border:1px solid var(--border2); cursor:pointer; }
  .gm-card:has(input:checked) { background:color-mix(in srgb,var(--accent2) 7%,transparent); border-color:var(--accent2); }
  .gm-card input { position:absolute; opacity:0; pointer-events:none; }
  .gm-card:has(input:focus-visible) { outline:1px solid var(--accent); }
  .gm-title { display:flex; align-items:center; gap:8px; font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case); font-size:1.15rem; letter-spacing:.06em; color:var(--accent); }
  .gm-dot { width:13px; height:13px; border-radius:50%; border:2px solid var(--border2); flex-shrink:0; }
  .gm-card:has(input:checked) .gm-dot { border:4px solid var(--accent2); background:var(--bg); }
  .gm-desc { font-size:.7rem; color:var(--text2); line-height:1.6; }
  .gm-box { margin-top:12px; background:var(--surface2); border:1px solid var(--border); padding:12px 14px; font-size:.7rem; color:var(--text2); line-height:1.6; }
  .gm-default { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
  .gm-lbl { font-size:.56rem; letter-spacing:.2em; text-transform:uppercase; color:var(--muted); }
  .gm-previews { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .gm-previews .gm-box { display:flex; flex-direction:column; gap:8px; }
  @media(max-width:600px) { .gm-cards, .gm-previews { grid-template-columns:1fr; } }
</style>
<?= csrfScript() ?>
<?= appScript(['settings', 'import']) ?>
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/dashboard.php" class="site-logo" style="text-decoration:none"><?= siteLogoHtml() ?></a>
  <nav class="site-nav">
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link"><?= t('common.nav.dashboard') ?></a>
    <a href="<?= BASE_URL ?>/collection.php" class="nav-link"><?= t('common.nav.collection') ?></a>
    <a href="<?= BASE_URL ?>/wishlist.php" class="nav-link"><?= t('common.nav.wishlist') ?></a>
    <?php if (isAdmin()): ?><a href="<?= BASE_URL ?>/admin.php" class="nav-link"><?= t('common.nav.admin') ?></a><?php endif; ?>
    <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link"><?= t('common.nav.sign_out') ?></a>
  </nav>
</header>

<div class="settings-wrap">
  <h1 style="font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case);font-size:2rem;color:var(--accent);letter-spacing:.06em;margin-bottom:28px"><?= t('common.nav.settings') ?></h1>

  <?php if ($msg): ?>
    <div style="background:color-mix(in srgb,var(--green) 10%,transparent);border:1px solid color-mix(in srgb,var(--green) 30%,transparent);color:var(--green);padding:10px 16px;margin-bottom:20px;font-size:.8rem;"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div style="background:color-mix(in srgb,var(--red) 10%,transparent);border:1px solid color-mix(in srgb,var(--red) 30%,transparent);color:var(--red);padding:10px 16px;margin-bottom:20px;font-size:.8rem;"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <!-- ACCOUNT INFO -->
  <div class="settings-section">
    <h2><?= t('settings.account') ?></h2>
    <p style="font-size:.8rem;color:var(--text2)"><?= t('auth.username') ?>: <strong style="color:var(--accent)"><?= htmlspecialchars($user['username']) ?></strong></p>
    <p style="font-size:.75rem;color:var(--muted);margin-top:6px"><?= t('settings.username_note') ?></p>
  </div>

  <!-- LANGUAGE -->
  <div class="settings-section">
    <h2><?= t('settings.language') ?></h2>
    <p class="export-desc"><?= t('settings.language_desc') ?></p>
    <select id="lang-select" onchange="saveLanguage(this.value)" style="max-width:280px">
      <?php foreach (availableLanguages() as $l): ?>
      <option value="<?= htmlspecialchars($l['code']) ?>" <?= currentLang() === $l['code'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?><?= $l['code'] === siteLanguage() ? ' ★' : '' ?></option>
      <?php endforeach; ?>
    </select>
    <p style="font-size:.66rem;color:var(--muted);margin-top:6px">★ <?= t('settings.site_default_mark') ?></p>
  </div>

  <!-- THEME -->
  <div class="settings-section">
    <h2><?= t('settings.theme') ?></h2>
    <p class="export-desc"><?= t('settings.theme_desc') ?></p>
    <div class="theme-grid" role="radiogroup" aria-label="<?= t('settings.theme') ?>">
      <?php foreach (availableThemes() as $t) echo themeCardHtml($t, 'theme', activeTheme($user) === $t['slug'], 'pickTheme'); ?>
    </div>
  </div>

  <!-- CONDITION GRADING -->
  <div class="settings-section">
    <h2><?= t('settings.grading') ?></h2>
    <p class="export-desc"><?= t('settings.grading_desc') ?></p>
    <div class="gm-cards" role="radiogroup" aria-label="<?= t('settings.grading') ?>">
      <?php foreach ([
        'simple' => [t('grading.mode_simple'), t('grading.mode_simple_desc')],
        'points' => [t('grading.mode_points'), t('grading.mode_points_desc')],
        'both'   => [t('grading.mode_both'), t('grading.mode_both_desc')],
      ] as $val => [$title, $desc]): ?>
      <label class="gm-card">
        <input type="radio" name="grading_mode" value="<?= $val ?>" <?= $gradingMode === $val ? 'checked' : '' ?> onchange="gmRefresh()">
        <span class="gm-title"><span class="gm-dot"></span><?= $title ?></span>
        <span class="gm-desc"><?= $desc ?></span>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="gm-box gm-default" id="gm-default" style="<?= $gradingMode === 'both' ? '' : 'display:none' ?>">
      <span class="gm-lbl"><?= t('settings.default_new') ?></span>
      <div class="gr-switch" role="group" aria-label="<?= t('settings.default_new') ?>">
        <button type="button" data-def="simple" class="<?= $gradingDef === 'simple' ? 'on' : '' ?>" aria-pressed="<?= $gradingDef === 'simple' ? 'true' : 'false' ?>" onclick="gmSetDefault('simple')"><?= t('grading.simple') ?></button>
        <button type="button" data-def="points" class="<?= $gradingDef === 'points' ? 'on' : '' ?>" aria-pressed="<?= $gradingDef === 'points' ? 'true' : 'false' ?>" onclick="gmSetDefault('points')"><?= t('grading.points') ?></button>
      </div>
      <span style="color:var(--muted)"><?= t('settings.switch_note') ?></span>
    </div>

    <div class="gm-previews">
      <div class="gm-box">
        <span class="gm-lbl"><?= t('settings.simple_looks') ?></span>
        <div style="display:flex;gap:6px;flex-wrap:wrap"><?php foreach ($gradeLabels as $l) echo gradeBadgeHtml($l); ?></div>
      </div>
      <div class="gm-box">
        <span class="gm-lbl"><?= t('settings.points_looks') ?></span>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <span style="font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case);font-size:1.5rem;line-height:1;color:<?= htmlspecialchars($previewLabel['color'] ?? 'var(--text2)') ?>">93</span>
          <?= gradeBadgeHtml($previewLabel) ?>
          <span style="font-size:.64rem;color:var(--muted)"><?= t('settings.points_example') ?></span>
        </div>
      </div>
    </div>

    <div class="gm-box">
      <?= t('settings.grading_admin_note') ?>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="btn btn-sm" onclick="saveGrading()"><?= t('settings.save_grading') ?></button></div>
  </div>

  <!-- WISHLIST SHARING -->
  <div class="settings-section">
    <h2><?= t('settings.sharing') ?></h2>
    <p class="export-desc"><?= t('settings.sharing_desc') ?></p>
    <div style="display:flex;flex-direction:column;gap:14px;max-width:480px">
      <div class="toggle-row">
        <label class="toggle">
          <input type="checkbox" id="wishlist_public" value="1" <?= $user['wishlist_public'] ? 'checked' : '' ?> onchange="saveWishlistPublic(this.checked)">
          <span class="toggle-slider"></span>
        </label>
        <span class="toggle-label"><?= t('settings.sharing_toggle') ?></span>
      </div>
    </div>
    <?php if ($user['wishlist_public']): ?>
    <div style="margin-top:14px;padding:12px 14px;background:var(--surface2);border:1px solid var(--border2)">
      <div style="font-size:.62rem;color:var(--muted);letter-spacing:.15em;text-transform:uppercase;margin-bottom:6px"><?= t('settings.your_link') ?></div>
      <?php $wtoken = $user['wishlist_token'] ?? ''; ?>
      <?php if ($wtoken): ?>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <code id="wishlist-link-code" style="font-size:.75rem;color:var(--wiiu);background:color-mix(in srgb,var(--wiiu) 7%,transparent);padding:6px 10px;border:1px solid color-mix(in srgb,var(--wiiu) 20%,transparent);flex:1;word-break:break-all"><?= BASE_URL ?>/wishlist.php?token=<?= htmlspecialchars($wtoken) ?></code>
        <button class="btn-ghost" onclick="copyLink()" style="white-space:nowrap"><?= t('common.copy') ?></button>
      </div>
      <div style="margin-top:8px">
        <button class="btn-ghost" onclick="regenerateToken()" style="font-size:.68rem">⟳ <?= t('settings.new_link') ?></button>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Other public wishlists -->
    <?php
    $publicUsers = db()->query("SELECT username, wishlist_token FROM users WHERE wishlist_public=1 AND status='active' ORDER BY username")->fetchAll();
    $publicUsers = array_filter($publicUsers, fn($u) => $u['username'] !== $user['username'] && !empty($u['wishlist_token']));
    ?>
    <?php if ($publicUsers): ?>
    <div style="margin-top:20px">
      <div style="font-size:.62rem;color:var(--muted);letter-spacing:.15em;text-transform:uppercase;margin-bottom:8px"><?= t('settings.other_wishlists') ?></div>
      <select onchange="if(this.value) window.open(this.value,'_blank')" style="padding:8px 10px;font-size:.78rem">
        <option value="">— <?= t('settings.select_user') ?> —</option>
        <?php foreach ($publicUsers as $pu):
          $tok = $pu['wishlist_token'] ?? '';
          if (!$tok) continue;
        ?>
        <option value="<?= BASE_URL ?>/wishlist.php?token=<?= htmlspecialchars($tok) ?>"><?= htmlspecialchars($pu['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
  </div>

  <!-- TAG OPTIONS -->
  <div class="settings-section">
    <h2><?= t('settings.tags') ?></h2>
    <p class="export-desc"><?= t('settings.tags_desc') ?></p>
    <div id="tag-list" class="comp-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px"></div>
    <div style="display:flex;gap:6px;margin-bottom:10px">
      <input type="text" id="new-tag" placeholder="<?= t('settings.new_tag') ?>" style="flex:1;padding:8px 10px;font-size:.78rem">
      <button class="btn-ghost" onclick="addTagItem()"><?= t('settings.add_tag') ?></button>
    </div>
    <button class="btn btn-sm" onclick="saveTagOptions()"><?= t('settings.save_tags') ?></button>
  </div>

  <!-- AUCTION SITES -->
  <div class="settings-section">
    <h2><?= t('settings.sites') ?></h2>
    <p class="export-desc"><?= t('settings.sites_desc') ?><br>
    <?= t('settings.example') ?>: <code>https://www.ebay.nl/sch/i.html?_nkw={system}+{title}+{region}</code></p>
    <div id="auction-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px"></div>
    <button class="btn-ghost" onclick="addAuctionSite()" style="font-size:.75rem">+ <?= t('settings.add_site') ?></button>
    <button class="btn btn-sm" onclick="saveAuctionSites()" style="margin-left:8px"><?= t('settings.save_sites') ?></button>
  </div>

  <!-- WISHLIST COLUMNS -->
  <div class="settings-section">
    <h2><?= t('settings.wish_cols') ?></h2>
    <p class="export-desc"><?= t('settings.wish_cols_desc') ?></p>
    <div id="col-wish-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px"></div>
    <button class="btn btn-sm" onclick="saveColPrefs('wishlist')"><?= t('settings.save_wish_cols') ?></button>
  </div>

  <!-- COLLECTION COLUMNS -->
  <div class="settings-section">
    <h2><?= t('settings.coll_cols') ?></h2>
    <p class="export-desc"><?= t('settings.coll_cols_desc') ?></p>
    <div id="col-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px"></div>
    <button class="btn btn-sm" onclick="saveColPrefs('collection')"><?= t('settings.save_coll_cols') ?></button>
  </div>

  <!-- COMPLETENESS OPTIONS -->
  <div class="settings-section">
    <h2><?= t('settings.comp') ?></h2>
    <p class="export-desc"><?= t('settings.comp_desc') ?></p>
    <div>
      <div class="comp-list" id="comp-list">
        <?php foreach ($compOpts as $label): ?>
        <div class="comp-item" draggable="true">
          <span class="drag-handle">⠿</span>
          <input type="text" class="comp-label-input" value="<?= htmlspecialchars($label) ?>" maxlength="100">
          <button type="button" class="btn-danger" onclick="removeItem(this)">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:8px;margin-top:10px">
        <button type="button" class="btn-ghost" onclick="addItem('comp-list')">+ <?= t('settings.add_option') ?></button>
        <button type="button" class="btn btn-sm" onclick="saveCompleteness()"><?= t('common.save') ?></button>
      </div>
    </div>
  </div>

  <!-- PLAYED STATUS OPTIONS -->
  <div class="settings-section">
    <h2><?= t('settings.played') ?></h2>
    <p class="export-desc"><?= t('settings.played_desc') ?></p>
    <div>
      <div class="comp-list" id="played-list">
        <?php foreach ($playedOpts as $label): ?>
        <div class="comp-item" draggable="true">
          <span class="drag-handle">⠿</span>
          <input type="text" class="comp-label-input" value="<?= htmlspecialchars($label) ?>" maxlength="100">
          <button type="button" class="btn-danger" onclick="removeItem(this)">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:8px;margin-top:10px">
        <button type="button" class="btn-ghost" onclick="addItem('played-list')">+ <?= t('settings.add_option') ?></button>
        <button type="button" class="btn btn-sm" onclick="savePlayed()"><?= t('common.save') ?></button>
      </div>
    </div>
  </div>

  <!-- SYSTEM VISIBILITY & ORDER -->
  <div class="settings-section">
    <h2><?= t('settings.systems') ?></h2>
    <form method="POST" style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
      <input type="hidden" name="csrf"   value="<?= csrf() ?>">
      <input type="hidden" name="action" value="save_display_prefs">
      <label class="toggle">
        <input type="checkbox" name="show_icons" value="1" <?= ($user['show_system_icons']??1) ? 'checked' : '' ?> onchange="this.form.submit()">
        <span class="toggle-slider"></span>
      </label>
      <span class="toggle-label" style="font-size:.78rem"><?= t('settings.show_icons') ?></span>
    </form>
    <p class="export-desc"><?= t('settings.systems_desc') ?></p>
    <div id="sys-sort-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px">
      <!-- populated by JS -->
    </div>
    <button class="btn btn-sm" onclick="saveSystemPrefs()"><?= t('settings.save_systems') ?></button>
  </div>

  <!-- EXPORT / IMPORT -->
  <div class="settings-section">
    <h2><?= t('settings.backup') ?></h2>
    <p class="export-desc"><?= t('settings.backup_desc') ?></p>
    <div class="export-row">
      <button class="btn btn-sm" onclick="exportData()">⬇ <?= t('settings.export') ?></button>
      <label class="btn btn-sm" style="cursor:pointer">⬆ <?= t('settings.import') ?>
        <input type="file" id="import-file" accept=".json" style="display:none" onchange="importData(event)">
      </label>
    </div>
    <p style="font-size:.68rem;color:var(--muted);margin-top:10px">⚠ <?= t('settings.import_warn') ?></p>
  </div>

  <!-- IMAGE BACKUPS -->
  <div class="settings-section">
    <h2><?= t('settings.img_backups') ?></h2>
    <p class="export-desc">
      <?= t('settings.img_backups_desc') ?>
    </p>
    <?php if (!$backupSystems): ?>
    <p style="font-size:.78rem;color:var(--muted)"><?= t('settings.no_photos') ?></p>
    <?php else: ?>
    <table class="admin-table" style="margin-top:10px">
      <thead>
        <tr><th><?= t('common.system') ?></th><th><?= t('settings.photos') ?></th><th><?= t('settings.zip_size') ?></th><th><?= t('settings.generated') ?></th><th><?= t('settings.expires') ?></th><th><?= t('settings.actions') ?></th></tr>
      </thead>
      <tbody>
      <?php foreach ($backupSystems as $bs):
        $hasBackup = (bool)$bs['has_backup'];
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($bs['short_name']) ?></strong><br><span style="font-size:.65rem;color:var(--muted)"><?= htmlspecialchars($bs['name']) ?></span></td>
        <td><?= (int)$bs['photo_count'] ?></td>
        <td><?= $hasBackup ? fmtNum($bs['file_size']/1024/1024, 1).' MB' : '—' ?></td>
        <td><?= $hasBackup ? fmtDate($bs['created_at'], true) : ($bs['status'] === 'failed' ? '<span style="color:var(--red)">'.t('settings.failed').'</span>' : '—') ?></td>
        <td><?= $hasBackup ? fmtDate($bs['expires_at'], true) : '—' ?></td>
        <td style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn btn-sm" onclick="generateBackup(this)"
                  data-system-id="<?= (int)$bs['id'] ?>" data-name="<?= htmlspecialchars($bs['short_name']) ?>">
            <?= t($hasBackup ? 'settings.regenerate' : 'settings.generate') ?>
          </button>
          <?php if ($hasBackup): ?>
          <a href="<?= BASE_URL ?>/api/backup_download.php?token=<?= htmlspecialchars($bs['token']) ?>" class="btn-ghost" style="padding:7px 12px;font-size:.72rem;text-decoration:none"><?= t('common.download') ?></a>
          <button class="btn-ghost" style="color:var(--red)" onclick="deleteBackup(this)" data-token="<?= htmlspecialchars($bs['token']) ?>"><?= t('common.delete') ?></button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- CHANGE PASSWORD -->
  <div class="settings-section">
    <h2><?= t('settings.change_pw') ?></h2>
    <div style="display:flex;flex-direction:column;gap:14px;max-width:360px">
      <div class="field"><label><?= t('settings.current_pw') ?></label><input type="password" id="cur-pw"></div>
      <div class="field"><label><?= t('settings.new_pw') ?></label><input type="password" id="new-pw"></div>
      <div class="field"><label><?= t('settings.confirm_pw') ?></label><input type="password" id="conf-pw"></div>
      <button type="button" class="btn btn-sm" onclick="changePassword()" style="width:fit-content"><?= t('settings.change_pw') ?></button>
    </div>
  </div>

</div>

<div class="toast" id="toast"></div>

<script>
const BASE = <?= json_encode(BASE_URL) ?>;

function copyLink() {
  const code = document.getElementById('wishlist-link-code');
  if (!code) { toast(tRaw('settings.no_link'), true); return; }
  navigator.clipboard.writeText(code.textContent.trim())
    .then(()=>toast(tRaw('settings.link_copied')))
    .catch(()=>{ /* fallback */ const r=document.createRange(); r.selectNode(code); window.getSelection().removeAllRanges(); window.getSelection().addRange(r); try{document.execCommand('copy');toast(tRaw('settings.link_copied'));}catch(e){toast(tRaw('settings.copy_failed'),true);} });
}

async function saveWishlistPublic(checked) {
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_wishlist_public',wishlist_public:checked})
  }).then(r=>r.json());
  if (res.ok) {
    toast(tRaw(checked ? 'settings.sharing_on' : 'settings.sharing_off'));
    // Update the link display without page reload
    const wrap = document.getElementById('wishlist-link-wrap');
    if (wrap) {
      if (res.token) {
        const code = document.getElementById('wishlist-link-code');
        if (code) code.textContent = res.base_url+'/wishlist.php?token='+res.token;
      }
    }
  } else { toast(tRaw('common.err_saving'), true); }
}

async function regenerateToken() {
  if (!confirm(tRaw('settings.confirm_new_link'))) return;
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'regenerate_token'})
  }).then(r=>r.json());
  if (res.ok) {
    const code = document.getElementById('wishlist-link-code');
    if (code) code.textContent = res.base_url+'/wishlist.php?token='+res.token;
    toast(tRaw('settings.link_new'));
  } else { toast(tRaw('common.error'), true); }
}

async function saveCompleteness() {
  const labels = [...document.querySelectorAll('#comp-list .comp-label-input')].map(i=>i.value.trim()).filter(Boolean);
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_completeness',labels})
  }).then(r=>r.json());
  toast(res.ok ? tRaw('settings.comp_saved') : (res.error||tRaw('common.error')), !res.ok);
}

async function savePlayed() {
  const labels = [...document.querySelectorAll('#played-list .comp-label-input')].map(i=>i.value.trim()).filter(Boolean);
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_played',labels})
  }).then(r=>r.json());
  toast(res.ok ? tRaw('settings.played_saved') : (res.error||tRaw('common.error')), !res.ok);
}

async function changePassword() {
  const cur  = document.getElementById('cur-pw').value;
  const newp = document.getElementById('new-pw').value;
  const conf = document.getElementById('conf-pw').value;
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'change_password',current_password:cur,new_password:newp,confirm_password:conf})
  }).then(r=>r.json());
  if (res.ok) {
    toast(tRaw('settings.pw_changed'));
    document.getElementById('cur-pw').value='';
    document.getElementById('new-pw').value='';
    document.getElementById('conf-pw').value='';
  } else { toast(res.error||tRaw('settings.err_pw'), true); }
}

function addItem(listId) {
  const div = document.createElement('div');
  div.className = 'comp-item'; div.draggable = true;
  div.innerHTML = `<span class="drag-handle">⠿</span><input type="text" class="comp-label-input" value="" maxlength="100" placeholder="${t('settings.new_option')}"><button type="button" class="btn-danger" onclick="removeItem(this)">✕</button>`;
  document.getElementById(listId).appendChild(div);
  div.querySelector('input').focus();
}

function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── CONDITION GRADING ──
let gmDefault = <?= json_encode($gradingDef) ?>;
function gmRefresh() {
  const mode = document.querySelector('input[name="grading_mode"]:checked')?.value;
  document.getElementById('gm-default').style.display = mode === 'both' ? '' : 'none';
}
function gmSetDefault(v) {
  gmDefault = v;
  document.querySelectorAll('#gm-default [data-def]').forEach(b => {
    b.classList.toggle('on', b.dataset.def === v);
    b.setAttribute('aria-pressed', b.dataset.def === v ? 'true' : 'false');
  });
}
async function saveGrading() {
  const mode = document.querySelector('input[name="grading_mode"]:checked')?.value || 'simple';
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_grading', mode, default: gmDefault})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  toast(res.ok ? tRaw('settings.grading_saved') : (res.error||tRaw('common.error')), !res.ok);
}

// ── THEME ──
const THEMES     = <?= themesClientJson() ?>;
const SITE_THEME = <?= json_encode(siteTheme()) ?>;
let savedTheme   = <?= json_encode(activeTheme($user)) ?>;

/** Swaps the theme stylesheets in place, so the choice shows immediately. */
function applyTheme(slug) {
  const t = THEMES[slug];
  if (!t) return;
  document.getElementById('theme-css').href = t.css;
  let fonts = document.getElementById('theme-fonts');
  if (t.fonts) {
    if (!fonts) {
      fonts = Object.assign(document.createElement('link'), { id: 'theme-fonts', rel: 'stylesheet' });
      document.getElementById('theme-css').before(fonts);
    }
    fonts.href = t.fonts;
  } else if (fonts) fonts.remove();
  const meta = document.querySelector('meta[name="color-scheme"]');
  if (meta) meta.content = t.scheme;
}

async function pickTheme(slug) {
  applyTheme(slug);
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    // Picking the site default stores "no choice", so the user keeps following it if the admin changes it
    body:JSON.stringify({action:'save_theme', theme: slug === SITE_THEME ? '' : slug})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if (res.ok) { savedTheme = slug; toast(tRaw('settings.theme_saved')); return; }
  // Put the previous theme back
  applyTheme(savedTheme);
  const prev = document.querySelector(`input[name="theme"][value="${CSS.escape(savedTheme)}"]`);
  if (prev) prev.checked = true;
  toast(res.error || tRaw('settings.theme_failed'), true);
}

// ── LANGUAGE ──
async function saveLanguage(code) {
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_language', language: code})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if (res.ok) { window.location.reload(); return; }
  toast(res.error || tRaw('common.err_saving'), true);
}

// ── AUCTION SITES ──
async function loadAuctionSites() {
  const res = await fetch(`${BASE}/api/auction_sites.php`).then(r=>r.json());
  const sites = res.ok ? (res.sites||[]) : [];
  if (!sites.length) {
    // Add default eBay example
    sites.push({label:'eBay', url_template:'https://www.ebay.nl/sch/i.html?_nkw={system}+{title}+{region}'});
  }
  document.getElementById('auction-list').innerHTML='';
  sites.forEach(s => renderAuctionRow(s.label, s.url_template));
}

function renderAuctionRow(label='', url='') {
  const div = document.createElement('div');
  div.style.cssText = 'display:flex;gap:6px;align-items:center';

  const lblInp = document.createElement('input');
  lblInp.type = 'text'; lblInp.className = 'auction-label';
  lblInp.placeholder = tRaw('settings.site_label');
  lblInp.value = label;
  lblInp.style.cssText = 'width:140px;padding:7px 10px;font-size:.75rem;background:var(--surface);border:1px solid var(--border2);color:var(--text);outline:none';

  const urlInp = document.createElement('input');
  urlInp.type = 'text'; urlInp.className = 'auction-url';
  urlInp.placeholder = tRaw('settings.site_url');
  urlInp.value = url;
  urlInp.style.cssText = 'flex:1;padding:7px 10px;font-size:.72rem;background:var(--surface);border:1px solid var(--border2);color:var(--text);outline:none';

  const delBtn = document.createElement('button');
  delBtn.className = 'btn-ghost';
  delBtn.textContent = '✕';
  delBtn.style.cssText = 'padding:7px 10px;font-size:.72rem;color:var(--red)';
  delBtn.onclick = () => div.remove();

  div.appendChild(lblInp);
  div.appendChild(urlInp);
  div.appendChild(delBtn);
  document.getElementById('auction-list').appendChild(div);
}

function addAuctionSite() { renderAuctionRow(); }

async function saveAuctionSites() {
  const sites=[...document.querySelectorAll('#auction-list > div')].map(div=>({
    label:        div.querySelector('.auction-label').value.trim(),
    url_template: div.querySelector('.auction-url').value.trim(),
  })).filter(s=>s.label&&s.url_template);
  const res = await fetch(`${BASE}/api/auction_sites.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({sites})
  }).then(r=>r.json());
  toast(res.ok?tRaw('settings.sites_saved'):tRaw('common.error'),!res.ok);
}

loadAuctionSites();

// ── TAG OPTIONS ──
async function loadTagOptions() {
  const res = await fetch(`${BASE}/api/tag_options.php`).then(r=>r.json());
  const tags = res.ok ? (res.tags||[]) : [];
  const list = document.getElementById('tag-list');
  list.innerHTML='';
  tags.forEach(t => addTagItemTo(list, t));
  if (!tags.length) initDrag('tag-list');
}

function addTagItem() {
  const inp = document.getElementById('new-tag');
  const val = inp.value.trim();
  if (!val) return;
  addTagItemTo(document.getElementById('tag-list'), val);
  inp.value='';
}

function addTagItemTo(list, label) {
  const div = document.createElement('div');
  div.className = 'comp-item sys-sort-item';
  div.draggable = true;
  div.style.cssText = 'display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--surface2);border:1px solid var(--border2)';

  const handle = document.createElement('span');
  handle.textContent = '⠿';
  handle.style.cssText = 'cursor:grab;color:var(--muted)';

  const lbl = document.createElement('span');
  lbl.className = 'comp-label';
  lbl.textContent = label; // textContent is safe — no XSS
  lbl.style.cssText = 'flex:1;font-size:.8rem';

  const del = document.createElement('button');
  del.className = 'btn-ghost';
  del.textContent = '✕';
  del.style.cssText = 'padding:2px 8px;font-size:.68rem;color:var(--red)';
  del.onclick = () => div.remove();

  div.appendChild(handle);
  div.appendChild(lbl);
  div.appendChild(del);
  list.appendChild(div);
  initDrag('tag-list');
}

async function saveTagOptions() {
  const tags=[...document.querySelectorAll('#tag-list .comp-label')].map(el=>el.textContent.trim());
  const res = await fetch(`${BASE}/api/tag_options.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tags})
  }).then(r=>r.json());
  toast(res.ok?tRaw('settings.tags_saved'):tRaw('common.error'),!res.ok);
}

loadTagOptions();

// ── COLUMN PREFERENCES ──
// Labels come from the language file: common.col.<id>
const col = (id, on) => ({ id, label: t('common.col.' + id), on });
const DEFAULT_COLS_COLLECTION = [
  col('img', true), col('owned', true), col('wishlist', true), col('upgrade', true), col('title', true),
  col('quality', true), col('completeness', true), col('played', true), col('copies', true),
  col('price_paid', true), col('buy_range', true), col('loose_price', false), col('cib_price', true),
  col('new_price', false), col('upgrade_reason', true), col('tag', false), col('notes', true),
];
const DEFAULT_COLS_WISHLIST = [
  col('img', true), col('owned', true), col('upgrade', true), col('system', true), col('title', true),
  col('upgrade_reason', true), col('quality', true), col('completeness', true), col('price_paid', true),
  col('buy_range', true), col('loose_price', false), col('cib_price', true), col('new_price', false),
  col('tag', false), col('notes', true),
];

function mergeWithDefaults(saved, defaults) {
  if (!saved || !saved.length) return [...defaults];
  // Build label lookup from defaults
  const labelMap = {};
  defaults.forEach(d => labelMap[d.id] = d.label);
  // Keep saved order but restore labels from defaults
  const savedIds = saved.map(c=>c.id);
  const merged = saved.map(c => ({
    id:    c.id,
    label: labelMap[c.id] || c.id, // always use default label, fallback to id
    on:    c.on !== undefined ? c.on : true,
  }));
  // Add any new default cols not in saved
  defaults.forEach(d => { if (!savedIds.includes(d.id)) merged.push({...d}); });
  return merged;
}

async function loadColPrefs() {
  const res = await fetch(`${BASE}/api/column_prefs.php`).then(r=>r.json());
  const c = mergeWithDefaults(res.ok ? res.cols_collection : null, DEFAULT_COLS_COLLECTION);
  const w = mergeWithDefaults(res.ok ? res.cols_wishlist   : null, DEFAULT_COLS_WISHLIST);
  renderColList('col-list',      c);
  renderColList('col-wish-list', w);
}

function renderColList(listId, data) {
  const list = document.getElementById(listId);
  list.innerHTML = '';
  data.forEach(col => {
    const div = document.createElement('div');
    div.className = 'sys-sort-item'; div.draggable = true; div.dataset.id = col.id;
    div.style.cssText = 'display:flex;align-items:center;gap:12px;padding:8px 14px;background:var(--surface2);border:1px solid var(--border2)';
    div.innerHTML = `<span style="color:var(--muted);cursor:grab;font-size:1rem;padding:0 4px">⠿</span>
      <label class="toggle" style="flex-shrink:0"><input type="checkbox" class="col-check" data-id="${col.id}" ${col.on?'checked':''}><span class="toggle-slider"></span></label>
      <span style="font-size:.8rem;color:var(--text)">${col.label}</span>`;
    list.appendChild(div);
  });
  initColDrag(listId);
}

function initColDrag(listId) {
  let dragSrc = null;
  document.querySelectorAll(`#${listId} .sys-sort-item`).forEach(item => {
    item.addEventListener('dragstart', () => { dragSrc=item; item.style.opacity='.4'; });
    item.addEventListener('dragend',   () => { item.style.opacity='1'; dragSrc=null; });
    item.addEventListener('dragover',  e => e.preventDefault());
    item.addEventListener('drop', e => {
      e.preventDefault();
      if (dragSrc && dragSrc!==item) {
        const li=document.getElementById(listId);
        const l=[...li.querySelectorAll('.sys-sort-item')];
        if(l.indexOf(dragSrc)<l.indexOf(item)) li.insertBefore(dragSrc,item.nextSibling);
        else li.insertBefore(dragSrc,item);
      }
    });
  });
}

async function saveColPrefs(type) {
  const listId = type==='wishlist' ? 'col-wish-list' : 'col-list';
  const cols = [...document.querySelectorAll(`#${listId} .sys-sort-item`)].map(el=>({
    id: el.dataset.id, on: el.querySelector('.col-check').checked,
  }));
  const res = await fetch(`${BASE}/api/column_prefs.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({type,cols})
  }).then(r=>r.json());
  toast(res.ok?tRaw('common.saved'):tRaw('common.error'),!res.ok);
}

loadColPrefs();
function initDrag(listId) {
  let dragSrc = null;
  document.querySelectorAll('#'+listId+' .comp-item').forEach(item => {
    item.addEventListener('dragstart', () => { dragSrc = item; item.style.opacity='.4'; });
    item.addEventListener('dragend',   () => { item.style.opacity='1'; dragSrc=null; });
    item.addEventListener('dragover',  e => e.preventDefault());
    item.addEventListener('drop', e => {
      e.preventDefault();
      if (dragSrc && dragSrc !== item) {
        const list  = document.getElementById(listId);
        const items = [...list.querySelectorAll('.comp-item')];
        if (items.indexOf(dragSrc) < items.indexOf(item)) list.insertBefore(dragSrc, item.nextSibling);
        else list.insertBefore(dragSrc, item);
      }
    });
  });
}

function removeItem(btn) { btn.closest('.comp-item, .sys-sort-item').remove(); }

initDrag('comp-list');
initDrag('played-list');

// ── SYSTEM ORDER & VISIBILITY ──
let sysData = [];

async function loadSystems() {
  const res = await fetch(`${BASE}/api/system_prefs.php`).then(r=>r.json());
  if (!res.ok) return;
  sysData = res.systems;
  renderSysList();
}

function renderSysList() {
  const list = document.getElementById('sys-sort-list');
  list.innerHTML = '';
  sysData.forEach((s, i) => {
    const div = document.createElement('div');
    div.className = 'sys-sort-item';
    div.draggable = true;
    div.dataset.id = s.id;
    div.style.cssText = 'display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--surface2);border:1px solid var(--border2);cursor:default';
    const iconHtml = s.icon_image
      ? `<img src="${BASE}/uploads/icons/${s.icon_image}" style="width:24px;height:24px;object-fit:contain;flex-shrink:0">`
      : `<span style="width:24px;height:24px;flex-shrink:0;display:inline-block"></span>`;
    div.innerHTML = `
      <span style="color:var(--muted);cursor:grab;font-size:1rem;padding:0 4px" class="sys-drag-handle">⠿</span>
      <label class="toggle" style="flex-shrink:0" title="${t('settings.show_in_coll')}">
        <input type="checkbox" class="sys-check" data-id="${s.id}" ${s.visible?'checked':''}>
        <span class="toggle-slider"></span>
      </label>
      ${iconHtml}
      <span style="flex:1">
        <span style="font-size:.8rem;color:var(--text)">${esc(s.short_name)}</span>
        <span style="font-size:.62rem;color:var(--muted);display:block">${esc(s.name)}</span>
      </span>
      <label style="display:flex;align-items:center;gap:5px;font-size:.65rem;color:var(--muted);flex-shrink:0;cursor:pointer" title="${t('settings.count_totals_title')}">
        <input type="checkbox" class="sys-count-check" data-id="${s.id}" ${s.count_for_totals!==false?'checked':''}>
        <span>${t('settings.count_totals')}</span>
      </label>
    `;
    list.appendChild(div);
  });
  initSysDrag();
}

function initSysDrag() {
  let dragSrc = null;
  document.querySelectorAll('.sys-sort-item').forEach(item => {
    item.addEventListener('dragstart', () => { dragSrc = item; item.style.opacity = '.4'; });
    item.addEventListener('dragend',   () => { item.style.opacity = '1'; dragSrc = null; });
    item.addEventListener('dragover',  e => e.preventDefault());
    item.addEventListener('drop', e => {
      e.preventDefault();
      if (dragSrc && dragSrc !== item) {
        const list  = document.getElementById('sys-sort-list');
        const items = [...list.querySelectorAll('.sys-sort-item')];
        if (items.indexOf(dragSrc) < items.indexOf(item)) list.insertBefore(dragSrc, item.nextSibling);
        else list.insertBefore(dragSrc, item);
        // Update sysData order to match DOM
        const newOrder = [...list.querySelectorAll('.sys-sort-item')].map(el => parseInt(el.dataset.id));
        sysData.sort((a,b) => newOrder.indexOf(a.id) - newOrder.indexOf(b.id));
      }
    });
  });
}

async function saveSystemPrefs() {
  const items = [...document.querySelectorAll('#sys-sort-list .sys-sort-item')];
  const prefs = items.map((el, i) => ({
    system_id:       parseInt(el.dataset.id),
    visible:         el.querySelector('.sys-check').checked,
    sort_order:      i,
    count_for_totals: el.querySelector('.sys-count-check')?.checked ?? true,
  }));
  const res = await fetch(`${BASE}/api/system_prefs.php`, {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({prefs})
  }).then(r=>r.json());
  toast(res.ok ? tRaw('settings.systems_saved') : tRaw('common.err_saving'), !res.ok);
}

loadSystems();

// ── EXPORT / IMPORT ──
function exportData() {
  toast(tRaw('settings.preparing_export'));
  window.location.href = `${BASE}/api/export.php`; // server responds with a file download
}

async function importData(e) {
  const file = e.target.files[0]; if (!file) return;
  const text = await file.text();
  let data; try { data = JSON.parse(text); } catch { toast(tRaw('settings.invalid_json'), true); return; }
  if (!confirm(tRaw('settings.import_confirm'))) return;
  toast(tRaw('import.importing'));
  const res = await fetch(`${BASE}/api/import.php`, {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({data})
  }).then(r=>r.json()).catch(e=>({ok:false, error:tRaw('common.err_server', {error: e.message})}));
  toast(res.ok ? tRaw('import.done_toast') : tRaw('import.failed', {error: res.error||''}), !res.ok);
}

// ── IMAGE BACKUPS ──
async function generateBackup(btn) {
  const label = btn.textContent.trim();
  btn.disabled = true;
  btn.textContent = tRaw('settings.generating');
  toast(tRaw('settings.generating_zip', {name: btn.dataset.name}));
  try {
    const res = await fetch(`${BASE}/api/backup_generate.php`, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({system_id: Number(btn.dataset.systemId)})
    }).then(r => r.json());
    if (!res.ok) throw new Error(res.error || tRaw('common.err_unknown'));
    let msg = tRaw('settings.zip_ready', {n: fmtNum(res.photo_count), size: fmtNum(res.file_size/1024/1024, 1)});
    if (res.missing) msg += ' ' + tRaw('settings.zip_missing', {n: res.missing});
    toast(msg);
    setTimeout(() => window.location.reload(), 1500);
  } catch (e) {
    btn.textContent = label;
    btn.disabled = false;
    toast(tRaw('common.err_prefix', {error: e.message}), true);
  }
}

async function deleteBackup(btn) {
  if (!confirm(tRaw('settings.confirm_delete_zip'))) return;
  const res = await fetch(`${BASE}/api/backup_delete.php`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body:JSON.stringify({token: btn.dataset.token})
  }).then(r => r.json()).catch(() => ({ok:false}));
  if (res.ok) { toast(tRaw('settings.zip_deleted')); setTimeout(() => window.location.reload(), 800); }
  else toast(tRaw('settings.err_delete_zip', {error: res.error || tRaw('common.err_request')}), true);
}

function toast(msg, err=false) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.style.borderColor = err ? 'var(--red)' : 'var(--accent2)';
  t.style.color       = err ? 'var(--red)' : 'var(--accent)';
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'), 2500);
}
</script>
</body>
</html>
