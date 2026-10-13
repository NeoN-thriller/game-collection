<?php
/* SETTINGS › Database export (admin): the game catalogue as CSV, JSON or XML for anyone to use (data_export.php).
   Columns are switched on and put in order here (assets/js/data-export.js), with a live preview from
   api/catalogue_export.php?preview=1. Save (save_export_settings) keeps them as the default and for the public link. */
if (!defined('IN_SETTINGS')) exit;

$h         = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES);
$dxCfg     = dxConfig();
$dxPublic  = setting('dx_public') === '1';
$dxSystems = db()->query("SELECT id, short_name, name, active FROM systems ORDER BY active DESC, sort_order, id")->fetchAll();
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE;
?>
<script>CP.dx = <?= json_encode([
    'cfg'      => $dxCfg,
    'defaults' => array_map(fn($id, $c) => ['id' => $id, 'on' => $c[0]], array_keys(DX_COLUMNS), DX_COLUMNS),
    'prices'   => DX_PRICE_COLUMNS,
    'money'    => array_keys(array_filter(DX_COLUMNS, fn($c) => $c[1] === 'money')),
    'public'   => $dxPublic,
    'site'     => dxSiteUrl(),
], $jsonFlags) ?>;</script>

<p class="ga-desc dx-intro"><?= t('dx.intro') ?></p>

<section class="cp-card">
  <h2><?= t('dx.columns_title') ?></h2>
  <p class="export-desc"><?= t('dx.columns_help') ?></p>
  <div class="dx-col-tools">
    <button type="button" class="btn-ghost btn-sm" id="dx-all-off"><?= t('dx.cols_none') ?></button>
    <button type="button" class="btn-ghost btn-sm" id="dx-reset"><?= t('dx.cols_reset') ?></button>
    <span class="sb-sub" id="dx-col-count" aria-live="polite"></span>
  </div>
  <ol class="dx-cols" id="dx-cols" aria-label="<?= t('dx.columns_title') ?>"></ol>
  <p class="cp-msg cp-msg--err dx-price-note" id="dx-price-note" hidden><?= t('dx.price_note') ?></p>
</section>

<section class="cp-card">
  <h2><?= t('dx.options_title') ?></h2>

  <h3 class="log-h3"><?= t('dx.format') ?></h3>
  <div class="log-seg" role="radiogroup" aria-label="<?= t('dx.format') ?>">
    <?php foreach (DX_FORMATS as $f): ?>
    <label><input type="radio" name="dx-format" value="<?= $f ?>" <?= $f === $dxCfg['format'] ? 'checked' : '' ?>><span><?= t('dx.format_'.$f) ?></span></label>
    <?php endforeach; ?>
  </div>
  <p class="sb-sub dx-hint" id="dx-format-hint"></p>

  <div id="dx-sep-wrap">
    <h3 class="log-h3"><?= t('dx.sep') ?></h3>
    <div class="log-seg" role="radiogroup" aria-label="<?= t('dx.sep') ?>">
      <?php foreach (array_keys(DX_SEPS) as $sp): ?>
      <label><input type="radio" name="dx-sep" value="<?= $sp ?>" <?= $sp === $dxCfg['sep'] ? 'checked' : '' ?>><span><?= t('dx.sep_'.$sp) ?></span></label>
      <?php endforeach; ?>
    </div>
    <p class="sb-sub dx-hint"><?= t('dx.sep_hint') ?></p>
  </div>

  <h3 class="log-h3"><?= t('dx.rows') ?></h3>
  <div class="log-seg" role="radiogroup" aria-label="<?= t('dx.rows') ?>">
    <?php foreach (DX_ROWS as $r): ?>
    <label><input type="radio" name="dx-rows" value="<?= $r ?>" <?= $r === $dxCfg['rows'] ? 'checked' : '' ?> data-hint="<?= $h(tRaw('dx.rows_hint_'.$r, ['sep' => DX_LIST_SEP])) ?>"><span><?= t('dx.rows_'.$r) ?></span></label>
    <?php endforeach; ?>
  </div>
  <p class="sb-sub dx-hint" id="dx-rows-hint"></p>

  <h3 class="log-h3"><?= t('dx.systems') ?></h3>
  <p class="sb-sub" style="margin:0 0 8px"><?= t('dx.systems_help') ?></p>
  <div class="log-chips" id="dx-systems">
    <button type="button" class="log-chip" data-sys="0" aria-pressed="<?= $dxCfg['systems'] ? 'false' : 'true' ?>"><?= t('dx.systems_all') ?></button>
    <?php foreach ($dxSystems as $sy): ?>
    <button type="button" class="log-chip<?= $sy['active'] ? '' : ' dx-chip-hidden' ?>" data-sys="<?= (int)$sy['id'] ?>" title="<?= $h($sy['name']) ?><?= $sy['active'] ? '' : ' · '.$h(tRaw('dx.system_hidden')) ?>"
            aria-pressed="<?= in_array((int)$sy['id'], $dxCfg['systems'], true) ? 'true' : 'false' ?>"><?= $h($sy['short_name']) ?></button>
    <?php endforeach; ?>
  </div>

  <div class="log-type-row" style="margin-top:14px">
    <span class="log-type-text"><strong><?= t('dx.inactive') ?></strong><br><span class="sb-sub"><?= t('dx.inactive_help') ?></span></span>
    <label class="toggle log-switch"><input type="checkbox" role="switch" id="dx-inactive" <?= $dxCfg['inactive'] ? 'checked' : '' ?>
           aria-label="<?= t('dx.inactive') ?>"><span class="toggle-slider"></span></label>
  </div>
