/* ═══════════════════════════════════════════
   SETTINGS — the user's own settings, the toast and the form handler (settings.php)
   Loaded on every section; each part only starts when its section is on the page.
   Needs window.CP from settings.php (base URL; section data from the partials).
   ═══════════════════════════════════════════ */
const BASE = CP.base;

function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── TOAST ──
function toast(msg, err=false) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.style.borderColor = err ? 'var(--red)' : 'var(--accent2)';
  t.style.color       = err ? 'var(--red)' : 'var(--accent)';
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'), 2500);
}
/** Old admin name (grading-admin.js uses it): note the flag is "ok", not "err". */
function adminToast(msg, ok=true) { toast(msg, !ok); }

// ── FORMS: every POST form in the main pane is sent with AJAX and gets a toast ──
// The server (settings.php) answers with JSON because of the X-Admin-Ajax header.
// bindAjaxForms(root) also binds forms added later (e.g. the catalogue game list after a refresh).
function bindAjaxForms(root = document.querySelector('.cp-main')) {
  root.querySelectorAll('form[method="POST"]').forEach(form => {
    if (form.dataset.ajax) return;
    form.dataset.ajax = '1';
    form.addEventListener('submit', async (e) => {
      if (e.defaultPrevented) return; // e.g. cancelled confirm() in an onsubmit handler
      e.preventDefault();
      const fd = new FormData(form);
      try {
        const res = await fetch(window.location.href, { method:'POST', body: fd, headers:{'X-Admin-Ajax':'1'} }).then(r=>r.json());
        toast(res.msg || res.error || tRaw('common.saved'), !res.ok);
        if (res.ok && fd.get('action') !== 'reset_password') setTimeout(()=>window.location.reload(), 800);
        if (res.ok && fd.get('action') === 'reset_password') form.reset();
      } catch(err) {
        toast(tRaw('common.err_prefix', {error: err.message}), true);
      }
    });
  });
}
document.addEventListener('DOMContentLoaded', () => bindAjaxForms());

// ── ACCOUNT ──
async function changePassword() {
  const cur  = document.getElementById('cur-pw').value;
  const newp = document.getElementById('new-pw').value;
  const conf = document.getElementById('conf-pw').value;
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'change_password',current_password:cur,new_password:newp,confirm_password:conf})
  }).then(r=>r.json());
  if (res.ok) {
    toast(tRaw('settings.pw_changed'));
    document.getElementById('cur-pw').value='';
    document.getElementById('new-pw').value='';
    document.getElementById('conf-pw').value='';
  } else { toast(res.error||tRaw('settings.err_pw'), true); }
}

// ── LANGUAGE ──
async function saveLanguage(code) {
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_language', language: code})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if (res.ok) { window.location.reload(); return; }
  toast(res.error || tRaw('common.err_saving'), true);
}

// ── THEME ── (CP.themes, CP.siteTheme, CP.savedTheme come from settings/s_appearance.php)
/** Swaps the theme stylesheets in place, so the choice shows immediately. */
function applyTheme(slug) {
  const t = (CP.themes || {})[slug];
  if (!t) return;
  document.getElementById('theme-css').href = t.css;
  let fonts = document.getElementById('theme-fonts');
  if (t.fonts) {
    if (!fonts) {
      fonts = Object.assign(document.createElement('link'), { id: 'theme-fonts', rel: 'stylesheet' });
      document.getElementById('theme-css').before(fonts);
    }
    fonts.href = t.fonts;
  } else if (fonts) fonts.remove();
  const meta = document.querySelector('meta[name="color-scheme"]');
  if (meta) meta.content = t.scheme;
}

async function pickTheme(slug) {
  applyTheme(slug);
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    // Picking the site default stores "no choice", so the user keeps following it if the admin changes it
    body:JSON.stringify({action:'save_theme', theme: slug === CP.siteTheme ? '' : slug})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if (res.ok) { CP.savedTheme = slug; toast(tRaw('settings.theme_saved')); return; }
  // Put the previous theme back
  applyTheme(CP.savedTheme);
  const prev = document.querySelector(`input[name="theme"][value="${CSS.escape(CP.savedTheme)}"]`);
  if (prev) prev.checked = true;
  toast(res.error || tRaw('settings.theme_failed'), true);
}

