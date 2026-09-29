<?php
require_once __DIR__ . '/boot.php';
$user = requireAdmin();

$systems = db()->query("SELECT * FROM systems WHERE active=1 ORDER BY sort_order")->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pageTitle(tRaw('common.nav.import_games')) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<?= themeHead($user) ?>
<style>
  .import-wrap { max-width:700px; margin:36px auto; padding:0 20px 60px; }
  .import-wrap h1 { font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case); font-size:2rem; color:var(--accent); letter-spacing:.06em; margin-bottom:6px; }
  .import-wrap p.desc { font-size:.75rem; color:var(--muted); margin-bottom:28px; line-height:1.6; }
  .step { background:var(--surface); border:1px solid var(--border2); padding:22px 24px; margin-bottom:16px; }
  .step-label { font-size:.58rem; letter-spacing:.25em; text-transform:uppercase; color:var(--muted); margin-bottom:10px; }
  .step h3 { font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case); font-size:1.2rem; color:var(--accent2); letter-spacing:.06em; margin-bottom:12px; }
  textarea.game-list { width:100%; height:320px; font-size:.75rem; line-height:1.7; padding:12px; resize:vertical; }
  .preview-table { width:100%; border-collapse:collapse; font-size:.73rem; margin-top:12px; }
  .preview-table th { padding:7px 10px; font-size:.58rem; letter-spacing:.18em; text-transform:uppercase; color:var(--muted); text-align:left; border-bottom:1px solid var(--border2); }
  .preview-table td { padding:7px 10px; border-bottom:1px solid var(--border); }
  .tag-new  { background:color-mix(in srgb,var(--green) 12%,transparent); color:var(--green); border:1px solid color-mix(in srgb,var(--green) 30%,transparent); padding:1px 6px; font-size:.6rem; }
  .tag-dupe { background:color-mix(in srgb,var(--orange) 10%,transparent); color:var(--orange); border:1px solid color-mix(in srgb,var(--orange) 30%,transparent); padding:1px 6px; font-size:.6rem; }
  .tag-skip { background:color-mix(in srgb,var(--muted) 10%,transparent); color:var(--muted); border:1px solid var(--border); padding:1px 6px; font-size:.6rem; }
  .result-box { background:var(--surface2); border:1px solid var(--border2); padding:16px 20px; margin-top:16px; font-size:.78rem; line-height:1.8; display:none; }
  .result-box.show { display:block; }
  .result-box .ok  { color:var(--green); }
  .result-box .err { color:var(--red); }
  #preview-wrap { display:none; }
</style>
<?= csrfScript() ?>
<?= appScript(['import']) ?>
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/collection.php" class="site-logo" style="text-decoration:none"><?= siteLogoHtml() ?></a>
  <nav class="site-nav">
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link"><?= t('common.nav.dashboard') ?></a>
    <a href="<?= BASE_URL ?>/collection.php" class="nav-link">← <?= t('common.nav.collection') ?></a>
    <a href="<?= BASE_URL ?>/admin.php" class="nav-link"><?= t('common.nav.admin') ?></a>
    <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link"><?= t('common.nav.sign_out') ?></a>
  </nav>
</header>

<div class="import-wrap">
  <h1><?= t('import.title') ?></h1>
  <p class="desc">
    <?= t('import.intro') ?>
  </p>

  <!-- STEP 1: System -->
  <div class="step">
    <div class="step-label"><?= t('import.step', ['n' => 1]) ?></div>
    <h3><?= t('import.select_system') ?></h3>
    <select id="sel-system" style="width:100%;padding:10px">
      <option value="">— <?= t('import.choose_system') ?> —</option>
      <?php foreach ($systems as $s): ?>
      <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['short_name']) ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- STEP 2: Paste -->
  <div class="step">
    <div class="step-label"><?= t('import.step', ['n' => 2]) ?></div>
    <h3><?= t('import.paste_titles') ?></h3>
    <textarea class="game-list" id="game-list" placeholder="<?= t('import.placeholder') ?>

Super Mario 64
GoldenEye 007
Banjo-Kazooie
Mario Kart 64
..."></textarea>
    <div style="display:flex;justify-content:flex-end;margin-top:10px">
      <button class="btn" onclick="previewImport()"><?= t('import.preview') ?> →</button>
    </div>
  </div>

  <!-- STEP 3: Preview -->
  <div id="preview-wrap">
    <div class="step">
      <div class="step-label"><?= t('import.step', ['n' => 3]) ?></div>
      <h3><?= t('import.review') ?></h3>
      <div id="preview-summary" style="font-size:.75rem;color:var(--muted);margin-bottom:10px"></div>
      <table class="preview-table">
        <thead><tr><th>#</th><th><?= t('import.col_title') ?></th><th><?= t('import.col_status') ?></th></tr></thead>
        <tbody id="preview-body"></tbody>
      </table>
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
        <button class="btn-ghost" onclick="resetImport()">← <?= t('import.start_over') ?></button>
        <button class="btn" id="btn-confirm" onclick="confirmImport()"><?= t('import.import_new') ?></button>
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
  if (!systemId) { toast(tRaw('import.err_no_system'), true); return; }

  const raw = document.getElementById('game-list').value.trim();
  if (!raw) { toast(tRaw('import.err_no_titles'), true); return; }

  // Parse: one title per line, trim whitespace, skip empty lines
  parsedTitles = raw.split('\n')
    .map(l => l.trim())
    .filter(l => l.length > 0);

  if (!parsedTitles.length) { toast(tRaw('import.err_no_valid'), true); return; }

  // Check against existing games in DB
  fetch(`${BASE}/api/import_preview.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ system_id: systemId, titles: parsedTitles })
  })
  .then(r => r.json())
  .then(res => {
    if (!res.ok) { toast(tRaw('import.err_preview', {error: res.error}), true); return; }
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
      tag = `<span class="tag-new">${t('import.status_new')}</span>`;
      newCount++;
    } else if (item.status === 'duplicate') {
      tag = `<span class="tag-dupe">${t('import.status_exists')}</span>`;
      dupeCount++;
    }
    tr.innerHTML = `<td style="color:var(--muted);font-size:.65rem">${i+1}</td><td>${esc(item.title)}</td><td>${tag}</td>`;
    tbody.appendChild(tr);
  });

  document.getElementById('preview-summary').innerHTML =
    `<span style="color:var(--green)">${tn('import.summary_new', newCount)}</span> &nbsp;·&nbsp; ` +
    `<span style="color:var(--orange)">${tn('import.summary_dupe', dupeCount)}</span>`;

  document.getElementById('btn-confirm').textContent = tnRaw('import.import_n', newCount);
  document.getElementById('btn-confirm').disabled = newCount === 0;

  document.getElementById('preview-wrap').style.display = 'block';
  document.getElementById('preview-wrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function confirmImport() {
  const btn = document.getElementById('btn-confirm');
  btn.textContent = tRaw('import.importing');
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
      box.innerHTML = `<div class="ok">✓ ${tn('import.done', res.imported, {skipped: res.skipped})}</div>`;
      document.getElementById('preview-wrap').style.display = 'none';
      document.getElementById('game-list').value = '';
      toast(tRaw('import.done_toast'));
    } else {
      box.innerHTML = `<div class="err">✗ ${t('import.failed', {error: res.error || tRaw('common.err_unknown')})}</div>`;
      btn.disabled = false;
      btn.textContent = tRaw('import.retry');
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
