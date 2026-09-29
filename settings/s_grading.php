<?php
/* SETTINGS › Grading: the user's condition grading method */
if (!defined('IN_SETTINGS')) exit;

$gradeLabels  = gradingConfig()['labels'];
$gradingMode  = $user['grading_mode']    ?? 'simple';
$gradingDef   = $user['grading_default'] ?? 'simple';
$previewLabel = gradeLabelForScore(93);
?>
<script>CP.gradingDefault = <?= json_encode($gradingDef) ?>;</script>

<section class="cp-card">
  <h2><?= t('settings.grading') ?></h2>
  <p class="export-desc"><?= t('settings.grading_desc') ?></p>
  <div class="gm-cards" role="radiogroup" aria-label="<?= t('settings.grading') ?>">
    <?php foreach ([
      'simple' => [t('grading.mode_simple'), t('grading.mode_simple_desc')],
      'points' => [t('grading.mode_points'), t('grading.mode_points_desc')],
      'both'   => [t('grading.mode_both'), t('grading.mode_both_desc')],
    ] as $val => [$title, $desc]): ?>
    <label class="gm-card">
      <input type="radio" name="grading_mode" value="<?= $val ?>" <?= $gradingMode === $val ? 'checked' : '' ?> onchange="gmRefresh()">
      <span class="gm-title"><span class="gm-dot"></span><?= $title ?></span>
      <span class="gm-desc"><?= $desc ?></span>
    </label>
    <?php endforeach; ?>
  </div>

  <div class="gm-box gm-default" id="gm-default" style="<?= $gradingMode === 'both' ? '' : 'display:none' ?>">
    <span class="gm-lbl"><?= t('settings.default_new') ?></span>
    <div class="gr-switch" role="group" aria-label="<?= t('settings.default_new') ?>">
      <button type="button" data-def="simple" class="<?= $gradingDef === 'simple' ? 'on' : '' ?>" aria-pressed="<?= $gradingDef === 'simple' ? 'true' : 'false' ?>" onclick="gmSetDefault('simple')"><?= t('grading.simple') ?></button>
      <button type="button" data-def="points" class="<?= $gradingDef === 'points' ? 'on' : '' ?>" aria-pressed="<?= $gradingDef === 'points' ? 'true' : 'false' ?>" onclick="gmSetDefault('points')"><?= t('grading.points') ?></button>
    </div>
    <span style="color:var(--muted)"><?= t('settings.switch_note') ?></span>
  </div>

  <div class="gm-previews">
    <div class="gm-box">
      <span class="gm-lbl"><?= t('settings.simple_looks') ?></span>
      <div style="display:flex;gap:6px;flex-wrap:wrap"><?php foreach ($gradeLabels as $l) echo gradeBadgeHtml($l); ?></div>
    </div>
    <div class="gm-box">
      <span class="gm-lbl"><?= t('settings.points_looks') ?></span>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span style="font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case);font-size:1.5rem;line-height:1;color:<?= htmlspecialchars($previewLabel['color'] ?? 'var(--text2)') ?>">93</span>
        <?= gradeBadgeHtml($previewLabel) ?>
        <span style="font-size:.64rem;color:var(--muted)"><?= t('settings.points_example') ?></span>
      </div>
    </div>
  </div>

  <div class="gm-box">
    <?= t('settings.grading_admin_note') ?>
    <?php if (isAdmin()): ?>
    <a href="<?= BASE_URL ?>/settings.php?s=grading-system" style="display:inline-block;margin-top:6px"><?= t('settings.nav.grading_system') ?> →</a>
    <?php endif; ?>
  </div>
  <div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="btn btn-sm" onclick="saveGrading()"><?= t('settings.save_grading') ?></button></div>
</section>
