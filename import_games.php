<?php
require_once __DIR__ . '/config.php';
$user = requireAdmin();

$systems = db()->query("SELECT * FROM systems WHERE active=1 ORDER BY sort_order")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import Games — Game Collection</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
  .import-wrap { max-width:700px; margin:36px auto; padding:0 20px 60px; }
  .import-wrap h1 { font-family:'Bebas Neue',sans-serif; font-size:2rem; color:var(--accent); letter-spacing:.06em; margin-bottom:6px; }
  .import-wrap p.desc { font-size:.75rem; color:var(--muted); margin-bottom:28px; line-height:1.6; }
  .step { background:var(--surface); border:1px solid var(--border2); padding:22px 24px; margin-bottom:16px; }
  .step-label { font-size:.58rem; letter-spacing:.25em; text-transform:uppercase; color:var(--muted); margin-bottom:10px; }
  .step h3 { font-family:'Bebas Neue',sans-serif; font-size:1.2rem; color:var(--accent2); letter-spacing:.06em; margin-bottom:12px; }
  textarea.game-list { width:100%; height:320px; font-size:.75rem; line-height:1.7; padding:12px; resize:vertical; }
  .preview-table { width:100%; border-collapse:collapse; font-size:.73rem; margin-top:12px; }
  .preview-table th { padding:7px 10px; font-size:.58rem; letter-spacing:.18em; text-transform:uppercase; color:var(--muted); text-align:left; border-bottom:1px solid var(--border2); }
  .preview-table td { padding:7px 10px; border-bottom:1px solid var(--border); }
  .tag-new  { background:rgba(74,158,107,.12); color:var(--green); border:1px solid rgba(74,158,107,.3); padding:1px 6px; font-size:.6rem; }
  .tag-dupe { background:rgba(212,121,59,.1); color:var(--orange); border:1px solid rgba(212,121,59,.3); padding:1px 6px; font-size:.6rem; }
  .tag-skip { background:rgba(106,101,96,.1); color:var(--muted); border:1px solid var(--border); padding:1px 6px; font-size:.6rem; }
  .result-box { background:var(--surface2); border:1px solid var(--border2); padding:16px 20px; margin-top:16px; font-size:.78rem; line-height:1.8; display:none; }
  .result-box.show { display:block; }
  .result-box .ok  { color:var(--green); }
  .result-box .err { color:var(--red); }
  #preview-wrap { display:none; }
</style>
<?= csrfScript() ?>
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/collection.php" class="site-logo" style="text-decoration:none">Game <span>Collection</span></a>
  <nav class="site-nav">
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link">Dashboard</a>
    <a href="<?= BASE_URL ?>/collection.php" class="nav-link">← Collection</a>
    <a href="<?= BASE_URL ?>/admin.php" class="nav-link">Admin</a>
    <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link">Sign Out</a>
  </nav>
</header>

<div class="import-wrap">
  <h1>Import Game List</h1>
  <p class="desc">
    Paste a list of game titles — one per line — select the system and click Preview.<br>
    Duplicate titles already in the database will be flagged and skipped automatically.
  </p>

  <!-- STEP 1: System -->
  <div class="step">
    <div class="step-label">Step 1</div>
    <h3>Select System</h3>
    <select id="sel-system" style="width:100%;padding:10px">
      <option value="">— Choose a system —</option>
      <?php foreach ($systems as $s): ?>
      <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['short_name']) ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- STEP 2: Paste -->
  <div class="step">
    <div class="step-label">Step 2</div>
    <h3>Paste Game Titles</h3>
    <textarea class="game-list" id="game-list" placeholder="Paste one game title per line, e.g.:

Super Mario 64
GoldenEye 007
Banjo-Kazooie
Mario Kart 64
..."></textarea>
    <div style="display:flex;justify-content:flex-end;margin-top:10px">
      <button class="btn" onclick="previewImport()">Preview Import →</button>
    </div>
  </div>

  <!-- STEP 3: Preview -->
  <div id="preview-wrap">
    <div class="step">
      <div class="step-label">Step 3</div>
      <h3>Review & Confirm</h3>
      <div id="preview-summary" style="font-size:.75rem;color:var(--muted);margin-bottom:10px"></div>
      <table class="preview-table">
        <thead><tr><th>#</th><th>Title</th><th>Status</th></tr></thead>
        <tbody id="preview-body"></tbody>
      </table>
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
        <button class="btn-ghost" onclick="resetImport()">← Start Over</button>
        <button class="btn" id="btn-confirm" onclick="confirmImport()">Import New Games</button>
      </div>
    </div>
  </div>

  <!-- Result -->
  <div class="result-box" id="result-box"></div>

