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
        $msg = 'Display preferences saved.';
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
        $msg = 'Wishlist sharing ' . ($public ? 'enabled' : 'disabled') . '.';
    }

    if ($action === 'regenerate_token') {
        $token = bin2hex(random_bytes(12));
        db()->prepare("UPDATE users SET wishlist_token=? WHERE id=?")->execute([$token, $user['id']]);
        $st = db()->prepare("SELECT * FROM users WHERE id=?"); $st->execute([$user['id']]); $user = $st->fetch();
        $msg = 'Wishlist link regenerated.';
    }

    if ($action === 'save_completeness') {
        $labels = array_values(array_filter(array_map('trim', $_POST['labels'] ?? [])));
        db()->prepare("DELETE FROM user_completeness_options WHERE user_id=?")->execute([$user['id']]);
        $ins = db()->prepare("INSERT INTO user_completeness_options (user_id, label, sort_order) VALUES (?,?,?)");
        foreach ($labels as $i => $label) { if (strlen($label)<=100) $ins->execute([$user['id'], $label, $i]); }
        $msg = 'Completeness options saved.';
    }

    if ($action === 'save_played') {
        $labels = array_values(array_filter(array_map('trim', $_POST['labels'] ?? [])));
        db()->prepare("DELETE FROM user_played_options WHERE user_id=?")->execute([$user['id']]);
        $ins = db()->prepare("INSERT INTO user_played_options (user_id, label, sort_order) VALUES (?,?,?)");
        foreach ($labels as $i => $label) { if (strlen($label)<=100) $ins->execute([$user['id'], $label, $i]); }
        $msg = 'Played status options saved.';
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        // Re-fetch fresh user for password check
        $fu = db()->prepare("SELECT password FROM users WHERE id=?"); $fu->execute([$user['id']]); $fu = $fu->fetch();
        $pwKeys = ['pw:'.$user['id']];
        if ($locked = lockRemaining($pwKeys)) { $err = lockMessage($locked); }
        elseif (!password_verify($current, $fu['password'])) { recordFailure($pwKeys); $err = 'Current password is incorrect.'; }
        elseif (strlen($new) < MIN_PASSWORD_LENGTH) { $err = 'New password must be at least '.MIN_PASSWORD_LENGTH.' characters.'; }
        elseif ($new !== $confirm)  { $err = 'New passwords do not match.'; }
        else {
            clearFailures($pwKeys);
            setPassword($user['id'], $new);
            $msg = 'Password changed successfully.';
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
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — Game Collection</title>
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
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/dashboard.php" class="site-logo" style="text-decoration:none">Game <span>Collection</span></a>
  <nav class="site-nav">
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link">Dashboard</a>
    <a href="<?= BASE_URL ?>/collection.php" class="nav-link">Collection</a>
    <a href="<?= BASE_URL ?>/wishlist.php" class="nav-link">Wishlist</a>
    <?php if (isAdmin()): ?><a href="<?= BASE_URL ?>/admin.php" class="nav-link">Admin</a><?php endif; ?>
    <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link">Sign Out</a>
  </nav>
</header>

<div class="settings-wrap">
  <h1 style="font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case);font-size:2rem;color:var(--accent);letter-spacing:.06em;margin-bottom:28px">Settings</h1>

  <?php if ($msg): ?>
    <div style="background:color-mix(in srgb,var(--green) 10%,transparent);border:1px solid color-mix(in srgb,var(--green) 30%,transparent);color:var(--green);padding:10px 16px;margin-bottom:20px;font-size:.8rem;"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div style="background:color-mix(in srgb,var(--red) 10%,transparent);border:1px solid color-mix(in srgb,var(--red) 30%,transparent);color:var(--red);padding:10px 16px;margin-bottom:20px;font-size:.8rem;"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <!-- ACCOUNT INFO -->
  <div class="settings-section">
    <h2>Account</h2>
    <p style="font-size:.8rem;color:var(--text2)">Username: <strong style="color:var(--accent)"><?= htmlspecialchars($user['username']) ?></strong></p>
    <p style="font-size:.75rem;color:var(--muted);margin-top:6px">To change your username, ask the admin.</p>
  </div>

  <!-- THEME -->
  <div class="settings-section">
    <h2>Theme</h2>
    <p class="export-desc">Changes colours, fonts and effects on every page. Only you see your choice; your public wishlist is shown in it too. The theme marked <span style="color:var(--accent2)">★ Site default</span> is the one the admin picked for everyone.</p>
    <div class="theme-grid" role="radiogroup" aria-label="Theme">
      <?php foreach (availableThemes() as $t) echo themeCardHtml($t, 'theme', activeTheme($user) === $t['slug'], 'pickTheme'); ?>
    </div>
  </div>

  <!-- CONDITION GRADING -->
  <div class="settings-section">
    <h2>Condition Grading</h2>
    <p class="export-desc">Choose which grading methods you use. You can switch at any time — nothing is lost: point scores and simple labels are both kept on every copy.</p>
    <div class="gm-cards" role="radiogroup" aria-label="Grading method">
      <?php foreach ([
        'simple' => ['Simple only', 'One label per copy, picked from a list. Quick, and what you used so far.'],
        'points' => ['Points only', 'Grade each part (box, manual, cartridge…) out of 100 by logging defects. The score maps to a label.'],
        'both'   => ['Both', 'Use either per copy. Pick a default below and switch any copy in its edit drawer.'],
      ] as $val => [$title, $desc]): ?>
      <label class="gm-card">
        <input type="radio" name="grading_mode" value="<?= $val ?>" <?= $gradingMode === $val ? 'checked' : '' ?> onchange="gmRefresh()">
        <span class="gm-title"><span class="gm-dot"></span><?= $title ?></span>
        <span class="gm-desc"><?= $desc ?></span>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="gm-box gm-default" id="gm-default" style="<?= $gradingMode === 'both' ? '' : 'display:none' ?>">
      <span class="gm-lbl">Default for new copies</span>
      <div class="gr-switch" role="group" aria-label="Default grading method">
        <button type="button" data-def="simple" class="<?= $gradingDef === 'simple' ? 'on' : '' ?>" aria-pressed="<?= $gradingDef === 'simple' ? 'true' : 'false' ?>" onclick="gmSetDefault('simple')">Simple</button>
        <button type="button" data-def="points" class="<?= $gradingDef === 'points' ? 'on' : '' ?>" aria-pressed="<?= $gradingDef === 'points' ? 'true' : 'false' ?>" onclick="gmSetDefault('points')">Points</button>
      </div>
      <span style="color:var(--muted)">Switch any copy between Simple and Points in its edit drawer.</span>
    </div>

    <div class="gm-previews">
      <div class="gm-box">
        <span class="gm-lbl">Simple looks like</span>
        <div style="display:flex;gap:6px;flex-wrap:wrap"><?php foreach ($gradeLabels as $l) echo gradeBadgeHtml($l); ?></div>
      </div>
      <div class="gm-box">
        <span class="gm-lbl">Points looks like</span>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <span style="font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case);font-size:1.5rem;line-height:1;color:<?= htmlspecialchars($previewLabel['color'] ?? 'var(--text2)') ?>">93</span>
          <?= gradeBadgeHtml($previewLabel) ?>
          <span style="font-size:.64rem;color:var(--muted)">Box 86 · Cart 98 · Man 97</span>
        </div>
      </div>
    </div>

    <div class="gm-box">
      <span style="color:var(--wiiu2)">Managed by the admin:</span> the grade labels (and the score each one starts at), format profiles and component templates.
      Both methods use the same labels, so stats and filters stay consistent.
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="btn btn-sm" onclick="saveGrading()">Save Grading</button></div>
  </div>

  <!-- WISHLIST SHARING -->
  <div class="settings-section">
    <h2>Wishlist Sharing</h2>
    <p class="export-desc">Make your wishlist publicly viewable — anyone with the link can view it without logging in.</p>
    <div style="display:flex;flex-direction:column;gap:14px;max-width:480px">
      <div class="toggle-row">
        <label class="toggle">
          <input type="checkbox" id="wishlist_public" value="1" <?= $user['wishlist_public'] ? 'checked' : '' ?> onchange="saveWishlistPublic(this.checked)">
          <span class="toggle-slider"></span>
        </label>
        <span class="toggle-label">Wishlist is publicly viewable</span>
      </div>
    </div>
    <?php if ($user['wishlist_public']): ?>
    <div style="margin-top:14px;padding:12px 14px;background:var(--surface2);border:1px solid var(--border2)">
      <div style="font-size:.62rem;color:var(--muted);letter-spacing:.15em;text-transform:uppercase;margin-bottom:6px">Your public wishlist link</div>
      <?php $wtoken = $user['wishlist_token'] ?? ''; ?>
      <?php if ($wtoken): ?>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <code id="wishlist-link-code" style="font-size:.75rem;color:var(--wiiu);background:color-mix(in srgb,var(--wiiu) 7%,transparent);padding:6px 10px;border:1px solid color-mix(in srgb,var(--wiiu) 20%,transparent);flex:1;word-break:break-all"><?= BASE_URL ?>/wishlist.php?token=<?= htmlspecialchars($wtoken) ?></code>
        <button class="btn-ghost" onclick="copyLink()" style="white-space:nowrap">Copy</button>
      </div>
      <div style="margin-top:8px">
        <button class="btn-ghost" onclick="regenerateToken()" style="font-size:.68rem">⟳ Generate new link</button>
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
      <div style="font-size:.62rem;color:var(--muted);letter-spacing:.15em;text-transform:uppercase;margin-bottom:8px">Other public wishlists</div>
      <select onchange="if(this.value) window.open(this.value,'_blank')" style="padding:8px 10px;font-size:.78rem">
        <option value="">— Select a user —</option>
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
    <h2>Game Tags</h2>
    <p class="export-desc">Assign tags to games (e.g. "Must Have", "Probably", "Maybe"). Filterable in Collection and Wishlist.</p>
    <div id="tag-list" class="comp-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px"></div>
    <div style="display:flex;gap:6px;margin-bottom:10px">
      <input type="text" id="new-tag" placeholder="New tag label" style="flex:1;padding:8px 10px;font-size:.78rem">
      <button class="btn-ghost" onclick="addTagItem()">Add Tag</button>
    </div>
    <button class="btn btn-sm" onclick="saveTagOptions()">Save Tags</button>
  </div>

  <!-- AUCTION SITES -->
  <div class="settings-section">
    <h2>Auction / Search Sites</h2>
    <p class="export-desc">Add sites to get quick search links from the game sidebar. Use <code>{system}</code>, <code>{title}</code> and <code>{region}</code> as placeholders.<br>
    Example: <code>https://www.ebay.nl/sch/i.html?_nkw={system}+{title}+{region}</code></p>
    <div id="auction-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px"></div>
    <button class="btn-ghost" onclick="addAuctionSite()" style="font-size:.75rem">+ Add Site</button>
    <button class="btn btn-sm" onclick="saveAuctionSites()" style="margin-left:8px">Save Sites</button>
  </div>

  <!-- WISHLIST COLUMNS -->
  <div class="settings-section">
    <h2>Wishlist Columns</h2>
    <p class="export-desc">Choose which columns to show in the Wishlist page.</p>
    <div id="col-wish-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px"></div>
    <button class="btn btn-sm" onclick="saveColPrefs('wishlist')">Save Wishlist Columns</button>
  </div>

  <!-- COLLECTION COLUMNS -->
  <div class="settings-section">
    <h2>Collection Columns</h2>
    <p class="export-desc">Choose which columns to show and drag to reorder.</p>
    <div id="col-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px"></div>
    <button class="btn btn-sm" onclick="saveColPrefs('collection')">Save Collection Columns</button>
  </div>

  <!-- COMPLETENESS OPTIONS -->
  <div class="settings-section">
    <h2>Completeness Options</h2>
    <p class="export-desc">Drag to reorder, ✕ to remove. These appear in the completeness dropdown.</p>
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
        <button type="button" class="btn-ghost" onclick="addItem('comp-list')">+ Add Option</button>
        <button type="button" class="btn btn-sm" onclick="saveCompleteness()">Save</button>
      </div>
    </div>
  </div>

  <!-- PLAYED STATUS OPTIONS -->
  <div class="settings-section">
    <h2>Played Status Options</h2>
    <p class="export-desc">Drag to reorder, ✕ to remove. These appear in the played status dropdown.</p>
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
        <button type="button" class="btn-ghost" onclick="addItem('played-list')">+ Add Option</button>
        <button type="button" class="btn btn-sm" onclick="savePlayed()">Save</button>
      </div>
    </div>
  </div>

  <!-- SYSTEM VISIBILITY & ORDER -->
  <div class="settings-section">
    <h2>Visible Systems & Order</h2>
    <form method="POST" style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
      <input type="hidden" name="csrf"   value="<?= csrf() ?>">
      <input type="hidden" name="action" value="save_display_prefs">
      <label class="toggle">
        <input type="checkbox" name="show_icons" value="1" <?= ($user['show_system_icons']??1) ? 'checked' : '' ?> onchange="this.form.submit()">
        <span class="toggle-slider"></span>
      </label>
      <span class="toggle-label" style="font-size:.78rem">Show system icons on dashboard</span>
    </form>
    <p class="export-desc">Toggle systems on/off, drag to reorder. <strong>Count totals</strong> controls whether a system is included in the global owned %, copies, and upgrade counts on the dashboard and collection page. Disable it for systems like "Hardware" that you don't want affecting your game completion stats. Spent and owned value always include all systems.</p>
    <div id="sys-sort-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px">
      <!-- populated by JS -->
    </div>
    <button class="btn btn-sm" onclick="saveSystemPrefs()">Save Systems & Order</button>
  </div>

  <!-- EXPORT / IMPORT -->
  <div class="settings-section">
    <h2>Backup & Restore</h2>
    <p class="export-desc">Export your collection data (entries, prices, notes, condition grades, options) as a JSON file. Photos are not included — use Image Backups below. Import restores the data; older exports that still contain photos restore those too.</p>
    <div class="export-row">
      <button class="btn btn-sm" onclick="exportData()">⬇ Export Collection</button>
      <label class="btn btn-sm" style="cursor:pointer">⬆ Import Collection
        <input type="file" id="import-file" accept=".json" style="display:none" onchange="importData(event)">
      </label>
    </div>
    <p style="font-size:.68rem;color:var(--muted);margin-top:10px">⚠ Import will merge data. Existing entries may be overwritten.</p>
  </div>

  <!-- IMAGE BACKUPS -->
  <div class="settings-section">
    <h2>Image Backups</h2>
    <p class="export-desc">
      Generate a zip of your uploaded photos per system, organised as <code>System/Game/photo.jpg</code>. Download links are valid for 24 hours.
      One zip per system — generating a new one replaces the previous.
      Default cover art and system icons are not included.
    </p>
    <?php if (!$backupSystems): ?>
    <p style="font-size:.78rem;color:var(--muted)">No photos uploaded yet.</p>
    <?php else: ?>
    <table class="admin-table" style="margin-top:10px">
      <thead>
        <tr><th>System</th><th>Photos</th><th>Zip Size</th><th>Generated</th><th>Expires</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($backupSystems as $bs):
        $hasBackup = (bool)$bs['has_backup'];
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($bs['short_name']) ?></strong><br><span style="font-size:.65rem;color:var(--muted)"><?= htmlspecialchars($bs['name']) ?></span></td>
        <td><?= (int)$bs['photo_count'] ?></td>
        <td><?= $hasBackup ? number_format($bs['file_size']/1024/1024, 1).' MB' : '—' ?></td>
        <td><?= $hasBackup ? htmlspecialchars(substr($bs['created_at'], 0, 16)) : ($bs['status'] === 'failed' ? '<span style="color:var(--red)">Failed</span>' : '—') ?></td>
        <td><?= $hasBackup ? htmlspecialchars(substr($bs['expires_at'], 0, 16)) : '—' ?></td>
        <td style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn btn-sm" onclick="generateBackup(this)"
                  data-system-id="<?= (int)$bs['id'] ?>" data-name="<?= htmlspecialchars($bs['short_name']) ?>">
            <?= $hasBackup ? 'Regenerate' : 'Generate' ?>
          </button>
          <?php if ($hasBackup): ?>
          <a href="<?= BASE_URL ?>/api/backup_download.php?token=<?= htmlspecialchars($bs['token']) ?>" class="btn-ghost" style="padding:7px 12px;font-size:.72rem;text-decoration:none">Download</a>
          <button class="btn-ghost" style="color:var(--red)" onclick="deleteBackup(this)" data-token="<?= htmlspecialchars($bs['token']) ?>">Delete</button>
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
    <h2>Change Password</h2>
    <div style="display:flex;flex-direction:column;gap:14px;max-width:360px">
      <div class="field"><label>Current Password</label><input type="password" id="cur-pw"></div>
      <div class="field"><label>New Password</label><input type="password" id="new-pw"></div>
      <div class="field"><label>Confirm New Password</label><input type="password" id="conf-pw"></div>
      <button type="button" class="btn btn-sm" onclick="changePassword()" style="width:fit-content">Change Password</button>
    </div>
  </div>

</div>

<div class="toast" id="toast"></div>

<script>
const BASE = <?= json_encode(BASE_URL) ?>;

function copyLink() {
  const code = document.getElementById('wishlist-link-code');
  if (!code) { toast('No link available', true); return; }
  navigator.clipboard.writeText(code.textContent.trim())
    .then(()=>toast('Link copied!'))
    .catch(()=>{ /* fallback */ const r=document.createRange(); r.selectNode(code); window.getSelection().removeAllRanges(); window.getSelection().addRange(r); try{document.execCommand('copy');toast('Link copied!');}catch(e){toast('Copy failed',true);} });
}

async function saveWishlistPublic(checked) {
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_wishlist_public',wishlist_public:checked})
  }).then(r=>r.json());
  if (res.ok) {
    toast(checked ? 'Wishlist sharing enabled.' : 'Wishlist sharing disabled.');
    // Update the link display without page reload
    const wrap = document.getElementById('wishlist-link-wrap');
    if (wrap) {
      if (res.token) {
        const code = document.getElementById('wishlist-link-code');
        if (code) code.textContent = res.base_url+'/wishlist.php?token='+res.token;
      }
    }
  } else { toast('Error saving.', true); }
}

