<?php
/* ═══════════════════════════════════════════
   SETTINGS — one control panel for user and admin settings
   settings.php?s=<slug> shows one section (see settings/sections.php);
   each section's HTML and data live in settings/s_<slug>.php.
   ═══════════════════════════════════════════ */
require_once __DIR__ . '/boot.php';
define('IN_SETTINGS', true);
$user = requireAuth();

$sections = require __DIR__ . '/settings/sections.php';
$s = (string)($_GET['s'] ?? 'account');
if (!isset($sections[$s])) $s = 'account';
// Admin-only section for a normal user: back to the start, without saying it exists
if ($sections[$s]['admin'] && !isAdmin()) { header('Location: '.BASE_URL.'/settings.php'); exit; }
$sec       = $sections[$s];
$showAdmin = $sec['admin'] === true;
$navKey    = fn(string $slug) => 'settings.nav.'.str_replace('-', '_', $slug);

$msg    = '';
$msgErr = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string)($_POST['action'] ?? '');
    if (in_array($action, CP_ADMIN_ACTIONS, true)) require __DIR__ . '/settings/actions_admin.php';
    else                                           require __DIR__ . '/settings/actions_user.php';
    // Forms sent by the AJAX handler (assets/js/settings.js) get a JSON result instead of the page
    if (!empty($_SERVER['HTTP_X_ADMIN_AJAX'])) jsonOut(['ok'=>!$msgErr, 'msg'=>$msg ?: tRaw('common.saved')]);
}

// Sidebar / jump menu: sections per group, admin-only ones only for admins
$groups = [];
foreach ($sections as $slug => $cfg) {
    if ($cfg['admin'] && !isAdmin()) continue;
    $groups[$cfg['group']][] = $slug;
}

$prefixes = array_merge(['settings'], $sec['prefixes'], $showAdmin ? ['admin'] : []);
$jsVer    = fn(string $f) => BASE_URL.'/assets/js/'.$f.'?v='.@filemtime(__DIR__.'/assets/js/'.$f);
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pageTitle(tRaw('common.nav.settings').' – '.tRaw($navKey($s))) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<?= themeHead($user) ?>
<?= csrfScript() ?>
<?= appScript($prefixes) ?>
<script>window.CP = { base: <?= json_encode(BASE_URL) ?>, section: <?= json_encode($s) ?> };</script>
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/dashboard.php" class="site-logo" style="text-decoration:none"><?= siteLogoHtml() ?></a>
  <nav class="site-nav">
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link"><?= t('common.nav.dashboard') ?></a>
    <a href="<?= BASE_URL ?>/collection.php" class="nav-link"><?= t('common.nav.collection') ?></a>
    <a href="<?= BASE_URL ?>/wishlist.php" class="nav-link"><?= t('common.nav.wishlist') ?></a>
    <a href="<?= BASE_URL ?>/settings.php" class="nav-link active"><?= t('common.nav.settings') ?></a>
    <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link"><?= t('common.nav.sign_out') ?></a>
  </nav>
</header>

<div class="cp-wrap">
  <nav class="cp-side" aria-label="<?= t('common.nav.settings') ?>">
    <?php foreach ($groups as $group => $slugs): ?>
    <p class="cp-group"><?= t('settings.group.'.$group) ?></p>
    <?php foreach ($slugs as $slug): ?>
    <a href="<?= BASE_URL ?>/settings.php?s=<?= $slug ?>" class="cp-link<?= $slug === $s ? ' active' : '' ?>"<?= $slug === $s ? ' aria-current="page"' : '' ?>>
      <?= t($navKey($slug)) ?><?php if ($sections[$slug]['admin']): ?> <span class="cp-badge"><?= t('settings.admin_badge') ?></span><?php elseif (!empty($sections[$slug]['badge'])): ?> <span class="cp-badge"><?= t($sections[$slug]['badge']) ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <main class="cp-main">
    <div class="cp-jump field">
      <label for="cp-jump"><?= t('settings.jump_to') ?></label>
      <select id="cp-jump" onchange="window.location.href=this.value">
        <?php foreach ($groups as $group => $slugs): ?>
        <optgroup label="<?= t('settings.group.'.$group) ?>">
          <?php foreach ($slugs as $slug): ?>
          <option value="<?= BASE_URL ?>/settings.php?s=<?= $slug ?>" <?= $slug === $s ? 'selected' : '' ?>><?= t($navKey($slug)) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php endforeach; ?>
      </select>
    </div>

    <h1 class="cp-title"><?= t($navKey($s)) ?></h1>

    <?php if ($msg): ?>
    <div class="cp-msg<?= $msgErr ? ' cp-msg--err' : '' ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php require __DIR__ . '/settings/s_'.str_replace('-', '_', $s).'.php'; ?>
  </main>
</div>

<div class="toast" id="toast"></div>

<script src="<?= $jsVer('settings.js') ?>"></script>
<?php if ($showAdmin): ?><script src="<?= $jsVer('admin.js') ?>"></script><?php endif; ?>
<?php foreach ($sec['scripts'] ?? [] as $f): ?><script src="<?= $jsVer($f) ?>"></script><?php endforeach; ?>
</body>
</html>
