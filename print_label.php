<?php
// One printable condition-report sticker: print_label.php?share=<share id>&tpl=<template id>
// Without share: a test label with sample data (template editor). Own shares only.
// The page is exactly the sticker's size (@page) and opens the print dialog on load.
require_once __DIR__ . '/boot.php';
$user = requireAuth();

$shareId = (int)($_GET['share'] ?? 0);
if ($shareId) {
    $st = db()->prepare("SELECT * FROM copy_shares WHERE id=? AND user_id=? AND active=1");
    $st->execute([$shareId, (int)$user['id']]);
    $share  = $st->fetch();
    $report = $share ? shareReport($share) : null;
    if (!$report) { http_response_code(404); exit(htmlspecialchars(tRaw('cr.err_share'))); }
    $data = labelDataFromReport($report, $share['token']);
} else {
    $data = labelSampleData($user);
}

$templates = labelTemplatesFor($user);
$tplId = (int)($_GET['tpl'] ?? 0);
$tpl = null;
foreach ($templates as $t) if ($t['id'] === $tplId) $tpl = $t;
if (!$tpl) {
    $def = labelDefaultTemplateId($user, $templates);
    foreach ($templates as $t) if ($t['id'] === $def) $tpl = $t;
}
if (!$tpl) { http_response_code(404); exit(htmlspecialchars(tRaw('lbl.err_template'))); }

// Page size in mm, after orientation (landscape = long side across)
$long  = max($tpl['width_mm'], $tpl['height_mm']);
$short = min($tpl['width_mm'], $tpl['height_mm']);
[$w, $h] = $tpl['orientation'] === 'portrait' ? [$short, $long] : [$long, $short];
$mm = fn(float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . 'mm';
$json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex">
<title><?= pageTitle(tRaw('cr.print_label')) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<?= themeHead($user) ?>
<?= appScript(['cr', 'lbl']) ?>
<style>
  @page { size: <?= $mm($w) ?> <?= $mm($h) ?>; margin: 0; }
  html, body { margin: 0; padding: 0; background: #fff; }
  body::before, body::after { display: none !important; }   /* no theme overlays on paper */
  .print-page { width: <?= $mm($w) ?>; height: <?= $mm($h) ?>; overflow: hidden; }
  .print-warn { font: 12px/1.5 sans-serif; color: #b00; padding: 8px 0; max-width: 90mm; }
  @media print { .print-warn { display: none; } }
</style>
</head>
<body>
<div class="print-page"><div id="label"></div></div>
<p class="print-warn" id="print-warn" hidden><?= t('lbl.fit_warning') ?></p>
<script src="<?= BASE_URL ?>/assets/js/vendor/qrcode.js?v=<?= @filemtime(__DIR__.'/assets/js/vendor/qrcode.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/labels.js?v=<?= @filemtime(__DIR__.'/assets/js/labels.js') ?>"></script>
<script>
(async () => {
  const fits = Labels.render(document.getElementById('label'), <?= json_encode($tpl, $json) ?>, <?= json_encode($data, $json) ?>, null);
  document.getElementById('print-warn').hidden = fits;
  try { await document.fonts.ready; } catch { /* print anyway */ }
  window.print();
})();
</script>
</body>
</html>
