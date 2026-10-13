<?php
/* SETTINGS › Search sites: the user's auction / search sites (filled by settings.js).
   The wishlist and collection columns are under Language & appearance (s_appearance.php). */
if (!defined('IN_SETTINGS')) exit;
?>
<section class="cp-card">
  <h2><?= t('settings.sites') ?></h2>
  <p class="export-desc"><?= t('settings.sites_desc') ?><br>
  <?= t('settings.example') ?>: <code>https://www.ebay.nl/sch/i.html?_nkw={system}+{title}+{region}</code></p>
  <div id="auction-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px"></div>
  <button class="btn-ghost" onclick="addAuctionSite()" style="font-size:.75rem">+ <?= t('settings.add_site') ?></button>
  <button class="btn btn-sm" onclick="saveAuctionSites()" style="margin-left:8px"><?= t('settings.save_sites') ?></button>
</section>
