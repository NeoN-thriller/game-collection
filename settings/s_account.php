<?php
/* SETTINGS › Account: username and password */
if (!defined('IN_SETTINGS')) exit;
?>
<section class="cp-card">
  <h2><?= t('settings.account') ?></h2>
  <p style="font-size:.8rem;color:var(--text2)"><?= t('auth.username') ?>: <strong style="color:var(--accent)"><?= htmlspecialchars($user['username']) ?></strong></p>
  <p style="font-size:.75rem;color:var(--muted);margin-top:6px"><?= t('settings.username_note') ?></p>
</section>

<section class="cp-card">
  <h2><?= t('settings.change_pw') ?></h2>
  <div style="display:flex;flex-direction:column;gap:14px;max-width:360px">
    <div class="field"><label for="cur-pw"><?= t('settings.current_pw') ?></label><input type="password" id="cur-pw" autocomplete="current-password"></div>
    <div class="field"><label for="new-pw"><?= t('settings.new_pw') ?></label><input type="password" id="new-pw" autocomplete="new-password"></div>
    <div class="field"><label for="conf-pw"><?= t('settings.confirm_pw') ?></label><input type="password" id="conf-pw" autocomplete="new-password"></div>
    <button type="button" class="btn btn-sm" onclick="changePassword()" style="width:fit-content"><?= t('settings.change_pw') ?></button>
  </div>
</section>
