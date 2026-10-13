<?php
/* SETTINGS › Barcodes (admin): every linked barcode, reported ones, unknown scans, codes on several games and games
   without a code (assets/js/barcodes-admin.js reads api/barcode_admin.php), CSV import / export, who may link
   barcodes (save_barcode_settings) and a camera check. */
if (!defined('IN_SETTINGS')) exit;

$h       = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES);
$who     = in_array(setting('barcode_who'), BARCODE_WHO, true) ? setting('barcode_who') : 'all';
$forget  = (string)(int)setting('barcode_unknown_days');
if (!isset(BARCODE_UNKNOWN_DAYS[$forget])) $forget = '30';
$bcSystems = db()->query("SELECT id, short_name, name FROM systems WHERE active = 1 ORDER BY sort_order, id")->fetchAll();
try {
    $tablesOk = (bool)db()->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_barcodes'")->fetchColumn();
} catch (PDOException) { $tablesOk = true; }
?>
<?= barcodeConfigScript($user) ?>

<?php if (!barcodeEnabled()): ?>
<div class="cp-msg cp-msg--err"><?= t('bc.off_admin_notice') ?> <a href="#bc-settings"><?= t('bc.off_admin_link') ?> ↓</a></div>
<?php endif; ?>

<?php if (!$tablesOk): ?>
<div class="cp-msg cp-msg--err"><?= t('bc.no_tables') ?></div>
<?php endif; ?>

<div class="bc-admin-head">
  <p class="ga-desc" style="margin:0"><?= t('bc.admin_intro') ?></p>
  <div class="bc-actions">
    <button type="button" class="btn-ghost btn-sm" id="bca-import-open">⬆ <?= t('bc.import_csv') ?></button>
    <a class="btn-ghost btn-sm" href="<?= BASE_URL ?>/api/barcode_export.php">⬇ <?= t('bc.export_csv') ?></a>
    <a class="btn-ghost btn-sm" href="<?= BASE_URL ?>/barcode_add.php"><?= t('bc.add_page') ?></a>
    <a class="btn btn-sm" href="<?= BASE_URL ?>/scan.php">▥ <?= t('bc.open_scanner') ?></a>
  </div>
</div>

<div class="bc-stats" id="bca-stats">
  <div class="bc-stat"><span class="bc-stat-n" id="bca-st-linked">–</span><span class="bc-stat-l"><?= t('bc.stat_linked') ?></span></div>
  <div class="bc-stat"><span class="bc-stat-n" id="bca-st-games">–</span><span class="bc-stat-l"><?= t('bc.stat_games') ?></span></div>
  <div class="bc-stat"><span class="bc-stat-n" id="bca-st-unknown">–</span><span class="bc-stat-l"><?= t('bc.stat_unknown') ?></span></div>
  <div class="bc-stat"><span class="bc-stat-n" id="bca-st-flagged">–</span><span class="bc-stat-l"><?= t('bc.stat_flagged') ?></span></div>
</div>

