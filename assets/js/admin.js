/* ═══════════════════════════════════════════
   SETTINGS — admin parts (settings.php, the Site sections)
   Loaded after settings.js (toast) for admins only; each part only starts when its section is on the page.
   ═══════════════════════════════════════════ */
const ADMIN_BASE = CP.base;
window.GA_BASE = ADMIN_BASE;   // for grading-admin.js

// ── USERS ──
function confirmReset() { return confirm(tRaw('admin.users.confirm_reset')); }

// ── SITE DEFAULT THEME ──
async function setSiteTheme(slug) {
  const fd = new FormData();
  fd.append('action', 'set_default_theme');
  fd.append('theme', slug);
  try {
    const res = await fetch(window.location.href, { method:'POST', body: fd, headers:{'X-Admin-Ajax':'1'} }).then(r=>r.json());
    toast(res.msg || res.error || tRaw('common.saved'), !res.ok);
    setTimeout(()=>window.location.reload(), 800); // refresh the "Site default" marks (and the page theme)
  } catch(err) {
    toast(tRaw('common.err_prefix', {error: err.message}), true);
  }
}

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
  msg.style.display='block'; msg.textContent=res.ok?tRaw('common.saved'):tRaw('common.err_saving');
  msg.style.color=res.ok?'var(--green)':'var(--red)';
  setTimeout(()=>msg.style.display='none',2000);
}

// ── SITE SETTINGS (live previews) ── (CP.logoColors comes from settings/s_general.php)
let sfColors = [];

function sfLogo() {
  const words = document.getElementById('sf-name').value.trim().split(/\s+/).filter(Boolean);
  const style = document.getElementById('sf-style').value;
  const wrap  = document.getElementById('sf-words');
  // One colour picker per word (style "custom")
  wrap.style.display = style === 'custom' ? 'flex' : 'none';
  if (style === 'custom') {
    wrap.innerHTML = words.map((w, i) => `<div class="field" style="width:170px"><label for="sf-word-${i}">${esc(w)}</label><select id="sf-word-${i}" data-i="${i}" onchange="sfColors[this.dataset.i]=this.value;sfLogo()">` +
      Object.entries(CP.logoColors).map(([k, lbl]) => `<option value="${k}" ${(sfColors[i] || 'header-logo') === k ? 'selected' : ''}>${esc(lbl)}</option>`).join('') +
      '</select></div>').join('');
  }
  document.getElementById('sf-colors').value = JSON.stringify(words.map((_, i) => sfColors[i] || 'header-logo'));
  document.getElementById('sf-logo').innerHTML = words.map((w, i) => {
    const c = style === 'custom' ? (sfColors[i] || 'header-logo') : (style === 'last' && words.length > 1 && i === words.length - 1 ? 'header-logo2' : '');
    return c ? `<span style="color:var(--${c})">${esc(w)}</span>` : esc(w);
  }).join(' ');
}

function sfMoney() {
  const f = { sym: document.getElementById('sf-sym').value, after: document.getElementById('sf-pos').value === 'after',
              space: document.getElementById('sf-space').value === '1', dec: document.getElementById('sf-dec').value, thou: document.getElementById('sf-thou').value };
  const [i, d] = (1234.56).toFixed(2).split('.');
  const n = i.replace(/\B(?=(\d{3})+(?!\d))/g, f.thou) + f.dec + d, sp = f.space ? ' ' : '';
  document.getElementById('sf-money-preview').textContent = f.after ? n + sp + f.sym : f.sym + sp + n;
}

function sfDate() {
  const saved = FMT.date;
  FMT.date = document.getElementById('sf-date').value;
  document.getElementById('sf-date-preview').textContent = fmtDate(new Date());
  FMT.date = saved;
}

// ── GAME LISTS ──
// ── PRICECHARTING QUICK IMPORT ──
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
  for(const r of req){if(idx[r]===undefined){alert(tRaw('pc.err_column', {column: r}));return[];}}
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
  if (!csv) { alert(tRaw('pc.err_no_csv')); return; }
  const rows = parseCSV(csv);
  if (!rows.length) { alert(tRaw('pc.err_no_rows')); return; }

  const res = document.getElementById('pc-result');
  res.style.display='block'; res.textContent=tRaw('admin.pc.previewing');

  const prev = await fetch(ADMIN_BASE+'/api/pc_preview.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({rows:rows.map(r=>({console:r.console,pc_id:r.pc_id,name:r.name,cib:r.cib,hasArt:!!r.artBase64}))})}).then(r=>r.json());
  if (!prev.ok) { res.innerHTML=`<span style="color:var(--red)">${t('import.err_preview', {error: prev.error})}</span>`; return; }

  const matched = prev.items.filter(x=>x.status==='match').length;
  const newG    = prev.items.filter(x=>x.status==='new' && x.system_ok).length;
  const noSys   = prev.items.filter(x=>x.status==='new' && !x.system_ok).length;

  res.innerHTML = `${t('admin.pc.found')} <strong style="color:var(--green)">${t('pc.sum_matched', {n: matched})}</strong>, <strong style="color:var(--wiiu)">${t('pc.sum_new', {n: newG})}</strong>${noSys?`, <strong style="color:var(--red)">${t('pc.sum_nosys', {n: noSys})}</strong>`:''}. <button class="btn btn-sm" onclick="pcConfirm()" style="margin-left:12px">${t('admin.pc.confirm')}</button>`;
  res._rows = rows;
}

async function pcConfirm() {
  const res = document.getElementById('pc-result');
  const rows = res._rows; if (!rows) return;
  res.textContent = tRaw('import.importing');
  const BATCH=20; let imported=0,updated=0,errors=0; const sysIds=[];
  for(let i=0;i<rows.length;i+=BATCH){
    const r=await fetch(ADMIN_BASE+'/api/pc_import.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({rows:rows.slice(i,i+BATCH)})}).then(r=>r.json());
    if(r.ok){imported+=r.imported||0;updated+=r.updated||0;errors+=r.errors||0;sysIds.push(...(r.system_ids||[]));}else errors++;
  }
  res.innerHTML=`<span style="color:var(--green)">✓ ${t('admin.pc.done', {added: imported, updated})}${errors?`, <span style="color:var(--red)">${tn('pc.res_errors', errors)}</span>`:''}.</span>`;
  if (typeof edImportNotice === 'function') edImportNotice(res, sysIds);
}

// ── START: only what the current section shows ──
if (document.getElementById('site-form')) {
  try { sfColors = JSON.parse(document.getElementById('sf-colors').value) || []; } catch { sfColors = []; }
  sfLogo(); sfMoney(); sfDate();
}
if (document.getElementById('img-max-w')) loadImgSettings();