// ── WISHLIST SHARING ──
function copyLink() {
  const code = document.getElementById('wishlist-link-code');
  if (!code) { toast(tRaw('settings.no_link'), true); return; }
  navigator.clipboard.writeText(code.textContent.trim())
    .then(()=>toast(tRaw('settings.link_copied')))
    .catch(()=>{ /* fallback */ const r=document.createRange(); r.selectNode(code); window.getSelection().removeAllRanges(); window.getSelection().addRange(r); try{document.execCommand('copy');toast(tRaw('settings.link_copied'));}catch(e){toast(tRaw('settings.copy_failed'),true);} });
}

async function saveWishlistPublic(checked) {
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_wishlist_public',wishlist_public:checked})
  }).then(r=>r.json());
  if (res.ok) {
    toast(tRaw(checked ? 'settings.sharing_on' : 'settings.sharing_off'));
    // Show or hide the link without a page reload (turning sharing on may have created the token)
    if (res.token) document.getElementById('wishlist-link-code').textContent = res.base_url+'/wishlist.php?token='+res.token;
    document.getElementById('wishlist-link-wrap').style.display = checked && res.token ? '' : 'none';
  } else {
    document.getElementById('wishlist_public').checked = !checked;   // put the toggle back
    toast(tRaw('common.err_saving'), true);
  }
}

async function regenerateToken() {
  if (!confirm(tRaw('settings.confirm_new_link'))) return;
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'regenerate_token'})
  }).then(r=>r.json());
  if (res.ok) {
    const code = document.getElementById('wishlist-link-code');
    if (code) code.textContent = res.base_url+'/wishlist.php?token='+res.token;
    toast(tRaw('settings.link_new'));
  } else { toast(tRaw('common.error'), true); }
}

// ── CONDITION GRADING ── (CP.gradingDefault comes from settings/s_grading.php)
let gmDefault = CP.gradingDefault || 'simple';
function gmRefresh() {
  const mode = document.querySelector('input[name="grading_mode"]:checked')?.value;
  document.getElementById('gm-default').style.display = mode === 'both' ? '' : 'none';
}
function gmSetDefault(v) {
  gmDefault = v;
  document.querySelectorAll('#gm-default [data-def]').forEach(b => {
    b.classList.toggle('on', b.dataset.def === v);
    b.setAttribute('aria-pressed', b.dataset.def === v ? 'true' : 'false');
  });
}
async function saveGrading() {
  const mode = document.querySelector('input[name="grading_mode"]:checked')?.value || 'simple';
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_grading', mode, default: gmDefault})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  toast(res.ok ? tRaw('settings.grading_saved') : (res.error||tRaw('common.error')), !res.ok);
}

// ── COMPLETENESS / PLAYED OPTIONS ──
async function saveCompleteness() {
  const labels = [...document.querySelectorAll('#comp-list .comp-label-input')].map(i=>i.value.trim()).filter(Boolean);
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_completeness',labels})
  }).then(r=>r.json());
  toast(res.ok ? tRaw('settings.comp_saved') : (res.error||tRaw('common.error')), !res.ok);
}

/** Group of a new played status, guessed from its name. Mirror of playGroupOf() in site.php. */
function playGroupGuess(label) {
  const l = String(label).toLowerCase();
  if (/finish|complet|100|cheat|beat|clear|done|platin|uitgespeeld|voltooid|valsgespeeld|gehaald|klaar/.test(l)) return 'finished';
  if (/start|stuck|playing|progress|begonnen|vastgelopen|vast|bezig|gestart/.test(l)) return 'started';
  return 'none';
}

