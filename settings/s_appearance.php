<?php
/* SETTINGS › Language & appearance: the user's language and theme, the dashboard's value graphs,
   and the wishlist and collection columns (filled by settings.js) */
if (!defined('IN_SETTINGS')) exit;
?>
<script>CP.trackVariants = <?= json_encode(!empty($user['track_variants'])) ?>; CP.barcodes = <?= json_encode(barcodeEnabled()) ?>; CP.igdb = <?= json_encode(igdbConfigured()) ?>;</script>
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

<section class="cp-card">
  <h2><?= t('common.nav.dashboard') ?></h2>
  <?php
  // Collection value graphs on the dashboard: saved straight away (api/value_pref.php); switching them on also opens a folded card
  $vgTrack = null;
  try {
      $vt = db()->prepare("SELECT MIN(recorded_at) AS since, COUNT(DISTINCT system_id) AS n_sys FROM value_history WHERE user_id=?");
      $vt->execute([$user['id']]);
      $vgTrack = $vt->fetch() ?: null;
  } catch (Throwable) { /* the value history table is not there yet */ }
  ?>
  <div style="display:flex;align-items:flex-start;gap:12px">
    <label class="toggle">
      <input type="checkbox" role="switch" id="show-value-graphs" <?= showValueGraphs($user) ? 'checked' : '' ?>
             aria-checked="<?= showValueGraphs($user) ? 'true' : 'false' ?>" aria-describedby="show-value-graphs-hint">
      <span class="toggle-slider"></span>
    </label>
    <div>
      <label class="toggle-label" for="show-value-graphs" style="font-size:.78rem"><?= t('settings.show_value_graphs') ?></label>
      <div class="sb-sub" id="show-value-graphs-hint"><?= t('settings.show_value_graphs_hint') ?></div>
      <div class="sb-sub" style="margin-top:4px"><?= t('settings.value_recorded') ?></div>
      <?php if ($vgTrack && $vgTrack['since']): ?>
      <div class="sb-sub" style="margin-top:4px"><?= t('settings.value_tracking_since', ['date' => fmtDate((string)$vgTrack['since']), 'n' => fmtNum((int)$vgTrack['n_sys'])]) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <script>
  document.getElementById('show-value-graphs').addEventListener('change', async e => {
    const cb = e.target, show = cb.checked;
    cb.setAttribute('aria-checked', show ? 'true' : 'false');
    const r = await fetch(BASE + '/api/value_pref.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ show: show ? 1 : 0 }) })
      .then(r => r.json()).catch(() => ({ ok: false }));
    if (r.ok) toast(tRaw('settings.msg_display_saved'));
    else { cb.checked = !show; cb.setAttribute('aria-checked', cb.checked ? 'true' : 'false'); toast(r.error || tRaw('common.error'), true); }
  });
  </script>
</section>

<section class="cp-card">
  <h2><?= t('settings.wish_cols') ?></h2>
  <p class="export-desc"><?= t('settings.wish_cols_desc') ?></p>
  <div id="col-wish-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px"></div>
  <button class="btn btn-sm" onclick="saveColPrefs('wishlist')"><?= t('settings.save_wish_cols') ?></button>
</section>

<section class="cp-card">
  <h2><?= t('settings.coll_cols') ?></h2>
  <p class="export-desc"><?= t('settings.coll_cols_desc') ?></p>
  <div id="col-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px"></div>
  <button class="btn btn-sm" onclick="saveColPrefs('collection')"><?= t('settings.save_coll_cols') ?></button>
</section>
