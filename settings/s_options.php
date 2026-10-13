<?php
/* SETTINGS › Options: the user's completeness choices, played statuses (with the counter group each one
   counts in) and tags — three lists with the same editor (assets/js/settings.js: addItem, initDrag) */
if (!defined('IN_SETTINGS')) exit;

$optList = function (string $table) use ($user): array {
    $st = db()->prepare("SELECT label FROM $table WHERE user_id=? ORDER BY sort_order");
    $st->execute([$user['id']]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};
$compOpts   = $optList('user_completeness_options');
$tagOpts    = $optList('user_tag_options');
$playedRows = userPlayedOptions((int)$user['id']);
$playedOpts = array_column($playedRows, 'label');
$playedGrp  = array_column($playedRows, 'group', 'label');

$lists = [
    ['comp-list',   'settings.comp',   'settings.comp_desc',   $compOpts,   'saveCompleteness()'],
    ['played-list', 'settings.played', 'settings.played_desc', $playedOpts, 'savePlayed()'],
    ['tag-list',    'settings.tags',   'settings.tags_desc',   $tagOpts,    'saveTagOptions()'],
];

/** The Finished / Started / not counted toggle of a played status (also built in settings.js: playGroupToggle). */
$groupToggle = function (string $group): string {
    $out = '<span class="pg-toggle" role="radiogroup" aria-label="' . t('settings.play_group') . '">';
    foreach (['finished' => '✓', 'started' => '▶', 'none' => '–'] as $g => $icon) {
        $on = $group === $g;
        $out .= '<button type="button" class="pg-btn pg-' . $g . ($on ? ' on' : '') . '" data-pg="' . $g . '" role="radio" aria-checked="' . ($on ? 'true' : 'false')
              . '" title="' . t('settings.pg_' . $g) . '" aria-label="' . t('settings.pg_' . $g) . '">' . $icon . '</button>';
    }
    return $out . '</span>';
};
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
        <?php if ($listId === 'played-list'): ?><?= $groupToggle($playedGrp[$label] ?? 'none') ?><?php endif; ?>
        <button type="button" class="btn-danger" onclick="removeItem(this)" aria-label="<?= t('common.delete') ?>">✕</button>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($listId === 'played-list'): $pctOwned = ($user['played_pct'] ?? 'all') === 'owned'; ?>
    <p class="export-desc" style="margin:10px 0 0"><?= t('settings.play_group_hint') ?></p>
    <div class="toggle-row" style="margin-top:10px">
      <label class="toggle"><input type="checkbox" id="played-show"<?= showPlayedCounters($user) ? ' checked' : '' ?>><span class="toggle-slider"></span></label>
      <label class="toggle-label" for="played-show"><?= t('settings.played_show') ?></label>
    </div>
    <div class="pg-pct">
      <span class="pg-pct-label"><?= t('settings.played_pct') ?></span>
      <span class="gr-seg" role="radiogroup" aria-label="<?= t('settings.played_pct') ?>" id="played-pct">
        <button type="button" role="radio" data-pct="all" class="<?= $pctOwned ? '' : 'on' ?>" aria-checked="<?= $pctOwned ? 'false' : 'true' ?>"><?= t('settings.played_pct_all') ?></button>
        <button type="button" role="radio" data-pct="owned" class="<?= $pctOwned ? 'on' : '' ?>" aria-checked="<?= $pctOwned ? 'true' : 'false' ?>"><?= t('settings.played_pct_owned') ?></button>
      </span>
    </div>
    <?php endif; ?>
    <div style="display:flex;gap:8px;margin-top:10px">
      <button type="button" class="btn-ghost" onclick="addItem('<?= $listId ?>')">+ <?= t('settings.add_option') ?></button>
      <button type="button" class="btn btn-sm" onclick="<?= $save ?>"><?= t('common.save') ?></button>
    </div>
  </section>
  <?php endforeach; ?>
</div>
