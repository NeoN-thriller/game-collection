<?php
/* SETTINGS › Activity log (admin): everything that changed on the site, with filters
   (assets/js/activity.js reads api/activity.php), and the Log settings card (save_log_settings, purge_log). */
if (!defined('IN_SETTINGS')) exit;

$h        = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES);
$logUsers = db()->query("SELECT id, username FROM users ORDER BY username")->fetchAll();
$stats    = db()->query("SELECT COUNT(*) AS n, UNIX_TIMESTAMP(MIN(created_at)) AS oldest FROM activity_log")->fetch();
$last30   = array_fill_keys(LOG_CATEGORIES, 0);
foreach (db()->query("SELECT category, COUNT(*) AS n FROM activity_log WHERE created_at >= NOW() - INTERVAL 30 DAY GROUP BY category")->fetchAll() as $r) {
    $last30[$r['category']] = (int)$r['n'];
}
// Entries each retention choice would remove at the next clean-up (for the hint under the buttons)
$olderThan = [];
foreach (LOG_RETENTIONS as $k => $days) {
    $k = (string)$k;
    if (!$days) { $olderThan[$k] = 0; continue; }
    $st = db()->prepare("SELECT COUNT(*) FROM activity_log WHERE created_at < NOW() - INTERVAL ? DAY");
    $st->execute([$days]);
    $olderThan[$k] = (int)$st->fetchColumn();
}
// Size in the database: information_schema isn't readable on every host
try {
    $logSize = db()->query("SELECT DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log'")->fetchColumn();
} catch (PDOException) { $logSize = false; }
$retention = (string)(int)setting('log_retention_days');
if (!isset(LOG_RETENTIONS[$retention])) $retention = '90';
$ipMode    = in_array(setting('log_ip_mode'), LOG_IP_MODES, true) ? setting('log_ip_mode') : 'security';
$defaultF  = activityFiltersFromRequest(['period' => '30']);
$tz        = setting('timezone') !== '' ? setting('timezone') : date_default_timezone_get();
$keepTxt   = fn(string $k) => tRaw('log.keep.'.($k === '0' ? 'forever' : $k));
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE;
?>
<script>CP.log = <?= json_encode([
    'page'  => activityPage($defaultF, $user),
    'users' => array_map(fn($u) => ['id' => (int)$u['id'], 'name' => $u['username']], $logUsers),
    'cats'  => LOG_CATEGORIES,
], $jsonFlags) ?>;</script>

<p class="ga-desc log-intro">
  <?= t($retention === '0' ? 'log.intro_forever' : 'log.intro', ['n' => $keepTxt($retention)]) ?>
  <a href="#log-settings"><?= t('log.settings_link') ?> ↓</a>
</p>

<?php if (setting('log_enabled') !== '1'): ?>
<div class="cp-msg cp-msg--err"><?= t('log.off_notice') ?> <a href="#log-settings"><?= t('log.settings_link') ?> ↓</a></div>
<?php endif; ?>

<section class="cp-card log-toolbar" aria-label="<?= t('log.filters') ?>">
  <div class="log-row">
    <input type="search" id="log-q" class="log-search" placeholder="<?= t('log.search_ph') ?>" aria-label="<?= t('log.search_ph') ?>">
    <select id="log-period" class="log-select" aria-label="<?= t('log.period_label') ?>">
      <?php foreach (array_keys(LOG_PERIODS) as $p): $p = (string)$p;   // PHP made '30' an int key ?>
      <option value="<?= $p ?>" <?= $p === '30' ? 'selected' : '' ?>><?= t('log.period.'.$p) ?></option>
      <?php endforeach; ?>
    </select>
    <a class="btn-ghost log-btn" id="log-export" href="<?= BASE_URL ?>/api/activity_export.php">⬇ <?= t('log.export_csv') ?></a>
  </div>
  <div class="log-filter">
    <span class="log-filter-label"><?= t('log.filter_user') ?></span>
    <div class="log-chips" id="log-users"></div>
  </div>
  <div class="log-filter">
    <span class="log-filter-label"><?= t('log.filter_type') ?></span>
    <div class="log-chips" id="log-types"></div>
  </div>
  <div class="log-filter">
    <span class="log-filter-label"><?= t('log.filter_show') ?></span>
    <div class="log-chips">
      <button type="button" class="log-chip log-chip-admin" id="log-admin" aria-pressed="false"><?= t('log.admin_only') ?> <span class="log-count" id="log-admin-n">0</span></button>
      <span class="sb-sub"><?= t('log.admin_only_help') ?></span>
    </div>
  </div>
</section>

<p class="log-result"><span id="log-result"></span> · <?= t('log.times_in', ['tz' => $tz]) ?></p>

<div id="log-list" class="log-list" aria-live="polite"></div>
<div class="cp-card log-empty" id="log-empty" hidden>
  <h2><?= t('log.empty_title') ?></h2>
  <p class="ga-desc"><?= t('log.empty_hint') ?></p>
  <button type="button" class="btn btn-sm" id="log-clear"><?= t('log.clear_filters') ?></button>
</div>
<div class="log-foot">
  <span class="sb-sub"><?= $stats['n'] ? t('log.oldest', ['date' => fmtDate((int)$stats['oldest']), 'n' => fmtNum($stats['n'])]) : t('log.no_entries') ?></span>
  <button type="button" class="btn-ghost log-btn" id="log-more" hidden><?= t('log.load_older') ?></button>
</div>

