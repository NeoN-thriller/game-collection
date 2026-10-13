<?php
/* SETTINGS › IGDB (admin): developer, publisher, genres, release date and ratings from igdb.com for the game drawer.
   Per system: the IGDB platform, Match (games never looked up) and Refresh (matched games again). Below: the games
   per state, where an admin picks the right IGDB game for the ones the automatic match wasn't sure about.
   assets/js/igdb-admin.js reads api/igdb.php. See igdb.php. */
if (!defined('IN_SETTINGS')) exit;

$h = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES);
try {
    $tablesOk = (bool)db()->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_igdb'")->fetchColumn();
} catch (PDOException) { $tablesOk = true; }
$igSystems = db()->query("SELECT id, name FROM systems WHERE active = 1 ORDER BY sort_order, id")->fetchAll();
?>
<?php if (!$tablesOk): ?>
<div class="cp-msg cp-msg--err"><?= t('igdb.no_tables') ?></div>
<?php endif; ?>

<?php if (!igdbConfigured()): ?>
<section class="cp-card">
  <h2><?= t('igdb.setup_title') ?></h2>
  <p class="ga-desc"><?= t('igdb.setup_intro') ?></p>
  <ol class="ig-steps">
    <li><?= t('igdb.setup_1') ?></li>
    <li><?= t('igdb.setup_2') ?></li>
    <li><?= t('igdb.setup_3') ?></li>
    <li><?= t('igdb.setup_4') ?></li>
  </ol>
  <pre class="ig-code">define('IGDB_CLIENT_ID',     '…');
define('IGDB_CLIENT_SECRET', '…');</pre>
  <p class="ga-desc" style="margin:0"><?= t('igdb.setup_terms') ?></p>
</section>
<?php else: ?>
<p class="ga-desc" style="margin:0 0 14px"><?= t('igdb.intro') ?></p>
<?php endif; ?>

<section class="cp-card">
  <h2><?= t('igdb.systems_title') ?></h2>
  <p class="ga-desc" style="margin-top:0"><?= t('igdb.systems_help') ?></p>
  <!-- A running Match / Refresh (assets/js/igdb-admin.js) -->
  <div class="ig-run" id="ig-progress" hidden>
    <div class="ig-run-head">
      <span class="ig-spin" id="ig-run-spin" aria-hidden="true"></span>
      <strong id="ig-run-title"></strong>
      <span class="sb-sub" id="ig-run-time"></span>
      <button type="button" class="btn-ghost btn-sm" data-a="stop" id="ig-run-stop"><?= t('igdb.stop') ?></button>
    </div>
    <div class="progress-track"><div class="progress-fill" id="ig-run-fill" style="width:0"></div></div>
    <div class="sb-sub" id="ig-run-text" role="status" aria-live="polite"></div>
  </div>
  <div id="ig-systems"><p class="ga-desc"><?= t('igdb.loading') ?></p></div>
</section>

<section class="cp-card">
  <h2><?= t('igdb.games_title') ?></h2>
  <div class="bc-toolbar">
    <select id="ig-sys" aria-label="<?= t('common.col.system') ?>">
      <?php foreach ($igSystems as $sy): ?><option value="<?= (int)$sy['id'] ?>"><?= $h($sy['name']) ?></option><?php endforeach; ?>
    </select>
    <input type="search" id="ig-q" placeholder="<?= t('igdb.search_list') ?>" aria-label="<?= t('igdb.search_list') ?>">
  </div>
  <div class="log-chips" role="group" aria-label="<?= t('igdb.games_title') ?>" style="margin-top:10px">
    <?php foreach (['review', 'matched', 'none', 'pending', 'all'] as $tab): ?>
    <button type="button" class="log-chip" data-tab="<?= $tab ?>" aria-pressed="<?= $tab === 'review' ? 'true' : 'false' ?>">
      <?= t('igdb.tab_'.$tab) ?> <span class="log-count" id="ig-n-<?= $tab ?>">0</span></button>
    <?php endforeach; ?>
  </div>
  <div class="ig-tab-head">
    <p class="ga-desc" id="ig-tab-help" style="margin:10px 0"></p>
    <button type="button" class="btn-ghost btn-sm" id="ig-redo" hidden><?= t('igdb.redo_review') ?></button>
  </div>
  <div id="ig-list" aria-live="polite"></div>
  <div class="bc-more">
    <span class="sb-sub" id="ig-showing"></span>
    <button type="button" class="btn-ghost btn-sm" id="ig-more" hidden><?= t('igdb.load_more') ?></button>
  </div>
