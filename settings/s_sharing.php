<?php
/* SETTINGS › Wishlist sharing: public link, other users' public wishlists */
if (!defined('IN_SETTINGS')) exit;

$publicUsers = db()->query("SELECT username, wishlist_token FROM users WHERE wishlist_public=1 AND status='active' ORDER BY username")->fetchAll();
$publicUsers = array_filter($publicUsers, fn($u) => $u['username'] !== $user['username'] && !empty($u['wishlist_token']));
$wtoken      = $user['wishlist_token'] ?? '';
?>
<section class="cp-card">
  <h2><?= t('settings.sharing') ?></h2>
  <p class="export-desc"><?= t('settings.sharing_desc') ?></p>
  <div style="display:flex;flex-direction:column;gap:14px;max-width:480px">
    <div class="toggle-row">
      <label class="toggle">
        <input type="checkbox" id="wishlist_public" value="1" <?= $user['wishlist_public'] ? 'checked' : '' ?> onchange="saveWishlistPublic(this.checked)">
        <span class="toggle-slider"></span>
      </label>
      <span class="toggle-label"><?= t('settings.sharing_toggle') ?></span>
    </div>
  </div>
  <!-- Always on the page; settings.js shows / hides it when the toggle changes -->
  <div id="wishlist-link-wrap" style="<?= $user['wishlist_public'] && $wtoken ? '' : 'display:none;' ?>margin-top:14px;padding:12px 14px;background:var(--surface2);border:1px solid var(--border2)">
    <div style="font-size:.62rem;color:var(--muted);letter-spacing:.15em;text-transform:uppercase;margin-bottom:6px"><?= t('settings.your_link') ?></div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <code id="wishlist-link-code" style="font-size:.75rem;color:var(--wiiu);background:color-mix(in srgb,var(--wiiu) 7%,transparent);padding:6px 10px;border:1px solid color-mix(in srgb,var(--wiiu) 20%,transparent);flex:1;word-break:break-all"><?= $wtoken ? BASE_URL.'/wishlist.php?token='.htmlspecialchars($wtoken) : '' ?></code>
      <button class="btn-ghost" onclick="copyLink()" style="white-space:nowrap"><?= t('common.copy') ?></button>
    </div>
    <div style="margin-top:8px">
      <button class="btn-ghost" onclick="regenerateToken()" style="font-size:.68rem">⟳ <?= t('settings.new_link') ?></button>
    </div>
  </div>

  <?php if ($publicUsers): ?>
  <div style="margin-top:20px">
    <div style="font-size:.62rem;color:var(--muted);letter-spacing:.15em;text-transform:uppercase;margin-bottom:8px"><?= t('settings.other_wishlists') ?></div>
    <select onchange="if(this.value) window.open(this.value,'_blank')" style="padding:8px 10px;font-size:.78rem">
      <option value="">— <?= t('settings.select_user') ?> —</option>
      <?php foreach ($publicUsers as $pu): ?>
      <option value="<?= BASE_URL ?>/wishlist.php?token=<?= htmlspecialchars($pu['wishlist_token']) ?>"><?= htmlspecialchars($pu['username']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
</section>