</div>

<div class="toast" id="toast"></div>

<script>
const BASE = <?= json_encode(BASE_URL) ?>;
let parsedTitles = [];
let systemId = null;

function previewImport() {
  systemId = document.getElementById('sel-system').value;
  if (!systemId) { toast('Please select a system first.', true); return; }

  const raw = document.getElementById('game-list').value.trim();
  if (!raw) { toast('Please paste some game titles first.', true); return; }

  // Parse: one title per line, trim whitespace, skip empty lines
  parsedTitles = raw.split('\n')
    .map(l => l.trim())
    .filter(l => l.length > 0);

  if (!parsedTitles.length) { toast('No valid titles found.', true); return; }

  // Check against existing games in DB
  fetch(`${BASE}/api/import_preview.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ system_id: systemId, titles: parsedTitles })
  })
  .then(r => r.json())
  .then(res => {
    if (!res.ok) { toast('Preview failed: ' + res.error, true); return; }
    renderPreview(res.items);
  });
}

function renderPreview(items) {
  const tbody = document.getElementById('preview-body');
  tbody.innerHTML = '';

  let newCount  = 0;
  let dupeCount = 0;

  items.forEach((item, i) => {
    const tr = document.createElement('tr');
    let tag = '';
    if (item.status === 'new') {
      tag = '<span class="tag-new">New</span>';
      newCount++;
    } else if (item.status === 'duplicate') {
      tag = '<span class="tag-dupe">Already exists</span>';
      dupeCount++;
    }
    tr.innerHTML = `<td style="color:var(--muted);font-size:.65rem">${i+1}</td><td>${esc(item.title)}</td><td>${tag}</td>`;
    tbody.appendChild(tr);
  });

  document.getElementById('preview-summary').innerHTML =
    `<strong style="color:var(--green)">${newCount} new</strong> game${newCount!==1?'s':''} will be added &nbsp;·&nbsp; ` +
    `<strong style="color:var(--orange)">${dupeCount}</strong> already exist and will be skipped`;

  document.getElementById('btn-confirm').textContent = `Import ${newCount} New Game${newCount!==1?'s':''}`;
  document.getElementById('btn-confirm').disabled = newCount === 0;

  document.getElementById('preview-wrap').style.display = 'block';
  document.getElementById('preview-wrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function confirmImport() {
  const btn = document.getElementById('btn-confirm');
  btn.textContent = 'Importing...';
  btn.disabled = true;

  fetch(`${BASE}/api/import_games.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ system_id: systemId, titles: parsedTitles })
  })
  .then(r => r.json())
  .then(res => {
    const box = document.getElementById('result-box');
    box.classList.add('show');
    if (res.ok) {
      box.innerHTML = `<div class="ok">✓ Import complete — <strong>${res.imported}</strong> game${res.imported!==1?'s':''} added, <strong>${res.skipped}</strong> skipped (already existed).</div>`;
      document.getElementById('preview-wrap').style.display = 'none';
      document.getElementById('game-list').value = '';
      toast('Import complete!');
    } else {
      box.innerHTML = `<div class="err">✗ Import failed: ${esc(res.error||'Unknown error')}</div>`;
      btn.disabled = false;
      btn.textContent = 'Retry Import';
    }
  });
}

function resetImport() {
  document.getElementById('preview-wrap').style.display = 'none';
  document.getElementById('result-box').classList.remove('show');
  parsedTitles = [];
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function toast(msg, err=false) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.style.borderColor = err ? 'var(--red)' : 'var(--accent2)';
  t.style.color       = err ? 'var(--red)' : 'var(--accent)';
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2500);
}
</script>
</body>
</html>
