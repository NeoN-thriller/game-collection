<?php
// Scan barcodes (assets/js/scan.js): the camera, typed digits or a USB / Bluetooth scanner. A scan looks the code up
// in game_barcodes and, depending on the chosen action, shows the game, adds a copy or puts it on the wishlist.
// Unknown codes can be linked to a game here. scan.php?code=<digits> looks a code up straight away
// (Enter on a barcode in the header search box).
require_once __DIR__ . '/boot.php';
$user = requireAuth();

$startCode = preg_replace('/\D+/', '', (string)($_GET['code'] ?? ''));
$jsVer = fn(string $f) => BASE_URL . '/assets/js/' . $f . '?v=' . @filemtime(__DIR__ . '/assets/js/' . $f);
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pageTitle(tRaw('bc.title')) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<?= themeHead($user) ?>
<?= csrfScript() ?>
<?= updateRefreshScript($user) ?>
<?= siteBackupAutoScript($user) ?>
<?= appScript(['bc']) ?>
</head>
<body class="scan-page">

<header class="site-header">
  <a href="<?= BASE_URL ?>/collection.php" class="site-logo" style="text-decoration:none"><?= siteLogoHtml() ?></a>
  <?= siteNav($user, 'scan') ?>
</header>

<?php if (!barcodeEnabled()): ?>
<main class="scan-wrap">
  <h1 class="scan-title"><?= t('bc.title') ?></h1>
  <div class="cp-msg cp-msg--err"><?= t('bc.off_notice') ?><?php if (($user['role'] ?? '') === 'admin'): ?> <a href="<?= BASE_URL ?>/settings.php?s=barcodes"><?= t('bc.off_admin_link') ?></a><?php endif; ?></div>
</main>
<?php else: ?>
<main class="scan-wrap">
  <h1 class="scan-title"><?= t('bc.title') ?></h1>
  <?php if (barcodeCanEdit($user)): ?>
  <a href="<?= BASE_URL ?>/barcode_add.php" class="bc-linkbtn scan-add-link"><?= t('bc.add_page_link') ?> →</a>
  <?php endif; ?>

  <div class="bc-seg scan-tabs" role="group" aria-label="<?= t('bc.title') ?>">
    <button type="button" id="scan-tab-camera" aria-pressed="true" aria-controls="scan-camera"><?= t('bc.tab_camera') ?></button>
    <button type="button" id="scan-tab-type" aria-pressed="false" aria-controls="scan-type"><?= t('bc.tab_type') ?></button>
  </div>

  <section id="scan-camera" class="scan-panel">
    <div class="bc-view" id="scan-view">
      <video id="scan-video" playsinline muted></video>
      <div class="bc-frame" id="scan-frame"><span class="bc-line"></span></div>
      <span class="bc-live" id="scan-live" hidden>● <?= t('bc.live') ?></span>
      <div class="bc-view-msg" id="scan-cam-msg" hidden></div>
    </div>
    <div class="scan-cam-tools">
      <button type="button" class="btn-ghost scan-tool" id="scan-torch" aria-pressed="false" hidden>🔦 <?= t('bc.torch') ?></button>
      <select id="scan-camera-pick" class="scan-tool" aria-label="<?= t('bc.camera_pick') ?>" hidden></select>
    </div>
    <p class="ga-desc scan-help"><?= t('bc.help_camera') ?></p>
  </section>

  <section id="scan-type" class="scan-panel" hidden>
    <label for="scan-digits" class="bc-label"><?= t('bc.digits') ?></label>
    <div class="bc-inline">
      <input type="text" id="scan-digits" class="bc-digits-in" inputmode="numeric" autocomplete="off" spellcheck="false" value="<?= htmlspecialchars($startCode) ?>">
      <button type="button" class="btn" id="scan-lookup"><?= t('bc.look_up') ?></button>
    </div>
    <p class="bc-check" id="scan-digits-msg" hidden></p>
    <p class="ga-desc scan-help"><?= t('bc.help_type') ?></p>
  </section>

  <div class="scan-mode">
    <span class="bc-label" id="scan-mode-label"><?= t('bc.scan_should') ?></span>
    <div class="bc-seg" role="radiogroup" aria-labelledby="scan-mode-label">
      <button type="button" role="radio" data-mode="look" aria-checked="true"><?= t('bc.mode_look') ?></button>
      <button type="button" role="radio" data-mode="own" aria-checked="false"><?= t('bc.mode_own') ?></button>
      <button type="button" role="radio" data-mode="wish" aria-checked="false"><?= t('bc.mode_wish') ?></button>
    </div>
  </div>

  <section class="bc-result" id="scan-result" aria-live="polite" hidden></section>
</main>
<?php endif; ?>

<div class="toast" id="toast" role="status"></div>

<?php if (barcodeEnabled()): ?>
<?= barcodeConfigScript($user) ?>
<script>
window.SCAN = { startCode: <?= json_encode($startCode) ?> };
</script>
<script src="<?= $jsVer('barcode.js') ?>"></script>
<script src="<?= $jsVer('scan.js') ?>"></script>
<?php endif; ?>
</body>
</html>