/** The Finished / Started / not counted toggle of a played status (mirror of $groupToggle in settings/s_options.php). */
function playGroupToggle(group) {
  return `<span class="pg-toggle" role="radiogroup" aria-label="${t('settings.play_group')}">` + [['finished', '✓'], ['started', '▶'], ['none', '–']].map(([g, icon]) =>
    `<button type="button" class="pg-btn pg-${g}${g === group ? ' on' : ''}" data-pg="${g}" role="radio" aria-checked="${g === group}" title="${t('settings.pg_' + g)}" aria-label="${t('settings.pg_' + g)}">${icon}</button>`).join('') + '</span>';
}
function setPlayGroup(tog, group) {
  tog.querySelectorAll('.pg-btn').forEach(b => { const on = b.dataset.pg === group; b.classList.toggle('on', on); b.setAttribute('aria-checked', on); });
}
// Finished / Started counters: of all games or of owned games (saved with the played statuses)
document.addEventListener('click', e => {
  const b = e.target.closest('#played-pct [data-pct]');
  if (!b) return;
  document.querySelectorAll('#played-pct [data-pct]').forEach(x => { const on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-checked', on); });
});
document.addEventListener('click', e => {
  const b = e.target.closest('.pg-toggle .pg-btn');
  if (!b) return;
  const tog = b.closest('.pg-toggle');
  tog.dataset.picked = '1';
  setPlayGroup(tog, b.dataset.pg);
});

async function savePlayed() {
  // Each status with the group it counts in (Finished / Started counters on the Collection page)
  const items = [...document.querySelectorAll('#played-list .comp-item')].map(row => ({
    label: row.querySelector('.comp-label-input').value.trim(),
    group: row.querySelector('.pg-btn.on')?.dataset.pg || 'none',
  })).filter(x => x.label);
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_played', labels: items.map(x => x.label), groups: items.map(x => x.group),
                         pct: document.querySelector('#played-pct .on')?.dataset.pct || 'all',
                         show: document.getElementById('played-show')?.checked ? 1 : 0})
  }).then(r=>r.json());
  toast(res.ok ? tRaw('settings.played_saved') : (res.error||tRaw('common.error')), !res.ok);
}

function addItem(listId) {
  const div = document.createElement('div');
  div.className = 'comp-item'; div.draggable = true;
  const list = document.getElementById(listId);
  div.innerHTML = `<span class="drag-handle">⠿</span><input type="text" class="comp-label-input" value="" maxlength="100" placeholder="${list.dataset.placeholder ? esc(list.dataset.placeholder) : t('settings.new_option')}"><button type="button" class="btn-danger" onclick="removeItem(this)">✕</button>`;
  if (listId === 'played-list') {
    // Played statuses also say which counter they count in; the name suggests one until it's picked by hand
    const input = div.querySelector('input');
    input.insertAdjacentHTML('afterend', playGroupToggle('none'));
    const tog = div.querySelector('.pg-toggle');
    input.addEventListener('input', () => { if (!tog.dataset.picked) setPlayGroup(tog, playGroupGuess(input.value)); });
  }
  list.appendChild(div);
  div.querySelector('input').focus();
}

// ── EDITIONS & VARIANTS ──
function edPreviewRefresh() {
  const box = document.getElementById('ed-preview');
  if (!box) return;
  const mode = document.querySelector('input[name="edition_mode"]:checked')?.value || 'one';
  document.getElementById('ed-preview-val').textContent = box.dataset[mode];
}

async function saveEditions() {
  const mode = document.querySelector('input[name="edition_mode"]:checked')?.value || 'one';
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_editions', mode, wishlist: document.getElementById('ed-wishlist').value})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  toast(res.ok ? tRaw('common.saved') : (res.error||tRaw('common.error')), !res.ok);
}

async function saveCompilationMode() {
  const mode = document.querySelector('input[name="compilation_mode"]:checked')?.value || 'own';
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_compilation_mode', mode})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  toast(res.ok ? tRaw('common.saved') : (res.error||tRaw('common.error')), !res.ok);
}

