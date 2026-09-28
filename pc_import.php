<?php
require_once __DIR__ . '/config.php';
$user = requireAdmin();
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pageTitle(tRaw('common.nav.pc_import')) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__.'/assets/css/main.css') ?>">
<?= themeHead($user) ?>
<style>
  .pc-wrap { max-width:960px; margin:36px auto; padding:0 20px 60px; }
  .pc-wrap h1 { font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case); font-size:2rem; color:var(--accent); letter-spacing:.06em; margin-bottom:6px; }
  .pc-wrap p.desc { font-size:.75rem; color:var(--muted); margin-bottom:28px; line-height:1.7; }
  .step { background:var(--surface); border:1px solid var(--border2); padding:22px 24px; margin-bottom:16px; }
  .step-label { font-size:.58rem; letter-spacing:.25em; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
  .step h3 { font-family:var(--font-display);font-weight:var(--display-weight);text-transform:var(--display-case); font-size:1.2rem; color:var(--accent2); letter-spacing:.06em; margin-bottom:12px; }
  textarea.csv-input { width:100%; height:160px; font-size:.7rem; line-height:1.6; padding:10px; resize:vertical; }
  .preview-table { width:100%; border-collapse:collapse; font-size:.71rem; margin-top:12px; }
  .preview-table th { padding:7px 10px; font-size:.58rem; letter-spacing:.15em; text-transform:uppercase; color:var(--muted); text-align:left; border-bottom:1px solid var(--border2); }
  .preview-table td { padding:7px 10px; border-bottom:1px solid var(--border); vertical-align:middle; }
  .tag-match  { background:color-mix(in srgb,var(--green) 12%,transparent); color:var(--green);  border:1px solid color-mix(in srgb,var(--green) 30%,transparent); padding:1px 6px; font-size:.6rem; }
  .tag-new    { background:color-mix(in srgb,var(--wiiu) 10%,transparent);   color:var(--wiiu);   border:1px solid color-mix(in srgb,var(--wiiu) 30%,transparent);  padding:1px 6px; font-size:.6rem; }
  .tag-nosy   { background:color-mix(in srgb,var(--red) 10%,transparent);   color:var(--red);    border:1px solid color-mix(in srgb,var(--red) 30%,transparent);  padding:1px 6px; font-size:.6rem; }
  .tag-skip   { background:color-mix(in srgb,var(--muted) 10%,transparent);  color:var(--muted);  border:1px solid var(--border);       padding:1px 6px; font-size:.6rem; }
  .tag-update { background:color-mix(in srgb,var(--orange) 10%,transparent);  color:var(--orange); border:1px solid color-mix(in srgb,var(--orange) 30%,transparent); padding:1px 6px; font-size:.6rem; }
  .s-chip { padding:4px 12px; font-size:.68rem; }
  .summary-chips { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
  .thumb { width:30px; height:30px; object-fit:cover; border:1px solid var(--border2); display:block; }
  #preview-wrap { display:none; }
  .result-box { background:var(--surface2); border:1px solid var(--border2); padding:16px 20px; margin-top:16px; font-size:.78rem; line-height:2; display:none; }
  .result-box.show { display:block; }
  .ok   { color:var(--green); } .warn { color:var(--orange); } .err { color:var(--red); }
</style>
<?= csrfScript() ?>
<?= appScript(['pc', 'import']) ?>
</head>
<body>

<header class="site-header">
  <a href="<?= BASE_URL ?>/dashboard.php" class="site-logo" style="text-decoration:none"><?= siteLogoHtml() ?></a>
  <nav class="site-nav">
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link"><?= t('common.nav.dashboard') ?></a>
    <a href="<?= BASE_URL ?>/collection.php" class="nav-link"><?= t('common.nav.collection') ?></a>
    <a href="<?= BASE_URL ?>/wishlist.php" class="nav-link"><?= t('common.nav.wishlist') ?></a>
    <a href="<?= BASE_URL ?>/admin.php" class="nav-link">← <?= t('common.nav.admin') ?></a>
    <a href="<?= BASE_URL ?>/settings.php" class="nav-link"><?= t('common.nav.settings') ?></a>
    <a href="<?= BASE_URL ?>/api/logout.php" class="nav-link"><?= t('common.nav.sign_out') ?></a>
  </nav>
</header>

<div class="pc-wrap">
  <h1><?= t('common.nav.pc_import') ?></h1>
  <p class="desc">
    <?= t('pc.intro') ?><br>
    <span style="color:var(--orange)"><?= t('common.currency_note', ['symbol' => setting('currency_symbol')]) ?></span>
  </p>

  <div class="step">
    <div class="step-label"><?= t('import.step', ['n' => 1]) ?></div>
    <h3><?= t('pc.paste_csv') ?></h3>
    <textarea class="csv-input" id="csv-input" placeholder="<?= t('pc.placeholder') ?>
console,name,data-product,link,loose,cib,new,coverArt,coverArtBase64
WiiU,007 Legends,63286,https://www.pricecharting.com/game/pal-wii-u/007-legends,17.51,24.86,40.83,63286.jpg,data:image/jpeg;base64,..."></textarea>
    <div style="display:flex;justify-content:flex-end;margin-top:10px">
      <button class="btn" onclick="previewImport()"><?= t('pc.preview') ?> →</button>
    </div>
  </div>

  <div id="preview-wrap">
    <div class="step">
      <div class="step-label"><?= t('import.step', ['n' => 2]) ?></div>
      <h3><?= t('import.review') ?></h3>
      <div class="summary-chips" id="summary-chips"></div>
      <table class="preview-table">
        <thead>
          <tr>
            <th><?= t('pc.col_art') ?></th><th><?= t('pc.col_console') ?></th><th><?= t('pc.col_csv_name') ?></th><th><?= t('pc.col_matched') ?></th>
            <th><?= t('common.price.loose') ?></th><th><?= t('common.price.cib') ?></th><th><?= t('common.price.new') ?></th><th><?= t('pc.col_updated') ?></th><th><?= t('pc.col_art') ?></th><th><?= t('import.col_status') ?></th>
          </tr>
        </thead>
        <tbody id="preview-body"></tbody>
      </table>
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
        <button class="btn-ghost" onclick="reset()">← <?= t('import.start_over') ?></button>
        <button class="btn" id="btn-confirm" onclick="confirmImport()"><?= t('pc.import') ?></button>
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
    if (idx[r] === undefined) { toast(tRaw('pc.err_column', {column: r}), true); return []; }
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
  if (!csv) { toast(tRaw('pc.err_no_csv'), true); return; }
  parsedRows = parseCSV(csv);
  if (!parsedRows.length) { toast(tRaw('pc.err_no_rows'), true); return; }

  const res = await fetch(`${BASE}/api/pc_preview.php`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({rows: parsedRows.map(r=>({console:r.console,pc_id:r.pc_id,name:r.name,cib:r.cib,hasArt:!!r.artBase64}))})
  }).then(r=>r.json());

  if (!res.ok) { toast(tRaw('import.err_preview', {error: res.error}), true); return; }
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
      statusTag  = `<span class="tag-match">✓ ${t(item.match_type==='pc_id' ? 'pc.match_id' : 'pc.match_title')}</span>`;
      matchCell  = esc(item.game_title);
      lastUpd    = item.last_updated ? fmtDate(item.last_updated) : '—';
      artTag     = item.art_action==='set' ? `<span class="tag-update">${t('pc.art_set')}</span>` :
                   item.art_action==='skip'? `<span class="tag-skip">${t('pc.art_keep')}</span>` :
                                             `<span class="tag-skip">${t('pc.art_none')}</span>`;
      if (item.art_action==='set') cntArtSet++;
      if (item.art_action==='skip') cntArtSkip++;
      if (item.cib_changed) cntPriceUpdate++;
    } else if (item.status === 'new') {
      if (item.system_ok) {
        cntNew++;
        statusTag = `<span class="tag-new">+ ${t('pc.new_game')}</span>`;
        matchCell = `<span style="color:var(--muted)">${t('pc.will_create')}</span>`;
        artTag    = row.artBase64 ? `<span class="tag-update">${t('pc.art_set')}</span>` : `<span class="tag-skip">${t('pc.art_none')}</span>`;
      } else {
        cntNoSys++;
        statusTag = `<span class="tag-nosy">✗ ${t('pc.unknown_system', {console: row.console})}</span>`;
        matchCell = `<span style="color:var(--red)">${t('pc.system_not_found')}</span>`;
        artTag = '—';
      }
    }

    // Price cells — always show from CSV
    looseCell = row.loose != null ? `<span style="color:var(--muted)">${money(row.loose)}</span>` : '—';
    cibCell   = row.cib   != null ? `<span style="color:var(--wiiu)${item.cib_changed?' ;font-weight:bold':''}">${money(row.cib)}${item.cib_changed?' ↑':''}</span>` : '—';
    newCell   = row.new   != null ? `<span style="color:var(--green)">${money(row.new)}</span>` : '—';

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
    <span class="s-chip tag-match">${t('pc.sum_matched', {n: cntMatch})}</span>
    <span class="s-chip tag-new">${t('pc.sum_new', {n: cntNew})}</span>
    ${cntNoSys ? `<span class="s-chip tag-nosy">${t('pc.sum_nosys', {n: cntNoSys})}</span>` : ''}
    <span class="s-chip tag-update">${t('pc.sum_prices', {n: cntPriceUpdate})}</span>
    <span class="s-chip tag-update">${t('pc.sum_art_set', {n: cntArtSet})}</span>
    ${cntArtSkip ? `<span class="s-chip tag-skip">${t('pc.sum_art_kept', {n: cntArtSkip})}</span>` : ''}
  `;

  const total = cntMatch + cntNew;
  document.getElementById('btn-confirm').textContent = tnRaw('pc.import_n', total);
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
    btn.textContent = tRaw('pc.importing', {done: Math.min(i+BATCH,parsedRows.length), total: parsedRows.length});
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
    <div class="ok">✓ ${t('pc.done')}</div>
    ${imported ? `<div class="ok">• ${tn('pc.res_added', imported)}</div>` : ''}
    ${updated  ? `<div class="ok">• ${tn('pc.res_updated', updated)}</div>` : ''}
    ${skippedArt ? `<div class="warn">• ${tn('pc.res_art_skipped', skippedArt)}</div>` : ''}
    ${errors   ? `<div class="err">• ${tn('pc.res_errors', errors)}</div>` : ''}
  `;
  document.getElementById('preview-wrap').style.display='none';
  toast(tRaw('import.done_toast'));
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
