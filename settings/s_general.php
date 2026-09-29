<?php
/* SETTINGS › General (admin): site name / logo, money, region and date, new-user defaults, image settings */
if (!defined('IN_SETTINGS')) exit;
?>
<script>CP.logoColors = <?= json_encode(array_combine(LOGO_COLORS, array_map(fn($c) => tRaw('admin.site.color_'.str_replace('-', '_', $c)), LOGO_COLORS)), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;</script>

<section class="cp-card">
  <h2><?= t('admin.site.title') ?></h2>
  <p class="ga-desc"><?= t('admin.site.desc') ?></p>
  <form method="POST" id="site-form" class="site-form">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="save_site_settings">
    <input type="hidden" name="site_name_colors" id="sf-colors" value="<?= htmlspecialchars(setting('site_name_colors')) ?>">

    <div class="sf-group">
      <div class="sf-head"><?= t('admin.site.name_head') ?></div>
      <div class="sf-row">
        <div class="field" style="flex:1;min-width:220px"><label for="sf-name"><?= t('admin.site.name') ?></label>
          <input type="text" name="site_name" id="sf-name" maxlength="60" value="<?= htmlspecialchars(siteName()) ?>" oninput="sfLogo()"></div>
        <div class="field" style="width:230px"><label for="sf-style"><?= t('admin.site.name_style') ?></label>
          <select name="site_name_style" id="sf-style" onchange="sfLogo()">
            <?php foreach (['single', 'last', 'custom'] as $st): ?>
            <option value="<?= $st ?>" <?= setting('site_name_style') === $st ? 'selected' : '' ?>><?= t('admin.site.style_'.$st) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div id="sf-words" class="sf-row" style="display:none"></div>
      <div class="sf-preview-bar site-header"><span class="site-logo" id="sf-logo"></span></div>
    </div>

    <div class="sf-group">
      <div class="sf-head"><?= t('admin.site.money_head') ?></div>
      <div class="sf-row">
        <div class="field" style="width:110px"><label for="sf-sym"><?= t('admin.site.symbol') ?></label>
          <input type="text" name="currency_symbol" id="sf-sym" maxlength="5" value="<?= htmlspecialchars(setting('currency_symbol')) ?>" oninput="sfMoney()"></div>
        <div class="field" style="width:150px"><label for="sf-pos"><?= t('admin.site.position') ?></label>
          <select name="currency_position" id="sf-pos" onchange="sfMoney()">
            <option value="before" <?= setting('currency_position') === 'before' ? 'selected' : '' ?>><?= t('admin.site.pos_before') ?></option>
            <option value="after"  <?= setting('currency_position') === 'after'  ? 'selected' : '' ?>><?= t('admin.site.pos_after') ?></option>
          </select></div>
        <div class="field" style="width:130px"><label for="sf-space"><?= t('admin.site.space') ?></label>
          <select name="currency_space" id="sf-space" onchange="sfMoney()">
            <option value="0" <?= setting('currency_space') !== '1' ? 'selected' : '' ?>><?= t('admin.site.no') ?></option>
            <option value="1" <?= setting('currency_space') === '1' ? 'selected' : '' ?>><?= t('admin.site.yes') ?></option>
          </select></div>
        <div class="field" style="width:150px"><label for="sf-dec"><?= t('admin.site.decimal') ?></label>
          <select name="decimal_sep" id="sf-dec" onchange="sfMoney()">
            <?php foreach (DECIMAL_SEPS as $sep): ?><option value="<?= htmlspecialchars($sep) ?>" <?= setting('decimal_sep') === $sep ? 'selected' : '' ?>><?= t('admin.site.sep_'.['.' => 'dot', ',' => 'comma'][$sep]) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" style="width:170px"><label for="sf-thou"><?= t('admin.site.thousands') ?></label>
          <select name="thousands_sep" id="sf-thou" onchange="sfMoney()">
            <?php foreach (THOUSANDS_SEPS as $sep): ?><option value="<?= htmlspecialchars($sep) ?>" <?= setting('thousands_sep') === $sep ? 'selected' : '' ?>><?= t('admin.site.sep_'.['.' => 'dot', ',' => 'comma', ' ' => 'space', "'" => 'apos', '' => 'none'][$sep]) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="sf-preview"><?= t('admin.site.preview') ?>: <b id="sf-money-preview"></b></div>
      <p class="ga-desc" style="margin:8px 0 0"><?= t('common.currency_note', ['symbol' => setting('currency_symbol')]) ?></p>
    </div>

    <div class="sf-group">
      <div class="sf-head"><?= t('admin.site.region_head') ?></div>
      <div class="sf-row">
        <div class="field" style="width:170px"><label for="sf-region"><?= t('admin.site.region') ?></label>
          <select name="default_region" id="sf-region">
            <?php foreach (REGIONS as $r): ?><option value="<?= $r ?>" <?= setting('default_region') === $r ? 'selected' : '' ?>><?= $r === 'Mixed' ? t('admin.site.region_mixed') : $r ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" style="width:200px"><label for="sf-date"><?= t('admin.site.date') ?></label>
          <select name="date_format" id="sf-date" onchange="sfDate()">
            <?php foreach (DATE_FORMATS as $f): ?><option value="<?= $f ?>" <?= setting('date_format') === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" style="width:200px"><label for="sf-lang"><?= t('admin.site.language') ?></label>
          <select name="default_language" id="sf-lang">
            <?php foreach (availableLanguages() as $l): ?><option value="<?= htmlspecialchars($l['code']) ?>" <?= siteLanguage() === $l['code'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" style="width:240px"><label for="sf-tz"><?= t('admin.site.timezone') ?></label>
          <select name="timezone" id="sf-tz">
            <option value=""><?= t('admin.site.tz_server', ['tz' => ini_get('date.timezone') ?: 'UTC']) ?></option>
            <?php foreach (timezone_identifiers_list() as $tz): ?><option value="<?= htmlspecialchars($tz) ?>" <?= setting('timezone') === $tz ? 'selected' : '' ?>><?= htmlspecialchars($tz) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="sf-preview"><?= t('admin.site.preview') ?>: <b id="sf-date-preview"></b></div>
      <p class="ga-desc" style="margin:8px 0 0"><?= t('admin.site.region_note') ?></p>
    </div>

    <div class="sf-group">
      <div class="sf-head"><?= t('admin.site.new_users_head') ?></div>
      <div class="sf-row">
        <div class="field" style="width:220px"><label for="sf-grading"><?= t('admin.site.grading') ?></label>
          <select name="default_grading" id="sf-grading">
            <?php foreach (['simple', 'points', 'both'] as $g): ?><option value="<?= $g ?>" <?= setting('default_grading') === $g ? 'selected' : '' ?>><?= t('grading.mode_'.$g) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" style="width:220px"><label for="sf-value"><?= t('admin.site.value_type') ?></label>
          <select name="default_value_type" id="sf-value">
            <?php foreach (VALUE_TYPES as $v): ?><option value="<?= $v ?>" <?= setting('default_value_type') === $v ? 'selected' : '' ?>><?= t('common.price.'.$v) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="sf-row">
        <div class="field" style="flex:1;min-width:220px"><label for="sf-comp"><?= t('settings.comp') ?></label>
          <textarea name="defaults_completeness" id="sf-comp" rows="9"><?= htmlspecialchars(implode("\n", defaultCompletenessOptions())) ?></textarea></div>
        <div class="field" style="flex:1;min-width:220px"><label for="sf-played"><?= t('settings.played') ?></label>
          <textarea name="defaults_played" id="sf-played" rows="9"><?= htmlspecialchars(implode("\n", defaultPlayedOptions())) ?></textarea></div>
      </div>
      <p class="ga-desc" style="margin:0"><?= t('admin.site.new_users_note') ?></p>
    </div>

    <div style="display:flex;justify-content:flex-end"><button class="btn btn-sm" type="submit"><?= t('admin.site.save') ?></button></div>
  </form>
</section>

<section class="cp-card">
  <h2><?= t('admin.img.title') ?></h2>
  <p style="font-size:.74rem;color:var(--muted);margin-bottom:14px"><?= t('admin.img.desc') ?></p>
  <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
    <div class="field" style="width:160px"><label for="img-max-w"><?= t('admin.img.max_w') ?></label><input type="number" id="img-max-w" min="200" max="4000" value="1200" step="100"></div>
    <div class="field" style="width:160px"><label for="img-max-h"><?= t('admin.img.max_h') ?></label><input type="number" id="img-max-h" min="200" max="4000" value="1200" step="100"></div>
    <div class="field" style="width:140px"><label for="img-qual"><?= t('admin.img.quality') ?></label><input type="number" id="img-qual" min="10" max="100" value="80"></div>
    <button class="btn btn-sm" onclick="saveImgSettings()"><?= t('admin.img.save') ?></button>
  </div>
  <div id="img-settings-msg" style="font-size:.75rem;color:var(--green);margin-top:10px;display:none"><?= t('common.saved') ?></div>
</section>