async function saveVariants() {
  const labels = [...document.querySelectorAll('#variant-list .comp-label-input')].map(i=>i.value.trim()).filter(Boolean);
  const res = await fetch(`${BASE}/api/settings.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'save_variants', track: document.getElementById('ed-track').checked, labels})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  toast(res.ok ? tRaw('common.saved') : (res.error||tRaw('common.error')), !res.ok);
}

function initEditions() {
  document.querySelectorAll('input[name="edition_mode"]').forEach(r => r.addEventListener('change', edPreviewRefresh));
  edPreviewRefresh();
  document.getElementById('ed-track').addEventListener('change', e => {
    document.getElementById('ed-var-on').hidden  = !e.target.checked;
    document.getElementById('ed-var-off').hidden = e.target.checked;
  });
  initDrag('variant-list');
}

/**
 * Drag to reorder the rows of an option list. The listeners sit on the list itself, so rows added
 * later (addItem) can be moved straight away, without saving and reloading.
 */
function initDrag(listId) {
  const list = document.getElementById(listId);
  if (!list || list.dataset.drag) return;
  list.dataset.drag = '1';
  let dragSrc = null;
  list.addEventListener('dragstart', e => {
    const item = e.target.closest && e.target.closest('.comp-item');
    if (!item || !list.contains(item)) return;
    dragSrc = item; item.style.opacity = '.4';
    if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); }   // Firefox needs data
  });
  list.addEventListener('dragend', () => { if (dragSrc) dragSrc.style.opacity = '1'; dragSrc = null; });
  list.addEventListener('dragover', e => { if (dragSrc) e.preventDefault(); });
  list.addEventListener('drop', e => {
    const item = e.target.closest && e.target.closest('.comp-item');
    if (!dragSrc || !item || item === dragSrc || !list.contains(item)) return;
    e.preventDefault();
    const items = [...list.querySelectorAll('.comp-item')];
    if (items.indexOf(dragSrc) < items.indexOf(item)) list.insertBefore(dragSrc, item.nextSibling);
    else list.insertBefore(dragSrc, item);
  });
}

function removeItem(btn) { btn.closest('.comp-item, .sys-sort-item').remove(); }

// ── TAG OPTIONS ── (same list editor as completeness / played: settings/s_options.php)
async function saveTagOptions() {
  const tags = [...document.querySelectorAll('#tag-list .comp-label-input')].map(i => i.value.trim()).filter(Boolean);
  const res = await fetch(`${BASE}/api/tag_options.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tags})
  }).then(r=>r.json());
  toast(res.ok?tRaw('settings.tags_saved'):tRaw('common.error'),!res.ok);
}

// ── SYSTEM ORDER & VISIBILITY ──
let sysData = [];

async function loadSystems() {
  const res = await fetch(`${BASE}/api/system_prefs.php`).then(r=>r.json());
  if (!res.ok) return;
  sysData = res.systems;
  renderSysList();
}

function renderSysList() {
  const list = document.getElementById('sys-sort-list');
  list.innerHTML = '';
  sysData.forEach((s, i) => {
    const div = document.createElement('div');
    div.className = 'sys-sort-item';
    div.draggable = true;
    div.dataset.id = s.id;
    div.style.cssText = 'display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--surface2);border:1px solid var(--border2);cursor:default';
    const iconHtml = s.icon_image
      ? `<img src="${BASE}/uploads/icons/${s.icon_image}" alt="" style="width:24px;height:24px;object-fit:contain;flex-shrink:0">`
      : `<span style="width:24px;height:24px;flex-shrink:0;display:inline-block"></span>`;
    div.innerHTML = `
      <span style="color:var(--muted);cursor:grab;font-size:1rem;padding:0 4px" class="sys-drag-handle">⠿</span>
      <label class="toggle" style="flex-shrink:0" title="${t('settings.show_in_coll')}">
        <input type="checkbox" class="sys-check" data-id="${s.id}" ${s.visible?'checked':''} aria-label="${t('settings.show_in_coll')}">
        <span class="toggle-slider"></span>
      </label>
      ${iconHtml}
      <span style="flex:1">
        <span style="font-size:.8rem;color:var(--text)">${esc(s.short_name)}</span>
        <span style="font-size:.62rem;color:var(--muted);display:block">${esc(s.name)}</span>
      </span>
      <label style="display:flex;align-items:center;gap:5px;font-size:.65rem;color:var(--muted);flex-shrink:0;cursor:pointer" title="${t('settings.count_totals_title')}">
        <input type="checkbox" class="sys-count-check" data-id="${s.id}" ${s.count_for_totals!==false?'checked':''}>
        <span>${t('settings.count_totals')}</span>
      </label>
    `;
    list.appendChild(div);
  });
  initSysDrag();
}