</section>

<p class="sb-sub"><?= t('igdb.credit') ?> <a href="https://www.igdb.com" target="_blank" rel="noopener">IGDB.com</a></p>

<!-- Pick the IGDB platform of a system -->
<div class="modal-backdrop" id="ig-plat" role="dialog" aria-modal="true" aria-labelledby="ig-plat-title">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3 id="ig-plat-title"><?= t('igdb.platform_title') ?></h3>
      <button class="btn-icon" type="button" data-close="ig-plat" aria-label="<?= t('common.close') ?>">✕</button>
    </div>
    <div class="modal-body">
      <p class="ga-desc" style="margin-top:0" id="ig-plat-sys"></p>
      <div class="bc-inline">
        <input type="search" id="ig-plat-q" autocomplete="off" placeholder="<?= t('igdb.platform_ph') ?>" aria-label="<?= t('igdb.platform_ph') ?>">
        <button type="button" class="btn-ghost btn-sm" id="ig-plat-go"><?= t('igdb.search') ?></button>
      </div>
      <div class="ig-results" id="ig-plat-results"></div>
      <p class="bc-err-text" id="ig-plat-err" hidden></p>
      <p class="sb-sub" style="margin:10px 0 0"><?= t('igdb.platform_change_note') ?></p>
    </div>
    <div class="modal-footer">
      <button class="btn-ghost btn-sm" type="button" id="ig-plat-clear"><?= t('igdb.platform_clear') ?></button>
      <button class="btn-ghost btn-sm" type="button" data-close="ig-plat"><?= t('common.cancel') ?></button>
    </div>
  </div>
</div>

<!-- Pick the IGDB game of one catalogue game -->
<div class="modal-backdrop" id="ig-pick" role="dialog" aria-modal="true" aria-labelledby="ig-pick-title">
  <div class="modal" style="max-width:600px">
    <div class="modal-header">
      <h3 id="ig-pick-title"><?= t('igdb.pick_title') ?></h3>
      <button class="btn-icon" type="button" data-close="ig-pick" aria-label="<?= t('common.close') ?>">✕</button>
    </div>
    <div class="modal-body">
      <p class="ga-desc" style="margin-top:0"><strong id="ig-pick-game"></strong></p>
      <div class="bc-inline">
        <input type="search" id="ig-pick-q" autocomplete="off" placeholder="<?= t('igdb.pick_ph') ?>" aria-label="<?= t('igdb.pick_ph') ?>">
        <button type="button" class="btn-ghost btn-sm" id="ig-pick-go"><?= t('igdb.search') ?></button>
      </div>
      <p class="sb-sub" style="margin:6px 0 0"><?= t('igdb.pick_hint') ?></p>
      <label class="ig-check"><input type="checkbox" id="ig-pick-any"> <?= t('igdb.any_platform') ?></label>
      <div class="ig-results" id="ig-pick-results"></div>
      <p class="bc-err-text" id="ig-pick-err" hidden></p>
    </div>
    <div class="modal-footer">
      <button class="btn-ghost btn-sm" type="button" id="ig-pick-none"><?= t('igdb.not_on_igdb') ?></button>
      <button class="btn-ghost btn-sm" type="button" data-close="ig-pick"><?= t('common.cancel') ?></button>
    </div>
  </div>
</div>
