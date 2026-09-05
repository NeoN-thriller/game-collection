<?php
require_once __DIR__ . '/config.php';
$admin = requireAdmin();

$msg = '';

// ── ACTIONS ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'gen_invite') {
        $code = bin2hex(random_bytes(16));
        db()->prepare("INSERT INTO invite_codes (code, created_by) VALUES (?,?)")->execute([$code, $admin['id']]);
        $msg = 'Invite code generated: '.$code;
    }

    if ($action === 'user_status') {
        $uid    = (int)$_POST['user_id'];
        $status = in_array($_POST['status'], ['active','inactive','banned']) ? $_POST['status'] : 'inactive';
        if ($uid !== $admin['id']) {
            db()->prepare("UPDATE users SET status=? WHERE id=?")->execute([$status, $uid]);
            $msg = 'User updated.';
        } else { $msg = 'Cannot change your own status.'; }
    }

    if ($action === 'reset_password') {
        $uid  = (int)$_POST['user_id'];
        $pass = $_POST['new_password'] ?? '';
        if (strlen($pass) < 8) { $msg = 'Password must be at least 8 characters.'; }
        else {
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);
            db()->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $uid]);
            $msg = 'Password reset.';
        }
    }

    if ($action === 'add_game') {
        $sysId = (int)$_POST['system_id'];
        $title = trim($_POST['title'] ?? '');
        if ($title && $sysId) {
            $sort = preg_replace('/^(the |a |an )/i', '', strtolower($title));
            // Get next sort_order
            $st = db()->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM games WHERE system_id=?");
            $st->execute([$sysId]); $so = $st->fetchColumn();
            db()->prepare("INSERT INTO games (system_id, title, sort_title, sort_order) VALUES (?,?,?,?)")
                ->execute([$sysId, $title, $sort, $so]);
            $msg = 'Game added.';
        }
    }

    if ($action === 'toggle_game') {
        $gid = (int)$_POST['game_id'];
        db()->prepare("UPDATE games SET active = 1-active WHERE id=?")->execute([$gid]);
        $msg = 'Game updated.';
    }

    if ($action === 'set_system_icon') {
        $sid = (int)$_POST['system_id'];
        if (!empty($_FILES['icon_image']) && $_FILES['icon_image']['error']===UPLOAD_ERR_OK) {
            $file  = $_FILES['icon_image'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            if (in_array($mime, ALLOWED_TYPES) || $mime === 'image/svg+xml') {
                $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg'];
                $ext    = $extMap[$mime] ?? 'png';
                $iconDir = __DIR__ . '/uploads/icons/';
                if (!is_dir($iconDir)) mkdir($iconDir, 0755, true);
                $fn = 'sys_'.$sid.'_'.uniqid().'.'.$ext;
                move_uploaded_file($file['tmp_name'], $iconDir.$fn);
                db()->prepare("UPDATE systems SET icon_image=? WHERE id=?")->execute([$fn, $sid]);
                $msg = 'System icon updated.';
            }
        }
    }

    if ($action === 'set_default_image') {
        $gid = (int)$_POST['game_id'];
        if (!empty($_FILES['default_image']) && $_FILES['default_image']['error']===UPLOAD_ERR_OK) {
            $file  = $_FILES['default_image'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            if (in_array($mime, ALLOWED_TYPES)) {
                $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime];
                $fn   = 'game_'.$gid.'_'.uniqid().'.'.$ext;
                move_uploaded_file($file['tmp_name'], DEFAULTS_DIR.$fn);
                db()->prepare("UPDATE games SET default_image=? WHERE id=?")->execute([$fn, $gid]);
                $msg = 'Default image set.';
            }
        }
    }

    if ($action === 'add_system') {
        $name  = trim($_POST['name']       ?? '');
        $short = strtoupper(trim($_POST['short_name'] ?? ''));
        if ($name && $short) {
            $st = db()->query("SELECT COALESCE(MAX(sort_order),0)+10 FROM systems"); $so = $st->fetchColumn();
            db()->prepare("INSERT INTO systems (name, short_name, region, sort_order) VALUES (?,?,'PAL',?)")
                ->execute([$name, $short, $so]);
            $msg = 'System added.';
        }
    }

    if ($action === 'delete_invite') {
        $id = (int)$_POST['invite_id'];
        db()->prepare("DELETE FROM invite_codes WHERE id=? AND used_by IS NULL")->execute([$id]);
        $msg = 'Invite deleted.';
    }
}

