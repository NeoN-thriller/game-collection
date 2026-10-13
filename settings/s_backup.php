<?php
/* SETTINGS › Backup: JSON export / import, photo zips per system */
if (!defined('IN_SETTINGS')) exit;

// Systems this user has photos for, with their current backup zip (if any)
purgeExpiredBackups();
$backupSysSt = db()->prepare("
    SELECT s.id, s.name, s.short_name, pc.photo_count,
           ub.token, ub.status, ub.file_size, ub.created_at, ub.expires_at,
           (ub.status = 'ready' AND ub.expires_at > NOW()) AS has_backup
    FROM systems s
    JOIN (SELECT g.system_id, COUNT(*) AS photo_count
          FROM copy_photos cp
          JOIN collection_entries ce ON ce.id = cp.entry_id
          JOIN games g ON g.id = ce.game_id
          WHERE ce.user_id = ?
          GROUP BY g.system_id) pc ON pc.system_id = s.id
    LEFT JOIN user_backups ub ON ub.system_id = s.id AND ub.user_id = ?
    WHERE s.active = 1
    ORDER BY s.sort_order
");
$backupSysSt->execute([$user['id'], $user['id']]);
$backupSystems = $backupSysSt->fetchAll();
?>
<section class="cp-card">
  <h2><?= t('settings.backup') ?></h2>
  <p class="export-desc"><?= t('settings.backup_desc') ?></p>
  <div class="export-row">
    <button class="btn btn-sm" onclick="exportData()">⬇ <?= t('settings.export') ?></button>
    <label class="btn btn-sm" style="cursor:pointer">⬆ <?= t('settings.import') ?>
      <input type="file" id="import-file" accept=".json" style="display:none" onchange="importData(event)">
    </label>
  </div>
  <p style="font-size:.68rem;color:var(--muted);margin-top:10px">⚠ <?= t('settings.import_warn') ?></p>
</section>

<section class="cp-card">
  <h2><?= t('settings.img_backups') ?></h2>
  <p class="export-desc"><?= t('settings.img_backups_desc') ?></p>
  <?php if (!$backupSystems): ?>
  <p style="font-size:.78rem;color:var(--muted)"><?= t('settings.no_photos') ?></p>
  <?php else: ?>
  <table class="admin-table" style="margin-top:10px">
    <thead>
      <tr><th><?= t('common.system') ?></th><th><?= t('settings.photos') ?></th><th><?= t('settings.zip_size') ?></th><th><?= t('settings.generated') ?></th><th><?= t('settings.expires') ?></th><th><?= t('settings.actions') ?></th></tr>
    </thead>
    <tbody>
    <?php foreach ($backupSystems as $bs):
      $hasBackup = (bool)$bs['has_backup'];
    ?>
    <tr>
      <td><strong><?= htmlspecialchars($bs['short_name']) ?></strong><br><span style="font-size:.65rem;color:var(--muted)"><?= htmlspecialchars($bs['name']) ?></span></td>
      <td><?= (int)$bs['photo_count'] ?></td>
      <td><?= $hasBackup ? fmtNum($bs['file_size']/1024/1024, 1).' MB' : '—' ?></td>
      <td><?= $hasBackup ? fmtDate($bs['created_at'], true) : ($bs['status'] === 'failed' ? '<span style="color:var(--red)">'.t('settings.failed').'</span>' : '—') ?></td>
      <td><?= $hasBackup ? fmtDate($bs['expires_at'], true) : '—' ?></td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <button class="btn btn-sm" onclick="generateBackup(this)"
                data-system-id="<?= (int)$bs['id'] ?>" data-name="<?= htmlspecialchars($bs['short_name']) ?>">
          <?= t($hasBackup ? 'settings.regenerate' : 'settings.generate') ?>
        </button>
        <?php if ($hasBackup): ?>
        <a href="<?= BASE_URL ?>/api/backup_download.php?token=<?= htmlspecialchars($bs['token']) ?>" class="btn-ghost" style="padding:7px 12px;font-size:.72rem;text-decoration:none"><?= t('common.download') ?></a>
        <button class="btn-ghost" style="color:var(--red)" onclick="deleteBackup(this)" data-token="<?= htmlspecialchars($bs['token']) ?>"><?= t('common.delete') ?></button>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
