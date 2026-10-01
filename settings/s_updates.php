<?php
/* SETTINGS › Updates (admin): installed version vs the latest GitHub release, the release zip
   fetched to the server, and the database updates (migrations/). Plain POST forms (data-ajax="off"),
   so results and errors stay on the page instead of a short toast. */
if (!defined('IN_SETTINGS')) exit;

$upd = updateCache();
// Opening this page asks GitHub when the last check is a day old (a POST here has just done its own work)
if (updateStale($upd) && $_SERVER['REQUEST_METHOD'] !== 'POST') $upd = updateCheckNow();
$release  = $upd['release'] ?? null;
$newer    = updateAvailable($upd);
$zips     = updateZips();
$migFiles = migrationFiles();
$applied  = appliedMigrations();
$pending  = array_values(array_diff($migFiles, $applied));
$h        = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES);
$hidden   = fn(string $action) => '<input type="hidden" name="csrf" value="'.csrf().'"><input type="hidden" name="action" value="'.$action.'">';
?>
<section class="cp-card">
  <h2><?= t('updates.version_title') ?></h2>
  <p class="ga-desc"><?= t('updates.version_desc') ?></p>
  <table class="admin-table" style="width:auto;margin:10px 0 14px">
    <tbody>
      <tr><td><?= t('updates.installed') ?></td><td><strong><?= $h(APP_VERSION) ?></strong></td></tr>
      <tr><td><?= t('updates.latest') ?></td><td>
        <?php if ($release): ?><strong><?= $h($release['version']) ?></strong><?= $release['published'] ? ' · '.fmtDate($release['published']) : '' ?>
        <?php elseif ($upd && $upd['ok']): ?><?= t('updates.none_published') ?>
        <?php else: ?>—<?php endif; ?>
      </td></tr>
      <tr><td><?= t('updates.checked') ?></td><td>
        <?= $upd ? fmtDate((int)$upd['checked'], true) : t('updates.never') ?>
        <?php if ($upd && !$upd['ok']): ?><br><span style="color:var(--red)"><?= t('updates.err_check', ['error' => $upd['error']]) ?></span><?php endif; ?>
      </td></tr>
    </tbody>
  </table>

  <?php if ($newer): ?>
  <p style="font-size:.85rem;color:var(--accent);margin-bottom:12px">★ <?= t('updates.status_new', ['version' => $newer['version']]) ?></p>
  <?php elseif ($release && version_compare($release['version'], APP_VERSION, '<')): ?>
  <p style="font-size:.8rem;color:var(--muted);margin-bottom:12px"><?= t('updates.status_ahead') ?></p>
  <?php elseif ($release): ?>
  <p style="font-size:.8rem;color:var(--green);margin-bottom:12px">✓ <?= t('updates.status_current') ?></p>
  <?php endif; ?>

  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <form method="POST" data-ajax="off"><?= $hidden('check_updates') ?><button class="btn btn-sm" type="submit"><?= t('updates.check_now') ?></button></form>
    <?php if ($release && str_starts_with($release['url'], 'https://github.com/')): ?>
    <a href="<?= $h($release['url']) ?>" target="_blank" rel="noopener" class="btn-ghost" style="padding:7px 12px;font-size:.72rem;text-decoration:none"><?= t('updates.on_github') ?> ↗</a>
    <?php endif; ?>
  </div>

  <?php if ($release && trim($release['notes']) !== ''): ?>
  <details style="margin-top:16px">
    <summary style="cursor:pointer;font-size:.78rem"><?= t('updates.notes', ['version' => $release['version']]) ?></summary>
    <pre style="white-space:pre-wrap;font:inherit;font-size:.75rem;color:var(--text2);margin-top:10px"><?= $h($release['notes']) ?></pre>
  </details>
  <?php endif; ?>
</section>

<?php if ($release): ?>
<section class="cp-card">
  <h2><?= t('updates.dl_title') ?></h2>
  <p class="ga-desc"><?= t('updates.dl_desc') ?></p>
  <form method="POST" data-ajax="off" style="margin:10px 0 14px"><?= $hidden('fetch_update') ?>
    <button class="btn btn-sm" type="submit">⬇ <?= t('updates.dl_fetch', ['version' => $release['version']]) ?></button>
  </form>

  <?php if ($zips): ?>
  <table class="admin-table">
    <thead><tr><th><?= t('updates.dl_file') ?></th><th><?= t('settings.zip_size') ?></th><th><?= t('updates.dl_date') ?></th><th><?= t('settings.actions') ?></th></tr></thead>
    <tbody>
    <?php foreach ($zips as $z): ?>
    <tr>
      <td><?= $h($z['name']) ?></td>
      <td><?= fmtNum($z['size'] / 1024 / 1024, 1) ?> MB</td>
      <td><?= fmtDate($z['time'], true) ?></td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/api/update_file.php?f=<?= rawurlencode($z['name']) ?>" class="btn-ghost" style="padding:7px 12px;font-size:.72rem;text-decoration:none"><?= t('common.download') ?></a>
        <form method="POST" data-ajax="off"><?= $hidden('delete_update') ?><input type="hidden" name="file" value="<?= $h($z['name']) ?>">
          <button class="btn-ghost" style="color:var(--red)" type="submit"><?= t('common.delete') ?></button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p style="font-size:.78rem;color:var(--muted)"><?= t('updates.dl_none') ?></p>
  <?php endif; ?>

  <p style="font-size:.8rem;margin:18px 0 6px"><strong><?= t('updates.steps_title') ?></strong></p>
  <ol style="font-size:.76rem;line-height:1.7;padding-left:20px">
    <?php foreach ([1, 2, 3, 4] as $i): ?><li><?= t('updates.step'.$i) ?></li><?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>

<section class="cp-card">
  <h2><?= t('updates.db_title') ?></h2>
  <p class="ga-desc"><?= t('updates.db_desc') ?></p>

  <?php if ($pending): ?>
  <div class="cp-msg cp-msg--err" style="margin:10px 0"><?= t('updates.db_pending', ['n' => count($pending)]) ?></div>
  <p style="font-size:.74rem;color:var(--muted);margin-bottom:12px">⚠ <?= t('updates.db_backup') ?></p>
  <form method="POST" data-ajax="off" onsubmit="return confirm(<?= $h(json_encode(tRaw('updates.db_confirm'))) ?>)"><?= $hidden('run_migrations') ?>
    <button class="btn btn-sm" type="submit"><?= t('updates.db_run') ?></button>
  </form>
  <?php else: ?>
  <p style="font-size:.8rem;color:var(--green);margin:10px 0">✓ <?= t('updates.db_current') ?></p>
  <?php endif; ?>

  <?php if ($migFiles): ?>
  <details style="margin-top:14px"<?= $pending ? ' open' : '' ?>>
    <summary style="cursor:pointer;font-size:.78rem"><?= t('updates.db_list', ['n' => count($migFiles)]) ?></summary>
    <table class="admin-table" style="width:auto;margin-top:8px">
      <tbody>
      <?php foreach ($migFiles as $f): $done = in_array($f, $applied, true); ?>
      <tr>
        <td><?= $h($f) ?></td>
        <td style="color:var(--<?= $done ? 'green' : ($f === ($migrationResult['failed'] ?? null) ? 'red' : 'accent') ?>)">
          <?= $done ? '✓ '.t('updates.db_applied') : ($f === ($migrationResult['failed'] ?? null) ? '✕ '.t('updates.db_failed_short') : t('updates.db_waiting')) ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php endif; ?>
</section>