async function regenerateToken() {
  if (!confirm('Generate a new link? The old link will stop working.')) return;
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'regenerate_token'})
  }).then(r=>r.json());
  if (res.ok) {
    const code = document.getElementById('wishlist-link-code');
    if (code) code.textContent = res.base_url+'/wishlist.php?token='+res.token;
    toast('New link generated.');
  } else { toast('Error.', true); }
}

async function saveCompleteness() {
  const labels = [...document.querySelectorAll('#comp-list .comp-label-input')].map(i=>i.value.trim()).filter(Boolean);
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_completeness',labels})
  }).then(r=>r.json());
  toast(res.ok ? 'Completeness options saved.' : (res.error||'Error.'), !res.ok);
}

async function savePlayed() {
  const labels = [...document.querySelectorAll('#played-list .comp-label-input')].map(i=>i.value.trim()).filter(Boolean);
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_played',labels})
  }).then(r=>r.json());
  toast(res.ok ? 'Played status options saved.' : (res.error||'Error.'), !res.ok);
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
    toast('Password changed successfully.');
    document.getElementById('cur-pw').value='';
    document.getElementById('new-pw').value='';
    document.getElementById('conf-pw').value='';
  } else { toast(res.error||'Error changing password.', true); }
}

function addItem(listId) {
  const div = document.createElement('div');
  div.className = 'comp-item'; div.draggable = true;
  div.innerHTML = `<span class="drag-handle">⠿</span><input type="text" class="comp-label-input" value="" maxlength="100" placeholder="New option"><button type="button" class="btn-danger" onclick="removeItem(this)">✕</button>`;
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
  toast(res.ok ? 'Grading preferences saved.' : (res.error||'Error.'), !res.ok);
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
  if (res.ok) { savedTheme = slug; toast('Theme saved.'); return; }
  // Put the previous theme back
  applyTheme(savedTheme);
  const prev = document.querySelector(`input[name="theme"][value="${CSS.escape(savedTheme)}"]`);
  if (prev) prev.checked = true;
  toast(res.error || 'Could not save the theme.', true);
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
  lblInp.placeholder = 'Label (e.g. eBay NL)';
  lblInp.value = label;
  lblInp.style.cssText = 'width:140px;padding:7px 10px;font-size:.75rem;background:var(--surface);border:1px solid var(--border2);color:var(--text);outline:none';

  const urlInp = document.createElement('input');
  urlInp.type = 'text'; urlInp.className = 'auction-url';
  urlInp.placeholder = 'URL template with {system} {title} {region}';
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
  toast(res.ok?'Auction sites saved.':'Error.',!res.ok);
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
  toast(res.ok?'Tags saved.':'Error.',!res.ok);
}