// ── LOAD DATA ────────────────────────────
$users   = db()->query("SELECT * FROM users ORDER BY created_at")->fetchAll();
$invites = db()->query("
    SELECT ic.*, u1.username AS creator, u2.username AS used_by_name
    FROM invite_codes ic
    JOIN users u1 ON u1.id = ic.created_by
    LEFT JOIN users u2 ON u2.id = ic.used_by
    ORDER BY ic.created_at DESC
    LIMIT 50
")->fetchAll();
$systems = db()->query("SELECT * FROM systems ORDER BY sort_order")->fetchAll();

// Games per system (paginated by system selection)
$viewSys = (int)($_GET['sys'] ?? ($systems[0]['id'] ?? 0));
$gamesSt = db()->prepare("SELECT * FROM games WHERE system_id=? ORDER BY sort_title");
$gamesSt->execute([$viewSys]);
$games = $gamesSt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — Game Collection</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/dashboard.php" class="site-logo" style="text-decoration:none">Game <span>Collection</span> <span style="font-size:1rem;color:var(--accent2);letter-spacing:.1em">ADMIN</span></a>
  <nav class="site-nav">
    <a href="<?= BASE_URL ?>/pc_import.php" class="nav-link">PriceCharting Import</a>
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link">Dashboard</a>
    <a href="<?= BASE_URL ?>/collection.php" class="nav-link">Collection</a>
    <a href="<?= BASE_URL ?>/wishlist.php" class="nav-link">Wishlist</a>
    <a href="<?= BASE_URL ?>/settings.php" class="nav-link">Settings</a>
    <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link">Sign Out</a>
  </nav>
</header>

<div class="admin-wrap">

<?php if ($msg): ?>
<div style="background:rgba(74,158,107,.1);border:1px solid rgba(74,158,107,.3);color:var(--green);padding:10px 16px;margin-bottom:20px;font-size:.8rem;">
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- ── IMAGE SETTINGS ── -->
<div class="admin-section">
  <h2>Image Settings</h2>
  <p style="font-size:.74rem;color:var(--muted);margin-bottom:14px">Uploaded photos are automatically resized and compressed. EXIF orientation is corrected automatically on upload.</p>
  <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
    <div class="field" style="width:160px"><label>Max Width (px)</label><input type="number" id="img-max-w" min="200" max="4000" value="1200" step="100"></div>
    <div class="field" style="width:160px"><label>Max Height (px)</label><input type="number" id="img-max-h" min="200" max="4000" value="1200" step="100"></div>
    <div class="field" style="width:140px"><label>JPEG Quality (1–100)</label><input type="number" id="img-qual" min="10" max="100" value="80"></div>
    <button class="btn btn-sm" onclick="saveImgSettings()">Save Image Settings</button>
  </div>
  <div id="img-settings-msg" style="font-size:.75rem;color:var(--green);margin-top:10px;display:none">Settings saved.</div>
</div>

<!-- ── INVITE CODES ── -->
<div class="admin-section">
  <h2>Invite Codes</h2>
  <form method="POST" style="display:flex;gap:8px;margin-bottom:16px">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="gen_invite">
    <button class="btn btn-sm" type="submit">Generate New Invite Code</button>
  </form>
  <table class="admin-table">
    <thead><tr><th>Code</th><th>Created By</th><th>Created</th><th>Used By</th><th>Used At</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($invites as $inv): ?>
    <tr>
      <td><?php if (!$inv['used_by']): ?><span class="invite-code"><?= htmlspecialchars($inv['code']) ?></span><?php else: ?><span style="color:var(--muted);text-decoration:line-through;font-size:.72rem"><?= htmlspecialchars($inv['code']) ?></span><?php endif; ?></td>
      <td><?= htmlspecialchars($inv['creator']) ?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= $inv['created_at'] ?></td>
      <td><?= $inv['used_by_name'] ? htmlspecialchars($inv['used_by_name']) : '<span style="color:var(--muted)">—</span>' ?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= $inv['used_at'] ?: '—' ?></td>
      <td><?php if (!$inv['used_by']): ?>
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf"      value="<?= csrf() ?>">
          <input type="hidden" name="action"    value="delete_invite">
          <input type="hidden" name="invite_id" value="<?= $inv['id'] ?>">
          <button class="btn-danger" type="submit">Delete</button>
        </form>
      <?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── PRICECHARTING IMPORT ── -->
<div class="admin-section">
  <h2>PriceCharting Import</h2>
  <p style="font-size:.74rem;color:var(--muted);margin-bottom:14px;line-height:1.7">
    Paste your PriceCharting CSV export to update CIB prices, links and cover art.<br>
    Match priority: 1) by <code>data-product</code> ID &nbsp;2) by console + title &nbsp;3) import as new game.
  </p>
  <textarea id="pc-csv" style="width:100%;height:130px;background:var(--surface);border:1px solid var(--border2);color:var(--text);font-family:'DM Mono',monospace;font-size:.7rem;padding:10px;resize:vertical;outline:none" placeholder="Paste CSV here including header row:
