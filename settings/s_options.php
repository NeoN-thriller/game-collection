<?php
/* SETTINGS › Options: the user's completeness and played-status choices, and tags (tags filled by settings.js) */
if (!defined('IN_SETTINGS')) exit;

$compOpts = db()->prepare("SELECT label FROM user_completeness_options WHERE user_id=? ORDER BY sort_order");
$compOpts->execute([$user['id']]); $compOpts = $compOpts->fetchAll(PDO::FETCH_COLUMN);

$playedOpts = db()->prepare("SELECT label FROM user_played_options WHERE user_id=? ORDER BY sort_order");
$playedOpts->execute([$user['id']]); $playedOpts = $playedOpts->fetchAll(PDO::FETCH_COLUMN);

$lists = [
    ['comp-list',   'settings.comp',   'settings.comp_desc',   $compOpts,   'saveCompleteness()'],
    ['played-list', 'settings.played', 'settings.played_desc', $playedOpts, 'savePlayed()'],
];
?>
<div class="cp-grid-2">
  <?php foreach ($lists as [$listId, $titleKey, $descKey, $labels, $save]): ?>
  <section class="cp-card">
    <h2><?= t($titleKey) ?></h2>
    <p class="export-desc"><?= t($descKey) ?></p>
    <div class="comp-list" id="<?= $listId ?>">
      <?php foreach ($labels as $label): ?>
      <div class="comp-item" draggable="true">
        <span class="drag-handle">⠿</span>
        <input type="text" class="comp-label-input" value="<?= htmlspecialchars($label) ?>" maxlength="100" aria-label="<?= t($titleKey) ?>">
        <button type="button" class="btn-danger" onclick="removeItem(this)" aria-label="<?= t('common.delete') ?>">✕</button>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:8px;margin-top:10px">
      <button type="button" class="btn-ghost" onclick="addItem('<?= $listId ?>')">+ <?= t('settings.add_option') ?></button>
      <button type="button" class="btn btn-sm" onclick="<?= $save ?>"><?= t('common.save') ?></button>
    </div>
  </section>
  <?php endforeach; ?>

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
</div>