loadTagOptions();

// ── COLUMN PREFERENCES ──
const DEFAULT_COLS_COLLECTION = [
  { id:'img',           label:'Image',          on:true },
  { id:'owned',         label:'Owned',          on:true },
  { id:'wishlist',      label:'Wishlist',        on:true },
  { id:'upgrade',       label:'Upgrade',         on:true },
  { id:'title',         label:'Title',           on:true },
  { id:'quality',       label:'Condition',       on:true },
  { id:'completeness',  label:'Completeness',    on:true },
  { id:'played',        label:'Played',          on:true },
  { id:'copies',        label:'Copies',          on:true },
  { id:'price_paid',    label:'Paid',            on:true },
  { id:'buy_range',     label:'Buy Range',       on:true },
  { id:'loose_price',   label:'Loose Price',     on:false },
  { id:'cib_price',     label:'CIB Price',       on:true },
  { id:'new_price',     label:'New Price',       on:false },
  { id:'upgrade_reason',label:'Upgrade Reason',  on:true },
  { id:'tag',           label:'Tag',             on:false },
  { id:'notes',         label:'Notes',           on:true },
];
const DEFAULT_COLS_WISHLIST = [
  { id:'img',           label:'Image',          on:true },
  { id:'owned',         label:'Owned',          on:true },
  { id:'upgrade',       label:'Upgrade',         on:true },
  { id:'system',        label:'System',          on:true },
  { id:'title',         label:'Title',           on:true },
  { id:'upgrade_reason',label:'Upgrade Reason',  on:true },
  { id:'quality',       label:'Condition',       on:true },
  { id:'completeness',  label:'Completeness',    on:true },
  { id:'price_paid',    label:'Paid',            on:true },
  { id:'buy_range',     label:'Buy Range',       on:true },
  { id:'loose_price',   label:'Loose Price',     on:false },
  { id:'cib_price',     label:'CIB Price',       on:true },
  { id:'new_price',     label:'New Price',       on:false },
  { id:'tag',           label:'Tag',             on:false },
  { id:'notes',         label:'Notes',           on:true },
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
  toast(res.ok?'Saved.':'Error.',!res.ok);
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
      <label class="toggle" style="flex-shrink:0" title="Show in collection">
        <input type="checkbox" class="sys-check" data-id="${s.id}" ${s.visible?'checked':''}>
        <span class="toggle-slider"></span>
      </label>
      ${iconHtml}
      <span style="flex:1">
        <span style="font-size:.8rem;color:var(--text)">${s.short_name}</span>
        <span style="font-size:.62rem;color:var(--muted);display:block">${s.name}</span>
      </span>
      <label style="display:flex;align-items:center;gap:5px;font-size:.65rem;color:var(--muted);flex-shrink:0;cursor:pointer" title="Include in global totals (owned %, copies)">
        <input type="checkbox" class="sys-count-check" data-id="${s.id}" ${s.count_for_totals!==false?'checked':''}>
        <span>Count totals</span>
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
  toast(res.ok ? 'Systems saved.' : 'Error saving.', !res.ok);
}