console,name,data-product,link,loose,cib,new,coverArt,coverArtBase64
WiiU,007 Legends,63286,https://...,17.51,24.86,40.83,63286.jpg,data:image/jpeg;base64,..."></textarea>
  <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
    <button class="btn btn-sm" onclick="pcPreview()">Preview Import →</button>
    <a href="<?= BASE_URL ?>/pc_import.php" class="btn-outline" style="padding:8px 14px;font-size:.72rem">Full Import Page ↗</a>
  </div>
  <div id="pc-result" style="display:none;margin-top:14px;background:var(--surface2);border:1px solid var(--border2);padding:12px 16px;font-size:.75rem;line-height:1.9"></div>
</div>

<!-- ── USERS ── -->
<div class="admin-section">
  <h2>Users</h2>
  <table class="admin-table">
    <thead><tr><th>Username</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><?= htmlspecialchars($u['username']) ?></td>
      <td><span class="tag tag-<?= $u['role']==='admin'?'admin':'active' ?>"><?= $u['role'] ?></span></td>
      <td><span class="tag tag-<?= $u['status'] ?>"><?= $u['status'] ?></span></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= $u['last_login'] ?: 'Never' ?></td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if ($u['id'] !== $admin['id']): ?>
        <!-- Status change -->
        <form method="POST" style="display:flex;gap:4px">
          <input type="hidden" name="csrf"    value="<?= csrf() ?>">
          <input type="hidden" name="action"  value="user_status">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
          <select name="status" style="font-size:.68rem;padding:3px 6px">
            <option value="active"   <?= $u['status']==='active'  ?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $u['status']==='inactive'?'selected':'' ?>>Inactive</option>
            <option value="banned"   <?= $u['status']==='banned'  ?'selected':'' ?>>Banned</option>
          </select>
          <button class="btn-icon" type="submit">Set</button>
        </form>
        <!-- Reset password -->
        <form method="POST" style="display:flex;gap:4px" onsubmit="return confirmReset()">
          <input type="hidden" name="csrf"    value="<?= csrf() ?>">
          <input type="hidden" name="action"  value="reset_password">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
          <input type="text" name="new_password" placeholder="New password" style="font-size:.68rem;padding:3px 8px;width:130px">
          <button class="btn-icon" type="submit">Reset PW</button>
        </form>
        <?php else: ?><span style="color:var(--muted);font-size:.7rem">You</span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── SYSTEMS ── -->
