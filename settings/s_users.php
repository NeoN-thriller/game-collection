<?php
/* SETTINGS › Users (admin): accounts, invite codes, login lockouts */
if (!defined('IN_SETTINGS')) exit;

$users   = db()->query("SELECT * FROM users ORDER BY created_at")->fetchAll();
$invites = db()->query("
    SELECT ic.*, u1.username AS creator, u2.username AS used_by_name
    FROM invite_codes ic
    JOIN users u1 ON u1.id = ic.created_by
    LEFT JOIN users u2 ON u2.id = ic.used_by
    ORDER BY ic.created_at DESC
    LIMIT 50
")->fetchAll();
$lockouts = db()->query("SELECT *, locked_until > NOW() AS is_locked FROM login_attempts
                         ORDER BY is_locked DESC, last_fail_at DESC LIMIT 100")->fetchAll();
?>
<section class="cp-card">
  <h2><?= t('admin.users.title') ?></h2>
  <table class="admin-table">
    <thead><tr><th><?= t('auth.username') ?></th><th><?= t('admin.users.role') ?></th><th><?= t('import.col_status') ?></th><th><?= t('admin.users.last_login') ?></th><th><?= t('settings.actions') ?></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><?= htmlspecialchars($u['username']) ?></td>
      <td><span class="tag tag-<?= $u['role']==='admin'?'admin':'active' ?>"><?= t('admin.users.role_'.$u['role']) ?></span></td>
      <td><span class="tag tag-<?= $u['status'] ?>"><?= t('admin.users.status_'.$u['status']) ?></span></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= $u['last_login'] ? fmtDate($u['last_login'], true) : t('admin.users.never') ?></td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if ($u['id'] !== $user['id']): ?>
        <!-- Status change -->
        <form method="POST" style="display:flex;gap:4px">
          <input type="hidden" name="csrf"    value="<?= csrf() ?>">
          <input type="hidden" name="action"  value="user_status">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
          <select name="status" aria-label="<?= t('import.col_status') ?>" style="font-size:.68rem;padding:3px 6px">
            <option value="active"   <?= $u['status']==='active'  ?'selected':'' ?>><?= t('admin.users.status_active') ?></option>
            <option value="inactive" <?= $u['status']==='inactive'?'selected':'' ?>><?= t('admin.users.status_inactive') ?></option>
            <option value="banned"   <?= $u['status']==='banned'  ?'selected':'' ?>><?= t('admin.users.status_banned') ?></option>
          </select>
          <button class="btn-icon" type="submit"><?= t('admin.users.set') ?></button>
        </form>
        <!-- Reset password -->
        <form method="POST" style="display:flex;gap:4px" onsubmit="return confirmReset()">
          <input type="hidden" name="csrf"    value="<?= csrf() ?>">
          <input type="hidden" name="action"  value="reset_password">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
          <input type="text" name="new_password" placeholder="<?= t('settings.new_pw') ?>" aria-label="<?= t('settings.new_pw') ?>" minlength="<?= MIN_PASSWORD_LENGTH ?>" style="font-size:.68rem;padding:3px 8px;width:130px">
          <button class="btn-icon" type="submit"><?= t('admin.users.reset_pw') ?></button>
        </form>
        <?php else: ?><span style="color:var(--muted);font-size:.7rem"><?= t('admin.users.you') ?></span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="cp-card">
  <h2><?= t('admin.inv.title') ?></h2>
  <form method="POST" style="display:flex;gap:8px;margin-bottom:16px">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="gen_invite">
    <button class="btn btn-sm" type="submit"><?= t('admin.inv.generate') ?></button>
  </form>
  <table class="admin-table">
    <thead><tr><th><?= t('admin.inv.code') ?></th><th><?= t('admin.inv.created_by') ?></th><th><?= t('admin.inv.created') ?></th><th><?= t('admin.inv.used_by') ?></th><th><?= t('admin.inv.used_at') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($invites as $inv): ?>
    <tr>
      <td><?php if (!$inv['used_by']): ?><span class="invite-code"><?= htmlspecialchars($inv['code']) ?></span><?php else: ?><span style="color:var(--muted);text-decoration:line-through;font-size:.72rem"><?= htmlspecialchars($inv['code']) ?></span><?php endif; ?></td>
      <td><?= htmlspecialchars($inv['creator']) ?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= fmtDate($inv['created_at'], true) ?></td>
      <td><?= $inv['used_by_name'] ? htmlspecialchars($inv['used_by_name']) : '<span style="color:var(--muted)">—</span>' ?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= $inv['used_at'] ? fmtDate($inv['used_at'], true) : '—' ?></td>
      <td><?php if (!$inv['used_by']): ?>
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf"      value="<?= csrf() ?>">
          <input type="hidden" name="action"    value="delete_invite">
          <input type="hidden" name="invite_id" value="<?= $inv['id'] ?>">
          <button class="btn-danger" type="submit"><?= t('common.delete') ?></button>
        </form>
      <?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="cp-card">
  <h2><?= t('admin.lock.title') ?></h2>
  <p style="font-size:.74rem;color:var(--muted);margin-bottom:14px"><?= t('admin.lock.desc') ?></p>
  <?php if (!$lockouts): ?>
    <p style="font-size:.75rem;color:var(--muted)"><?= t('admin.lock.none') ?></p>
  <?php else: ?>
  <form method="POST" style="margin-bottom:12px">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="unlock_all">
    <button class="btn-icon" type="submit"><?= t('admin.lock.clear_all') ?></button>
  </form>
  <table class="admin-table">
    <thead><tr><th><?= t('admin.lock.key') ?></th><th><?= t('admin.lock.failures') ?></th><th><?= t('admin.lock.last') ?></th><th><?= t('admin.lock.until') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lockouts as $l): ?>
    <tr>
      <td><?= htmlspecialchars($l['attempt_key']) ?></td>
      <td><?= (int)$l['fail_count'] ?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= $l['last_fail_at'] ? fmtDate($l['last_fail_at'], true) : '—' ?></td>
      <td><?php if ($l['is_locked']): ?><span class="tag tag-banned"><?= fmtDate($l['locked_until'], true) ?></span><?php else: ?><span style="color:var(--muted);font-size:.7rem"><?= t('admin.lock.not_locked') ?></span><?php endif; ?></td>
      <td>
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf"        value="<?= csrf() ?>">
          <input type="hidden" name="action"      value="unlock">
          <input type="hidden" name="attempt_key" value="<?= htmlspecialchars($l['attempt_key']) ?>">
          <button class="btn-icon" type="submit"><?= t($l['is_locked'] ? 'admin.lock.unlock' : 'admin.lock.reset') ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
