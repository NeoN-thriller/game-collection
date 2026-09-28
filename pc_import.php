<?php
require_once __DIR__ . '/config.php';
$user = requireAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PriceCharting Import — Game Collection</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<style>
  .pc-wrap { max-width:960px; margin:36px auto; padding:0 20px 60px; }
  .pc-wrap h1 { font-family:'Bebas Neue',sans-serif; font-size:2rem; color:var(--accent); letter-spacing:.06em; margin-bottom:6px; }
  .pc-wrap p.desc { font-size:.75rem; color:var(--muted); margin-bottom:28px; line-height:1.7; }
  .step { background:var(--surface); border:1px solid var(--border2); padding:22px 24px; margin-bottom:16px; }
  .step-label { font-size:.58rem; letter-spacing:.25em; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
  .step h3 { font-family:'Bebas Neue',sans-serif; font-size:1.2rem; color:var(--accent2); letter-spacing:.06em; margin-bottom:12px; }
  textarea.csv-input { width:100%; height:160px; font-size:.7rem; line-height:1.6; padding:10px; resize:vertical; }
  .preview-table { width:100%; border-collapse:collapse; font-size:.71rem; margin-top:12px; }
  .preview-table th { padding:7px 10px; font-size:.58rem; letter-spacing:.15em; text-transform:uppercase; color:var(--muted); text-align:left; border-bottom:1px solid var(--border2); }
  .preview-table td { padding:7px 10px; border-bottom:1px solid var(--border); vertical-align:middle; }
  .tag-match  { background:rgba(74,158,107,.12); color:var(--green);  border:1px solid rgba(74,158,107,.3); padding:1px 6px; font-size:.6rem; }
  .tag-new    { background:rgba(0,154,199,.1);   color:var(--wiiu);   border:1px solid rgba(0,154,199,.3);  padding:1px 6px; font-size:.6rem; }
  .tag-nosy   { background:rgba(201,79,58,.1);   color:var(--red);    border:1px solid rgba(201,79,58,.3);  padding:1px 6px; font-size:.6rem; }
  .tag-skip   { background:rgba(106,101,96,.1);  color:var(--muted);  border:1px solid var(--border);       padding:1px 6px; font-size:.6rem; }
  .tag-update { background:rgba(212,121,59,.1);  color:var(--orange); border:1px solid rgba(212,121,59,.3); padding:1px 6px; font-size:.6rem; }
  .s-chip { padding:4px 12px; font-size:.68rem; }
  .summary-chips { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
  .thumb { width:30px; height:30px; object-fit:cover; border:1px solid var(--border2); display:block; }
  #preview-wrap { display:none; }
  .result-box { background:var(--surface2); border:1px solid var(--border2); padding:16px 20px; margin-top:16px; font-size:.78rem; line-height:2; display:none; }
  .result-box.show { display:block; }
  .ok   { color:var(--green); } .warn { color:var(--orange); } .err { color:var(--red); }
</style>
<?= csrfScript() ?>
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/dashboard.php" class="site-logo" style="text-decoration:none">Game <span>Collection</span></a>
  <nav class="site-nav">
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link">Dashboard</a>
    <a href="<?= BASE_URL ?>/collection.php" class="nav-link">Collection</a>
    <a href="<?= BASE_URL ?>/wishlist.php" class="nav-link">Wishlist</a>
    <a href="<?= BASE_URL ?>/admin.php" class="nav-link">← Admin</a>
    <a href="<?= BASE_URL ?>/settings.php" class="nav-link">Settings</a>
    <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link">Sign Out</a>
  </nav>
</header>

<div class="pc-wrap">
  <h1>PriceCharting Import</h1>
  <p class="desc">
    Paste your PriceCharting CSV export. Matching priority: <strong>1)</strong> by <code>data-product</code> ID &nbsp;<strong>2)</strong> by console + title &nbsp;<strong>3)</strong> import as new game.<br>
    CIB price, link and cover art are updated if they differ from what's stored. Existing cover art is never overwritten.
  </p>

  <div class="step">
    <div class="step-label">Step 1</div>
    <h3>Paste CSV</h3>
    <textarea class="csv-input" id="csv-input" placeholder="Paste CSV including header:
