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
        $st = db()->prepare("SELECT * FROM users WHERE username=?");
        $st->execute([$username]);
        $user = $st->fetch();
        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] !== 'active') {
                $error = 'Your account has been deactivated. Contact the admin.';
            } else {
                $_SESSION['user_id'] = $user['id'];
                db()->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
                // Set remember me token (30 days)
                if ($rememberMe) {
                    $token   = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', time()+60*60*24*30);
                    db()->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?,?,?)")
                        ->execute([$user['id'], $token, $expires]);
                    setRememberCookie($token, time()+60*60*24*30);
                }
                header('Location: '.BASE_URL.'/dashboard.php'); exit;
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }

    if ($mode === 'register') {
        $code     = trim($_POST['invite_code'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm'] ?? '';

        if (strlen($username) < 3 || strlen($username) > 50) {
            $error = 'Username must be 3–50 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $error = 'Username may only contain letters, numbers and underscores.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            // Check invite code
            $st = db()->prepare("SELECT * FROM invite_codes WHERE code=? AND used_by IS NULL");
            $st->execute([$code]);
            $invite = $st->fetch();
            if (!$invite) {
                $error = 'Invalid or already used invite code.';
            } else {
                // Check username available
                $st = db()->prepare("SELECT id FROM users WHERE username=?");
                $st->execute([$username]);
                if ($st->fetch()) {
                    $error = 'That username is already taken.';
                } else {
                    $hash   = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $wToken = bin2hex(random_bytes(12));
                    $pdo  = db();
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("INSERT INTO users (username, password, role, status, wishlist_token) VALUES (?,?,'user','active',?)")
                            ->execute([$username, $hash, $wToken]);
                        $uid = $pdo->lastInsertId();
                        $pdo->prepare("UPDATE invite_codes SET used_by=?, used_at=NOW() WHERE id=?")
                            ->execute([$uid, $invite['id']]);
                        // Seed default completeness options
                        $defaults = ['Sealed','CIB','No Manual','No Box','Disc / Cart Only','Loose','Incomplete'];
                        $ins = $pdo->prepare("INSERT INTO user_completeness_options (user_id, label, sort_order) VALUES (?,?,?)");
                        foreach ($defaults as $i => $label) $ins->execute([$uid, $label, $i]);
                        // Seed default played options
                        $played = ['Finished','Started','Stuck','Cheated'];
                        $ins2 = $pdo->prepare("INSERT INTO user_played_options (user_id, label, sort_order) VALUES (?,?,?)");
                        foreach ($played as $i => $label) $ins2->execute([$uid, $label, $i]);
                        $pdo->commit();
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $uid;
                        header('Location: '.BASE_URL.'/dashboard.php'); exit;
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = 'Registration failed. Please try again.';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Game Collection — Sign In</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
  body { display:flex; align-items:center; justify-content:center; min-height:100vh; }
  .auth-box { width:100%; max-width:400px; background:var(--surface); border:1px solid var(--border2); padding:40px 36px; }
  .auth-logo { font-family:'Bebas Neue',sans-serif; font-size:2.2rem; color:var(--accent); letter-spacing:.1em; margin-bottom:4px; }
  .auth-logo span { color:var(--wiiu); }
  .auth-sub  { font-size:.65rem; color:var(--muted); letter-spacing:.2em; text-transform:uppercase; margin-bottom:28px; }
  .tab-row   { display:flex; gap:0; margin-bottom:24px; border-bottom:2px solid var(--border2); }
  .tab       { flex:1; padding:8px; font-family:'Bebas Neue',sans-serif; font-size:1.1rem; letter-spacing:.1em; background:none; border:none; color:var(--muted); cursor:pointer; }
  .tab.active{ color:var(--accent2); border-bottom:2px solid var(--accent2); margin-bottom:-2px; }
  .error-msg { background:rgba(201,79,58,.12); border:1px solid rgba(201,79,58,.4); color:#d44f3a; font-size:.75rem; padding:8px 12px; margin-bottom:16px; }
</style>
</head>
<body>
<div class="auth-box">
  <div class="auth-logo">Game <span>Collection</span></div>
  <div class="auth-sub">Invite-only collection tracker</div>

  <div class="tab-row">
    <button class="tab <?= $mode==='login'?'active':'' ?>" onclick="setMode('login')">Sign In</button>
    <button class="tab <?= $mode==='register'?'active':'' ?>" onclick="setMode('register')">Register</button>
  </div>

  <?php if ($error): ?>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- LOGIN -->
  <form id="form-login" method="POST" style="display:<?= $mode==='login'?'block':'none' ?>">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="mode" value="login">
    <div class="field"><label>Username</label><input type="text" name="username" required autocomplete="username"></div>
    <div class="field" style="margin-top:14px"><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div>
    <div style="display:flex;align-items:center;gap:8px;margin-top:12px">
      <input type="checkbox" name="remember_me" id="remember_me" value="1" style="width:auto">
      <label for="remember_me" style="font-size:.72rem;color:var(--muted);cursor:pointer">Remember me for 30 days</label>
    </div>
    <button type="submit" class="btn" style="width:100%;margin-top:16px;padding:12px">Sign In</button>
  </form>

  <!-- REGISTER -->
  <form id="form-register" method="POST" style="display:<?= $mode==='register'?'block':'none' ?>">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="mode" value="register">
    <div class="field"><label>Invite Code</label><input type="text" name="invite_code" required placeholder="Paste your invite code"></div>
    <div class="field" style="margin-top:14px"><label>Username</label><input type="text" name="username" required autocomplete="username"></div>
    <div class="field" style="margin-top:14px"><label>Password</label><input type="password" name="password" required autocomplete="new-password"></div>
    <div class="field" style="margin-top:14px"><label>Confirm Password</label><input type="password" name="confirm" required autocomplete="new-password"></div>
    <button type="submit" class="btn" style="width:100%;margin-top:20px;padding:12px">Create Account</button>
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
