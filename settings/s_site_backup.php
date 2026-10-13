<?php
/* SETTINGS › Site Backup (admin): full backups of the database and the site's files, kept on the
   server (site_backup.php). Making one and deleting one go through api/site_backup.php (site-backup.js),
   the schedule is a normal settings form. */
if (!defined('IN_SETTINGS')) exit;

siteBackupTable();
siteBackupAdopt();   // zips left in the folder without a row (e.g. after restoring the database)
siteBackupPrune();
$sbEst     = siteBackupEstimate();
$sbBackups = db()->query("SELECT sb.*, u.username FROM site_backups sb LEFT JOIN users u ON u.id = sb.created_by
                          ORDER BY sb.created_at DESC, sb.id DESC")->fetchAll();
$sbRunning = null;
$sbTotal   = 0;
foreach ($sbBackups as $b) {
    if ($b['status'] === 'running' && !$sbRunning) $sbRunning = siteBackupProgress($b);
    if ($b['status'] === 'ready') $sbTotal += (int)$b['file_size'];
}
$sbReady   = count(array_filter($sbBackups, fn($b) => $b['status'] === 'ready'));
$sbFree    = is_dir(SITE_BACKUP_DIR) ? @disk_free_space(SITE_BACKUP_DIR) : @disk_free_space(BACKUP_DIR);
$sbAuto    = siteBackupParts(setting('backup_parts'));
$h         = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES);
$sbPartsTx = fn(string $parts) => implode(', ', array_map(fn($p) => t('sbackup.part_'.$p), siteBackupParts($parts)));
$sbEstTx   = fn(string $p) => $p === 'db'
    ? t('sbackup.est_db', ['size' => siteBackupSize($sbEst['db']['bytes'])])
    : tn('sbackup.est_files', $sbEst[$p]['files'], ['size' => siteBackupSize($sbEst[$p]['bytes'])]);