</section>

<section class="cp-card">
  <h2><?= t('dx.preview_title') ?></h2>
  <p class="sb-sub" id="dx-preview-count" aria-live="polite"></p>
  <div class="dx-preview" id="dx-preview"></div>
  <div class="dx-actions">
    <a class="btn btn-sm" id="dx-download" href="<?= BASE_URL ?>/api/catalogue_export.php">⬇ <?= t('dx.download') ?></a>
    <span class="sb-sub"><?= t('dx.download_help') ?></span>
  </div>
</section>

<section class="cp-card" id="dx-save-card">
  <h2><?= t('dx.public_title') ?></h2>
  <form method="POST" id="dx-form">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="action" value="save_export_settings">
    <input type="hidden" name="dx_config" id="dx-config" value="<?= $h(json_encode($dxCfg, JSON_UNESCAPED_UNICODE)) ?>">
    <div class="log-type-row log-master">
      <span class="log-type-text"><strong><?= t('dx.public') ?></strong><br><span class="sb-sub"><?= t('dx.public_help') ?></span></span>
      <label class="toggle log-switch"><input type="checkbox" role="switch" name="dx_public" value="1" <?= $dxPublic ? 'checked' : '' ?>
             aria-label="<?= t('dx.public') ?>"><span class="toggle-slider"></span></label>
    </div>
    <?php if ($dxPublic): ?>
    <div class="dx-links">
      <p class="sb-sub" style="margin:0 0 6px"><?= t('dx.public_links') ?></p>
      <?php foreach (DX_FORMATS as $f): $url = dxSiteUrl().'/api/catalogue_export.php?format='.$f; ?>
      <div class="dx-link-row">
        <span class="dx-link-fmt"><?= strtoupper($f) ?></span>
        <input type="text" readonly value="<?= $h($url) ?>" aria-label="<?= t('dx.public_link', ['format' => strtoupper($f)]) ?>">
        <button type="button" class="btn-ghost btn-sm dx-copy" data-url="<?= $h($url) ?>"><?= t('dx.copy') ?></button>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p class="sb-sub" id="dx-unsaved" hidden style="margin-top:12px;color:var(--orange)"><?= t('dx.unsaved') ?></p>
    <button class="btn btn-sm" type="submit" style="margin-top:12px"><?= t('dx.save') ?></button>
    <p class="sb-sub" style="margin-top:8px"><?= t('dx.save_help') ?></p>
  </form>
</section>
