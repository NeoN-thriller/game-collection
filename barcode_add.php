<?php
// Add barcodes (assets/js/barcode-add.js): one system's games as a long list. Pick a game and keep scanning
// (camera, typed digits or a USB / Bluetooth scanner): every valid code is linked to that game and shows up next to it.
// For users who may link barcodes (Settings › Barcodes); gone while barcode scanning is switched off.
require_once __DIR__ . '/boot.php';
$user = requireAuth();

$canUse = barcodeEnabled() && barcodeCanEdit($user);
$sysSt = db()->prepare("
    SELECT s.id, s.name, s.short_name, s.region
    FROM systems s
    LEFT JOIN user_system_prefs usp ON usp.system_id = s.id AND usp.user_id = ?
    WHERE s.active = 1
    ORDER BY COALESCE(usp.user_sort_order, s.sort_order), s.id
");
$sysSt->execute([$user['id']]);
$systems = $sysSt->fetchAll();
// Lookup links (as in the edit drawer): the user's own search sites, and each system's short name and region for them
$sites = json_decode($user['auction_sites'] ?? '[]', true) ?: [];
$sysInfo = [];
foreach ($systems as $s) {
    $r = systemRegion($s);
    $sysInfo[(int)$s['id']] = ['short' => $s['short_name'], 'region' => $r === 'Mixed' ? '' : $r];
}
$jsVer = fn(string $f) => BASE_URL . '/assets/js/' . $f . '?v=' . @filemtime(__DIR__ . '/assets/js/' . $f);
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pageTitle(tRaw('bc.add_page')) ?></title>
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

<?php if (!$canUse): ?>
<main class="scan-wrap">
  <h1 class="scan-title"><?= t('bc.add_page') ?></h1>
  <div class="cp-msg cp-msg--err"><?= t(barcodeEnabled() ? 'bc.add_not_allowed' : 'bc.off_notice') ?></div>
</main>
<?php else: ?>
<main class="ba-wrap">
  <div class="ba-head">
    <h1 class="scan-title"><?= t('bc.add_page') ?></h1>
    <a href="<?= BASE_URL ?>/scan.php" class="bc-linkbtn">← <?= t('bc.title') ?></a>
  </div>
  <p class="ga-desc" style="margin:0"><?= t('bc.add_intro') ?></p>

  <!-- Stays at the top while the list scrolls -->
  <section class="ba-panel" id="ba-panel">
    <div class="ba-filters">
      <select id="ba-sys" aria-label="<?= t('common.col.system') ?>">
        <?php foreach ($systems as $s): ?><option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
      </select>
      <input type="search" id="ba-q" placeholder="<?= t('bc.add_filter') ?>" aria-label="<?= t('bc.add_filter') ?>">
      <label class="ba-check"><input type="checkbox" id="ba-missing"> <?= t('bc.add_only_missing') ?></label>
    </div>
    <div class="ba-filters ba-open-row">
      <label class="bc-label" for="ba-open"><?= t('bc.add_open') ?></label>
      <select id="ba-open">
        <option value=""><?= t('bc.add_open_off') ?></option>
        <option value="price"><?= t('bc.add_open_prices') ?></option>
        <option value="wiki">Wikipedia</option>
        <?php foreach ($sites as $i => $site): ?><option value="site:<?= (int)$i ?>"><?= htmlspecialchars((string)($site['label'] ?? '')) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="ba-scan">
      <div class="ba-scan-main">
        <div class="ba-current" id="ba-current"><?= t('bc.add_pick_first') ?></div>
        <div class="bc-inline">
          <input type="text" id="ba-code" class="bc-digits-in" inputmode="numeric" autocomplete="off" spellcheck="false"
                 placeholder="<?= t('bc.digits') ?>" aria-label="<?= t('bc.digits') ?>">
          <button type="button" class="btn-ghost" id="ba-cam-toggle" aria-pressed="false">▥ <?= t('bc.tab_camera') ?></button>
          <button type="button" class="btn-ghost" id="ba-sound" aria-pressed="true" title="<?= t('bc.add_sound_help') ?>">🔊 <?= t('bc.add_sound') ?></button>
        </div>
        <div class="ba-status" id="ba-status" aria-live="polite"></div>
      </div>
      <!-- The camera opens below the input and buttons -->
      <div class="ba-cam" id="ba-cam" hidden>
        <div class="bc-view ba-view"><video id="ba-video" playsinline muted></video><div class="bc-frame" id="ba-frame"><span class="bc-line"></span></div>
          <div class="bc-view-msg" id="ba-cam-msg" hidden></div></div>
      </div>
    </div>
  </section>

  <p class="sb-sub" id="ba-count"></p>
  <ol class="ba-list" id="ba-list" aria-label="<?= t('bc.add_list') ?>"></ol>
</main>
<?php endif; ?>

<div class="toast" id="toast" role="status"></div>

<?php if ($canUse): ?>
<?= barcodeConfigScript($user) ?>
<script>window.BA = <?= json_encode(['sites' => array_values($sites), 'systems' => $sysInfo], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= $jsVer('barcode.js') ?>"></script>
<script src="<?= $jsVer('barcode-add.js') ?>"></script>
<?php endif; ?>
</body>
</html>
