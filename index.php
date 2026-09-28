<?php
require_once __DIR__ . '/config.php';
if (auth()) { header('Location: '.BASE_URL.'/dashboard.php'); exit; }

$error = '';
$mode  = $_GET['mode'] ?? 'login';  // login | register

// ── HANDLE POST ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $mode = $_POST['mode'] ?? 'login';

    if ($mode === 'login') {
        $username   = trim($_POST['username'] ?? '');
        $password   = $_POST['password'] ?? '';
        $rememberMe = !empty($_POST['remember_me']);
        $keys = loginKeys($username);
        $st = db()->prepare("SELECT * FROM users WHERE username=?");
        $st->execute([$username]);
        $user = $st->fetch();
        $valid = false;
        if ($locked = lockRemaining($keys)) {
            $error = lockMessage($locked);
        } elseif ($user) {
            $valid = password_verify($password, $user['password']);
        } else {
            // Burn the same bcrypt time so unknown usernames can't be detected by timing
            password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }
        if ($valid) {
            clearFailures($keys);
            if ($user['status'] !== 'active') {
                $error = tRaw('auth.err_deactivated');
            } else {
                startUserSession($user);
                db()->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
                if ($rememberMe) issueRememberToken($user['id']);
                header('Location: '.BASE_URL.'/dashboard.php'); exit;
            }
        } elseif (!$error) {
            recordFailure($keys);
            $locked = lockRemaining($keys);
            $error  = $locked ? lockMessage($locked) : tRaw('auth.err_invalid');
        }
    }

    if ($mode === 'register') {
        $code     = trim($_POST['invite_code'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm'] ?? '';

        if (strlen($username) < 3 || strlen($username) > 50) {
            $error = tRaw('auth.err_username_length');
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $error = tRaw('auth.err_username_chars');
        } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
            $error = tRaw('common.err_password_length', ['n' => MIN_PASSWORD_LENGTH]);
        } elseif ($password !== $confirm) {
            $error = tRaw('common.err_password_match');
        } else {
            // Check invite code
            $st = db()->prepare("SELECT * FROM invite_codes WHERE code=? AND used_by IS NULL");
            $st->execute([$code]);
            $invite = $st->fetch();
            if (!$invite) {
                $error = tRaw('auth.err_invite');
            } else {
                // Check username available
                $st = db()->prepare("SELECT id FROM users WHERE username=?");
                $st->execute([$username]);
                if ($st->fetch()) {
                    $error = tRaw('auth.err_username_taken');
                } else {
                    $hash   = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $wToken = bin2hex(random_bytes(12));
                    $pdo  = db();
                    $pdo->beginTransaction();
                    try {
                        // New users start with the site's default grading method and language
                        $gMode = in_array(setting('default_grading'), ['simple','points','both'], true) ? setting('default_grading') : 'simple';
                        $gDef  = $gMode === 'both' ? 'simple' : $gMode;
                        $pdo->prepare("INSERT INTO users (username, password, role, status, wishlist_token, grading_mode, grading_default) VALUES (?,?,'user','active',?,?,?)")
                            ->execute([$username, $hash, $wToken, $gMode, $gDef]);
                        $uid = $pdo->lastInsertId();
                        $pdo->prepare("UPDATE invite_codes SET used_by=?, used_at=NOW() WHERE id=?")
                            ->execute([$uid, $invite['id']]);
                        // Seed default completeness options (in the site language; users can rename them)
                        $defaults = array_map('trim', explode('|', tRaw('defaults.completeness')));
                        $ins = $pdo->prepare("INSERT INTO user_completeness_options (user_id, label, sort_order) VALUES (?,?,?)");
                        foreach ($defaults as $i => $label) $ins->execute([$uid, $label, $i]);
                        // Seed default played options
                        $played = array_map('trim', explode('|', tRaw('defaults.played')));
                        $ins2 = $pdo->prepare("INSERT INTO user_played_options (user_id, label, sort_order) VALUES (?,?,?)");
                        foreach ($played as $i => $label) $ins2->execute([$uid, $label, $i]);
                        $pdo->commit();
                        startUserSession(['id'=>$uid, 'password'=>$hash]);
                        header('Location: '.BASE_URL.'/dashboard.php'); exit;
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = tRaw('auth.err_register_failed');
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pageTitle(tRaw('auth.sign_in')) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<?= themeHead(null) ?>
<style>
  body { display:flex; align-items:center; justify-content:center; min-height:100vh; }
  .auth-box { width:100%; max-width:400px; background:var(--surface); border:1px solid var(--border2); padding:40px 36px; }
  .auth-logo { font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case); font-size:2.2rem; color:var(--accent); letter-spacing:.1em; margin-bottom:4px; }
  /* The logo sits on a panel here, not on the header bar */
  .auth-logo { --header-logo:var(--accent); --header-logo2:var(--wiiu); }
  .auth-logo span { color:var(--header-logo2); }
  .auth-sub  { font-size:.65rem; color:var(--muted); letter-spacing:.2em; text-transform:uppercase; margin-bottom:28px; }
  .tab-row   { display:flex; gap:0; margin-bottom:24px; border-bottom:2px solid var(--border2); }
  .tab       { flex:1; padding:8px; font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case); font-size:1.1rem; letter-spacing:.1em; background:none; border:none; color:var(--muted); cursor:pointer; }
  .tab.active{ color:var(--accent2); border-bottom:2px solid var(--accent2); margin-bottom:-2px; }
  .error-msg { background:color-mix(in srgb,var(--red) 12%,transparent); border:1px solid color-mix(in srgb,var(--red) 40%,transparent); color:var(--red); font-size:.75rem; padding:8px 12px; margin-bottom:16px; }
</style>
</head>
<body>
<div class="auth-box">
  <div class="auth-logo"><?= siteLogoHtml() ?></div>
  <div class="auth-sub"><?= t('auth.tagline') ?></div>

  <div class="tab-row">
    <button class="tab <?= $mode==='login'?'active':'' ?>" onclick="setMode('login')"><?= t('auth.sign_in') ?></button>
    <button class="tab <?= $mode==='register'?'active':'' ?>" onclick="setMode('register')"><?= t('auth.register') ?></button>
  </div>

  <?php if ($error): ?>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- LOGIN -->
  <form id="form-login" method="POST" style="display:<?= $mode==='login'?'block':'none' ?>">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="mode" value="login">
    <div class="field"><label><?= t('auth.username') ?></label><input type="text" name="username" required autocomplete="username"></div>
    <div class="field" style="margin-top:14px"><label><?= t('auth.password') ?></label><input type="password" name="password" required autocomplete="current-password"></div>
    <div style="display:flex;align-items:center;gap:8px;margin-top:12px">
      <input type="checkbox" name="remember_me" id="remember_me" value="1" style="width:auto">
      <label for="remember_me" style="font-size:.72rem;color:var(--muted);cursor:pointer"><?= t('auth.remember') ?></label>
    </div>
    <button type="submit" class="btn" style="width:100%;margin-top:16px;padding:12px"><?= t('auth.sign_in') ?></button>
  </form>

  <!-- REGISTER -->
  <form id="form-register" method="POST" style="display:<?= $mode==='register'?'block':'none' ?>">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="mode" value="register">
    <div class="field"><label><?= t('auth.invite_code') ?></label><input type="text" name="invite_code" required placeholder="<?= t('auth.invite_placeholder') ?>"></div>
    <div class="field" style="margin-top:14px"><label><?= t('auth.username') ?></label><input type="text" name="username" required autocomplete="username"></div>
    <div class="field" style="margin-top:14px"><label><?= t('auth.password') ?></label><input type="password" name="password" required autocomplete="new-password"></div>
    <div class="field" style="margin-top:14px"><label><?= t('auth.confirm_password') ?></label><input type="password" name="confirm" required autocomplete="new-password"></div>
    <button type="submit" class="btn" style="width:100%;margin-top:20px;padding:12px"><?= t('auth.create_account') ?></button>
  </form>
</div>
<script>
function setMode(m) {
  document.getElementById('form-login').style.display    = m==='login'    ? 'block':'none';
  document.getElementById('form-register').style.display = m==='register' ? 'block':'none';
  document.querySelectorAll('.tab').forEach((t,i)=> t.classList.toggle('active', (i===0&&m==='login')||(i===1&&m==='register')));
}
</script>
</body>
</html>