<div class="admin-section">
  <h2>Systems</h2>
  <form method="POST" style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="add_system">
    <input type="text" name="name"       placeholder="Full name e.g. PAL Nintendo DS" style="width:260px">
    <input type="text" name="short_name" placeholder="Short e.g. DS" style="width:100px">
    <button class="btn btn-sm" type="submit">Add System</button>
  </form>
  <table class="admin-table">
    <thead><tr><th>#</th><th>Icon</th><th>Name</th><th>Short</th><th>Region</th><th>Active</th><th>Set Icon</th></tr></thead>
    <tbody>
    <?php foreach ($systems as $s): ?>
    <tr>
      <td style="color:var(--muted);font-size:.7rem"><?= $s['sort_order'] ?></td>
      <td><?php if (!empty($s['icon_image'])): ?>
        <img src="<?= BASE_URL ?>/uploads/icons/<?= htmlspecialchars($s['icon_image']) ?>" style="width:28px;height:28px;object-fit:contain">
      <?php else: ?><span style="color:var(--muted);font-size:.7rem">—</span><?php endif; ?></td>
      <td><?= htmlspecialchars($s['name']) ?></td>
      <td style="color:var(--wiiu)"><?= htmlspecialchars($s['short_name']) ?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?= $s['region'] ?></td>
      <td><span class="tag <?= $s['active']?'tag-active':'tag-inactive' ?>"><?= $s['active']?'Yes':'No' ?></span></td>
      <td>
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:4px">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="set_system_icon">
          <input type="hidden" name="system_id" value="<?= $s['id'] ?>">
          <input type="file" name="icon_image" accept="image/*" style="font-size:.65rem;width:140px">
          <button class="btn-icon" type="submit">Set Icon</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── GAME LIST MANAGEMENT ── -->
<div class="admin-section">
  <h2>Game Lists</h2>

  <!-- System tabs -->
  <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:16px;border-bottom:1px solid var(--border2);padding-bottom:0">
    <?php foreach ($systems as $s): ?>
    <a href="?sys=<?= $s['id'] ?>" style="padding:8px 14px;font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;color:<?= $s['id']==$viewSys?'var(--accent2)':'var(--muted)' ?>;border-bottom:2px solid <?= $s['id']==$viewSys?'var(--accent2)':'transparent' ?>;white-space:nowrap;margin-bottom:-1px"><?= htmlspecialchars($s['short_name']) ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Add game -->
  <form method="POST" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
    <input type="hidden" name="csrf"      value="<?= csrf() ?>">
    <input type="hidden" name="action"    value="add_game">
    <input type="hidden" name="system_id" value="<?= $viewSys ?>">
    <input type="text" name="title" placeholder="Game title" style="flex:1;min-width:200px">
    <button class="btn btn-sm" type="submit">Add Game</button>
  </form>

  <!-- Filter -->
  <div class="search-wrap" style="margin-bottom:12px;max-width:340px">
    <span class="search-icon">⌕</span>
    <input type="text" id="game-filter" placeholder="Filter titles..." oninput="filterGames(this.value)">
  </div>

  <table class="admin-table" id="games-admin-table">
    <thead><tr><th>#</th><th>Title</th><th>Default Image</th><th>Active</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($games as $g): ?>
    <tr style="<?= !$g['active']?'opacity:.45':'' ?>">
      <td style="color:var(--muted);font-size:.7rem"><?= $g['sort_order'] ?></td>
      <td><?= htmlspecialchars($g['title']) ?></td>
      <td>
        <?php if ($g['default_image']): ?>
          <img src="<?= BASE_URL ?>/uploads/defaults/<?= htmlspecialchars($g['default_image']) ?>" style="height:32px;border:1px solid var(--border2)">
        <?php else: ?>
          <span style="color:var(--muted);font-size:.7rem">—</span>
        <?php endif; ?>
      </td>
      <td><span class="tag <?= $g['active']?'tag-active':'tag-inactive' ?>"><?= $g['active']?'Yes':'No' ?></span></td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <!-- Toggle active -->
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf"    value="<?= csrf() ?>">
          <input type="hidden" name="action"  value="toggle_game">
          <input type="hidden" name="game_id" value="<?= $g['id'] ?>">
          <button class="btn-icon" type="submit"><?= $g['active']?'Disable':'Enable' ?></button>
        </form>
        <!-- Set default image -->
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:4px">
          <input type="hidden" name="csrf"    value="<?= csrf() ?>">
          <input type="hidden" name="action"  value="set_default_image">
          <input type="hidden" name="game_id" value="<?= $g['id'] ?>">
          <input type="file" name="default_image" accept="image/*" style="font-size:.65rem;width:160px">
          <button class="btn-icon" type="submit">Set Image</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

