<?php
/* SETTINGS › Wishlist sharing: the user's share links (each with its own visible columns), other users' public wishlists */
if (!defined('IN_SETTINGS')) exit;

$shareSt = db()->prepare("SELECT * FROM wishlist_shares WHERE user_id=? ORDER BY id");
$shareSt->execute([$user['id']]);
$shares = $shareSt->fetchAll();

$listedSt = db()->prepare("
    SELECT u.username, ws.name, ws.token
    FROM wishlist_shares ws JOIN users u ON u.id = ws.user_id
    WHERE ws.listed = 1 AND u.status = 'active' AND u.id <> ?
    ORDER BY u.username, ws.id");
$listedSt->execute([$user['id']]);
$publicShares = $listedSt->fetchAll();
?>
<section class="cp-card">
  <h2><?= t('settings.sharing') ?></h2>
  <p class="export-desc"><?= t('settings.sharing_desc') ?></p>

  <?php if (!$shares): ?>
  <p style="font-size:.75rem;color:var(--muted);font-style:italic"><?= t('settings.share_none') ?></p>
  <?php endif; ?>

  <?php foreach ($shares as $sh): $cols = wishlistShareCols($sh['columns']); ?>
  <div class="wshare" data-share-id="<?= (int)$sh['id'] ?>">
    <div class="field" style="margin-bottom:10px"><label><?= t('settings.share_name') ?></label>
      <input type="text" class="wshare-name" maxlength="100" value="<?= htmlspecialchars($sh['name']) ?>" placeholder="<?= t('settings.share_name_ph') ?>">
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <code class="wshare-url"><?= htmlspecialchars(wishlistShareUrl($sh['token'])) ?></code>
      <button type="button" class="btn-ghost" onclick="copyWishlistShare(this)" style="white-space:nowrap"><?= t('common.copy') ?></button>
      <a class="btn-ghost wshare-open" href="<?= htmlspecialchars(wishlistShareUrl($sh['token'])) ?>" target="_blank" style="text-decoration:none;white-space:nowrap"><?= t('settings.share_open') ?> ↗</a>
    </div>
    <div class="section-label" style="margin:12px 0 6px"><?= t('settings.share_columns') ?></div>
    <div class="wshare-cols">
      <?php foreach (WISHLIST_SHARE_COLS as $c): ?>
      <label class="lbl-check"><input type="checkbox" value="<?= $c ?>" <?= in_array($c, $cols, true) ? 'checked' : '' ?> <?= $c === 'title' ? 'disabled' : '' ?>> <?= t('common.col.'.$c) ?></label>
      <?php endforeach; ?>
    </div>
    <label class="lbl-check" style="margin-top:12px"><input type="checkbox" class="wshare-listed" <?= $sh['listed'] ? 'checked' : '' ?>> <?= t('settings.share_listed') ?></label>
    <div class="wshare-actions">
      <button type="button" class="btn" onclick="saveWishlistShare(this)"><?= t('common.save') ?></button>
      <button type="button" class="btn-ghost" onclick="newWishlistShareToken(this)">⟳ <?= t('settings.new_link') ?></button>
      <button type="button" class="btn-danger" onclick="deleteWishlistShare(this)" style="margin-left:auto"><?= t('settings.share_delete') ?></button>
    </div>
  </div>
  <?php endforeach; ?>

  <button type="button" class="btn-ghost" onclick="createWishlistShare()" style="margin-top:14px">＋ <?= t('settings.share_add') ?></button>

  <?php if ($publicShares): ?>
  <div style="margin-top:20px">
    <div style="font-size:.62rem;color:var(--muted);letter-spacing:.15em;text-transform:uppercase;margin-bottom:8px"><?= t('settings.other_wishlists') ?></div>
    <select onchange="if(this.value) window.open(this.value,'_blank')" style="padding:8px 10px;font-size:.78rem">
      <option value="">— <?= t('settings.select_user') ?> —</option>
      <?php foreach ($publicShares as $ps): ?>
      <option value="<?= htmlspecialchars(wishlistShareUrl($ps['token'])) ?>"><?= htmlspecialchars($ps['username'].($ps['name'] !== '' ? ' — '.$ps['name'] : '')) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
</section>