console,name,data-product,link,loose,cib,new,coverArt,coverArtBase64
WiiU,007 Legends,63286,https://www.pricecharting.com/game/pal-wii-u/007-legends,17.51,24.86,40.83,63286.jpg,data:image/jpeg;base64,..."></textarea>
    <div style="display:flex;justify-content:flex-end;margin-top:10px">
      <button class="btn" onclick="previewImport()">Preview →</button>
    </div>
  </div>

  <div id="preview-wrap">
    <div class="step">
      <div class="step-label">Step 2</div>
      <h3>Review & Confirm</h3>
      <div class="summary-chips" id="summary-chips"></div>
      <table class="preview-table">
        <thead>
          <tr>
            <th>Art</th><th>Console</th><th>CSV Name</th><th>Matched / Action</th>
            <th>Loose</th><th>CIB</th><th>New</th><th>Last Updated</th><th>Art</th><th>Status</th>
          </tr>
        </thead>
        <tbody id="preview-body"></tbody>
      </table>
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
        <button class="btn-ghost" onclick="reset()">← Start Over</button>
        <button class="btn" id="btn-confirm" onclick="confirmImport()">Import</button>
      </div>
    </div>
  </div>

  <div class="result-box" id="result-box"></div>
</div>

<div class="toast" id="toast"></div>

<script>
const BASE = <?= json_encode(BASE_URL) ?>;
let parsedRows = [];
let previewData = [];

function parseCSV(text) {
  const lines = text.trim().split('\n');
  if (lines.length < 2) return [];
  const header = parseCSVLine(lines[0]);
  const idx = {};
  header.forEach((h,i) => idx[h.trim()] = i);
  const required = ['console','name','data-product','link','cib','coverArtBase64'];
  for (const r of required) {
    if (idx[r] === undefined) { toast(`Missing CSV column: ${r}`, true); return []; }
  }
  const rows = [];
  for (let i=1;i<lines.length;i++) {
    const line = lines[i].trim(); if (!line) continue;
    const cols = parseCSVLine(line);
    rows.push({
      console:    (cols[idx['console']]||'').trim().toUpperCase(),
      name:       (cols[idx['name']]||'').trim(),
      pc_id:      (cols[idx['data-product']]||'').trim(),
      link:       (cols[idx['link']]||'').trim(),
      cib:        idx['cib']   !== undefined && cols[idx['cib']]   !== '' ? (parseFloat(cols[idx['cib']])   ?? null) : null,
      loose:      idx['loose'] !== undefined && cols[idx['loose']] !== '' ? (parseFloat(cols[idx['loose']]) ?? null) : null,
      new:        idx['new']   !== undefined && cols[idx['new']]   !== '' ? (parseFloat(cols[idx['new']])   ?? null) : null,
      artBase64:  (cols[idx['coverArtBase64']]||'').trim(),
    });
  }
  return rows;
}

function parseCSVLine(line) {
  const result=[]; let cur='',inQ=false;
  for (let i=0;i<line.length;i++) {
    const ch=line[i];
    if (ch==='"') inQ=!inQ;
    else if (ch===','&&!inQ) { result.push(cur); cur=''; }
    else cur+=ch;
  }
  result.push(cur); return result;
}

async function previewImport() {
  const csv = document.getElementById('csv-input').value.trim();
  if (!csv) { toast('Paste CSV first.', true); return; }
  parsedRows = parseCSV(csv);
  if (!parsedRows.length) { toast('No valid rows found.', true); return; }

  const res = await fetch(`${BASE}/api/pc_preview.php`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({rows: parsedRows.map(r=>({console:r.console,pc_id:r.pc_id,name:r.name,cib:r.cib,hasArt:!!r.artBase64}))})
  }).then(r=>r.json());

  if (!res.ok) { toast('Preview failed: '+res.error, true); return; }
  previewData = res.items;
  renderPreview();
}