?>
<script>CP.sbRunning = <?= json_encode($sbRunning, JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>

<section class="cp-card">
  <h2><?= t('sbackup.make_title') ?></h2>
  <p class="ga-desc"><?= t('sbackup.make_desc') ?></p>
  <div class="sb-parts" id="sb-parts">
    <?php foreach (SITE_BACKUP_PARTS as $p): ?>
    <label class="sb-check">
      <input type="checkbox" value="<?= $p ?>" checked>
      <span><strong><?= t('sbackup.part_'.$p) ?></strong> <span class="sb-est">· <?= $sbEstTx($p) ?></span><br>
        <span class="sb-sub"><?= t('sbackup.part_'.$p.'_desc') ?></span></span>
    </label>
    <?php endforeach; ?>
  </div>
  <button class="btn btn-sm" id="sb-start" type="button" onclick="sbStart()"<?= $sbRunning ? ' disabled' : '' ?>><?= t('sbackup.start') ?></button>
  <div class="sb-progress" id="sb-progress" hidden>
    <div class="progress-track"><div class="progress-fill" id="sb-bar" style="width:0"></div></div>
    <p class="sb-sub" id="sb-text"></p>
    <p class="sb-sub"><?= t('sbackup.keep_open') ?></p>
  </div>
</section>

<section class="cp-card">
  <h2><?= t('sbackup.list_title') ?></h2>
  <?php if (!$sbBackups): ?>
  <p style="font-size:.78rem;color:var(--muted)"><?= t('sbackup.none') ?></p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="admin-table">
    <thead><tr>
      <th><?= t('sbackup.col_date') ?></th><th><?= t('sbackup.col_kind') ?></th><th><?= t('sbackup.col_contents') ?></th>
      <th><?= t('sbackup.col_size') ?></th><th><?= t('sbackup.col_by') ?></th><th><?= t('settings.actions') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($sbBackups as $b): ?>
    <tr>
      <td style="white-space:nowrap"><?= fmtDate($b['created_at'], true) ?></td>
      <td><?= t('sbackup.kind_'.$b['trigger_type']) ?></td>
      <td><?= $sbPartsTx($b['parts']) ?>
        <?php if ($b['status'] === 'ready' && (int)$b['missing_count']): ?><br><span class="sb-sub"><?= tn('sbackup.missing', (int)$b['missing_count']) ?></span><?php endif; ?>
      </td>
      <td style="white-space:nowrap">
        <?php if ($b['status'] === 'ready'): ?><?= siteBackupSize($b['file_size']) ?>
        <?php elseif ($b['status'] === 'running'): ?><span style="color:var(--accent)" data-sb-status="<?= (int)$b['id'] ?>"><?= t('sbackup.status_running') ?></span>
        <?php else: ?><span style="color:var(--red)" title="<?= $h($b['error']) ?>"><?= t('sbackup.status_failed') ?></span><?php endif; ?>
      </td>
      <td><?= $b['username'] !== null ? $h($b['username']) : '—' ?></td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if ($b['status'] === 'ready'): ?>
        <a href="<?= BASE_URL ?>/api/site_backup_download.php?id=<?= (int)$b['id'] ?>" class="btn-ghost" style="padding:7px 12px;font-size:.72rem;text-decoration:none"><?= t('common.download') ?></a>
        <?php endif; ?>
        <button class="btn-ghost" style="color:var(--red)" type="button" onclick="sbDelete(<?= (int)$b['id'] ?>, <?= $b['status'] === 'running' ? 'true' : 'false' ?>)"><?= t('common.delete') ?></button>
      </td>
    </tr>
    <?php if ($b['status'] === 'failed' && $b['error'] !== null && $b['error'] !== ''): ?>
    <tr><td colspan="6" class="sb-sub" style="color:var(--red);padding-top:0">✕ <?= $h($b['error']) ?></td></tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <p class="sb-sub" style="margin-top:10px">
    <?= tn('sbackup.total', $sbReady, ['size' => siteBackupSize($sbTotal)]) ?>
    <?php if ($sbFree !== false): ?> · <?= t('sbackup.free', ['size' => siteBackupSize($sbFree)]) ?><?php endif; ?>
  </p>
</section>

<section class="cp-card">
  <h2><?= t('sbackup.auto_title') ?></h2>
  <p class="ga-desc"><?= t('sbackup.auto_desc') ?></p>
  <form method="POST">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="action" value="save_backup_settings">
    <div class="sf-row" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
      <div class="field" style="width:200px"><label for="sb-sched"><?= t('sbackup.schedule') ?></label>
        <select name="backup_schedule" id="sb-sched">
          <?php foreach (array_keys(SITE_BACKUP_SCHEDULES) as $k): ?>
          <option value="<?= $k ?>" <?= setting('backup_schedule') === $k ? 'selected' : '' ?>><?= t('sbackup.sched_'.$k) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field" style="width:120px"><label for="sb-keep"><?= t('sbackup.keep') ?></label>
        <input type="number" name="backup_keep" id="sb-keep" min="1" max="50" value="<?= max(1, (int)setting('backup_keep')) ?>"></div>
    </div>
    <p class="sb-sub" style="margin:-4px 0 12px"><?= t('sbackup.keep_hint') ?></p>
    <p style="font-size:.78rem;margin-bottom:6px"><?= t('sbackup.auto_parts') ?></p>
    <div class="sb-parts">
      <?php foreach (SITE_BACKUP_PARTS as $p): ?>
      <label class="sb-check"><input type="checkbox" name="backup_parts[]" value="<?= $p ?>" <?= in_array($p, $sbAuto, true) ? 'checked' : '' ?>>
        <span><?= t('sbackup.part_'.$p) ?></span></label>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-sm" type="submit"><?= t('common.save') ?></button>
  </form>
  <p class="sb-sub" style="margin-top:12px"><?= t('sbackup.update_note') ?></p>
</section>

<section class="cp-card">
  <h2><?= t('sbackup.restore_title') ?></h2>
  <p class="ga-desc"><?= t('sbackup.restore_desc') ?></p>
  <ol style="font-size:.76rem;line-height:1.7;padding-left:20px">
    <?php foreach ([1, 2, 3, 4, 5] as $i): ?><li><?= t('sbackup.restore'.$i) ?></li><?php endforeach; ?>
  </ol>
</section>
