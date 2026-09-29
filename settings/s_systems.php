<?php
/* SETTINGS › Systems: which systems the user sees, in what order (filled by settings.js) */
if (!defined('IN_SETTINGS')) exit;
?>
<section class="cp-card">
  <h2><?= t('settings.systems') ?></h2>
  <form method="POST" style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="save_display_prefs">
    <label class="toggle">
      <input type="checkbox" name="show_icons" value="1" <?= ($user['show_system_icons']??1) ? 'checked' : '' ?> onchange="this.form.requestSubmit()">
      <span class="toggle-slider"></span>
    </label>
    <span class="toggle-label" style="font-size:.78rem"><?= t('settings.show_icons') ?></span>
  </form>
  <p class="export-desc"><?= t('settings.systems_desc') ?></p>
  <div id="sys-sort-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px"></div>
  <button class="btn btn-sm" onclick="saveSystemPrefs()"><?= t('settings.save_systems') ?></button>
</section>
