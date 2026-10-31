<?php
/* SETTINGS — POST handlers for the user's own settings. Included by settings.php after verifyCsrf();
   sets $msg (and $msgErr on failure). Most user settings save through api/settings.php instead. */
if (!defined('IN_SETTINGS')) exit;

if ($action === 'save_display_prefs') {
    $showIcons = !empty($_POST['show_icons']) ? 1 : 0;
    db()->prepare("UPDATE users SET show_system_icons=? WHERE id=?")->execute([$showIcons, $user['id']]);
    $st = db()->prepare("SELECT * FROM users WHERE id=?"); $st->execute([$user['id']]); $user = $st->fetch();
    $msg = tRaw('settings.msg_display_saved');
}

if ($action === 'save_completeness') {
    $labels = array_values(array_filter(array_map('trim', $_POST['labels'] ?? [])));
    db()->prepare("DELETE FROM user_completeness_options WHERE user_id=?")->execute([$user['id']]);
    $ins = db()->prepare("INSERT INTO user_completeness_options (user_id, label, sort_order) VALUES (?,?,?)");
    foreach ($labels as $i => $label) { if (strlen($label)<=100) $ins->execute([$user['id'], $label, $i]); }
    $msg = tRaw('settings.comp_saved');
}

if ($action === 'save_played') {
    $labels = array_values(array_filter(array_map('trim', $_POST['labels'] ?? [])));
    db()->prepare("DELETE FROM user_played_options WHERE user_id=?")->execute([$user['id']]);
    $ins = db()->prepare("INSERT INTO user_played_options (user_id, label, sort_order) VALUES (?,?,?)");
    foreach ($labels as $i => $label) { if (strlen($label)<=100) $ins->execute([$user['id'], $label, $i]); }
    $msg = tRaw('settings.played_saved');
}

if ($action === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    // Re-fetch fresh user for password check
    $fu = db()->prepare("SELECT password FROM users WHERE id=?"); $fu->execute([$user['id']]); $fu = $fu->fetch();
    $pwKeys = ['pw:'.$user['id']];
    if ($locked = lockRemaining($pwKeys)) { $msg = lockMessage($locked); $msgErr = true; }
    elseif (!password_verify($current, $fu['password'])) {
        recordFailure($pwKeys); $msg = tRaw('settings.err_current_pw'); $msgErr = true;
        if ($locked = lockRemaining($pwKeys)) activityLogLockout('password.lockout', $pwKeys, $locked, $user);
    }
    elseif (strlen($new) < MIN_PASSWORD_LENGTH) { $msg = tRaw('common.err_password_length', ['n' => MIN_PASSWORD_LENGTH]); $msgErr = true; }
    elseif ($new !== $confirm)  { $msg = tRaw('common.err_password_match'); $msgErr = true; }
    else {
        clearFailures($pwKeys);
        setPassword($user['id'], $new);
        logEvent('accounts', 'password.change', ['actor' => $user]);
        $msg = tRaw('settings.pw_changed');
    }
}