</div><!-- /admin-wrap -->

<script>
function confirmReset() { return confirm('Reset this user\'s password?'); }

// ── IMAGE SETTINGS ──
async function loadImgSettings() {
  const res = await fetch(ADMIN_BASE+'/api/image_settings.php').then(r=>r.json());
  if (res.ok) {
    document.getElementById('img-max-w').value = res.settings.max_width;
    document.getElementById('img-max-h').value = res.settings.max_height;
    document.getElementById('img-qual').value  = res.settings.quality;
  }
}
async function saveImgSettings() {
  const res = await fetch(ADMIN_BASE+'/api/image_settings.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({max_width:+document.getElementById('img-max-w').value,max_height:+document.getElementById('img-max-h').value,quality:+document.getElementById('img-qual').value})
  }).then(r=>r.json());
  const msg = document.getElementById('img-settings-msg');
  msg.style.display='block'; msg.textContent=res.ok?'Settings saved.':'Error saving.';
  msg.style.color=res.ok?'var(--green)':'var(--red)';
  setTimeout(()=>msg.style.display='none',2000);
}

// ── PRICECHARTING QUICK IMPORT ──
function filterGames(q) {
  q = q.toLowerCase();
  document.querySelectorAll('#games-admin-table tbody tr').forEach(tr => {
    const title = tr.querySelector('td:nth-child(2)')?.textContent.toLowerCase() || '';
    tr.style.display = !q || title.includes(q) ? '' : 'none';
  });
}

// ── ADMIN TOAST HELPER ──
function adminToast(msg, ok=true) {
  let t = document.getElementById('admin-toast');
  if (!t) { t=document.createElement('div'); t.id='admin-toast'; t.className='toast'; document.body.appendChild(t); }
  t.textContent = msg;
  t.style.borderColor = ok ? 'var(--accent2)' : 'var(--red)';
  t.style.color       = ok ? 'var(--accent)'  : 'var(--red)';
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'), 2500);
}

// ── INTERCEPT ALL ADMIN FORMS WITH AJAX ──
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form[method="POST"]').forEach(form => {
    // Skip forms that already have special handlers
    if (form.id === 'pc-import-form') return;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      try {
        const res = await fetch(window.location.href, { method:'POST', body: fd });
        const text = await res.text();
        // Check if it redirected (success) or has an error
        const action = fd.get('action') || 'save';
        const actionLabels = {
          gen_invite:        'Invite code generated',
          del_invite:        'Invite code deleted',
          user_status:       'User status updated',
          user_reset_pw:     'Password reset',
          add_system:        'System added',
          set_system_icon:   'System icon updated',
          add_game:          'Game added',
          toggle_game:       'Game updated',
          set_default_image: 'Cover image set',
        };
        adminToast(actionLabels[action] || 'Saved successfully.');
        // Reload section data dynamically where possible
        if (['set_system_icon','set_default_image','toggle_game','add_game'].includes(action)) {
          setTimeout(()=>window.location.reload(), 800);
        } else if (['gen_invite','del_invite'].includes(action)) {
          setTimeout(()=>window.location.reload(), 800);
        } else if (action === 'add_system') {
          setTimeout(()=>window.location.reload(), 800);
        } else if (['user_status','user_reset_pw'].includes(action)) {
          setTimeout(()=>window.location.reload(), 800);
        }
      } catch(err) {
        adminToast('Error: ' + err.message, false);
      }
    });
  });
});

