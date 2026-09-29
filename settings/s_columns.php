<?php
/* SETTINGS › Columns: collection and wishlist columns, auction / search sites (filled by settings.js) */
if (!defined('IN_SETTINGS')) exit;
?>
<section class="cp-card">
  <h2><?= t('settings.coll_cols') ?></h2>
  <p class="export-desc"><?= t('settings.coll_cols_desc') ?></p>
  <div id="col-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px"></div>
  <button class="btn btn-sm" onclick="saveColPrefs('collection')"><?= t('settings.save_coll_cols') ?></button>
</section>

<section class="cp-card">
  <h2><?= t('settings.wish_cols') ?></h2>
  <p class="export-desc"><?= t('settings.wish_cols_desc') ?></p>
  <div id="col-wish-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px"></div>
  <button class="btn btn-sm" onclick="saveColPrefs('wishlist')"><?= t('settings.save_wish_cols') ?></button>
</section>

<section class="cp-card">
  <h2><?= t('settings.sites') ?></h2>
  <p class="export-desc"><?= t('settings.sites_desc') ?><br>
  <?= t('settings.example') ?>: <code>https://www.ebay.nl/sch/i.html?_nkw={system}+{title}+{region}</code></p>
  <div id="auction-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px"></div>
  <button class="btn-ghost" onclick="addAuctionSite()" style="font-size:.75rem">+ <?= t('settings.add_site') ?></button>
  <button class="btn btn-sm" onclick="saveAuctionSites()" style="margin-left:8px"><?= t('settings.save_sites') ?></button>
</section>