<!-- ── Log settings ── -->
<section class="cp-card log-settings" id="log-settings">
  <h2><?= t('log.settings_title') ?></h2>
  <form method="POST">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="action" value="save_log_settings">

    <div class="log-type-row log-master">
      <span class="log-type-text"><strong><?= t('log.set_enabled') ?></strong><br><span class="sb-sub"><?= t('log.set_enabled_help') ?></span></span>
      <label class="toggle log-switch"><input type="checkbox" role="switch" name="log_enabled" value="1" <?= setting('log_enabled') === '1' ? 'checked' : '' ?>
             aria-label="<?= t('log.set_enabled') ?>"><span class="toggle-slider"></span></label>
    </div>

    <h3 class="log-h3"><?= t('log.set_record') ?></h3>
    <p class="ga-desc"><?= t('log.set_record_help') ?></p>
    <?php foreach (LOG_CATEGORIES as $c): ?>
    <div class="log-type-row">
      <span class="log-sq log-c-<?= $c ?>" aria-hidden="true"></span>
      <span class="log-type-text">
        <strong><?= t('log.cat.'.$c) ?></strong> <span class="sb-sub">· <?= t('log.set_last30', ['n' => fmtNum($last30[$c])]) ?></span><br>
        <span class="sb-sub"><?= t('log.cat_desc.'.$c) ?></span>
      </span>
      <label class="toggle log-switch"><input type="checkbox" role="switch" name="log_on_<?= $c ?>" value="1" <?= setting('log_on_'.$c) === '1' ? 'checked' : '' ?>
             aria-label="<?= t('log.cat.'.$c) ?>"><span class="toggle-slider"></span></label>
    </div>
    <?php if ($c === 'accounts'): ?>
    <div class="log-type-row log-sub-row">
      <span class="log-type-text"><span><?= t('log.set_logins') ?></span><br><span class="sb-sub"><?= t('log.set_logins_help') ?></span></span>
      <label class="toggle log-switch"><input type="checkbox" role="switch" name="log_logins" value="1" <?= setting('log_logins') === '1' ? 'checked' : '' ?>
             aria-label="<?= t('log.set_logins') ?>"><span class="toggle-slider"></span></label>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>

    <h3 class="log-h3"><?= t('log.set_keep') ?></h3>
    <div class="log-seg" role="radiogroup" aria-label="<?= t('log.set_keep') ?>">
      <?php foreach (array_keys(LOG_RETENTIONS) as $k): $k = (string)$k;   // PHP made '30' an int key ?>
      <label><input type="radio" name="log_retention_days" value="<?= $k ?>" <?= $k === $retention ? 'checked' : '' ?>
             data-hint="<?= $h($k === '0' ? tRaw('log.keep_hint.forever') : tRaw('log.keep_hint.days', ['n' => fmtNum($olderThan[$k]), 'period' => $keepTxt($k)])) ?>"><span><?= $h($keepTxt($k)) ?></span></label>
      <?php endforeach; ?>
    </div>
    <p class="sb-sub log-hint" id="log-keep-hint"></p>

    <h3 class="log-h3"><?= t('log.set_ip') ?></h3>
    <div class="log-seg" role="radiogroup" aria-label="<?= t('log.set_ip') ?>">
      <?php foreach (LOG_IP_MODES as $m): ?>
      <label><input type="radio" name="log_ip_mode" value="<?= $m ?>" <?= $m === $ipMode ? 'checked' : '' ?> data-hint="<?= $h(tRaw('log.ip_hint.'.$m)) ?>"><span><?= t('log.ip.'.$m) ?></span></label>
      <?php endforeach; ?>
    </div>
    <p class="sb-sub log-hint" id="log-ip-hint"></p>

    <div class="log-type-row" style="margin-top:18px">
      <span class="log-type-text"><strong><?= t('log.set_users_see') ?></strong><br><span class="sb-sub"><?= t('log.set_users_see_help') ?></span></span>
      <label class="toggle log-switch"><input type="checkbox" role="switch" name="log_user_history" value="1" <?= setting('log_user_history') === '1' ? 'checked' : '' ?>
             aria-label="<?= t('log.set_users_see') ?>"><span class="toggle-slider"></span></label>
    </div>

    <button class="btn btn-sm" type="submit" style="margin-top:16px"><?= t('common.save') ?></button>
  </form>

  <h3 class="log-h3"><?= t('log.storage') ?></h3>
  <table class="admin-table" style="width:auto">
    <tbody>
      <tr><td><?= t('log.stor_entries') ?></td><td><?= fmtNum($stats['n']) ?></td></tr>
      <?php if ($logSize !== false && $logSize !== null): ?><tr><td><?= t('log.stor_size') ?></td><td><?= siteBackupSize((int)$logSize) ?></td></tr><?php endif; ?>
      <tr><td><?= t('log.stor_oldest') ?></td><td><?= $stats['n'] ? fmtDate((int)$stats['oldest'], true) : '—' ?></td></tr>
    </tbody>
  </table>
  <div class="log-row" style="margin-top:12px">
    <a class="btn-ghost log-btn" href="<?= BASE_URL ?>/api/activity_export.php?all=1">⬇ <?= t('log.export_all') ?></a>
    <?php $purgeN = $retention === '0' ? 0 : $olderThan[$retention]; ?>
    <form method="POST" onsubmit="return confirm(<?= $h(json_encode(tRaw('log.purge_confirm', ['n' => fmtNum($purgeN), 'period' => $keepTxt($retention)]))) ?>)">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="purge_log">
      <button class="btn-ghost log-btn log-danger" type="submit" <?= $purgeN ? '' : 'disabled' ?>>
        <?= $purgeN ? t('log.purge_btn', ['n' => fmtNum($purgeN), 'period' => $keepTxt($retention)]) : t('log.purge_none') ?>
      </button>
    </form>
  </div>
  <p class="sb-sub" style="margin-top:10px"><?= t('log.purge_note') ?></p>
</section>
