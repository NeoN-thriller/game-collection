<?php
/* SETTINGS › Tags: the user's game tags (filled by settings.js) */
if (!defined('IN_SETTINGS')) exit;
?>
<section class="cp-card">
  <h2><?= t('settings.tags') ?></h2>
  <p class="export-desc"><?= t('settings.tags_desc') ?></p>
  <div id="tag-list" class="comp-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px"></div>
  <div style="display:flex;gap:6px;margin-bottom:10px">
    <input type="text" id="new-tag" placeholder="<?= t('settings.new_tag') ?>" aria-label="<?= t('settings.new_tag') ?>" style="flex:1;padding:8px 10px;font-size:.78rem">
    <button class="btn-ghost" onclick="addTagItem()"><?= t('settings.add_tag') ?></button>
  </div>
  <button class="btn btn-sm" onclick="saveTagOptions()"><?= t('settings.save_tags') ?></button>
</section>
