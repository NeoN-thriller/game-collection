<?php
/* SETTINGS › Language & appearance: the user's language and theme */
if (!defined('IN_SETTINGS')) exit;
?>
<script>Object.assign(CP, { themes: <?= themesClientJson() ?>, siteTheme: <?= json_encode(siteTheme()) ?>, savedTheme: <?= json_encode(activeTheme($user)) ?> });</script>

<section class="cp-card">
  <h2><?= t('settings.language') ?></h2>
  <p class="export-desc"><?= t('settings.language_desc') ?></p>
  <select id="lang-select" onchange="saveLanguage(this.value)" style="max-width:280px" aria-label="<?= t('settings.language') ?>">
    <?php foreach (availableLanguages() as $l): ?>
    <option value="<?= htmlspecialchars($l['code']) ?>" <?= currentLang() === $l['code'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?><?= $l['code'] === siteLanguage() ? ' ★' : '' ?></option>
    <?php endforeach; ?>
  </select>
  <p style="font-size:.66rem;color:var(--muted);margin-top:6px">★ <?= t('settings.site_default_mark') ?></p>
</section>

<section class="cp-card">
  <h2><?= t('settings.theme') ?></h2>
  <p class="export-desc"><?= t('settings.theme_desc') ?></p>
  <div class="theme-grid" role="radiogroup" aria-label="<?= t('settings.theme') ?>">
    <?php foreach (availableThemes() as $t) echo themeCardHtml($t, 'theme', activeTheme($user) === $t['slug'], 'pickTheme'); ?>
  </div>
</section>