function initSysDrag() {
  let dragSrc = null;
  document.querySelectorAll('#sys-sort-list .sys-sort-item').forEach(item => {
    item.addEventListener('dragstart', () => { dragSrc = item; item.style.opacity = '.4'; });
    item.addEventListener('dragend',   () => { item.style.opacity = '1'; dragSrc = null; });
    item.addEventListener('dragover',  e => e.preventDefault());
    item.addEventListener('drop', e => {
      e.preventDefault();
      if (dragSrc && dragSrc !== item) {
        const list  = document.getElementById('sys-sort-list');
        const items = [...list.querySelectorAll('.sys-sort-item')];
        if (items.indexOf(dragSrc) < items.indexOf(item)) list.insertBefore(dragSrc, item.nextSibling);
        else list.insertBefore(dragSrc, item);
        // Update sysData order to match DOM
        const newOrder = [...list.querySelectorAll('.sys-sort-item')].map(el => parseInt(el.dataset.id));
        sysData.sort((a,b) => newOrder.indexOf(a.id) - newOrder.indexOf(b.id));
      }
    });
  });
}

async function saveSystemPrefs() {
  const items = [...document.querySelectorAll('#sys-sort-list .sys-sort-item')];
  const prefs = items.map((el, i) => ({
    system_id:       parseInt(el.dataset.id),
    visible:         el.querySelector('.sys-check').checked,
    sort_order:      i,
    count_for_totals: el.querySelector('.sys-count-check')?.checked ?? true,
  }));
  const res = await fetch(`${BASE}/api/system_prefs.php`, {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({prefs})
  }).then(r=>r.json());
  toast(res.ok ? tRaw('settings.systems_saved') : tRaw('common.err_saving'), !res.ok);
}

// ── COLUMN PREFERENCES ──
// Labels come from the language file: common.col.<id>
const col = (id, on) => ({ id, label: t('common.col.' + id), on });
const DEFAULT_COLS_COLLECTION = [
  col('img', true), col('owned', true), col('wishlist', true), col('upgrade', true), col('title', true),
  col('edition', true), ...(CP.trackVariants ? [col('variant', false)] : []),
  col('quality', true), col('completeness', true), col('played', true), col('copies', true),
  col('price_paid', true), col('buy_range', true), col('loose_price', false), col('cib_price', true),
  col('new_price', false), col('upgrade_reason', true), col('tag', false), col('notes', true),
];
const DEFAULT_COLS_WISHLIST = [
  col('img', true), col('owned', true), col('upgrade', true), col('system', true), col('title', true),
  col('upgrade_reason', true), col('quality', true), col('completeness', true), col('price_paid', true),
  col('buy_range', true), col('loose_price', false), col('cib_price', true), col('new_price', false),
  col('tag', false), col('notes', true),
];

function mergeWithDefaults(saved, defaults) {
  if (!saved || !saved.length) return [...defaults];
  // Build label lookup from defaults
  const labelMap = {};
  defaults.forEach(d => labelMap[d.id] = d.label);
  // Keep saved order but restore labels from defaults
  const savedIds = saved.map(c=>c.id);
  const merged = saved.map(c => ({
    id:    c.id,
    label: labelMap[c.id] || c.id, // always use default label, fallback to id
    on:    c.on !== undefined ? c.on : true,
  }));
  // Add any new default cols not in saved
  defaults.forEach(d => { if (!savedIds.includes(d.id)) merged.push({...d}); });
  return merged;
}