loadSystems();

// ── EXPORT / IMPORT ──
function exportData() {
  toast('Preparing export...');
  window.location.href = `${BASE}/api/export.php`; // server responds with a file download
}

async function importData(e) {
  const file = e.target.files[0]; if (!file) return;
  const text = await file.text();
  let data; try { data = JSON.parse(text); } catch { toast('Invalid JSON file', true); return; }
  if (!confirm('Import will merge data. Existing entries may be overwritten. Continue?')) return;
  toast('Importing...');
  const res = await fetch(`${BASE}/api/import.php`, {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({data})
  }).then(r=>r.json()).catch(e=>({ok:false, error:'server error ('+e.message+')'}));
  toast(res.ok ? 'Import complete' : 'Import failed: '+(res.error||''), !res.ok);
}

// ── IMAGE BACKUPS ──
async function generateBackup(btn) {
  const label = btn.textContent.trim();
  btn.disabled = true;
  btn.textContent = 'Generating…';
  toast('Generating zip for ' + btn.dataset.name + '… this can take a minute');
  try {
    const res = await fetch(`${BASE}/api/backup_generate.php`, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({system_id: Number(btn.dataset.systemId)})
    }).then(r => r.json());
    if (!res.ok) throw new Error(res.error || 'Unknown error');
    let msg = 'Zip ready — ' + res.photo_count + ' photos, ' + (res.file_size/1024/1024).toFixed(1) + ' MB';
    if (res.missing) msg += ' (' + res.missing + ' missing on server)';
    toast(msg);
    setTimeout(() => window.location.reload(), 1500);
  } catch (e) {
    btn.textContent = label;
    btn.disabled = false;
    toast('Error: ' + e.message, true);
  }
}

async function deleteBackup(btn) {
  if (!confirm('Delete this backup zip?')) return;
  const res = await fetch(`${BASE}/api/backup_delete.php`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body:JSON.stringify({token: btn.dataset.token})
  }).then(r => r.json()).catch(() => ({ok:false}));
  if (res.ok) { toast('Backup deleted.'); setTimeout(() => window.location.reload(), 800); }
  else toast('Error deleting backup: ' + (res.error || 'request failed'), true);
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
