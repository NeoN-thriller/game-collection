<?php
/* SETTINGS › Languages & themes (admin): installed language files, the site default theme */
if (!defined('IN_SETTINGS')) exit;

try {
    // Users on each theme; no (or an uninstalled) choice means they're on the site default
    $themeUse = [];
    foreach (db()->query("SELECT theme, COUNT(*) FROM users GROUP BY theme")->fetchAll(PDO::FETCH_KEY_PAIR) as $slug => $n) {
        $slug = isset(availableThemes()[(string)$slug]) ? (string)$slug : siteTheme();
        $themeUse[$slug] = ($themeUse[$slug] ?? 0) + (int)$n;
    }
    $themeMigrated = true;
} catch (PDOException) { $themeUse = []; $themeMigrated = false; }
?>
<section class="cp-card">
  <h2><?= t('admin.lang.title') ?></h2>
  <p class="ga-desc"><?= t('admin.lang.desc') ?></p>
  <?php if (!is_writable(LANG_DIR)): ?>
  <div class="ga-box ga-warn" style="margin:0 0 14px"><?= t('admin.lang.not_writable') ?></div>
  <?php endif; ?>
  <form method="POST" class="field" style="max-width:280px;margin-bottom:6px">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="set_default_language">
    <label for="site-lang"><?= t('admin.site.language') ?></label>
    <select name="language" id="site-lang" onchange="this.form.requestSubmit()">
      <?php foreach (availableLanguages() as $l): ?><option value="<?= htmlspecialchars($l['code']) ?>" <?= siteLanguage() === $l['code'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option><?php endforeach; ?>
    </select>
  </form>
  <p class="ga-desc" style="margin:0 0 14px"><?= t('admin.lang.default_note') ?></p>
  <table class="admin-table" style="margin-bottom:14px">
    <thead><tr><th><?= t('admin.lang.col_name') ?></th><th><?= t('admin.lang.col_code') ?></th><th><?= t('admin.lang.col_texts') ?></th><th><?= t('admin.lang.col_missing') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach (availableLanguages() as $l): $missing = missingLangKeys($l['code']); ?>
    <tr>
      <td><?= htmlspecialchars($l['name']) ?><?php if ($l['code'] === siteLanguage()): ?> <span class="tag tag-admin"><?= t('admin.lang.site_default') ?></span><?php endif; ?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= htmlspecialchars($l['code']) ?>.json</td>
      <td><?= fmtNum($l['keys']) ?></td>
      <td>
        <?php if ($l['code'] === LANG_FALLBACK): ?><span style="color:var(--muted);font-size:.7rem"><?= t('admin.lang.base') ?></span>
        <?php elseif (!$missing): ?><span class="tag tag-active"><?= t('admin.lang.complete') ?></span>
        <?php else: ?>
        <details class="lang-missing"><summary><span class="tag tag-banned"><?= tn('admin.lang.n_missing', count($missing)) ?></span></summary>
          <ul><?php foreach (array_slice($missing, 0, 300) as $k): ?><li><code><?= htmlspecialchars($k) ?></code></li><?php endforeach; ?></ul>
          <?php if (count($missing) > 300): ?><p style="font-size:.66rem;color:var(--muted)">…</p><?php endif; ?>
        </details>
        <?php endif; ?>
      </td>
      <td><a class="btn-icon" style="text-decoration:none" href="<?= BASE_URL ?>/api/lang_download.php?code=<?= urlencode($l['code']) ?>">⬇ <?= t('common.download') ?></a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="upload_language">
    <input type="file" name="lang_file" accept=".json,application/json" required style="font-size:.7rem" aria-label="<?= t('admin.lang.upload') ?>">
    <button class="btn btn-sm" type="submit">⬆ <?= t('admin.lang.upload') ?></button>
  </form>
  <p class="ga-desc" style="margin:10px 0 0"><?= t('admin.lang.upload_note') ?></p>
</section>

<section class="cp-card">
  <h2><?= t('admin.theme.title') ?></h2>
  <p style="font-size:.74rem;color:var(--muted);margin-bottom:14px;line-height:1.7">
    <?= t('admin.theme.desc') ?>
  </p>
  <?php if (!$themeMigrated): ?>
  <div class="ga-box ga-warn" style="margin:0 0 14px"><?= t('admin.theme.migrate') ?></div>
  <?php endif; ?>
  <div class="theme-grid" role="radiogroup" aria-label="<?= t('admin.theme.title') ?>">
    <?php foreach (availableThemes() as $t) {
        $n = (int)($themeUse[$t['slug']] ?? 0);
        echo themeCardHtml($t, 'site_theme', siteTheme() === $t['slug'], 'setSiteTheme', [
            $t['slug'].'.css',
            tRaw('admin.theme.fonts', ['fonts' => $t['font_names'] ? implode(', ', $t['font_names']) : tRaw('admin.theme.browser_fonts')]),
            tnRaw('admin.theme.used_by', $n),
        ]);
    } ?>
  </div>
</section>