async function loadColPrefs() {
  const res = await fetch(`${BASE}/api/column_prefs.php`).then(r=>r.json());
  // The variant column is only offered while the user tracks variants
  const c = mergeWithDefaults(res.ok ? res.cols_collection : null, DEFAULT_COLS_COLLECTION).filter(x => x.id !== 'variant' || CP.trackVariants);
  const w = mergeWithDefaults(res.ok ? res.cols_wishlist   : null, DEFAULT_COLS_WISHLIST);
  renderColList('col-list',      c);
  renderColList('col-wish-list', w);
}

function renderColList(listId, data) {
  const list = document.getElementById(listId);
  list.innerHTML = '';
  data.forEach(col => {
    const div = document.createElement('div');
    div.className = 'sys-sort-item'; div.draggable = true; div.dataset.id = col.id;
    div.style.cssText = 'display:flex;align-items:center;gap:12px;padding:8px 14px;background:var(--surface2);border:1px solid var(--border2)';
    div.innerHTML = `<span style="color:var(--muted);cursor:grab;font-size:1rem;padding:0 4px">⠿</span>
      <label class="toggle" style="flex-shrink:0"><input type="checkbox" class="col-check" data-id="${col.id}" ${col.on?'checked':''} aria-label="${col.label}"><span class="toggle-slider"></span></label>
      <span style="font-size:.8rem;color:var(--text)">${col.label}</span>`;
    list.appendChild(div);
  });
  initColDrag(listId);
}

function initColDrag(listId) {
  let dragSrc = null;
  document.querySelectorAll(`#${listId} .sys-sort-item`).forEach(item => {
    item.addEventListener('dragstart', () => { dragSrc=item; item.style.opacity='.4'; });
    item.addEventListener('dragend',   () => { item.style.opacity='1'; dragSrc=null; });
    item.addEventListener('dragover',  e => e.preventDefault());
    item.addEventListener('drop', e => {
      e.preventDefault();
      if (dragSrc && dragSrc!==item) {
        const li=document.getElementById(listId);
        const l=[...li.querySelectorAll('.sys-sort-item')];
        if(l.indexOf(dragSrc)<l.indexOf(item)) li.insertBefore(dragSrc,item.nextSibling);
        else li.insertBefore(dragSrc,item);
      }
    });
  });
}

