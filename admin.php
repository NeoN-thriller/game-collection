<?php
require_once __DIR__ . '/boot.php';
$admin = requireAdmin();

$msg    = '';
$msgErr = false;

// ── ACTIONS ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'gen_invite') {
        $code = bin2hex(random_bytes(16));
        db()->prepare("INSERT INTO invite_codes (code, created_by) VALUES (?,?)")->execute([$code, $admin['id']]);
        $msg = tRaw('admin.msg_invite', ['code' => $code]);
    }

    if ($action === 'user_status') {
        $uid    = (int)$_POST['user_id'];
        $status = in_array($_POST['status'], ['active','inactive','banned']) ? $_POST['status'] : 'inactive';
        if ($uid !== $admin['id']) {
            db()->prepare("UPDATE users SET status=? WHERE id=?")->execute([$status, $uid]);
            $msg = tRaw('admin.msg_user');
        } else { $msg = tRaw('admin.msg_own_status'); $msgErr = true; }
    }

    if ($action === 'reset_password') {
        $uid  = (int)$_POST['user_id'];
        $pass = $_POST['new_password'] ?? '';
        if (strlen($pass) < MIN_PASSWORD_LENGTH) { $msg = tRaw('common.err_password_length', ['n' => MIN_PASSWORD_LENGTH]); $msgErr = true; }
        else {
            setPassword($uid, $pass);
            $st = db()->prepare("SELECT username FROM users WHERE id=?"); $st->execute([$uid]);
            clearFailures(['u:'.strtolower((string)$st->fetchColumn()), 'pw:'.$uid]);
            $msg = tRaw('admin.msg_pw_reset');
        }
    }

    if ($action === 'unlock') {
        $key = (string)($_POST['attempt_key'] ?? '');
        db()->prepare("DELETE FROM login_attempts WHERE attempt_key=?")->execute([$key]);
        $msg = tRaw('admin.msg_unlocked');
    }

    if ($action === 'unlock_all') {
        db()->exec("DELETE FROM login_attempts");
        $msg = tRaw('admin.msg_unlocked_all');
    }

    if ($action === 'add_game') {
        $sysId = (int)$_POST['system_id'];
        $title = trim($_POST['title'] ?? '');
        if ($title && $sysId) {
            $sort = preg_replace('/^(the |a |an )/i', '', strtolower($title));
            // Get next sort_order
            $st = db()->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM games WHERE system_id=?");
            $st->execute([$sysId]); $so = $st->fetchColumn();
            db()->prepare("INSERT INTO games (system_id, title, sort_title, sort_order) VALUES (?,?,?,?)")
                ->execute([$sysId, $title, $sort, $so]);
            $msg = tRaw('admin.msg_game_added');
        }
    }

    if ($action === 'toggle_game') {
        $gid = (int)$_POST['game_id'];
        db()->prepare("UPDATE games SET active = 1-active WHERE id=?")->execute([$gid]);
        $msg = tRaw('admin.msg_game_updated');
    }

    if ($action === 'set_system_icon') {
        $sid = (int)$_POST['system_id'];
        if (!empty($_FILES['icon_image']) && $_FILES['icon_image']['error']===UPLOAD_ERR_OK) {
            $file  = $_FILES['icon_image'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            if (in_array($mime, ALLOWED_TYPES) || $mime === 'image/svg+xml') {
                $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg'];
                $ext    = $extMap[$mime] ?? 'png';
                $iconDir = __DIR__ . '/uploads/icons/';
                if (!is_dir($iconDir)) mkdir($iconDir, 0755, true);
                $fn = 'sys_'.$sid.'_'.uniqid().'.'.$ext;
                move_uploaded_file($file['tmp_name'], $iconDir.$fn);
                db()->prepare("UPDATE systems SET icon_image=? WHERE id=?")->execute([$fn, $sid]);
                $msg = tRaw('admin.msg_icon');
            }
        }
    }

    if ($action === 'set_default_image') {
        $gid = (int)$_POST['game_id'];
        if (!empty($_FILES['default_image']) && $_FILES['default_image']['error']===UPLOAD_ERR_OK) {
            $file  = $_FILES['default_image'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            if (in_array($mime, ALLOWED_TYPES)) {
                $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime];
                $fn   = 'game_'.$gid.'_'.uniqid().'.'.$ext;
                move_uploaded_file($file['tmp_name'], DEFAULTS_DIR.$fn);
                db()->prepare("UPDATE games SET default_image=? WHERE id=?")->execute([$fn, $gid]);
                $msg = tRaw('admin.msg_image');
            }
        }
    }

    if ($action === 'add_system') {
        $name  = trim($_POST['name']       ?? '');
        $short = strtoupper(trim($_POST['short_name'] ?? ''));
        $region = in_array($_POST['region'] ?? '', REGIONS, true) ? $_POST['region'] : setting('default_region');
        if ($name && $short) {
            $st = db()->query("SELECT COALESCE(MAX(sort_order),0)+10 FROM systems"); $so = $st->fetchColumn();
            db()->prepare("INSERT INTO systems (name, short_name, region, sort_order) VALUES (?,?,?,?)")
                ->execute([$name, $short, $region, $so]);
            // Default grading profile by name (e.g. "Nintendo 64" → cartridge in box with inner tray)
            $assigned = applySystemPatterns(null, true, (int)db()->lastInsertId());
            $msg = tRaw('admin.msg_system_added').($assigned ? ' '.tRaw('admin.msg_profile', ['profile' => explode(' → ', $assigned[0])[1]]) : '');
        }
    }

    if ($action === 'set_system_region') {
        $region = (string)($_POST['region'] ?? '');
        if (in_array($region, REGIONS, true)) {
            db()->prepare("UPDATE systems SET region=? WHERE id=?")->execute([$region, (int)$_POST['system_id']]);
            $msg = tRaw('admin.msg_region');
        } else { $msg = tRaw('common.error'); $msgErr = true; }
    }

    if ($action === 'save_site_settings') {
        $in  = fn(string $k) => trim((string)($_POST[$k] ?? ''));
        $raw = fn(string $k) => (string)($_POST[$k] ?? '');   // separators may be a space
        $errs = [];
        $name = $in('site_name');
        if ($name === '' || mb_strlen($name) > 60) $errs[] = tRaw('admin.site.err_name');
        $sym = $in('currency_symbol');
        if ($sym === '' || mb_strlen($sym) > 5) $errs[] = tRaw('admin.site.err_symbol');
        $dec = $raw('decimal_sep'); $thou = $raw('thousands_sep');
        if (!in_array($dec, DECIMAL_SEPS, true) || !in_array($thou, THOUSANDS_SEPS, true) || $dec === $thou) $errs[] = tRaw('admin.site.err_seps');
        $colors = json_decode($raw('site_name_colors'), true);
        $colors = is_array($colors) ? array_values(array_map(fn($c) => in_array($c, LOGO_COLORS, true) ? $c : 'header-logo', array_slice($colors, 0, 20))) : [];
        $pick = fn(string $k, array $allowed) => in_array($in($k), $allowed, true) ? $in($k) : SITE_DEFAULTS[$k];
        // One option per line, trimmed, no duplicates, max 30 options of 100 characters
        $lines = fn(string $k) => implode("\n", array_slice(array_values(array_unique(array_filter(
                     array_map(fn($s) => mb_substr(trim($s), 0, 100), preg_split('/\R/', $raw($k))), 'strlen'))), 0, 30));
        if ($errs) { $msg = implode(' ', $errs); $msgErr = true; }
        else {
            foreach ([
                'site_name'         => $name,
                'site_name_style'   => $pick('site_name_style', ['single', 'last', 'custom']),
                'site_name_colors'  => json_encode($colors),
                'currency_symbol'   => $sym,
                'currency_position' => $pick('currency_position', ['before', 'after']),
                'currency_space'    => $in('currency_space') === '1' ? '1' : '0',
                'decimal_sep'       => $dec,
                'thousands_sep'     => $thou,
                'default_region'    => $pick('default_region', REGIONS),
                'date_format'       => $pick('date_format', DATE_FORMATS),
                'default_language'  => $pick('default_language', array_keys(availableLanguages())),
                'default_grading'   => $pick('default_grading', ['simple', 'points', 'both']),
                'default_value_type'=> $pick('default_value_type', VALUE_TYPES),
                'timezone'          => $pick('timezone', timezone_identifiers_list()),
                'defaults_completeness' => $lines('defaults_completeness'),
                'defaults_played'   => $lines('defaults_played'),
            ] as $k => $v) setSetting($k, $v);
            $msg = tRaw('admin.site.saved');
        }
    }

    if ($action === 'upload_language') {
        $f = $_FILES['lang_file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) { $msg = tRaw('admin.lang.err_upload'); $msgErr = true; }
        else {
            [$data, $err] = validateLangFile((string)file_get_contents($f['tmp_name']));
            if ($err) { $msg = $err; $msgErr = true; }
            elseif (!is_dir(LANG_DIR) || @file_put_contents(LANG_DIR.$data['_meta']['code'].'.json',
                        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n") === false) {
                $msg = tRaw('admin.lang.not_writable'); $msgErr = true;
            } else {
                $msg = tRaw('admin.lang.uploaded', ['name' => $data['_meta']['name'], 'n' => fmtNum(count($data) - 1)]);
            }
        }
    }

    if ($action === 'set_default_theme') {
        $theme = (string)($_POST['theme'] ?? '');
        if (isset(availableThemes()[$theme])) {
            setAppSetting('default_theme', $theme);
            $msg = tRaw('admin.msg_theme', ['name' => availableThemes()[$theme]['name']]);
        } else { $msg = tRaw('settings.err_unknown_theme'); $msgErr = true; }
    }

    if ($action === 'delete_invite') {
        $id = (int)$_POST['invite_id'];
        db()->prepare("DELETE FROM invite_codes WHERE id=? AND used_by IS NULL")->execute([$id]);
        $msg = tRaw('admin.msg_invite_deleted');
    }

    // Forms submitted via the AJAX handler below get a JSON result instead of the page
    if (!empty($_SERVER['HTTP_X_ADMIN_AJAX'])) jsonOut(['ok'=>!$msgErr, 'msg'=>$msg ?: tRaw('common.saved')]);
}

// ── LOAD DATA ────────────────────────────
$users   = db()->query("SELECT * FROM users ORDER BY created_at")->fetchAll();
$invites = db()->query("
    SELECT ic.*, u1.username AS creator, u2.username AS used_by_name
    FROM invite_codes ic
    JOIN users u1 ON u1.id = ic.created_by
    LEFT JOIN users u2 ON u2.id = ic.used_by
    ORDER BY ic.created_at DESC
    LIMIT 50
")->fetchAll();
$systems = db()->query("SELECT * FROM systems ORDER BY sort_order")->fetchAll();

// Games per system (paginated by system selection)
$viewSys = (int)($_GET['sys'] ?? ($systems[0]['id'] ?? 0));
$gamesSt = db()->prepare("SELECT * FROM games WHERE system_id=? ORDER BY sort_title");
$gamesSt->execute([$viewSys]);
$games = $gamesSt->fetchAll();
$lockouts = db()->query("SELECT *, locked_until > NOW() AS is_locked FROM login_attempts
                         ORDER BY is_locked DESC, last_fail_at DESC LIMIT 100")->fetchAll();
try {
    // Users on each theme; no (or an uninstalled) choice means they're on the site default
    $themeUse = [];
    foreach (db()->query("SELECT theme, COUNT(*) FROM users GROUP BY theme")->fetchAll(PDO::FETCH_KEY_PAIR) as $slug => $n) {
        $slug = isset(availableThemes()[(string)$slug]) ? (string)$slug : siteTheme();
        $themeUse[$slug] = ($themeUse[$slug] ?? 0) + (int)$n;
    }
    $themeMigrated = true;
} catch (PDOException) { $themeUse = []; $themeMigrated = false; }
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pageTitle(tRaw('common.nav.admin')) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<?= themeHead($admin) ?>
<?= csrfScript() ?>
<?= appScript(['admin', 'grading', 'ga', 'pc', 'import']) ?>
<style>
  .site-form { display:flex; flex-direction:column; gap:14px; }
  .sf-group { background:var(--surface2); border:1px solid var(--border); border-radius:var(--radius); padding:14px 16px; display:flex; flex-direction:column; gap:10px; }
  .sf-head { font-size:.58rem; letter-spacing:.2em; text-transform:uppercase; color:var(--muted); }
  .sf-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
  .sf-preview { font-size:.72rem; color:var(--muted); }
  .sf-preview b { color:var(--accent); font-weight:400; font-family:var(--font-display); font-size:1.1rem; letter-spacing:.04em; }
  .sf-preview-bar { padding:12px 16px; border-radius:var(--radius); }
  .lang-missing summary { cursor:pointer; list-style:none; }
  .lang-missing ul { margin:6px 0 0 16px; font-size:.66rem; color:var(--muted); line-height:1.7; max-height:220px; overflow:auto; }
</style>
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/dashboard.php" class="site-logo" style="text-decoration:none"><?= siteLogoHtml('<span style="font-size:1rem;color:var(--header-logo);opacity:.75;letter-spacing:.1em">'.t('admin.badge').'</span>') ?></a>
  <nav class="site-nav">
    <a href="<?= BASE_URL ?>/pc_import.php" class="nav-link"><?= t('common.nav.pc_import') ?></a>
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link"><?= t('common.nav.dashboard') ?></a>
    <a href="<?= BASE_URL ?>/collection.php" class="nav-link"><?= t('common.nav.collection') ?></a>
    <a href="<?= BASE_URL ?>/wishlist.php" class="nav-link"><?= t('common.nav.wishlist') ?></a>
    <a href="<?= BASE_URL ?>/settings.php" class="nav-link"><?= t('common.nav.settings') ?></a>
    <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link"><?= t('common.nav.sign_out') ?></a>
  </nav>
</header>

<div class="admin-wrap">

<?php if ($msg): ?>
<div style="background:color-mix(in srgb,var(--green) 10%,transparent);border:1px solid color-mix(in srgb,var(--green) 30%,transparent);color:var(--green);padding:10px 16px;margin-bottom:20px;font-size:.8rem;">
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- ── SITE SETTINGS ── -->
<div class="admin-section">
  <h2><?= t('admin.site.title') ?></h2>
  <p class="ga-desc"><?= t('admin.site.desc') ?></p>
  <form method="POST" id="site-form" class="site-form">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="save_site_settings">
    <input type="hidden" name="site_name_colors" id="sf-colors" value="<?= htmlspecialchars(setting('site_name_colors')) ?>">

    <div class="sf-group">
      <div class="sf-head"><?= t('admin.site.name_head') ?></div>
      <div class="sf-row">
        <div class="field" style="flex:1;min-width:220px"><label><?= t('admin.site.name') ?></label>
          <input type="text" name="site_name" id="sf-name" maxlength="60" value="<?= htmlspecialchars(siteName()) ?>" oninput="sfLogo()"></div>
        <div class="field" style="width:230px"><label><?= t('admin.site.name_style') ?></label>
          <select name="site_name_style" id="sf-style" onchange="sfLogo()">
            <?php foreach (['single', 'last', 'custom'] as $st): ?>
            <option value="<?= $st ?>" <?= setting('site_name_style') === $st ? 'selected' : '' ?>><?= t('admin.site.style_'.$st) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div id="sf-words" class="sf-row" style="display:none"></div>
      <div class="sf-preview-bar site-header"><span class="site-logo" id="sf-logo"></span></div>
    </div>

    <div class="sf-group">
      <div class="sf-head"><?= t('admin.site.money_head') ?></div>
      <div class="sf-row">
        <div class="field" style="width:110px"><label><?= t('admin.site.symbol') ?></label>
          <input type="text" name="currency_symbol" id="sf-sym" maxlength="5" value="<?= htmlspecialchars(setting('currency_symbol')) ?>" oninput="sfMoney()"></div>
        <div class="field" style="width:150px"><label><?= t('admin.site.position') ?></label>
          <select name="currency_position" id="sf-pos" onchange="sfMoney()">
            <option value="before" <?= setting('currency_position') === 'before' ? 'selected' : '' ?>><?= t('admin.site.pos_before') ?></option>
            <option value="after"  <?= setting('currency_position') === 'after'  ? 'selected' : '' ?>><?= t('admin.site.pos_after') ?></option>
          </select></div>
        <div class="field" style="width:130px"><label><?= t('admin.site.space') ?></label>
          <select name="currency_space" id="sf-space" onchange="sfMoney()">
            <option value="0" <?= setting('currency_space') !== '1' ? 'selected' : '' ?>><?= t('admin.site.no') ?></option>
            <option value="1" <?= setting('currency_space') === '1' ? 'selected' : '' ?>><?= t('admin.site.yes') ?></option>
          </select></div>
        <div class="field" style="width:150px"><label><?= t('admin.site.decimal') ?></label>
          <select name="decimal_sep" id="sf-dec" onchange="sfMoney()">
            <?php foreach (DECIMAL_SEPS as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= setting('decimal_sep') === $s ? 'selected' : '' ?>><?= t('admin.site.sep_'.['.' => 'dot', ',' => 'comma'][$s]) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" style="width:170px"><label><?= t('admin.site.thousands') ?></label>
          <select name="thousands_sep" id="sf-thou" onchange="sfMoney()">
            <?php foreach (THOUSANDS_SEPS as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= setting('thousands_sep') === $s ? 'selected' : '' ?>><?= t('admin.site.sep_'.['.' => 'dot', ',' => 'comma', ' ' => 'space', "'" => 'apos', '' => 'none'][$s]) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="sf-preview"><?= t('admin.site.preview') ?>: <b id="sf-money-preview"></b></div>
      <p class="ga-desc" style="margin:8px 0 0"><?= t('common.currency_note', ['symbol' => setting('currency_symbol')]) ?></p>
    </div>

    <div class="sf-group">
      <div class="sf-head"><?= t('admin.site.region_head') ?></div>
      <div class="sf-row">
        <div class="field" style="width:170px"><label><?= t('admin.site.region') ?></label>
          <select name="default_region">
            <?php foreach (REGIONS as $r): ?><option value="<?= $r ?>" <?= setting('default_region') === $r ? 'selected' : '' ?>><?= $r === 'Mixed' ? t('admin.site.region_mixed') : $r ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" style="width:200px"><label><?= t('admin.site.date') ?></label>
          <select name="date_format" id="sf-date" onchange="sfDate()">
            <?php foreach (DATE_FORMATS as $f): ?><option value="<?= $f ?>" <?= setting('date_format') === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" style="width:200px"><label><?= t('admin.site.language') ?></label>
          <select name="default_language">
            <?php foreach (availableLanguages() as $l): ?><option value="<?= htmlspecialchars($l['code']) ?>" <?= siteLanguage() === $l['code'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" style="width:240px"><label><?= t('admin.site.timezone') ?></label>
          <select name="timezone">
            <option value=""><?= t('admin.site.tz_server', ['tz' => ini_get('date.timezone') ?: 'UTC']) ?></option>
            <?php foreach (timezone_identifiers_list() as $tz): ?><option value="<?= htmlspecialchars($tz) ?>" <?= setting('timezone') === $tz ? 'selected' : '' ?>><?= htmlspecialchars($tz) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="sf-preview"><?= t('admin.site.preview') ?>: <b id="sf-date-preview"></b></div>
      <p class="ga-desc" style="margin:8px 0 0"><?= t('admin.site.region_note') ?></p>
    </div>

    <div class="sf-group">
      <div class="sf-head"><?= t('admin.site.new_users_head') ?></div>
      <div class="sf-row">
        <div class="field" style="width:220px"><label><?= t('admin.site.grading') ?></label>
          <select name="default_grading">
            <?php foreach (['simple', 'points', 'both'] as $g): ?><option value="<?= $g ?>" <?= setting('default_grading') === $g ? 'selected' : '' ?>><?= t('grading.mode_'.$g) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" style="width:220px"><label><?= t('admin.site.value_type') ?></label>
          <select name="default_value_type">
            <?php foreach (VALUE_TYPES as $v): ?><option value="<?= $v ?>" <?= setting('default_value_type') === $v ? 'selected' : '' ?>><?= t('common.price.'.$v) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="sf-row">
        <div class="field" style="flex:1;min-width:220px"><label><?= t('settings.comp') ?></label>
          <textarea name="defaults_completeness" rows="9"><?= htmlspecialchars(implode("\n", defaultCompletenessOptions())) ?></textarea></div>
        <div class="field" style="flex:1;min-width:220px"><label><?= t('settings.played') ?></label>
          <textarea name="defaults_played" rows="9"><?= htmlspecialchars(implode("\n", defaultPlayedOptions())) ?></textarea></div>
      </div>
      <p class="ga-desc" style="margin:0"><?= t('admin.site.new_users_note') ?></p>
    </div>

    <div style="display:flex;justify-content:flex-end"><button class="btn btn-sm" type="submit"><?= t('admin.site.save') ?></button></div>
  </form>
</div>

<!-- ── LANGUAGES ── -->
<div class="admin-section">
  <h2><?= t('admin.lang.title') ?></h2>
  <p class="ga-desc"><?= t('admin.lang.desc') ?></p>
  <?php if (!is_writable(LANG_DIR)): ?>
  <div class="ga-box ga-warn" style="margin:0 0 14px"><?= t('admin.lang.not_writable') ?></div>
  <?php endif; ?>
  <table class="admin-table" style="margin-bottom:14px">
    <thead><tr><th><?= t('admin.lang.col_name') ?></th><th><?= t('admin.lang.col_code') ?></th><th><?= t('admin.lang.col_texts') ?></th><th><?= t('admin.lang.col_missing') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach (availableLanguages() as $l): $missing = missingLangKeys($l['code']); ?>
    <tr>
      <td><?= htmlspecialchars($l['name']) ?><?php if ($l['code'] === siteLanguage()): ?> <span class="tag tag-admin"><?= t('admin.lang.site_default') ?></span><?php endif; ?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= htmlspecialchars($l['code']) ?>.json</td>
      <td><?= fmtNum($l['keys']) ?></td>
      <td>
        <?php if ($l['code'] === LANG_FALLBACK): ?><span style="color:var(--muted);font-size:.7rem"><?= t('admin.lang.base') ?></span>
        <?php elseif (!$missing): ?><span class="tag tag-active"><?= t('admin.lang.complete') ?></span>
        <?php else: ?>
        <details class="lang-missing"><summary><span class="tag tag-banned"><?= tn('admin.lang.n_missing', count($missing)) ?></span></summary>
          <ul><?php foreach (array_slice($missing, 0, 300) as $k): ?><li><code><?= htmlspecialchars($k) ?></code></li><?php endforeach; ?></ul>
          <?php if (count($missing) > 300): ?><p style="font-size:.66rem;color:var(--muted)">…</p><?php endif; ?>
        </details>
        <?php endif; ?>
      </td>
      <td><a class="btn-icon" style="text-decoration:none" href="<?= BASE_URL ?>/api/lang_download.php?code=<?= urlencode($l['code']) ?>">⬇ <?= t('common.download') ?></a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="upload_language">
    <input type="file" name="lang_file" accept=".json,application/json" required style="font-size:.7rem">
    <button class="btn btn-sm" type="submit">⬆ <?= t('admin.lang.upload') ?></button>
  </form>
  <p class="ga-desc" style="margin:10px 0 0"><?= t('admin.lang.upload_note') ?></p>
</div>

<!-- ── THEMES ── -->
<div class="admin-section">
  <h2><?= t('admin.theme.title') ?></h2>
  <p style="font-size:.74rem;color:var(--muted);margin-bottom:14px;line-height:1.7">
    <?= t('admin.theme.desc') ?>
  </p>
  <?php if (!$themeMigrated): ?>
  <div class="ga-box ga-warn" style="margin:0 0 14px"><?= t('admin.theme.migrate') ?></div>
  <?php endif; ?>
  <div class="theme-grid" role="radiogroup" aria-label="<?= t('admin.theme.title') ?>">
    <?php foreach (availableThemes() as $t) {
        $n = (int)($themeUse[$t['slug']] ?? 0);
        echo themeCardHtml($t, 'site_theme', siteTheme() === $t['slug'], 'setSiteTheme', [
            $t['slug'].'.css',
            tRaw('admin.theme.fonts', ['fonts' => $t['font_names'] ? implode(', ', $t['font_names']) : tRaw('admin.theme.browser_fonts')]),
            tnRaw('admin.theme.used_by', $n),
        ]);
    } ?>
  </div>
</div>

<!-- ── IMAGE SETTINGS ── -->
<div class="admin-section">
  <h2><?= t('admin.img.title') ?></h2>
  <p style="font-size:.74rem;color:var(--muted);margin-bottom:14px"><?= t('admin.img.desc') ?></p>
  <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
    <div class="field" style="width:160px"><label><?= t('admin.img.max_w') ?></label><input type="number" id="img-max-w" min="200" max="4000" value="1200" step="100"></div>
    <div class="field" style="width:160px"><label><?= t('admin.img.max_h') ?></label><input type="number" id="img-max-h" min="200" max="4000" value="1200" step="100"></div>
    <div class="field" style="width:140px"><label><?= t('admin.img.quality') ?></label><input type="number" id="img-qual" min="10" max="100" value="80"></div>
    <button class="btn btn-sm" onclick="saveImgSettings()"><?= t('admin.img.save') ?></button>
  </div>
  <div id="img-settings-msg" style="font-size:.75rem;color:var(--green);margin-top:10px;display:none"><?= t('common.saved') ?></div>
</div>

<!-- ── CONDITION GRADING (assets/js/grading-admin.js) ── -->
<div class="admin-section">
  <h2><?= t('admin.ga.labels') ?></h2>
  <div id="ga-labels"><p class="ga-desc"><?= t('admin.loading') ?></p></div>
</div>

<div class="admin-section">
  <h2><?= t('admin.ga.profiles') ?></h2>
  <p class="ga-desc"><?= t('admin.ga.profiles_desc') ?></p>
  <div id="ga-profiles"></div>
</div>

<div class="admin-section">
  <h2><?= t('admin.ga.templates') ?></h2>
  <p class="ga-desc"><?= t('admin.ga.templates_desc') ?></p>
  <div id="ga-templates"></div>
</div>

<div class="admin-section">
  <h2><?= t('admin.ga.io') ?></h2>
  <div id="ga-io"></div>
</div>

<!-- ── INVITE CODES ── -->
<div class="admin-section">
  <h2><?= t('admin.inv.title') ?></h2>
  <form method="POST" style="display:flex;gap:8px;margin-bottom:16px">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="gen_invite">
    <button class="btn btn-sm" type="submit"><?= t('admin.inv.generate') ?></button>
  </form>
  <table class="admin-table">
    <thead><tr><th><?= t('admin.inv.code') ?></th><th><?= t('admin.inv.created_by') ?></th><th><?= t('admin.inv.created') ?></th><th><?= t('admin.inv.used_by') ?></th><th><?= t('admin.inv.used_at') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($invites as $inv): ?>
    <tr>
      <td><?php if (!$inv['used_by']): ?><span class="invite-code"><?= htmlspecialchars($inv['code']) ?></span><?php else: ?><span style="color:var(--muted);text-decoration:line-through;font-size:.72rem"><?= htmlspecialchars($inv['code']) ?></span><?php endif; ?></td>
      <td><?= htmlspecialchars($inv['creator']) ?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= fmtDate($inv['created_at'], true) ?></td>
      <td><?= $inv['used_by_name'] ? htmlspecialchars($inv['used_by_name']) : '<span style="color:var(--muted)">—</span>' ?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= $inv['used_at'] ? fmtDate($inv['used_at'], true) : '—' ?></td>
      <td><?php if (!$inv['used_by']): ?>
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf"      value="<?= csrf() ?>">
          <input type="hidden" name="action"    value="delete_invite">
          <input type="hidden" name="invite_id" value="<?= $inv['id'] ?>">
          <button class="btn-danger" type="submit"><?= t('common.delete') ?></button>
        </form>
      <?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── PRICECHARTING IMPORT ── -->
<div class="admin-section">
  <h2><?= t('common.nav.pc_import') ?></h2>
  <p style="font-size:.74rem;color:var(--muted);margin-bottom:14px;line-height:1.7">
    <?= t('admin.pc.desc') ?><br>
    <span style="color:var(--orange)"><?= t('common.currency_note', ['symbol' => setting('currency_symbol')]) ?></span>
  </p>
  <textarea id="pc-csv" style="width:100%;height:130px;background:var(--surface);border:1px solid var(--border2);color:var(--text);font-family:var(--font-body);font-size:.7rem;padding:10px;resize:vertical;outline:none" placeholder="<?= t('pc.placeholder') ?>
console,name,data-product,link,loose,cib,new,coverArt,coverArtBase64
WiiU,007 Legends,63286,https://...,17.51,24.86,40.83,63286.jpg,data:image/jpeg;base64,..."></textarea>
  <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
    <button class="btn btn-sm" onclick="pcPreview()"><?= t('import.preview') ?> →</button>
    <a href="<?= BASE_URL ?>/pc_import.php" class="btn-outline" style="padding:8px 14px;font-size:.72rem"><?= t('admin.pc.full_page') ?> ↗</a>
  </div>
  <div id="pc-result" style="display:none;margin-top:14px;background:var(--surface2);border:1px solid var(--border2);padding:12px 16px;font-size:.75rem;line-height:1.9"></div>
</div>

<!-- ── USERS ── -->
<div class="admin-section">
  <h2><?= t('admin.users.title') ?></h2>
  <table class="admin-table">
    <thead><tr><th><?= t('auth.username') ?></th><th><?= t('admin.users.role') ?></th><th><?= t('import.col_status') ?></th><th><?= t('admin.users.last_login') ?></th><th><?= t('settings.actions') ?></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><?= htmlspecialchars($u['username']) ?></td>
      <td><span class="tag tag-<?= $u['role']==='admin'?'admin':'active' ?>"><?= t('admin.users.role_'.$u['role']) ?></span></td>
      <td><span class="tag tag-<?= $u['status'] ?>"><?= t('admin.users.status_'.$u['status']) ?></span></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= $u['last_login'] ? fmtDate($u['last_login'], true) : t('admin.users.never') ?></td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if ($u['id'] !== $admin['id']): ?>
        <!-- Status change -->
        <form method="POST" style="display:flex;gap:4px">
          <input type="hidden" name="csrf"    value="<?= csrf() ?>">
          <input type="hidden" name="action"  value="user_status">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
          <select name="status" style="font-size:.68rem;padding:3px 6px">
            <option value="active"   <?= $u['status']==='active'  ?'selected':'' ?>><?= t('admin.users.status_active') ?></option>
            <option value="inactive" <?= $u['status']==='inactive'?'selected':'' ?>><?= t('admin.users.status_inactive') ?></option>
            <option value="banned"   <?= $u['status']==='banned'  ?'selected':'' ?>><?= t('admin.users.status_banned') ?></option>
          </select>
          <button class="btn-icon" type="submit"><?= t('admin.users.set') ?></button>
        </form>
        <!-- Reset password -->
        <form method="POST" style="display:flex;gap:4px" onsubmit="return confirmReset()">
          <input type="hidden" name="csrf"    value="<?= csrf() ?>">
          <input type="hidden" name="action"  value="reset_password">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
          <input type="text" name="new_password" placeholder="<?= t('settings.new_pw') ?>" minlength="<?= MIN_PASSWORD_LENGTH ?>" style="font-size:.68rem;padding:3px 8px;width:130px">
          <button class="btn-icon" type="submit"><?= t('admin.users.reset_pw') ?></button>
        </form>
        <?php else: ?><span style="color:var(--muted);font-size:.7rem"><?= t('admin.users.you') ?></span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── LOGIN LOCKOUTS ── -->
<div class="admin-section">
  <h2><?= t('admin.lock.title') ?></h2>
  <p style="font-size:.74rem;color:var(--muted);margin-bottom:14px"><?= t('admin.lock.desc') ?></p>
  <?php if (!$lockouts): ?>
    <p style="font-size:.75rem;color:var(--muted)"><?= t('admin.lock.none') ?></p>
  <?php else: ?>
  <form method="POST" style="margin-bottom:12px">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="unlock_all">
    <button class="btn-icon" type="submit"><?= t('admin.lock.clear_all') ?></button>
  </form>
  <table class="admin-table">
    <thead><tr><th><?= t('admin.lock.key') ?></th><th><?= t('admin.lock.failures') ?></th><th><?= t('admin.lock.last') ?></th><th><?= t('admin.lock.until') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lockouts as $l): ?>
    <tr>
      <td><?= htmlspecialchars($l['attempt_key']) ?></td>
      <td><?= (int)$l['fail_count'] ?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= $l['last_fail_at'] ? fmtDate($l['last_fail_at'], true) : '—' ?></td>
      <td><?php if ($l['is_locked']): ?><span class="tag tag-banned"><?= fmtDate($l['locked_until'], true) ?></span><?php else: ?><span style="color:var(--muted);font-size:.7rem"><?= t('admin.lock.not_locked') ?></span><?php endif; ?></td>
      <td>
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf"        value="<?= csrf() ?>">
          <input type="hidden" name="action"      value="unlock">
          <input type="hidden" name="attempt_key" value="<?= htmlspecialchars($l['attempt_key']) ?>">
          <button class="btn-icon" type="submit"><?= t($l['is_locked'] ? 'admin.lock.unlock' : 'admin.lock.reset') ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ── SYSTEMS ── -->
<div class="admin-section">
  <h2><?= t('dashboard.systems') ?></h2>
  <form method="POST" style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="add_system">
    <input type="text" name="name"       placeholder="<?= t('admin.sys.name_ph') ?>" style="width:260px">
    <input type="text" name="short_name" placeholder="<?= t('admin.sys.short_ph') ?>" style="width:100px">
    <select name="region" style="width:auto" title="<?= t('admin.site.region') ?>">
      <?php foreach (REGIONS as $r): ?><option value="<?= $r ?>" <?= setting('default_region') === $r ? 'selected' : '' ?>><?= $r === 'Mixed' ? t('admin.site.region_mixed') : $r ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit"><?= t('admin.sys.add') ?></button>
  </form>
  <table class="admin-table">
    <thead><tr><th>#</th><th><?= t('admin.sys.icon') ?></th><th><?= t('admin.sys.name') ?></th><th><?= t('admin.sys.short') ?></th><th><?= t('admin.site.region') ?></th><th><?= t('admin.sys.active') ?></th><th><?= t('admin.sys.set_icon') ?></th></tr></thead>
    <tbody>
    <?php foreach ($systems as $s): ?>
    <tr>
      <td style="color:var(--muted);font-size:.7rem"><?= $s['sort_order'] ?></td>
      <td><?php if (!empty($s['icon_image'])): ?>
        <img src="<?= BASE_URL ?>/uploads/icons/<?= htmlspecialchars($s['icon_image']) ?>" style="width:28px;height:28px;object-fit:contain">
      <?php else: ?><span style="color:var(--muted);font-size:.7rem">—</span><?php endif; ?></td>
      <td><?= htmlspecialchars($s['name']) ?></td>
      <td style="color:var(--wiiu)"><?= htmlspecialchars($s['short_name']) ?></td>
      <td>
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf"      value="<?= csrf() ?>">
          <input type="hidden" name="action"    value="set_system_region">
          <input type="hidden" name="system_id" value="<?= $s['id'] ?>">
          <select name="region" onchange="this.form.requestSubmit()" style="font-size:.68rem;padding:3px 6px;width:auto">
            <?php foreach (REGIONS as $r): ?><option value="<?= $r ?>" <?= $s['region'] === $r ? 'selected' : '' ?>><?= $r === 'Mixed' ? t('admin.site.region_mixed') : $r ?></option><?php endforeach; ?>
          </select>
        </form>
      </td>
      <td><span class="tag <?= $s['active']?'tag-active':'tag-inactive' ?>"><?= t($s['active'] ? 'admin.site.yes' : 'admin.site.no') ?></span></td>
      <td>
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:4px">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="set_system_icon">
          <input type="hidden" name="system_id" value="<?= $s['id'] ?>">
          <input type="file" name="icon_image" accept="image/*" style="font-size:.65rem;width:140px">
          <button class="btn-icon" type="submit"><?= t('admin.sys.set_icon') ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── GAME LIST MANAGEMENT ── -->
<div class="admin-section">
  <h2><?= t('admin.games.title') ?></h2>

  <!-- System tabs -->
  <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:16px;border-bottom:1px solid var(--border2);padding-bottom:0">
    <?php foreach ($systems as $s): ?>
    <a href="?sys=<?= $s['id'] ?>" style="padding:8px 14px;font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;color:<?= $s['id']==$viewSys?'var(--accent2)':'var(--muted)' ?>;border-bottom:2px solid <?= $s['id']==$viewSys?'var(--accent2)':'transparent' ?>;white-space:nowrap;margin-bottom:-1px"><?= htmlspecialchars($s['short_name']) ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Add game -->
  <form method="POST" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
    <input type="hidden" name="csrf"      value="<?= csrf() ?>">
    <input type="hidden" name="action"    value="add_game">
    <input type="hidden" name="system_id" value="<?= $viewSys ?>">
    <input type="text" name="title" placeholder="<?= t('admin.games.title_ph') ?>" style="flex:1;min-width:200px">
    <button class="btn btn-sm" type="submit"><?= t('admin.games.add') ?></button>
  </form>

  <!-- Filter -->
  <div class="search-wrap" style="margin-bottom:12px;max-width:340px">
    <span class="search-icon">⌕</span>
    <input type="text" id="game-filter" placeholder="<?= t('admin.games.filter') ?>" oninput="filterGames(this.value)">
  </div>

  <table class="admin-table" id="games-admin-table">
    <thead><tr><th>#</th><th><?= t('common.col.title') ?></th><th><?= t('admin.games.default_image') ?></th><th><?= t('admin.sys.active') ?></th><th><?= t('settings.actions') ?></th></tr></thead>
    <tbody>
    <?php foreach ($games as $g): ?>
    <tr style="<?= !$g['active']?'opacity:.45':'' ?>">
      <td style="color:var(--muted);font-size:.7rem"><?= $g['sort_order'] ?></td>
      <td><?= htmlspecialchars($g['title']) ?></td>
      <td>
        <?php if ($g['default_image']): ?>
          <img src="<?= BASE_URL ?>/uploads/defaults/<?= htmlspecialchars($g['default_image']) ?>" style="height:32px;border:1px solid var(--border2)">
        <?php else: ?>
          <span style="color:var(--muted);font-size:.7rem">—</span>
        <?php endif; ?>
      </td>
      <td><span class="tag <?= $g['active']?'tag-active':'tag-inactive' ?>"><?= t($g['active'] ? 'admin.site.yes' : 'admin.site.no') ?></span></td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <!-- Toggle active -->
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf"    value="<?= csrf() ?>">
          <input type="hidden" name="action"  value="toggle_game">
          <input type="hidden" name="game_id" value="<?= $g['id'] ?>">
          <button class="btn-icon" type="submit"><?= t($g['active'] ? 'admin.games.disable' : 'admin.games.enable') ?></button>
        </form>
        <!-- Set default image -->
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:4px">
          <input type="hidden" name="csrf"    value="<?= csrf() ?>">
          <input type="hidden" name="action"  value="set_default_image">
          <input type="hidden" name="game_id" value="<?= $g['id'] ?>">
          <input type="file" name="default_image" accept="image/*" style="font-size:.65rem;width:160px">
          <button class="btn-icon" type="submit"><?= t('admin.games.set_image') ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

</div><!-- /admin-wrap -->

<script>
function confirmReset() { return confirm(tRaw('admin.users.confirm_reset')); }

// ── IMAGE SETTINGS ──
async function loadImgSettings() {
  const res = await fetch(ADMIN_BASE+'/api/image_settings.php').then(r=>r.json());
  if (res.ok) {
    document.getElementById('img-max-w').value = res.settings.max_width;
    document.getElementById('img-max-h').value = res.settings.max_height;
    document.getElementById('img-qual').value  = res.settings.quality;
  }
}
async function saveImgSettings() {
  const res = await fetch(ADMIN_BASE+'/api/image_settings.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({max_width:+document.getElementById('img-max-w').value,max_height:+document.getElementById('img-max-h').value,quality:+document.getElementById('img-qual').value})
  }).then(r=>r.json());
  const msg = document.getElementById('img-settings-msg');
  msg.style.display='block'; msg.textContent=res.ok?tRaw('common.saved'):tRaw('common.err_saving');
  msg.style.color=res.ok?'var(--green)':'var(--red)';
  setTimeout(()=>msg.style.display='none',2000);
}

// ── PRICECHARTING QUICK IMPORT ──
function filterGames(q) {
  q = q.toLowerCase();
  document.querySelectorAll('#games-admin-table tbody tr').forEach(tr => {
    const title = tr.querySelector('td:nth-child(2)')?.textContent.toLowerCase() || '';
    tr.style.display = !q || title.includes(q) ? '' : 'none';
  });
}

// ── ADMIN TOAST HELPER ──
function adminToast(msg, ok=true) {
  let t = document.getElementById('admin-toast');
  if (!t) { t=document.createElement('div'); t.id='admin-toast'; t.className='toast'; document.body.appendChild(t); }
  t.textContent = msg;
  t.style.borderColor = ok ? 'var(--accent2)' : 'var(--red)';
  t.style.color       = ok ? 'var(--accent)'  : 'var(--red)';
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'), 2500);
}

// ── SITE SETTINGS (live previews) ──
const LOGO_COLORS = <?= json_encode(array_combine(LOGO_COLORS, array_map(fn($c) => tRaw('admin.site.color_'.str_replace('-', '_', $c)), LOGO_COLORS)), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
let sfColors = (() => { try { return JSON.parse(document.getElementById('sf-colors').value) || []; } catch { return []; } })();

function sfLogo() {
  const words = document.getElementById('sf-name').value.trim().split(/\s+/).filter(Boolean);
  const style = document.getElementById('sf-style').value;
  const wrap  = document.getElementById('sf-words');
  const esc   = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  // One colour picker per word (style "custom")
  wrap.style.display = style === 'custom' ? 'flex' : 'none';
  if (style === 'custom') {
    wrap.innerHTML = words.map((w, i) => `<div class="field" style="width:170px"><label>${esc(w)}</label><select data-i="${i}" onchange="sfColors[this.dataset.i]=this.value;sfLogo()">` +
      Object.entries(LOGO_COLORS).map(([k, lbl]) => `<option value="${k}" ${(sfColors[i] || 'header-logo') === k ? 'selected' : ''}>${esc(lbl)}</option>`).join('') +
      '</select></div>').join('');
  }
  document.getElementById('sf-colors').value = JSON.stringify(words.map((_, i) => sfColors[i] || 'header-logo'));
  document.getElementById('sf-logo').innerHTML = words.map((w, i) => {
    const c = style === 'custom' ? (sfColors[i] || 'header-logo') : (style === 'last' && words.length > 1 && i === words.length - 1 ? 'header-logo2' : '');
    return c ? `<span style="color:var(--${c})">${esc(w)}</span>` : esc(w);
  }).join(' ');
}

function sfMoney() {
  const f = { sym: document.getElementById('sf-sym').value, after: document.getElementById('sf-pos').value === 'after',
              space: document.getElementById('sf-space').value === '1', dec: document.getElementById('sf-dec').value, thou: document.getElementById('sf-thou').value };
  const [i, d] = (1234.56).toFixed(2).split('.');
  const n = i.replace(/\B(?=(\d{3})+(?!\d))/g, f.thou) + f.dec + d, sp = f.space ? ' ' : '';
  document.getElementById('sf-money-preview').textContent = f.after ? n + sp + f.sym : f.sym + sp + n;
}

function sfDate() {
  const saved = FMT.date;
  FMT.date = document.getElementById('sf-date').value;
  document.getElementById('sf-date-preview').textContent = fmtDate(new Date());
  FMT.date = saved;
}

sfLogo(); sfMoney(); sfDate();

// ── SITE DEFAULT THEME ──
async function setSiteTheme(slug) {
  const fd = new FormData();
  fd.append('action', 'set_default_theme');
  fd.append('theme', slug);
  try {
    const res = await fetch(window.location.href, { method:'POST', body: fd, headers:{'X-Admin-Ajax':'1'} }).then(r=>r.json());
    adminToast(res.msg || res.error || tRaw('common.saved'), !!res.ok);
    setTimeout(()=>window.location.reload(), 800); // refresh the "Site default" marks (and the page theme)
  } catch(err) {
    adminToast(tRaw('common.err_prefix', {error: err.message}), false);
  }
}

// ── INTERCEPT ALL ADMIN FORMS WITH AJAX ──
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form[method="POST"]').forEach(form => {
    // Skip forms that already have special handlers
    if (form.id === 'pc-import-form') return;
    form.addEventListener('submit', async (e) => {
      if (e.defaultPrevented) return; // e.g. cancelled confirm() in an onsubmit handler
      e.preventDefault();
      const fd = new FormData(form);
      try {
        const res = await fetch(window.location.href, { method:'POST', body: fd, headers:{'X-Admin-Ajax':'1'} }).then(r=>r.json());
        adminToast(res.msg || res.error || tRaw('common.saved'), !!res.ok);
        if (res.ok && fd.get('action') !== 'reset_password') setTimeout(()=>window.location.reload(), 800);
        if (res.ok && fd.get('action') === 'reset_password') form.reset();
      } catch(err) {
        adminToast(tRaw('common.err_prefix', {error: err.message}), false);
      }
    });
  });
});

const ADMIN_BASE = <?= json_encode(BASE_URL) ?>;
window.GA_BASE = ADMIN_BASE;

loadImgSettings();

function parseCSVLine(line) {
  const result=[]; let cur='',inQ=false;
  for(let i=0;i<line.length;i++){const ch=line[i];if(ch==='"')inQ=!inQ;else if(ch===','&&!inQ){result.push(cur);cur='';}else cur+=ch;}
  result.push(cur); return result;
}

function parseCSV(text) {
  const lines=text.trim().split('\n'); if(lines.length<2) return [];
  const hdr=parseCSVLine(lines[0]); const idx={};
  hdr.forEach((h,i)=>idx[h.trim()]=i);
  const req=['console','name','data-product','link','cib','coverArtBase64'];
  for(const r of req){if(idx[r]===undefined){alert(tRaw('pc.err_column', {column: r}));return[];}}
  const rows=[];
  for(let i=1;i<lines.length;i++){
    const l=lines[i].trim(); if(!l) continue;
    const c=parseCSVLine(l);
    rows.push({console:(c[idx['console']]||'').trim().toUpperCase(),name:(c[idx['name']]||'').trim(),pc_id:(c[idx['data-product']]||'').trim(),link:(c[idx['link']]||'').trim(),cib:idx['cib']!==undefined&&c[idx['cib']]!==''?(parseFloat(c[idx['cib']])??null):null,loose:idx['loose']!==undefined&&c[idx['loose']]!==''?(parseFloat(c[idx['loose']])??null):null,new:idx['new']!==undefined&&c[idx['new']]!==''?(parseFloat(c[idx['new']])??null):null,artBase64:(c[idx['coverArtBase64']]||'').trim()});
  }
  return rows;
}

async function pcPreview() {
  const csv = document.getElementById('pc-csv').value.trim();
  if (!csv) { alert(tRaw('pc.err_no_csv')); return; }
  const rows = parseCSV(csv);
  if (!rows.length) { alert(tRaw('pc.err_no_rows')); return; }

  const res = document.getElementById('pc-result');
  res.style.display='block'; res.textContent=tRaw('admin.pc.previewing');

  const prev = await fetch(ADMIN_BASE+'/api/pc_preview.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({rows:rows.map(r=>({console:r.console,pc_id:r.pc_id,name:r.name,cib:r.cib,hasArt:!!r.artBase64}))})}).then(r=>r.json());
  if (!prev.ok) { res.innerHTML=`<span style="color:var(--red)">${t('import.err_preview', {error: prev.error})}</span>`; return; }

  const matched = prev.items.filter(x=>x.status==='match').length;
  const newG    = prev.items.filter(x=>x.status==='new' && x.system_ok).length;
  const noSys   = prev.items.filter(x=>x.status==='new' && !x.system_ok).length;

  res.innerHTML = `${t('admin.pc.found')} <strong style="color:var(--green)">${t('pc.sum_matched', {n: matched})}</strong>, <strong style="color:var(--wiiu)">${t('pc.sum_new', {n: newG})}</strong>${noSys?`, <strong style="color:var(--red)">${t('pc.sum_nosys', {n: noSys})}</strong>`:''}. <button class="btn btn-sm" onclick="pcConfirm()" style="margin-left:12px">${t('admin.pc.confirm')}</button>`;
  res._rows = rows;
}

async function pcConfirm() {
  const res = document.getElementById('pc-result');
  const rows = res._rows; if (!rows) return;
  res.textContent = tRaw('import.importing');
  const BATCH=20; let imported=0,updated=0,errors=0;
  for(let i=0;i<rows.length;i+=BATCH){
    const r=await fetch(ADMIN_BASE+'/api/pc_import.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({rows:rows.slice(i,i+BATCH)})}).then(r=>r.json());
    if(r.ok){imported+=r.imported||0;updated+=r.updated||0;errors+=r.errors||0;}else errors++;
  }
  res.innerHTML=`<span style="color:var(--green)">✓ ${t('admin.pc.done', {added: imported, updated})}${errors?`, <span style="color:var(--red)">${tn('pc.res_errors', errors)}</span>`:''}.</span>`;
}
</script>
<script src="<?= BASE_URL ?>/assets/js/grading-admin.js?v=<?= @filemtime(__DIR__.'/assets/js/grading-admin.js') ?>"></script>
</body>
</html>
