<?php
/* SETTINGS › Editions & Variants: how editions count, the wishlist default, how compilations count,
   and optional print variants */
if (!defined('IN_SETTINGS')) exit;

$edMode     = ($user['edition_mode'] ?? 'one') === 'every' ? 'every' : 'one';
$edWishlist = ($user['edition_wishlist'] ?? 'any') === 'exact' ? 'exact' : 'any';
$trackVar   = !empty($user['track_variants']);
$compMode   = compilationMode($user);

$varOpts = db()->prepare("SELECT label FROM user_variant_options WHERE user_id=? ORDER BY sort_order");
$varOpts->execute([$user['id']]);
$varOpts = $varOpts->fetchAll(PDO::FETCH_COLUMN);

// Live preview: one of the user's systems in both modes. Prefer a system where the modes differ, then the most owned.
$unit = editionUnitSql('g');
$prev = db()->prepare("
    SELECT s.name,
           COUNT(DISTINCT g.id)                                     AS total_every,
           COUNT(DISTINCT $unit)                                    AS total_one,
           COUNT(DISTINCT CASE WHEN ce.id IS NOT NULL THEN g.id END)  AS owned_every,
           COUNT(DISTINCT CASE WHEN ce.id IS NOT NULL THEN $unit END) AS owned_one
    FROM systems s
    JOIN games g ON g.system_id = s.id AND g.active = 1
    LEFT JOIN collection_entries ce ON ce.game_id = g.id AND ce.user_id = ? AND ce.owned = 1
    LEFT JOIN user_system_prefs usp ON usp.system_id = s.id AND usp.user_id = ?
    WHERE s.active = 1 AND COALESCE(usp.visible, 1) = 1
    GROUP BY s.id, s.name
    ORDER BY (COUNT(DISTINCT g.id) > COUNT(DISTINCT $unit)) DESC, owned_every DESC, s.sort_order
    LIMIT 1
");
$prev->execute([$user['id'], $user['id']]);
$preview = $prev->fetch();
?>
<section class="cp-card">
  <h2><?= t('ed.count_title') ?></h2>
  <div class="gm-cards ed-mode-cards" role="radiogroup" aria-label="<?= t('ed.count_title') ?>">
    <label class="gm-card">
      <input type="radio" name="edition_mode" value="one" <?= $edMode === 'one' ? 'checked' : '' ?>>
      <span class="gm-title"><span class="gm-dot"></span><?= t('ed.mode_one') ?></span>
      <span class="gm-desc"><?= t('ed.mode_one_desc') ?></span>
      <span class="gm-desc" style="color:var(--muted)"><?= t('ed.mode_one_eg') ?></span>
    </label>
    <label class="gm-card">
      <input type="radio" name="edition_mode" value="every" <?= $edMode === 'every' ? 'checked' : '' ?>>
      <span class="gm-title"><span class="gm-dot"></span><?= t('ed.mode_every') ?></span>
      <span class="gm-desc"><?= t('ed.mode_every_desc') ?></span>
    </label>
  </div>

  <?php if ($preview): ?>
  <div class="gm-box" id="ed-preview"
       data-one="<?= htmlspecialchars(tRaw('ed.progress_games', ['owned' => fmtNum($preview['owned_one']), 'total' => fmtNum($preview['total_one'])])) ?>"
       data-every="<?= htmlspecialchars(tRaw('ed.progress_editions', ['owned' => fmtNum($preview['owned_every']), 'total' => fmtNum($preview['total_every'])])) ?>">
    <span class="gm-lbl"><?= t('ed.preview', ['system' => $preview['name']]) ?></span>
    <strong id="ed-preview-val" style="color:var(--wiiu)"></strong>
  </div>
  <?php endif; ?>

  <div class="field" style="margin-top:14px;max-width:420px">
    <label for="ed-wishlist"><?= t('ed.wish_label') ?></label>
    <select id="ed-wishlist">
      <option value="any"<?= $edWishlist === 'any' ? ' selected' : '' ?>><?= t('ed.wish_any') ?></option>
      <option value="exact"<?= $edWishlist === 'exact' ? ' selected' : '' ?>><?= t('ed.wish_exact') ?></option>
    </select>
  </div>
  <div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="btn btn-sm" type="button" onclick="saveEditions()"><?= t('common.save') ?></button></div>
</section>

<section class="cp-card">
  <h2><?= t('comp.count_title') ?></h2>
  <p class="export-desc"><?= t('comp.count_intro') ?></p>
  <div class="gm-cards ed-mode-cards" role="radiogroup" aria-label="<?= t('comp.count_title') ?>">
    <label class="gm-card">
      <input type="radio" name="compilation_mode" value="own" <?= $compMode === 'own' ? 'checked' : '' ?>>
      <span class="gm-title"><span class="gm-dot"></span><?= t('comp.mode_own') ?></span>
      <span class="gm-desc"><?= t('comp.mode_own_desc') ?></span>
    </label>
    <label class="gm-card">
      <input type="radio" name="compilation_mode" value="contents" <?= $compMode === 'contents' ? 'checked' : '' ?>>
      <span class="gm-title"><span class="gm-dot"></span><?= t('comp.mode_contents') ?></span>
      <span class="gm-desc"><?= t('comp.mode_contents_desc') ?></span>
    </label>
  </div>
  <p class="export-desc" style="margin-top:10px"><?= t('comp.wish_note') ?></p>
  <div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="btn btn-sm" type="button" onclick="saveCompilationMode()"><?= t('common.save') ?></button></div>
</section>

<section class="cp-card">
  <h2><?= t('ed.var_title') ?></h2>
  <p class="export-desc"><?= t('ed.var_intro') ?></p>
  <div class="toggle-row">
    <label class="toggle"><input type="checkbox" id="ed-track" <?= $trackVar ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
    <label class="toggle-label" for="ed-track"><?= t('ed.var_toggle') ?></label>
  </div>

  <div class="ed-var-off" id="ed-var-off"<?= $trackVar ? ' hidden' : '' ?>><?= t('ed.var_off') ?></div>

  <div id="ed-var-on"<?= $trackVar ? '' : ' hidden' ?> style="margin-top:14px">
    <div class="comp-list" id="variant-list" data-placeholder="<?= t('ed.var_placeholder') ?>">
      <?php foreach ($varOpts as $label): ?>
      <div class="comp-item" draggable="true">
        <span class="drag-handle">⠿</span>
        <input type="text" class="comp-label-input" value="<?= htmlspecialchars($label) ?>" maxlength="100" aria-label="<?= t('ed.variant') ?>">
        <button type="button" class="btn-danger" onclick="removeItem(this)" aria-label="<?= t('common.delete') ?>">✕</button>
      </div>
      <?php endforeach; ?>
      <?php if (!$varOpts): ?>
      <div class="comp-item" draggable="true">
        <span class="drag-handle">⠿</span>
        <input type="text" class="comp-label-input" value="" maxlength="100" placeholder="<?= t('ed.var_placeholder') ?>" aria-label="<?= t('ed.variant') ?>">
        <button type="button" class="btn-danger" onclick="removeItem(this)" aria-label="<?= t('common.delete') ?>">✕</button>
      </div>
      <?php endif; ?>
    </div>
    <button type="button" class="btn-ghost" onclick="addItem('variant-list')">+ <?= t('settings.add_option') ?></button>
  </div>
  <div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="btn btn-sm" type="button" onclick="saveVariants()"><?= t('common.save') ?></button></div>
</section>