function renderPreview() {
  const tbody = document.getElementById('preview-body');
  tbody.innerHTML = '';
  let cntMatch=0, cntNew=0, cntNoSys=0, cntArtSet=0, cntArtSkip=0, cntPriceUpdate=0;

  previewData.forEach((item,i) => {
    const row = parsedRows[i];
    const tr  = document.createElement('tr');

    let statusTag='', matchCell='', artTag='', lastUpd='—';
    let looseCell='—', cibCell='—', newCell='—';

    const thumb = row.artBase64
      ? `<img class="thumb" src="${row.artBase64.substring(0,500)}" onerror="this.style.display='none'">`
      : '—';

    if (item.status === 'match') {
      cntMatch++;
      const mt = item.match_type==='pc_id'?'by ID':'by title';
      statusTag  = `<span class="tag-match">✓ Match (${mt})</span>`;
      matchCell  = esc(item.game_title);
      lastUpd    = item.last_updated ? item.last_updated.substring(0,10) : '—';
      artTag     = item.art_action==='set' ? `<span class="tag-update">Will set</span>` :
                   item.art_action==='skip'? `<span class="tag-skip">Keep existing</span>` :
                                             `<span class="tag-skip">No art</span>`;
      if (item.art_action==='set') cntArtSet++;
      if (item.art_action==='skip') cntArtSkip++;
      if (item.cib_changed) cntPriceUpdate++;
    } else if (item.status === 'new') {
      if (item.system_ok) {
        cntNew++;
        statusTag = `<span class="tag-new">+ New game</span>`;
        matchCell = '<span style="color:var(--muted)">Will be created</span>';
        artTag    = row.artBase64 ? `<span class="tag-update">Will set</span>` : '<span class="tag-skip">No art</span>';
      } else {
        cntNoSys++;
        statusTag = `<span class="tag-nosy">✗ Unknown system: ${esc(row.console)}</span>`;
        matchCell = '<span style="color:var(--red)">System not found</span>';
        artTag = '—';
      }
    }

    // Price cells — always show from CSV
    looseCell = row.loose != null ? `<span style="color:var(--muted)">€${row.loose.toFixed(2)}</span>` : '—';
    cibCell   = row.cib   != null ? `<span style="color:var(--wiiu)${item.cib_changed?' ;font-weight:bold':''}">€${row.cib.toFixed(2)}${item.cib_changed?' ↑':''}</span>` : '—';
    newCell   = row.new   != null ? `<span style="color:var(--green)">€${row.new.toFixed(2)}</span>` : '—';

    tr.innerHTML = `
      <td>${thumb}</td>
      <td style="color:var(--wiiu);font-size:.65rem">${esc(row.console)}</td>
      <td style="color:var(--text2)">${esc(row.name)}</td>
      <td>${matchCell}</td>
      <td>${looseCell}</td>
      <td>${cibCell}</td>
      <td>${newCell}</td>
      <td style="color:var(--muted);font-size:.65rem">${lastUpd}</td>
      <td>${artTag}</td>
      <td>${statusTag}</td>
    `;
    tbody.appendChild(tr);
  });

  document.getElementById('summary-chips').innerHTML = `
    <span class="s-chip tag-match">${cntMatch} matched</span>
    <span class="s-chip tag-new">${cntNew} new games</span>
    ${cntNoSys ? `<span class="s-chip tag-nosy">${cntNoSys} unknown system</span>` : ''}
    <span class="s-chip tag-update">${cntPriceUpdate} prices updating</span>
    <span class="s-chip tag-update">${cntArtSet} art to set</span>
    ${cntArtSkip ? `<span class="s-chip tag-skip">${cntArtSkip} art kept</span>` : ''}
  `;

  const total = cntMatch + cntNew;
  document.getElementById('btn-confirm').textContent = `Import / Update ${total} Games`;
  document.getElementById('btn-confirm').disabled = total === 0;
  document.getElementById('preview-wrap').style.display='block';
  document.getElementById('preview-wrap').scrollIntoView({behavior:'smooth',block:'start'});
}

async function confirmImport() {
  const btn = document.getElementById('btn-confirm');
  btn.disabled = true;
  const BATCH = 20;
  let imported=0, updated=0, skippedArt=0, errors=0;

  for (let i=0; i<parsedRows.length; i+=BATCH) {
    const batch = parsedRows.slice(i, i+BATCH);
    btn.textContent = `Importing... ${Math.min(i+BATCH,parsedRows.length)}/${parsedRows.length}`;
    const res = await fetch(`${BASE}/api/pc_import.php`, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({rows: batch})
    }).then(r=>r.json());
    if (res.ok) { imported+=res.imported||0; updated+=res.updated||0; skippedArt+=res.skipped_art||0; errors+=res.errors||0; }
    else errors++;
  }

  const box = document.getElementById('result-box');
  box.classList.add('show');
  box.innerHTML = `
    <div class="ok">✓ Done</div>
    ${imported ? `<div class="ok">• ${imported} new game${imported!==1?'s':''} added to database</div>` : ''}
    ${updated  ? `<div class="ok">• ${updated} game${updated!==1?'s':''} updated (CIB price / link / art)</div>` : ''}
    ${skippedArt ? `<div class="warn">• ${skippedArt} cover art${skippedArt!==1?'s':''} skipped (existing art kept)</div>` : ''}
    ${errors   ? `<div class="err">• ${errors} error${errors!==1?'s':''}</div>` : ''}
  `;
  document.getElementById('preview-wrap').style.display='none';
  toast('Import complete!');
}

function reset() {
  document.getElementById('preview-wrap').style.display='none';
  document.getElementById('result-box').classList.remove('show');
  parsedRows=[]; previewData=[];
}

function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function toast(msg,err=false){const t=document.getElementById('toast');t.textContent=msg;t.style.borderColor=err?'var(--red)':'var(--accent2)';t.style.color=err?'var(--red)':'var(--accent)';t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2500);}
</script>
</body>
</html>
