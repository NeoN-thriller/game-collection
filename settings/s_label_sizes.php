<?php
/* SETTINGS › Label sizes (admin): the site's sticker sizes (users can add sizes of their own in Label templates) */
if (!defined('IN_SETTINGS')) exit;

$sizes = db()->query("
    SELECT s.*, (SELECT COUNT(*) FROM label_templates t WHERE t.size_id = s.id) AS used
    FROM label_sizes s WHERE s.user_id IS NULL ORDER BY s.sort_order, s.id
")->fetchAll();
$fmtMm = fn($v) => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
?>
<section class="cp-card">
  <h2><?= t('lbl.site_sizes') ?></h2>
  <p class="export-desc"><?= t('lbl.site_sizes_desc') ?></p>
  <table class="admin-table" id="ls-table">
    <thead><tr><th><?= t('lbl.size_name') ?></th><th><?= t('lbl.width_mm') ?></th><th><?= t('lbl.height_mm') ?></th><th><?= t('lbl.used_by') ?></th><th><?= t('settings.actions') ?></th></tr></thead>
    <tbody>
    <?php foreach ($sizes as $s): ?>
    <tr data-id="<?= (int)$s['id'] ?>" data-used="<?= (int)$s['used'] ?>">
      <td><input type="text" class="ls-name" value="<?= htmlspecialchars($s['name']) ?>" maxlength="100" aria-label="<?= t('lbl.size_name') ?>"></td>
      <td><input type="number" class="ls-w" value="<?= $fmtMm($s['width_mm']) ?>" min="15" max="300" step="0.1" style="width:90px" aria-label="<?= t('lbl.width_mm') ?>"></td>
      <td><input type="number" class="ls-h" value="<?= $fmtMm($s['height_mm']) ?>" min="15" max="300" step="0.1" style="width:90px" aria-label="<?= t('lbl.height_mm') ?>"></td>
      <td><?= t('lbl.n_templates', ['n' => fmtNum($s['used'])]) ?></td>
      <td>
        <div class="ls-actions">
          <button type="button" class="btn-icon" data-ls="save"><?= t('common.save') ?></button>
          <button type="button" class="btn-icon ed-danger" data-ls="delete"><?= t('common.delete') ?></button>
        </div>
        <div class="ls-replace" hidden>
          <label><?= t('lbl.replace_with') ?>
            <select class="ls-replace-sel">
              <?php foreach ($sizes as $o): if ($o['id'] === $s['id']) continue; ?>
              <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="button" class="btn-danger btn-sm" data-ls="confirm-delete"><?= t('lbl.delete_replace') ?></button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="cp-card">
  <h2><?= t('lbl.add_site_size') ?></h2>
  <div class="lbl-add-size" style="display:flex">
    <div class="field"><label for="ls-new-name"><?= t('lbl.size_name') ?></label><input type="text" id="ls-new-name" maxlength="100"></div>
    <div class="field"><label for="ls-new-w"><?= t('lbl.width_mm') ?></label><input type="number" id="ls-new-w" min="15" max="300" step="0.1"></div>
    <div class="field"><label for="ls-new-h"><?= t('lbl.height_mm') ?></label><input type="number" id="ls-new-h" min="15" max="300" step="0.1"></div>
    <div class="lbl-add-size-btns"><button type="button" class="btn btn-sm" id="ls-add"><?= t('lbl.add_size') ?></button></div>
  </div>
</section>