<section class="cp-card">
  <div class="log-chips bc-tabs" role="group" aria-label="<?= t('bc.admin_title') ?>">
    <?php foreach (['all', 'flagged', 'unknown', 'shared', 'missing'] as $tab): ?>
    <button type="button" class="log-chip" data-tab="<?= $tab ?>" aria-pressed="<?= $tab === 'all' ? 'true' : 'false' ?>">
      <?= t('bc.tab_'.$tab) ?> <span class="log-count" id="bca-n-<?= $tab ?>">0</span></button>
    <?php endforeach; ?>
  </div>
  <div class="bc-toolbar">
    <input type="search" id="bca-q" placeholder="<?= t('bc.admin_search') ?>" aria-label="<?= t('bc.admin_search') ?>">
    <select id="bca-sys" aria-label="<?= t('common.col.system') ?>">
      <option value="0"><?= t('bc.all_systems') ?></option>
      <?php foreach ($bcSystems as $sy): ?><option value="<?= (int)$sy['id'] ?>"><?= $h($sy['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <p class="ga-desc" id="bca-tab-help" style="margin:10px 0"></p>
  <div id="bca-list" aria-live="polite"></div>
  <div class="bc-more">
    <span class="sb-sub" id="bca-showing"></span>
    <button type="button" class="btn-ghost btn-sm" id="bca-more" hidden><?= t('bc.load_more') ?></button>
  </div>
</section>

<section class="cp-card" id="bc-settings">
  <h2><?= t('bc.settings_title') ?></h2>
  <form method="POST">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="action" value="save_barcode_settings">
    <div class="log-type-row log-master">
      <span class="log-type-text"><strong><?= t('bc.enabled') ?></strong><br><span class="sb-sub"><?= t('bc.enabled_help') ?></span></span>
      <label class="toggle log-switch"><input type="checkbox" role="switch" name="barcode_enabled" value="1" <?= barcodeEnabled() ? 'checked' : '' ?>
             aria-label="<?= t('bc.enabled') ?>"><span class="toggle-slider"></span></label>
    </div>
    <h3 class="log-h3"><?= t('bc.who_title') ?></h3>
    <div class="log-seg" role="radiogroup" aria-label="<?= t('bc.who_title') ?>">
      <?php foreach (BARCODE_WHO as $w): ?>
      <label><input type="radio" name="barcode_who" value="<?= $w ?>" <?= $w === $who ? 'checked' : '' ?> data-hint="<?= $h(tRaw('bc.who_hint_'.$w)) ?>"><span><?= t('bc.who_'.$w) ?></span></label>
      <?php endforeach; ?>
    </div>
    <p class="sb-sub" id="bca-who-hint" style="margin:6px 0 16px"><?= t('bc.who_hint_'.$who) ?></p>
    <div class="field" style="max-width:260px">
      <label for="bca-forget"><?= t('bc.forget_after') ?></label>
      <select id="bca-forget" name="barcode_unknown_days">
        <?php foreach (array_keys(BARCODE_UNKNOWN_DAYS) as $d): $d = (string)$d; ?>
        <option value="<?= $d ?>" <?= $d === $forget ? 'selected' : '' ?>><?= $d === '0' ? t('bc.forget_never') : t('bc.forget_days', ['n' => $d]) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-sm" type="submit" style="margin-top:12px"><?= t('common.save') ?></button>
  </form>
</section>

<section class="cp-card">
  <h2><?= t('bc.cam_title') ?></h2>
  <table class="admin-table bc-camcheck">
    <tbody>
      <tr><td><?= t('bc.cam_https') ?></td><td id="bca-cam-https">…</td></tr>
      <tr><td><?= t('bc.cam_policy') ?></td><td id="bca-cam-policy">…</td></tr>
      <tr><td><?= t('bc.cam_reader') ?></td><td id="bca-cam-reader">…</td></tr>
    </tbody>
  </table>
  <p class="ga-desc" id="bca-cam-fix" hidden style="margin:10px 0 0"><?= t('bc.cam_policy_fix') ?></p>
  <p class="ga-desc" style="margin:10px 0 0"><?= t('bc.cam_foot') ?></p>
</section>

<!-- CSV import -->
<div class="modal-backdrop" id="bca-import" role="dialog" aria-modal="true" aria-labelledby="bca-import-title">
  <div class="modal" style="max-width:640px">
    <div class="modal-header">
      <h3 id="bca-import-title"><?= t('bc.import_csv') ?></h3>
      <button class="btn-icon" type="button" data-close="bca-import" aria-label="<?= t('common.close') ?>">✕</button>
    </div>
    <div class="modal-body">
      <p class="ga-desc" style="margin:0"><?= t('bc.import_help') ?></p>
      <textarea id="bca-csv" rows="8" aria-label="<?= t('bc.import_csv') ?>" placeholder="barcode,system,title,edition,variant,note"></textarea>
      <div class="bc-actions">
        <label class="btn-outline prices-file" style="padding:8px 14px;font-size:.72rem;cursor:pointer">⬆ <?= t('prices.choose_file') ?>
          <input type="file" id="bca-file" accept=".csv,text/csv" hidden></label>
        <button class="btn-ghost btn-sm" type="button" id="bca-check"><?= t('bc.import_check') ?></button>
      </div>
      <div id="bca-import-result" aria-live="polite"></div>
    </div>
    <div class="modal-footer">
      <button class="btn-ghost btn-sm" type="button" data-close="bca-import"><?= t('common.cancel') ?></button>
      <button class="btn btn-sm" type="button" id="bca-import-go" disabled><?= t('bc.import_go') ?></button>
    </div>
  </div>
</div>

<!-- Edit one barcode row -->
<div class="modal-backdrop" id="bca-edit" role="dialog" aria-modal="true" aria-labelledby="bca-edit-title">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3 id="bca-edit-title"><?= t('bc.edit_title') ?></h3>
      <button class="btn-icon" type="button" data-close="bca-edit" aria-label="<?= t('common.close') ?>">✕</button>
    </div>
    <div class="modal-body">
      <div class="bc-big" id="bca-edit-code"></div>
      <div class="field"><span class="bc-label"><?= t('bc.col_game') ?></span><strong id="bca-edit-game"></strong></div>
      <div class="field">
        <label for="bca-edit-move"><?= t('bc.move_to') ?></label>
        <input type="search" id="bca-edit-move" autocomplete="off" placeholder="<?= t('bc.search_ph') ?>">
        <div class="bc-radio-list" id="bca-edit-results" role="radiogroup" aria-label="<?= t('bc.move_to') ?>"></div>
      </div>
      <div class="field"><label for="bca-edit-variant"><?= t('bc.variant_opt') ?></label><input type="text" id="bca-edit-variant" maxlength="100" placeholder="<?= t('bc.variant_any') ?>"></div>
      <div class="field"><label for="bca-edit-note"><?= t('bc.note_opt') ?></label><input type="text" id="bca-edit-note" maxlength="200"></div>
      <p class="bc-err-text" id="bca-edit-err" hidden></p>
    </div>
    <div class="modal-footer">
      <button class="btn-ghost btn-sm" type="button" data-close="bca-edit"><?= t('common.cancel') ?></button>
      <button class="btn btn-sm" type="button" id="bca-edit-save"><?= t('common.save') ?></button>
    </div>
  </div>
</div>