const ADMIN_BASE = <?= json_encode(BASE_URL) ?>;

loadImgSettings();

function parseCSVLine(line) {
  const result=[]; let cur='',inQ=false;
  for(let i=0;i<line.length;i++){const ch=line[i];if(ch==='"')inQ=!inQ;else if(ch===','&&!inQ){result.push(cur);cur='';}else cur+=ch;}
  result.push(cur); return result;
}

function parseCSV(text) {
  const lines=text.trim().split('\n'); if(lines.length<2) return [];
  const hdr=parseCSVLine(lines[0]); const idx={};
  hdr.forEach((h,i)=>idx[h.trim()]=i);
  const req=['console','name','data-product','link','cib','coverArtBase64'];
  for(const r of req){if(idx[r]===undefined){alert('Missing column: '+r);return[];}}
  const rows=[];
  for(let i=1;i<lines.length;i++){
    const l=lines[i].trim(); if(!l) continue;
    const c=parseCSVLine(l);
    rows.push({console:(c[idx['console']]||'').trim().toUpperCase(),name:(c[idx['name']]||'').trim(),pc_id:(c[idx['data-product']]||'').trim(),link:(c[idx['link']]||'').trim(),cib:idx['cib']!==undefined&&c[idx['cib']]!==''?(parseFloat(c[idx['cib']])??null):null,loose:idx['loose']!==undefined&&c[idx['loose']]!==''?(parseFloat(c[idx['loose']])??null):null,new:idx['new']!==undefined&&c[idx['new']]!==''?(parseFloat(c[idx['new']])??null):null,artBase64:(c[idx['coverArtBase64']]||'').trim()});
  }
  return rows;
}

async function pcPreview() {
  const csv = document.getElementById('pc-csv').value.trim();
  if (!csv) { alert('Paste CSV first.'); return; }
  const rows = parseCSV(csv);
  if (!rows.length) { alert('No valid rows found.'); return; }

  const res = document.getElementById('pc-result');
  res.style.display='block'; res.innerHTML='Previewing...';

  const prev = await fetch(ADMIN_BASE+'/api/pc_preview.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({rows:rows.map(r=>({console:r.console,pc_id:r.pc_id,name:r.name,cib:r.cib,hasArt:!!r.artBase64}))})}).then(r=>r.json());
  if (!prev.ok) { res.innerHTML='<span style="color:var(--red)">Preview failed: '+prev.error+'</span>'; return; }

  const matched = prev.items.filter(x=>x.status==='match').length;
  const newG    = prev.items.filter(x=>x.status==='new' && x.system_ok).length;
  const noSys   = prev.items.filter(x=>x.status==='new' && !x.system_ok).length;

  res.innerHTML = `Found: <strong style="color:var(--green)">${matched} matches</strong>, <strong style="color:var(--wiiu)">${newG} new</strong>${noSys?`, <strong style="color:var(--red)">${noSys} unknown system</strong>`:''}. <button class="btn btn-sm" onclick="pcConfirm()" style="margin-left:12px">Confirm Import</button>`;
  res._rows = rows;
}

async function pcConfirm() {
  const res = document.getElementById('pc-result');
  const rows = res._rows; if (!rows) return;
  res.innerHTML = 'Importing...';
  const BATCH=20; let imported=0,updated=0,errors=0;
  for(let i=0;i<rows.length;i+=BATCH){
    const r=await fetch(ADMIN_BASE+'/api/pc_import.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({rows:rows.slice(i,i+BATCH)})}).then(r=>r.json());
    if(r.ok){imported+=r.imported||0;updated+=r.updated||0;errors+=r.errors||0;}else errors++;
  }
  res.innerHTML=`<span style="color:var(--green)">✓ Done — ${imported} added, ${updated} updated${errors?', <span style="color:var(--red)">'+errors+' errors</span>':''}.</span>`;
}
</script>
</body>
</html>
