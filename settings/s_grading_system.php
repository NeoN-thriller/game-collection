<?php
/* SETTINGS › Grading system (admin): editors filled by assets/js/grading-admin.js */
if (!defined('IN_SETTINGS')) exit;
?>
<section class="cp-card">
  <h2><?= t('admin.ga.labels') ?></h2>
  <div id="ga-labels"><p class="ga-desc"><?= t('admin.loading') ?></p></div>
</section>

<section class="cp-card">
  <h2><?= t('admin.ga.profiles') ?></h2>
  <p class="ga-desc"><?= t('admin.ga.profiles_desc') ?></p>
  <div id="ga-profiles"></div>
</section>

<section class="cp-card">
  <h2><?= t('admin.ga.templates') ?></h2>
  <p class="ga-desc"><?= t('admin.ga.templates_desc') ?></p>
  <div id="ga-templates"></div>
</section>

<section class="cp-card">
  <h2><?= t('admin.ga.io') ?></h2>
  <div id="ga-io"></div>
</section>
