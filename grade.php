<?php
// Public condition report of one copy: grade.php?t=<token>. No login.
// Shows why the copy got its grade; never prices paid, notes, tags, upgrade flags, the username or other copies.
require_once __DIR__ . '/boot.php';

$token  = (string)($_GET['t'] ?? '');
$share  = shareFindActive($token);
$report = $share ? shareReport($share) : null;
$owner  = null;
if ($share) {
    $st = db()->prepare("SELECT * FROM users WHERE id=?");
    $st->execute([(int)$share['user_id']]);
    $owner = $st->fetch() ?: null;
}
if (!$report) http_response_code(404);
header('X-Robots-Tag: noindex');

// The owner, signed in, also sees what they paid and their personal price (read live, never stored in the share)
$ownerPrices = null;
$viewer = auth();
if ($report && $viewer && (int)$viewer['id'] === (int)$share['user_id']) {
    $st = db()->prepare("SELECT price_paid, chart_price FROM collection_entries WHERE id=? AND user_id=?");
    $st->execute([(int)$share['entry_id'], (int)$viewer['id']]);
    $ownerPrices = $st->fetch() ?: null;
    header('Cache-Control: private, no-store');
}

$h        = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$photoUrl = fn(int $id) => BASE_URL . '/grade_photo.php?t=' . rawurlencode($token) . '&p=' . $id;
$colorFor = function (?int $score) {
    $l = $score !== null ? gradeLabelForScore($score) : null;
    return $l ? gradingColor($l['color']) : 'var(--muted)';
};
$tint = fn(string $c, int $pct) => "color-mix(in srgb, $c {$pct}%, transparent)";
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?= pageTitle(tRaw('cr.title')) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<?= themeHead($owner) ?>
<?= appScript(['cr', 'drawer']) ?>
</head>
<body class="cr-body">
<main class="cr-page">
  <header class="cr-head">
    <span class="site-logo"><?= siteLogoHtml() ?></span>
    <span class="cr-head-label"><?= t('cr.title') ?></span>
  </header>

<?php if (!$report): ?>
  <section class="cr-card cr-gone">
    <h1 class="cr-h1"><?= t('cr.gone_title') ?></h1>
    <p><?= t('cr.gone_text') ?></p>
    <p><?= t('cr.gone_buyer') ?></p>
  </section>
<?php else:
    $photos = $report['photos'] ?? [];
    $score  = $report['score'];
    $label  = $report['label'];
    $isPts  = $report['method'] === 'points' && $score !== null;
    $parts  = $report['parts'] ?? [];
