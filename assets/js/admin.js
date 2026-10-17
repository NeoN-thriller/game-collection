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
// ── PRICE QUICK IMPORT ──
function parseCSVLine(line) {
  const result=[]; let cur='',inQ=false;
  for(let i=0;i<line.length;i++){const ch=line[i];if(ch==='"')inQ=!inQ;else if(ch===','&&!inQ){result.push(cur);cur='';}else cur+=ch;}
  result.push(cur); return result;
}

/** A price from the CSV: "12.99", "$12.99", "€ 12,99", "1,234.50" or "1.234,50"; empty or unreadable → null. */
function csvPrice(v) {
  let s = String(v ?? '').replace(/[^0-9.,-]/g, '');
  if (s === '') return null;
  const c = s.lastIndexOf(','), d = s.lastIndexOf('.');
  if (c > -1 && d > -1) s = c > d ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');      // 1.234,50 / 1,234.50
  else if (c > -1) s = /^-?[1-9]\d{0,2}(,\d{3})+$/.test(s) ? s.replace(/,/g, '') : s.replace(',', '.');  // 1,234 / 12,99
  const n = parseFloat(s);
  return isFinite(n) ? n : null;
}

function parseCSV(text) {
  const lines=text.trim().split('\n'); if(lines.length<2) return [];
  const hdr=parseCSVLine(lines[0]); const idx={};
  hdr.forEach((h,i)=>idx[h.trim()]=i);
  if (idx['external_id'] === undefined && idx['data-product'] !== undefined) idx['external_id'] = idx['data-product'];   // files made before the rename
  const req=['console','name','external_id','link','cib','coverArtBase64'];
  for(const r of req){if(idx[r]===undefined){alert(tRaw('prices.err_column', {column: r}));return[];}}
  const rows=[];
  for(let i=1;i<lines.length;i++){
    const l=lines[i].trim(); if(!l) continue;
    const c=parseCSVLine(l);
    rows.push({console:(c[idx['console']]||'').trim().toUpperCase(),name:(c[idx['name']]||'').trim(),external_id:(c[idx['external_id']]||'').trim(),link:(c[idx['link']]||'').trim(),cib:idx['cib']!==undefined?csvPrice(c[idx['cib']]):null,loose:idx['loose']!==undefined?csvPrice(c[idx['loose']]):null,new:idx['new']!==undefined?csvPrice(c[idx['new']]):null,artBase64:(c[idx['coverArtBase64']]||'').trim()});
  }
  return rows;
}

/** A CSV file chosen on this computer: read in the browser (never uploaded or stored), put in the box, then previewed. */
async function priceFromFile(input, textareaId, next) {
  const f = input.files && input.files[0];
  input.value = '';
  if (!f) return;
  try { document.getElementById(textareaId).value = await f.text(); }
  catch (e) { alert(tRaw('common.err_prefix', {error: e.message})); return; }
  next();
}

/** The two import options; "only update" means nothing is added, so "add as hidden" doesn't apply. */
function priceImportOptions() {
  const updateOnly = document.getElementById('prices-update-only').checked;
  return {update_only: updateOnly, new_inactive: !updateOnly && document.getElementById('prices-new-hidden').checked};
}
function priceImportOpts() {
  const updateOnly = document.getElementById('prices-update-only').checked;
  document.getElementById('prices-new-hidden').disabled = updateOnly;
  document.getElementById('prices-new-hidden-wrap').style.opacity = updateOnly ? '.45' : '';
  const res = document.getElementById('prices-result');
  if (res._prev) priceShowPreview();
}

async function pricePreview() {
  const csv = document.getElementById('prices-csv').value.trim();
  if (!csv) { alert(tRaw('prices.err_no_csv')); return; }
  const rows = parseCSV(csv);
  if (!rows.length) { alert(tRaw('prices.err_no_rows')); return; }

  const res = document.getElementById('prices-result');
  res.style.display='block'; res.textContent=tRaw('admin.prices.previewing');

  const prev = await fetch(ADMIN_BASE+'/api/price_preview.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({rows:rows.map(r=>({console:r.console,external_id:r.external_id,name:r.name,cib:r.cib,loose:r.loose,new:r.new,hasArt:!!r.artBase64}))})}).then(r=>r.json());
  if (!prev.ok) { res.innerHTML=`<span style="color:var(--red)">${t('import.err_preview', {error: prev.error})}</span>`; return; }

  res._rows = rows; res._prev = prev.items;
  priceShowPreview();
}

function priceShowPreview() {
  const res = document.getElementById('prices-result');
  const items = res._prev, opts = priceImportOptions();
  const matched = items.filter(x=>x.status==='match').length;
  const newG    = items.filter(x=>x.status==='new' && x.system_ok).length;
  const noSys   = items.filter(x=>x.status==='new' && !x.system_ok).length;
  const newPart = opts.update_only
    ? `<strong style="color:var(--muted)">${t('prices.sum_not_added', {n: newG + noSys})}</strong>`
    : `<strong style="color:var(--wiiu)">${t(opts.new_inactive ? 'prices.sum_new_hidden' : 'prices.sum_new', {n: newG})}</strong>${noSys?`, <strong style="color:var(--red)">${t('prices.sum_nosys', {n: noSys})}</strong>`:''}`;

  res.innerHTML = `${t('admin.prices.found')} <strong style="color:var(--green)">${t('prices.sum_matched', {n: matched})}</strong>, ${newPart}. <button class="btn btn-sm" onclick="priceConfirm()" style="margin-left:12px">${t('admin.prices.confirm')}</button>`;
}

async function priceConfirm() {
  const res = document.getElementById('prices-result');
  const rows = res._rows; if (!rows) return;
  res._rows = res._prev = null;
  res.textContent = tRaw('import.importing');
  const opts = priceImportOptions();
  const run = Date.now().toString(36) + Math.random().toString(36).slice(2, 8);   // one activity log line for the whole import
  const BATCH=20; let imported=0,updated=0,skippedNew=0,errors=0; const sysIds=[],priceSysIds=[];
  for(let i=0;i<rows.length;i+=BATCH){
    const r=await fetch(ADMIN_BASE+'/api/price_import.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({rows:rows.slice(i,i+BATCH),run,defer:true,...opts})}).then(r=>r.json());
    if(r.ok){imported+=r.imported||0;updated+=r.updated||0;skippedNew+=r.skipped_new||0;errors+=r.errors||0;sysIds.push(...(r.system_ids||[]));priceSysIds.push(...(r.price_system_ids||[]));}else errors++;
  }
  // Once for the whole import: the value graph point
  if(priceSysIds.length) await fetch(ADMIN_BASE+'/api/price_import.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({finish:true,run,price_system_ids:[...new Set(priceSysIds)]})}).catch(()=>{});
  res.innerHTML=`<span style="color:var(--green)">✓ ${t('admin.prices.done', {added: imported, updated})}${opts.new_inactive&&imported?` (${t('prices.hidden')})`:''}${skippedNew?`, <span style="color:var(--orange)">${tn('prices.res_not_added', skippedNew)}</span>`:''}${errors?`, <span style="color:var(--red)">${tn('prices.res_errors', errors)}</span>`:''}.</span>`;
  if (typeof edImportNotice === 'function') edImportNotice(res, sysIds);
}

// ── START: only what the current section shows ──
if (document.getElementById('site-form')) {
  try { sfColors = JSON.parse(document.getElementById('sf-colors').value) || []; } catch { sfColors = []; }
  sfLogo(); sfMoney(); sfDate();
}
if (document.getElementById('img-max-w')) loadImgSettings();
