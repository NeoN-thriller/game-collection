<?php
/* SETTINGS › Label templates: design the printable condition-report sticker (assets/js/labels-editor.js) */
if (!defined('IN_SETTINGS')) exit;

$lblTemplates = labelTemplatesFor($user);
$lblData = [
    'templates'  => $lblTemplates,
    'sizes'      => labelSizesFor((int)$user['id']),
    'default_id' => labelDefaultTemplateId($user, $lblTemplates),
    'sample'     => labelSampleData($user),
    'is_admin'   => isAdmin(),
];
?>
<script>CP.labels = <?= json_encode($lblData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;</script>

<p class="export-desc" style="margin:0"><?= t('lbl.intro') ?></p>
<div class="lbl-grid">
  <!-- 1. Templates -->
  <section class="cp-card lbl-list-card">
    <h2><?= t('lbl.templates') ?></h2>
    <div class="lbl-list" id="lbl-list"></div>
    <div class="lbl-list-actions">
      <button type="button" class="btn btn-sm" id="lbl-new">+ <?= t('lbl.new_template') ?></button>
      <button type="button" class="btn-ghost btn-sm" id="lbl-import-btn"><?= t('lbl.import') ?></button>
      <input type="file" id="lbl-import" accept=".json,application/json" hidden>
    </div>
  </section>

  <!-- 2. Editor -->
  <section class="cp-card lbl-editor-card" id="lbl-editor" aria-labelledby="lbl-editor-title">
    <h2 id="lbl-editor-title"><?= t('lbl.editor') ?></h2>
    <p class="lbl-readonly" id="lbl-readonly" hidden></p>
    <div class="field">
      <label for="lbl-name"><?= t('lbl.name') ?></label>
      <input type="text" id="lbl-name" maxlength="100">
    </div>

    <div class="lbl-block">
      <div class="section-label"><?= t('lbl.size') ?></div>
      <div class="lbl-sizes" id="lbl-sizes" role="radiogroup" aria-label="<?= t('lbl.size') ?>"></div>
      <div class="lbl-add-size" id="lbl-add-size" hidden>
        <div class="field"><label for="lbl-size-name"><?= t('lbl.size_name') ?></label><input type="text" id="lbl-size-name" maxlength="100"></div>
        <div class="field"><label for="lbl-size-w"><?= t('lbl.width_mm') ?></label><input type="number" id="lbl-size-w" min="15" max="300" step="0.1"></div>
        <div class="field"><label for="lbl-size-h"><?= t('lbl.height_mm') ?></label><input type="number" id="lbl-size-h" min="15" max="300" step="0.1"></div>
        <div class="lbl-add-size-btns">
          <button type="button" class="btn-ghost btn-sm" id="lbl-size-cancel"><?= t('common.cancel') ?></button>
          <button type="button" class="btn btn-sm" id="lbl-size-add"><?= t('lbl.add_size') ?></button>
        </div>
      </div>
      <p class="d-hint"><?= t('lbl.sizes_note') ?></p>
    </div>

    <div class="lbl-block lbl-two">
      <fieldset>
        <legend class="section-label"><?= t('lbl.orientation') ?></legend>
        <label><input type="radio" name="lbl-orient" value="landscape"> <?= t('lbl.landscape') ?></label>
        <label><input type="radio" name="lbl-orient" value="portrait"> <?= t('lbl.portrait') ?></label>
      </fieldset>
      <fieldset>
        <legend class="section-label"><?= t('lbl.layout') ?></legend>
        <label><input type="radio" name="lbl-layout" value="horizontal"> <?= t('lbl.horizontal') ?></label>
        <label><input type="radio" name="lbl-layout" value="stacked"> <?= t('lbl.stacked') ?></label>
      </fieldset>
    </div>

    <div class="lbl-block">
      <div class="section-label"><?= t('lbl.fields') ?></div>
      <div class="lbl-fields" id="lbl-fields"></div>
      <button type="button" class="btn-ghost btn-sm" id="lbl-reset-sizes"><?= t('lbl.reset_sizes') ?></button>
    </div>

    <div class="lbl-block">
      <div class="section-label"><?= t('lbl.print_options') ?></div>
      <label class="lbl-check"><input type="checkbox" id="lbl-colour"> <?= t('lbl.colour') ?></label>
      <label class="lbl-check"><input type="checkbox" id="lbl-cut"> <?= t('lbl.cut_line') ?></label>
    </div>
  </section>

  <!-- 3. Preview + actions -->
  <section class="cp-card lbl-preview-card">
    <h2><?= t('lbl.preview') ?></h2>
    <div class="lbl-paper" id="lbl-paper"><div id="lbl-preview"></div></div>
    <p class="lbl-warn" id="lbl-fit" hidden role="status"><?= t('lbl.fit_warning') ?></p>
    <label class="lbl-check" id="lbl-share-wrap"><input type="checkbox" id="lbl-shared"> <?= t('lbl.share') ?></label>
    <div class="lbl-actions">
      <button type="button" class="btn btn-sm" id="lbl-save"><?= t('lbl.save') ?></button>
      <button type="button" class="btn-ghost btn-sm" id="lbl-duplicate"><?= t('lbl.duplicate') ?></button>
      <button type="button" class="btn-ghost btn-sm" id="lbl-print"><?= t('lbl.print_test') ?></button>
      <button type="button" class="btn-ghost btn-sm" id="lbl-export"><?= t('lbl.export') ?></button>
      <button type="button" class="btn-ghost btn-sm" id="lbl-default"><?= t('lbl.set_default') ?></button>
      <button type="button" class="btn-danger btn-sm" id="lbl-delete"><?= t('common.delete') ?></button>
    </div>
  </section>
</div>