?>
  <?php if ($photos): ?>
  <!-- Photos -->
  <section class="cr-photos" aria-label="<?= t('cr.photos') ?>">
    <button type="button" class="cr-main-photo" data-lb="0">
      <img src="<?= $h($photoUrl($photos[0])) ?>" alt="<?= t('cr.photo_n', ['n' => 1]) ?>">
      <span class="cr-photo-count"><?= t('cr.n_of', ['n' => 1, 'total' => count($photos)]) ?></span>
    </button>
    <?php if (count($photos) > 1): ?>
    <div class="cr-thumbs">
      <?php foreach ($photos as $i => $pid): ?>
      <button type="button" class="cr-thumb" data-lb="<?= $i ?>" aria-label="<?= t('cr.photo_n', ['n' => $i + 1]) ?>"><img src="<?= $h($photoUrl($pid)) ?>" alt="" loading="lazy"></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- Title -->
  <section class="cr-card">
    <div class="cr-chips">
      <span class="chip chip-n"><?= $h($report['system']) ?></span>
      <?php if (($report['region'] ?? '') !== '' && $report['region'] !== 'Mixed'): ?><span class="chip chip-n"><?= $h($report['region']) ?></span><?php endif; ?>
      <span class="chip chip-n"><?= t('cr.copy_n', ['n' => (int)$report['copy_number']]) ?></span>
      <?php if (!empty($share['for_sale'])): ?><span class="chip chip-y"><?= t('cr.for_sale') ?></span><?php endif; ?>
    </div>
    <h1 class="cr-h1"><?= $h($report['title']) ?><?php if (!empty($report['edition'])): ?> <span class="chip chip-blue"><?= $h($report['edition']) ?></span><?php endif; ?></h1>
    <?php if (!empty($report['profile'])): ?><p class="cr-muted"><?= t('cr.format', ['name' => $report['profile']]) ?></p><?php endif; ?>
  </section>

  <?php if ($ownerPrices): ?>
  <!-- Only for the signed-in owner -->
  <section class="cr-card cr-owner">
    <p class="cr-muted"><?= t('cr.owner_only') ?></p>
    <div class="cr-owner-prices">
      <div><span class="cr-muted"><?= t('cr.price_paid') ?></span><strong><?= $ownerPrices['price_paid'] !== null ? money($ownerPrices['price_paid']) : '—' ?></strong></div>
      <div><span class="cr-muted"><?= t('cr.personal_price') ?></span><strong style="color:var(--personal-price)"><?= $ownerPrices['chart_price'] !== null ? money($ownerPrices['chart_price']) : '—' ?></strong></div>
    </div>
  </section>
  <?php endif; ?>

  <!-- Score -->
  <section class="cr-card">
    <?php if ($isPts): ?>
    <div class="cr-score-row">
      <span class="cr-score" style="color:<?= $h($label['color'] ?? 'var(--text)') ?>"><?= (int)$score ?></span><span class="cr-score-max">/100</span>
      <?php if ($label): ?><span class="qbadge" style="color:<?= $h($label['color']) ?>;background:<?= $tint($label['color'], 18) ?>;border:1px solid <?= $tint($label['color'], 40) ?>"><?= $h($label['name']) ?></span><?php endif; ?>
    </div>
    <p class="cr-muted"><?= t('cr.points_grading') ?></p>
    <?php if (!empty($report['scale'])): ?>
    <div class="cr-scale" aria-hidden="true">
      <?php foreach ($report['scale'] as $seg): $cur = $score >= $seg['min'] && $score <= $seg['max']; ?>
      <span class="cr-seg<?= $cur ? ' cur' : '' ?>" style="flex:<?= $seg['max'] - $seg['min'] + 1 ?>;background:<?= $h($seg['color']) ?>"></span>
      <?php endforeach; ?>
      <span class="cr-marker" style="left:<?= max(0, min(100, (int)$score)) ?>%"></span>
    </div>
    <p class="cr-legend"><?= $h(implode(' · ', array_map(fn($s) => $s['name'] . ' ' . $s['min'] . '–' . $s['max'], $report['scale']))) ?></p>
    <?php endif; ?>
    <?php elseif ($label): ?>
    <div class="cr-score-row">
      <span class="qbadge cr-badge-lg" style="color:<?= $h($label['color']) ?>;background:<?= $tint($label['color'], 18) ?>;border:1px solid <?= $tint($label['color'], 40) ?>"><?= $h($label['name']) ?></span>
    </div>
    <p class="cr-muted"><?= t('cr.simple_grading') ?></p>
    <?php else: ?>
    <p class="cr-muted"><?= t('cr.not_graded') ?></p>
    <?php endif; ?>
    <p class="cr-note"><?= $share['mode'] === 'live'
        ? t('cr.live_note', ['date' => fmtDate($report['graded_at'])])
        : t('cr.snapshot_note', ['date' => fmtDate($report['graded_at'])]) ?></p>
  </section>

  <!-- What's included -->
  <?php if ($parts || ($report['completeness'] ?? '') !== ''): ?>
  <section class="cr-card">
    <h2 class="cr-h2"><?= t('cr.included') ?></h2>
    <?php if ($parts): ?>
    <div class="cr-included">
      <?php foreach ($parts as $p): ?>
      <div class="cr-inc<?= $p['included'] ? ' yes' : ' no' ?>">
        <span aria-hidden="true"><?= $p['included'] ? '✓' : '–' ?></span>
        <span><?= $h($p['name']) ?><?= $p['qty'] > 1 ? ' ×' . (int)$p['qty'] : '' ?></span>
        <span class="sr-only"><?= t($p['included'] ? 'cr.present' : 'cr.missing') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="cr-muted"><?= t('cr.missing_note') ?></p>
    <?php else: ?>
    <p><?= t('cr.completeness', ['value' => $report['completeness']]) ?></p>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($isPts && $parts): ?>
  <!-- Score per part -->
  <section class="cr-card">
    <h2 class="cr-h2"><?= t('cr.per_part') ?></h2>
    <div class="cr-parts">
      <?php foreach ($parts as $p): ?>
        <?php if (!$p['included']): ?>
        <div class="cr-unit cr-unit-missing"><span><?= $h($p['name']) ?></span><span class="cr-muted"><?= t('cr.not_included') ?></span></div>
        <?php continue; endif; ?>
        <?php foreach ($p['units'] as $u): $c = $colorFor($u['score']); ?>
        <details class="cr-unit">
          <summary>
            <span class="cr-unit-name"><?= $h($u['name']) ?> <span class="cr-muted"><?= t('cr.weight', ['n' => fmtNum($u['weight_pct'], $u['weight_pct'] == floor($u['weight_pct']) ? 0 : 1)]) ?></span></span>
            <span class="cr-bar"><span style="width:<?= (int)$u['score'] ?>%;background:<?= $h($c) ?>"></span></span>
            <span class="cr-unit-score" style="color:<?= $h($c) ?>"><?= (int)$u['score'] ?></span>
          </summary>
          <div class="cr-cats">
            <?php foreach ($u['categories'] as $cat): ?>
            <div class="cr-cat">
              <div class="cr-cat-head"><span><?= $h($cat['name']) ?></span><span><?= (int)$cat['score'] ?>/<?= (int)$cat['max'] ?></span></div>
              <span class="cr-bar cr-bar-thin"><span style="width:<?= $cat['max'] ? round($cat['score'] / $cat['max'] * 100) : 100 ?>%"></span></span>
              <?php if ($cat['defects']): ?>
              <ul class="cr-defects">
                <?php foreach ($cat['defects'] as $d): ?>
                <li><span><?= $h($d['name']) ?><?= $d['count'] > 1 ? ' ×' . (int)$d['count'] : '' ?></span><span>−<?= (int)$d['deduction'] ?></span></li>
                <?php endforeach; ?>
              </ul>
              <?php else: ?>
              <p class="cr-muted cr-nodef"><?= t('cr.no_defects') ?></p>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </details>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- How this score works -->
  <section class="cr-card">
    <h2 class="cr-h2"><?= t('cr.how_title') ?></h2>
    <ol class="cr-how">
      <li><?= t('cr.how_1') ?></li>
      <li><?= t('cr.how_2') ?></li>
      <li><?= t('cr.how_3') ?></li>
    </ol>
    <?php if (!empty($report['formula']['terms'])): $f = $report['formula']; ?>
    <p class="cr-formula">(<?= $h(implode(' + ', array_map(fn($t) => $t[0] . '×' . $t[1], $f['terms']))) ?>) ÷ <?= (int)$f['total'] ?> = <?= (int)$f['result'] ?></p>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <p class="cr-disclaimer"><?= t('cr.disclaimer', ['site' => siteName()]) ?></p>
  <footer class="cr-foot"><span><?= $h(siteName()) ?></span><span><?= t('cr.report_id', ['id' => shareReportId($token)]) ?></span></footer>

  <?php if ($photos): ?>
  <script src="<?= BASE_URL ?>/assets/js/lightbox.js?v=<?= @filemtime(__DIR__.'/assets/js/lightbox.js') ?>"></script>
  <script>
  (() => {
    const items = <?= json_encode(array_map(fn($id, $i) => ['src' => $photoUrl($id), 'caption' => tRaw('cr.photo_n', ['n' => $i + 1])], $photos, array_keys($photos)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;
    document.querySelectorAll('[data-lb]').forEach(b => b.addEventListener('click', () => Lightbox.open(items, +b.dataset.lb, { dots: true })));
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