async function saveColPrefs(type) {
  const listId = type==='wishlist' ? 'col-wish-list' : 'col-list';
  const cols = [...document.querySelectorAll(`#${listId} .sys-sort-item`)].map(el=>({
    id: el.dataset.id, on: el.querySelector('.col-check').checked,
  }));
  const res = await fetch(`${BASE}/api/column_prefs.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({type,cols})
  }).then(r=>r.json());
  toast(res.ok?tRaw('common.saved'):tRaw('common.error'),!res.ok);
}

// ── AUCTION SITES ──
async function loadAuctionSites() {
  const res = await fetch(`${BASE}/api/auction_sites.php`).then(r=>r.json());
  const sites = res.ok ? (res.sites||[]) : [];
  if (!sites.length) {
    // Add default eBay example
    sites.push({label:'eBay', url_template:'https://www.ebay.nl/sch/i.html?_nkw={system}+{title}+{region}'});
  }
  document.getElementById('auction-list').innerHTML='';
  sites.forEach(s => renderAuctionRow(s.label, s.url_template));
}

function renderAuctionRow(label='', url='') {
  const div = document.createElement('div');
  div.style.cssText = 'display:flex;gap:6px;align-items:center';

  const lblInp = document.createElement('input');
  lblInp.type = 'text'; lblInp.className = 'auction-label';
  lblInp.placeholder = tRaw('settings.site_label');
  lblInp.setAttribute('aria-label', tRaw('settings.site_label'));
  lblInp.value = label;
  lblInp.style.cssText = 'width:140px;padding:7px 10px;font-size:.75rem;background:var(--surface);border:1px solid var(--border2);color:var(--text);outline:none';

  const urlInp = document.createElement('input');
  urlInp.type = 'text'; urlInp.className = 'auction-url';
  urlInp.placeholder = tRaw('settings.site_url');
  urlInp.setAttribute('aria-label', tRaw('settings.site_url'));
  urlInp.value = url;
  urlInp.style.cssText = 'flex:1;min-width:0;padding:7px 10px;font-size:.72rem;background:var(--surface);border:1px solid var(--border2);color:var(--text);outline:none';

  const delBtn = document.createElement('button');
  delBtn.className = 'btn-ghost';
  delBtn.textContent = '✕';
  delBtn.setAttribute('aria-label', tRaw('common.delete'));
  delBtn.style.cssText = 'padding:7px 10px;font-size:.72rem;color:var(--red)';
  delBtn.onclick = () => div.remove();

  div.appendChild(lblInp);
  div.appendChild(urlInp);
  div.appendChild(delBtn);
  document.getElementById('auction-list').appendChild(div);
}

function addAuctionSite() { renderAuctionRow(); }

async function saveAuctionSites() {
  const sites=[...document.querySelectorAll('#auction-list > div')].map(div=>({
    label:        div.querySelector('.auction-label').value.trim(),
    url_template: div.querySelector('.auction-url').value.trim(),
  })).filter(s=>s.label&&s.url_template);
  const res = await fetch(`${BASE}/api/auction_sites.php`,{
    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({sites})
  }).then(r=>r.json());
  toast(res.ok?tRaw('settings.sites_saved'):tRaw('common.error'),!res.ok);
}

// ── EXPORT / IMPORT ──
function exportData() {
  toast(tRaw('settings.preparing_export'));
  window.location.href = `${BASE}/api/export.php`; // server responds with a file download
}

async function importData(e) {
  const file = e.target.files[0]; if (!file) return;
  const text = await file.text();
  let data; try { data = JSON.parse(text); } catch { toast(tRaw('settings.invalid_json'), true); return; }
  if (!confirm(tRaw('settings.import_confirm'))) return;
  toast(tRaw('import.importing'));
  const res = await fetch(`${BASE}/api/import.php`, {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({data})
  }).then(r=>r.json()).catch(e=>({ok:false, error:tRaw('common.err_server', {error: e.message})}));
  toast(res.ok ? tRaw('import.done_toast') : tRaw('import.failed', {error: res.error||''}), !res.ok);
}

// ── IMAGE BACKUPS ──
async function generateBackup(btn) {
  const label = btn.textContent.trim();
  btn.disabled = true;
  btn.textContent = tRaw('settings.generating');
  toast(tRaw('settings.generating_zip', {name: btn.dataset.name}));
  try {
    const res = await fetch(`${BASE}/api/backup_generate.php`, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({system_id: Number(btn.dataset.systemId)})
    }).then(r => r.json());
    if (!res.ok) throw new Error(res.error || tRaw('common.err_unknown'));
    let msg = tRaw('settings.zip_ready', {n: fmtNum(res.photo_count), size: fmtNum(res.file_size/1024/1024, 1)});
    if (res.missing) msg += ' ' + tRaw('settings.zip_missing', {n: res.missing});
    toast(msg);
    setTimeout(() => window.location.reload(), 1500);
  } catch (e) {
    btn.textContent = label;
    btn.disabled = false;
    toast(tRaw('common.err_prefix', {error: e.message}), true);
  }
}

async function deleteBackup(btn) {
  if (!confirm(tRaw('settings.confirm_delete_zip'))) return;
  const res = await fetch(`${BASE}/api/backup_delete.php`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body:JSON.stringify({token: btn.dataset.token})
  }).then(r => r.json()).catch(() => ({ok:false}));
  if (res.ok) { toast(tRaw('settings.zip_deleted')); setTimeout(() => window.location.reload(), 800); }
  else toast(tRaw('settings.err_delete_zip', {error: res.error || tRaw('common.err_request')}), true);
}

// ── START: only what the current section shows ──
const has = id => document.getElementById(id) !== null;
if (has('comp-list'))     initDrag('comp-list');
if (has('played-list'))   initDrag('played-list');
if (has('variant-list'))  initEditions();
if (has('tag-list'))      initDrag('tag-list');
if (has('sys-sort-list')) loadSystems();
if (has('col-list'))      loadColPrefs();
if (has('auction-list'))  loadAuctionSites();
