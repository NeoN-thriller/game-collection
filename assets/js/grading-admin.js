/* ═══════════════════════════════════════════
   ADMIN — condition grading editors (admin.php)
   Grade labels · Format profiles · Component templates · Export / import
   Talks to api/grading_admin.php. Needs window.GA_BASE.
   ═══════════════════════════════════════════ */
(function () {
'use strict';

const API = window.GA_BASE + '/api/grading_admin.php';
const KINDS = { each: 'each', once: 'once', max: 'max N', level: 'level' };
const $ = sel => document.querySelector(sel);
const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
const clone = o => JSON.parse(JSON.stringify(o));
const toast = (msg, ok = true) => (window.adminToast ? window.adminToast(msg, ok) : alert(msg));

let C = null;                    // config from the server
let L = [], labelMoves = null;   // working labels; pending "move copies" choices
let P = null, selP = null;       // working profile, selected profile id ('new' for a new one)
let T = null, selT = null;       // working template
let dirty = { labels: false, profile: false, template: false };
let importData = null, importReport = null;

async function api(action, data = {}) {
  return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...data }) })
    .then(r => r.json()).catch(e => ({ ok: false, error: e.message }));
}

/** Calls the API; asks to confirm when the server reports that users' data would be lost. */
async function apiConfirm(action, data) {
  let res = await api(action, data);
  if (!res.ok && res.needs_confirm && confirm(res.error)) res = await api(action, { ...data, confirm: true });
  return res;
}

function applyConfig(cfg, keep = {}) {
  C = cfg;
  L = clone(C.labels); dirty.labels = false; labelMoves = null;
  selP = keep.profile ?? (C.profiles.some(p => p.id === selP) ? selP : (C.profiles[0] ? C.profiles[0].id : null));
  selT = keep.template ?? (C.templates.some(t => t.id === selT) ? selT : (C.templates[0] ? C.templates[0].id : null));
  loadProfile(selP); loadTemplate(selT);
  renderLabels(); renderProfiles(); renderTemplates(); renderIO();
}

// ══ GRADE LABELS ══════════════════════════
function rgba(hex, a) {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || ''); if (!m) return `rgba(176,168,152,${a})`;
  const n = parseInt(m[1], 16); return `rgba(${n >> 16},${(n >> 8) & 255},${n & 255},${a})`;
}
function badge(l) {
  return `<span class="qbadge" style="color:${esc(l.color)};background:${rgba(l.color, .18)};border:1px solid ${rgba(l.color, .4)}">${esc(l.name || '—')}</span>`;
}
function labelProblems() {
  const mins = L.map(l => +l.min_score);
  if (!L.length) return 'Keep at least one label.';
  if (!mins.includes(0)) return 'The lowest label must start at 0.';
  if (new Set(mins).size !== mins.length) return 'Two labels start at the same score.';
  if (L.some(l => !String(l.name).trim())) return 'Every label needs a name.';
  if (mins.some(m => isNaN(m) || m < 0 || m > 100)) return 'Start scores must be 0–100.';
  return '';
}
function upTo(l) {
  const higher = L.filter(x => +x.min_score > +l.min_score).map(x => +x.min_score);
  return higher.length ? Math.max(+l.min_score, Math.min(...higher) - 1) : 100;
}
function sortLabels() { L.sort((a, b) => b.min_score - a.min_score); }

function renderLabels() {
  const el = $('#ga-labels'); if (!el) return;
  const rows = L.map((l, i) => `
    <div class="ga-lrow" data-i="${i}">
      <input type="color" value="${esc(l.color)}" data-f="color" aria-label="Colour of ${esc(l.name)}">
      <input type="text" value="${esc(l.name)}" data-f="name" maxlength="50" aria-label="Label name" class="ga-lname">
      <input type="text" value="${esc(l.short)}" data-f="short" maxlength="6" aria-label="Short code" class="ga-short" placeholder="${esc((l.name || '?').charAt(0))}" title="Short code for dashboard counts (e.g. NM)">
      <span class="ga-from">from <input type="number" min="0" max="100" value="${esc(l.min_score)}" data-f="min_score" aria-label="Starts at score"> <span class="ga-upto">– up to ${upTo(l)}</span></span>
      <span class="ga-prev">${badge(l)}</span>
      <span class="ga-used">${l.used ? `${l.used} simple cop${l.used === 1 ? 'y' : 'ies'}` : ''}</span>
      <button type="button" class="btn-danger" data-act="rm" aria-label="Remove ${esc(l.name)}">✕</button>
    </div>`).join('');
  const asc = L.slice().sort((a, b) => a.min_score - b.min_score);
  const scale = asc.map((l, i) => {
    const end = i < asc.length - 1 ? +asc[i + 1].min_score : 100, w = Math.max(0, end - l.min_score);
    return `<div style="width:${w}%;background:${rgba(l.color, .35)}" title="${esc(l.name)}: ${l.min_score}–${upTo(l)}">${w >= 7 ? esc(l.name) : ''}</div>`;
  }).join('');
  const prob = labelProblems();
  let moveBox = '';
  if (labelMoves) {
    const targets = L.map((l, i) => ({ v: l.id ? String(l.id) : 'n' + i, name: l.name }));
    moveBox = `<div class="ga-box ga-warn">
      <div><b>Some removed labels are still used by simple-graded copies.</b> Choose where those copies go:</div>
      ${labelMoves.map(m => `<div class="ga-move">“${esc(m.name)}” (${m.count} copies) →
        <select data-move="${m.id}">${targets.map(t => `<option value="${esc(t.v)}"${m.to === t.v ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select></div>`).join('')}
      <div class="ga-actions"><button type="button" class="btn-ghost" data-act="move-cancel">Cancel</button><button type="button" class="btn btn-sm" data-act="move-save">Move copies &amp; save</button></div>
    </div>`;
  }
  el.innerHTML = `
    <p class="ga-desc">Used by both methods: Simple shows them in the Quality dropdown; a point score gets the highest label whose start score it reaches.
      Changing a start score re-labels scored copies straight away. Renaming keeps every copy on its label.</p>
    <div class="ga-lhead"><span>Colour</span><span>Name</span><span>Short</span><span>Starts at</span><span>Preview</span></div>
    <div class="ga-lrows">${rows}</div>
    <div class="ga-scale">${scale}</div>
    <div class="ga-scale-axis"><span>0</span><span>100</span></div>
    <div class="ga-msg ${prob ? 'bad' : 'good'}" id="ga-label-msg">${prob || L.length + ' labels ✓'}</div>
    ${moveBox}
    <div class="ga-actions">
      <button type="button" class="btn-ghost" data-act="add">+ Add label</button>
      <span class="ga-dirty">${dirty.labels ? 'Unsaved changes' : ''}</span>
      <button type="button" class="btn btn-sm" data-act="save"${prob ? ' disabled' : ''}>Save labels</button>
    </div>`;
}

function initLabels() {
  const el = $('#ga-labels'); if (!el) return;
  el.addEventListener('input', e => {
    const row = e.target.closest('.ga-lrow'), f = e.target.dataset.f;
    if (!row || !f) return;
    const l = L[+row.dataset.i];
    l[f] = f === 'min_score' ? parseInt(e.target.value, 10) : e.target.value;
    dirty.labels = true;
    row.querySelector('.ga-prev').innerHTML = badge(l);
    const prob = labelProblems(), msg = $('#ga-label-msg');
    msg.textContent = prob || L.length + ' labels ✓'; msg.className = 'ga-msg ' + (prob ? 'bad' : 'good');
    el.querySelector('[data-act="save"]').disabled = !!prob;
    el.querySelector('.ga-dirty').textContent = 'Unsaved changes';
  });
  el.addEventListener('change', e => {
    if (e.target.dataset.move) { const m = labelMoves.find(x => String(x.id) === e.target.dataset.move); if (m) m.to = e.target.value; return; }
    if (['min_score', 'color'].includes(e.target.dataset.f)) { sortLabels(); renderLabels(); }
  });
  el.addEventListener('click', async e => {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const act = b.dataset.act;
    if (act === 'rm') {
      const l = L[+b.closest('.ga-lrow').dataset.i];
      if (L.length <= 1) return toast('Keep at least one label.', false);
      L.splice(L.indexOf(l), 1); dirty.labels = true; renderLabels();
    }
    if (act === 'add') {
      // New label in the middle of the largest gap
      const ms = L.map(l => +l.min_score).sort((a, b) => a - b);
      let best = -1, at = 50;
      ms.forEach((m, i) => { const end = i < ms.length - 1 ? ms[i + 1] : 100; if (end - m > best) { best = end - m; at = Math.round((m + end) / 2); } });
      L.push({ id: null, name: 'New label', short: '', min_score: ms.length ? at : 0, color: '#b0a898', used: 0 });
      sortLabels(); dirty.labels = true; renderLabels();
    }
    if (act === 'move-cancel') { labelMoves = null; renderLabels(); }
    if (act === 'save' || act === 'move-save') await saveLabels();
  });
}

async function saveLabels() {
  const moves = {};
  (labelMoves || []).forEach(m => { moves[m.id] = m.to; });
  const res = await api('save_labels', { labels: L.map(l => ({ id: l.id, name: l.name, short: l.short, min_score: +l.min_score, color: l.color })), moves });
  if (res.needs_move) {
    labelMoves = res.needs_move.map(m => {
      // Default target: the kept label that covers the removed label's start score
      const old = C.labels.find(x => x.id === m.id);
      const cand = L.slice().sort((a, b) => b.min_score - a.min_score).find(l => old && l.min_score <= old.min_score) || L[L.length - 1];
      const i = L.indexOf(cand);
      return { ...m, to: cand.id ? String(cand.id) : 'n' + i };
    });
    renderLabels(); return;
  }
  toast(res.ok ? res.msg : 'Error: ' + res.error, res.ok);
  if (res.ok) applyConfig(res.config, { profile: selP, template: selT });
}

// ══ FORMAT PROFILES ═══════════════════════
function loadProfile(id) {
  selP = id;
  const src = C.profiles.find(p => p.id === id);
  P = src ? clone(src) : (id === 'new' ? { id: null, name: 'New profile', systems: [], components: [] } : null);
  dirty.profile = id === 'new';
}
function templateOptions(sel) {
  return C.templates.map(t => `<option value="${t.id}"${t.id == sel ? ' selected' : ''}>${esc(t.name)}</option>`).join('');
}
function renderProfiles() {
  const el = $('#ga-profiles'); if (!el) return;
  const list = C.profiles.map(p => `
    <button type="button" class="ga-item${p.id === selP ? ' on' : ''}" data-act="pick" data-id="${p.id}">
      <span>${esc(p.name)}</span>
      <small>${p.systems.map(sid => (C.systems.find(s => s.id === sid) || {}).short).filter(Boolean).map(esc).join(' · ') || 'no systems'}</small>
    </button>`).join('') + `<button type="button" class="ga-item ga-new${selP === 'new' ? ' on' : ''}" data-act="pick" data-id="new">+ New profile</button>`;
  const unassigned = C.systems.filter(s => !s.profile_id);
  let edit = '<p class="ga-desc">Pick a profile.</p>';
  if (P) {
    const total = P.components.reduce((a, c) => a + (parseInt(c.weight, 10) || 0), 0);
    const sysChips = P.systems.map(sid => { const s = C.systems.find(x => x.id === sid); return s ? `<span class="ga-chip">${esc(s.short)} <button type="button" data-act="sys-rm" data-id="${sid}" aria-label="Remove ${esc(s.short)}">✕</button></span>` : ''; }).join('');
    const sysOpts = C.systems.filter(s => !P.systems.includes(s.id)).map(s => {
      const cur = s.profile_id ? C.profiles.find(p => p.id === s.profile_id) : null;
      return `<option value="${s.id}">${esc(s.short)} — ${esc(s.name)}${cur ? ` (now: ${esc(cur.name)})` : ''}</option>`;
    }).join('');
    const rows = P.components.map((c, i) => `
      <tr data-i="${i}">
        <td><input type="text" value="${esc(c.label)}" data-f="label" maxlength="100" aria-label="Part name"></td>
        <td><input type="text" value="${esc(c.abbr)}" data-f="abbr" maxlength="8" class="ga-short" aria-label="Short name" title="Short name in the collection table (e.g. Cart)"></td>
        <td><select data-f="template_id" aria-label="Template">${templateOptions(c.template_id)}</select></td>
        <td><input type="number" min="0" max="100" value="${esc(c.weight)}" data-f="weight" class="ga-num" aria-label="Weight"> %</td>
        <td><input type="number" min="0" max="9" value="${esc(c.default_qty)}" data-f="default_qty" class="ga-num" aria-label="Starting quantity" title="Quantity when a copy starts point grading (0 = usually missing)"></td>
        <td class="ga-used">${c.used ? c.used + ' graded' : ''}</td>
        <td class="ga-ord"><button type="button" data-act="up" aria-label="Move up"${i ? '' : ' disabled'}>↑</button><button type="button" data-act="down" aria-label="Move down"${i < P.components.length - 1 ? '' : ' disabled'}>↓</button><button type="button" class="btn-danger" data-act="c-rm" aria-label="Remove part">✕</button></td>
      </tr>`).join('');
    edit = `
      <div class="field"><label>Profile name</label><input type="text" value="${esc(P.name)}" data-pf="name" maxlength="100"></div>
      <div class="field"><label>Default for systems</label>
        <div class="ga-chips">${sysChips || '<span class="ga-desc">No systems yet.</span>'}
          ${sysOpts ? `<select data-act-sel="sys-add" aria-label="Add system" class="ga-sys-add"><option value="">+ System</option>${sysOpts}</select>` : ''}</div>
      </div>
      <table class="admin-table ga-ctable">
        <thead><tr><th>Part</th><th>Short</th><th>Template</th><th>Weight</th><th>Start qty</th><th></th><th></th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <div class="ga-actions">
        <button type="button" class="btn-ghost" data-act="c-add">+ Add part</button>
        <span class="ga-msg ${total === 100 ? 'good' : 'warn'}">Total ${total}%${total === 100 ? ' ✓' : ' — should be 100%'}</span>
      </div>
      <p class="ga-desc">Users set a <b>quantity</b> per part on each copy (0 = missing, skipped and not penalised; 2+ = each unit graded separately, sharing the part's weight).
        They can also add <b>their own items</b>, graded with any template.
        Own items start at <input type="number" min="1" max="100" value="${esc(C.own_weight)}" class="ga-num" id="ga-own-w" aria-label="Own item default weight"> %
        <button type="button" class="btn-ghost" data-act="own-save">Set</button></p>
      <div class="ga-actions">
        ${P.id ? '<button type="button" class="btn-danger" data-act="p-del">Delete profile</button>' : ''}
        <span class="ga-dirty">${dirty.profile ? 'Unsaved changes' : ''}</span>
        <button type="button" class="btn btn-sm" data-act="p-save">Save profile</button>
      </div>`;
  }
  el.innerHTML = `
    <div class="ga-split">
      <div class="ga-list">${list}</div>
      <div class="ga-edit">${edit}</div>
    </div>
    <div class="ga-box">
      <div>${unassigned.length ? `Systems without a default profile: <b>${unassigned.map(s => esc(s.short)).join(', ')}</b> — users pick a profile per copy there.`
                               : 'Every system has a default profile.'}</div>
      <button type="button" class="btn-ghost" data-act="auto">Auto-assign by system name</button>
    </div>`;
}

function initProfiles() {
  const el = $('#ga-profiles'); if (!el) return;
  const markDirty = () => { dirty.profile = true; const d = el.querySelector('.ga-dirty'); if (d) d.textContent = 'Unsaved changes'; };
  el.addEventListener('input', e => {
    if (e.target.dataset.pf === 'name') { P.name = e.target.value; return markDirty(); }
    const tr = e.target.closest('tr[data-i]'), f = e.target.dataset.f;
    if (!tr || !f) return;
    P.components[+tr.dataset.i][f] = ['weight', 'default_qty', 'template_id'].includes(f) ? parseInt(e.target.value, 10) || 0 : e.target.value;
    markDirty();
    if (f === 'weight') {
      const total = P.components.reduce((a, c) => a + (parseInt(c.weight, 10) || 0), 0), m = el.querySelector('.ga-msg');
      m.textContent = `Total ${total}%` + (total === 100 ? ' ✓' : ' — should be 100%'); m.className = 'ga-msg ' + (total === 100 ? 'good' : 'warn');
    }
  });
  el.addEventListener('change', e => {
    if (e.target.dataset.actSel === 'sys-add' && e.target.value) { P.systems.push(+e.target.value); markDirty(); renderProfiles(); }
    else if (e.target.dataset.f === 'template_id') { P.components[+e.target.closest('tr').dataset.i].template_id = +e.target.value; markDirty(); }
  });
  el.addEventListener('click', async e => {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const act = b.dataset.act, tr = b.closest('tr[data-i]'), i = tr ? +tr.dataset.i : -1;
    if (act === 'pick') {
      const id = b.dataset.id === 'new' ? 'new' : +b.dataset.id;
      if (id === selP) return;
      if (dirty.profile && !confirm('Discard unsaved changes to this profile?')) return;
      loadProfile(id); return renderProfiles();
    }
    if (act === 'sys-rm') { P.systems = P.systems.filter(s => s !== +b.dataset.id); markDirty(); return renderProfiles(); }
    if (act === 'c-add') {
      const paper = C.templates.find(t => /paper/i.test(t.name)) || C.templates[0];
      P.components.push({ id: null, label: '', abbr: '', template_id: paper ? paper.id : null, weight: 5, default_qty: 1, used: 0 });
      markDirty(); renderProfiles();
      const inputs = el.querySelectorAll('tr[data-i] input[data-f="label"]'); if (inputs.length) inputs[inputs.length - 1].focus();
      return;
    }
    if (act === 'c-rm') {
      const c = P.components[i];
      if (c.used && !confirm(`“${c.label}” is graded on ${c.used} copies. Removing it deletes that grading when you save. Continue?`)) return;
      P.components.splice(i, 1); markDirty(); return renderProfiles();
    }
    if (act === 'up' || act === 'down') {
      const j = act === 'up' ? i - 1 : i + 1;
      [P.components[i], P.components[j]] = [P.components[j], P.components[i]];
      markDirty(); return renderProfiles();
    }
    if (act === 'own-save') {
      const res = await api('save_settings', { own_weight: +$('#ga-own-w').value });
      toast(res.ok ? res.msg : 'Error: ' + res.error, res.ok);
      if (res.ok) C.own_weight = res.config.own_weight;
      return;
    }
    if (act === 'p-save') {
      const res = await apiConfirm('save_profile', { profile: { id: P.id, name: P.name, systems: P.systems,
        components: P.components.map(c => ({ id: c.id, label: c.label, abbr: c.abbr, template_id: c.template_id, weight: +c.weight, default_qty: +c.default_qty })) } });
      toast(res.ok ? res.msg : 'Error: ' + res.error, res.ok);
      if (res.ok) applyConfig(res.config, { profile: res.saved_id, template: selT });
      return;
    }
    if (act === 'p-del') {
      if (!confirm(`Delete the profile “${P.name}”? Systems using it will have no default profile.`)) return;
      const res = await apiConfirm('delete_profile', { id: P.id });
      toast(res.ok ? res.msg : 'Error: ' + res.error, res.ok);
      if (res.ok) { selP = null; applyConfig(res.config, { template: selT }); }
      return;
    }
    if (act === 'auto') {
      const res = await api('auto_assign');
      toast(res.ok ? res.msg : 'Error: ' + res.error, res.ok);
      if (res.ok) applyConfig(res.config, { profile: selP, template: selT });
    }
  });
}

// ══ COMPONENT TEMPLATES ═══════════════════
function loadTemplate(id) {
  selT = id;
  const src = C.templates.find(t => t.id === id);
  T = src ? clone(src) : (id === 'new' ? { id: null, name: 'New template', categories: [{ id: null, name: 'Condition', max_points: 100, defects: [] }] } : null);
  dirty.template = id === 'new';
}
function renderTemplates() {
  const el = $('#ga-templates'); if (!el) return;
  const tabs = C.templates.map(t => `<button type="button" class="ga-tab${t.id === selT ? ' on' : ''}" data-act="pick" data-id="${t.id}">${esc(t.name)}</button>`).join('')
    + `<button type="button" class="ga-tab ga-new${selT === 'new' ? ' on' : ''}" data-act="pick" data-id="new">+ New template</button>`;
  let edit = '<p class="ga-desc">Pick a template.</p>';
  if (T) {
    const total = T.categories.reduce((a, c) => a + (parseInt(c.max_points, 10) || 0), 0);
    const usedBy = C.profiles.filter(p => p.components.some(c => c.template_id === T.id)).map(p => p.name);
    const cats = T.categories.map((c, ci) => `
      <div class="ga-cat" data-ci="${ci}">
        <div class="ga-cat-head">
          <input type="text" value="${esc(c.name)}" data-cf="name" maxlength="100" aria-label="Category name">
          <label class="ga-max">max <input type="number" min="0" max="100" value="${esc(c.max_points)}" data-cf="max_points" class="ga-num" aria-label="Max points"></label>
          <span class="ga-ord"><button type="button" data-act="cat-up" aria-label="Move category up"${ci ? '' : ' disabled'}>↑</button><button type="button" data-act="cat-down" aria-label="Move category down"${ci < T.categories.length - 1 ? '' : ' disabled'}>↓</button><button type="button" class="btn-danger" data-act="cat-rm" aria-label="Remove category">✕</button></span>
        </div>
        ${c.defects.map((d, di) => `
          <div class="ga-def" data-di="${di}">
            <input type="text" value="${esc(d.name)}" data-df="name" maxlength="100" aria-label="Defect name" placeholder="${d.kind === 'level' ? 'e.g. Minor' : 'e.g. Crease'}">
            <select data-df="kind" aria-label="Kind">${Object.entries(KINDS).map(([k, v]) => `<option value="${k}"${d.kind === k ? ' selected' : ''}>${v}</option>`).join('')}</select>
            ${d.kind === 'max' ? `<label class="ga-sm">max <input type="number" min="1" max="99" value="${esc(d.max_count ?? 1)}" data-df="max_count" class="ga-num" aria-label="Maximum count"></label>` : ''}
            ${d.kind === 'level' ? `<label class="ga-sm">group <input type="text" value="${esc(d.level_group ?? '')}" data-df="level_group" maxlength="50" class="ga-grp" aria-label="Level group" placeholder="e.g. Fading"></label>` : ''}
            <label class="ga-sm">−<input type="number" min="0" max="100" value="${esc(d.penalty)}" data-df="penalty" class="ga-num" aria-label="Deduction"></label>
            <button type="button" class="btn-danger" data-act="def-rm" aria-label="Remove defect">✕</button>
          </div>`).join('')}
        <button type="button" class="gr-add" data-act="def-add">+ Add defect</button>
      </div>`).join('');
    edit = `
      <div class="ga-trow">
        <div class="field" style="flex:1"><label>Template name</label><input type="text" value="${esc(T.name)}" data-tf="name" maxlength="100"></div>
        <p class="ga-desc">${usedBy.length ? 'Used by: ' + usedBy.map(esc).join(', ') : 'Not used by any profile yet.'}</p>
      </div>
      <p class="ga-desc"><b>each</b> = deduction × count · <b>once</b> = at most once · <b>max N</b> = counter capped at N ·
        <b>level</b> = pick one per group (defects with the same group in a category are mutually exclusive, e.g. Fading: Minor / Moderate / Heavy).</p>
      <div class="ga-cats">${cats}</div>
      <div class="ga-actions">
        <button type="button" class="btn-ghost" data-act="cat-add">+ Add category</button>
        <span class="ga-msg ${total === 100 ? 'good' : 'warn'}" id="ga-tpl-total">Category maxes: ${total} / 100${total === 100 ? ' ✓' : ''}</span>
      </div>
      <div class="ga-actions">
        ${T.id ? '<button type="button" class="btn-danger" data-act="t-del">Delete template</button>' : ''}
        <button type="button" class="btn-ghost" data-act="recalc" title="Refreshes every copy's cached score. Saving already does this.">Recalculate all scores</button>
        <span class="ga-dirty">${dirty.template ? 'Unsaved changes' : ''}</span>
        <button type="button" class="btn btn-sm" data-act="t-save"${total === 100 ? '' : ' disabled'}>Save template</button>
      </div>`;
  }
  el.innerHTML = `<div class="ga-tabs">${tabs}</div>${edit}`;
}

function initTemplates() {
  const el = $('#ga-templates'); if (!el) return;
  const markDirty = () => { dirty.template = true; const d = el.querySelector('.ga-dirty'); if (d) d.textContent = 'Unsaved changes'; };
  const updTotal = () => {
    const total = T.categories.reduce((a, c) => a + (parseInt(c.max_points, 10) || 0), 0), m = $('#ga-tpl-total');
    m.textContent = `Category maxes: ${total} / 100` + (total === 100 ? ' ✓' : ''); m.className = 'ga-msg ' + (total === 100 ? 'good' : 'warn');
    el.querySelector('[data-act="t-save"]').disabled = total !== 100;
  };
  el.addEventListener('input', e => {
    const t = e.target;
    if (t.dataset.tf === 'name') { T.name = t.value; return markDirty(); }
    const cat = t.closest('.ga-cat'); if (!cat) return;
    const c = T.categories[+cat.dataset.ci];
    if (t.dataset.cf) { c[t.dataset.cf] = t.dataset.cf === 'max_points' ? parseInt(t.value, 10) || 0 : t.value; markDirty(); if (t.dataset.cf === 'max_points') updTotal(); return; }
    const row = t.closest('.ga-def'), f = t.dataset.df;
    if (row && f && f !== 'kind') { c.defects[+row.dataset.di][f] = ['penalty', 'max_count'].includes(f) ? parseInt(t.value, 10) || 0 : t.value; markDirty(); }
  });
  el.addEventListener('change', e => {
    const t = e.target;
    if (t.dataset.df !== 'kind') return;
    const c = T.categories[+t.closest('.ga-cat').dataset.ci], d = c.defects[+t.closest('.ga-def').dataset.di];
    d.kind = t.value;
    if (d.kind === 'max' && !d.max_count) d.max_count = 5;
    if (d.kind === 'level' && !d.level_group) d.level_group = (c.defects.find(x => x !== d && x.kind === 'level') || {}).level_group || 'Level';
    markDirty(); renderTemplates();
  });
  el.addEventListener('click', async e => {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const act = b.dataset.act, catEl = b.closest('.ga-cat'), ci = catEl ? +catEl.dataset.ci : -1, defEl = b.closest('.ga-def');
    if (act === 'pick') {
      const id = b.dataset.id === 'new' ? 'new' : +b.dataset.id;
      if (id === selT) return;
      if (dirty.template && !confirm('Discard unsaved changes to this template?')) return;
      loadTemplate(id); return renderTemplates();
    }
    if (act === 'cat-add') { T.categories.push({ id: null, name: '', max_points: 0, defects: [] }); markDirty(); return renderTemplates(); }
    if (act === 'cat-rm') { if (!confirm('Remove this category and its defects?')) return; T.categories.splice(ci, 1); markDirty(); return renderTemplates(); }
    if (act === 'cat-up' || act === 'cat-down') {
      const j = act === 'cat-up' ? ci - 1 : ci + 1;
      [T.categories[ci], T.categories[j]] = [T.categories[j], T.categories[ci]];
      markDirty(); return renderTemplates();
    }
    if (act === 'def-add') {
      T.categories[ci].defects.push({ id: null, name: '', penalty: 1, kind: 'each', max_count: null, level_group: null });
      markDirty(); renderTemplates();
      const rows = el.querySelectorAll(`.ga-cat[data-ci="${ci}"] .ga-def input[data-df="name"]`); if (rows.length) rows[rows.length - 1].focus();
      return;
    }
    if (act === 'def-rm') { T.categories[ci].defects.splice(+defEl.dataset.di, 1); markDirty(); return renderTemplates(); }
    if (act === 't-save') {
      const res = await apiConfirm('save_template', { template: T });
      toast(res.ok ? res.msg : 'Error: ' + res.error, res.ok);
      if (res.ok) applyConfig(res.config, { profile: selP, template: res.saved_id });
      return;
    }
    if (act === 't-del') {
      if (!confirm(`Delete the template “${T.name}”?`)) return;
      const res = await apiConfirm('delete_template', { id: T.id });
      toast(res.ok ? res.msg : 'Error: ' + res.error, res.ok);
      if (res.ok) { selT = null; applyConfig(res.config, { profile: selP }); }
      return;
    }
    if (act === 'recalc') {
      const res = await api('recalc');
      toast(res.ok ? res.msg : 'Error: ' + res.error, res.ok);
    }
  });
}

// ══ EXPORT / IMPORT ═══════════════════════
function reportHtml(r) {
  const block = (title, items, cls = '') => items && items.length
    ? `<details class="ga-rep ${cls}"${cls ? ' open' : ''}><summary>${title} (${items.length})</summary><ul>${items.map(x => `<li>${esc(x)}</li>`).join('')}</ul></details>` : '';
  return block('Problems', r.errors, 'bad') + block('Warnings', r.warnings, 'warn')
    + block('Added', r.added) + block('Updated', r.updated) + block('Removed', r.removed, r.removed.length ? 'warn' : '')
    + block('Kept (not in the file)', r.kept) + block('System defaults', r.systems);
}
function renderIO() {
  const el = $('#ga-io'); if (!el) return;
  const summary = importData
    ? `<b>${esc(importData._file)}</b>: ${(importData.labels || []).length} labels, ${(importData.templates || []).length} templates, ${(importData.profiles || []).length} profiles`
    : 'No file chosen.';
  el.innerHTML = `
    <p class="ga-desc">The whole grading system in one JSON file: grade labels, component templates, format profiles, which systems use which profile, and the own-item weight.
      Users' per-copy grades are not in it — those travel with each user's own collection export (Settings → Backup &amp; Restore).</p>
    <div class="ga-actions" style="justify-content:flex-start">
      <a class="btn btn-sm" href="${esc(API)}?action=export" style="text-decoration:none">⬇ Export grading system</a>
      <label class="btn-ghost" style="cursor:pointer">⬆ Choose file to import…<input type="file" accept=".json,application/json" id="ga-file" style="display:none"></label>
      <button type="button" class="btn-ghost" data-act="defaults">Reset to built-in defaults…</button>
    </div>
    <div class="ga-box" style="${importData ? '' : 'display:none'}">
      <div>${summary}</div>
      <div class="ga-modes">
        <label><input type="radio" name="ga-mode" value="merge" checked> <b>Merge by name</b> — update what matches, add what's new. Users' grading data is kept.</label>
        <label class="ga-sub"><input type="checkbox" id="ga-delmissing"> Also remove labels, templates, profiles, parts and defects that are not in the file (users' data on them is deleted)</label>
        <label><input type="radio" name="ga-mode" value="replace"> <b>Replace everything</b> — delete the current system and load the file. <span class="ga-bad">All users' point grades are deleted</span> (simple labels are kept and matched by name).</label>
      </div>
      <div class="ga-actions" style="justify-content:flex-start">
        <button type="button" class="btn-ghost" data-act="preview">Preview</button>
        <button type="button" class="btn btn-sm" data-act="apply"${importReport ? '' : ' disabled'}>Apply import</button>
        <button type="button" class="btn-ghost" data-act="clear">Cancel</button>
      </div>
      <div id="ga-report">${importReport ? reportHtml(importReport) : ''}</div>
    </div>`;
}

function initIO() {
  const el = $('#ga-io'); if (!el) return;
  const opts = () => ({ mode: el.querySelector('input[name="ga-mode"]:checked').value, delete_missing: el.querySelector('#ga-delmissing').checked });
  el.addEventListener('change', async e => {
    if (e.target.id === 'ga-file') {
      const f = e.target.files[0]; if (!f) return;
      try { importData = JSON.parse(await f.text()); importData._file = f.name; }
      catch { toast('That file is not valid JSON.', false); return; }
      if (importData.format !== 'game-collection-grading') { toast('That is not a grading system export.', false); importData = null; return; }
      importReport = null; renderIO();
    } else if (e.target.name === 'ga-mode' || e.target.id === 'ga-delmissing') {
      importReport = null;
      const rep = $('#ga-report'); if (rep) rep.innerHTML = '';
      const ap = el.querySelector('[data-act="apply"]'); if (ap) ap.disabled = true;
    }
  });
  el.addEventListener('click', async e => {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const act = b.dataset.act;
    if (act === 'clear') { importData = null; importReport = null; return renderIO(); }
    if (act === 'preview') {
      const o = opts(), data = { ...importData }; delete data._file;
      const res = await api('import', { data, ...o, dry_run: true });
      importReport = res.report || { errors: [res.error], warnings: [], added: [], updated: [], removed: [], kept: [], systems: [] };
      const mode = o.mode, delM = o.delete_missing;
      renderIO();
      el.querySelector(`input[name="ga-mode"][value="${mode}"]`).checked = true;
      el.querySelector('#ga-delmissing').checked = delM;
      el.querySelector('[data-act="apply"]').disabled = !res.ok;
      return;
    }
    if (act === 'apply') {
      const o = opts(), data = { ...importData }; delete data._file;
      if (o.mode === 'replace' && C.graded && prompt(`Replace deletes the point grades of ${C.graded} copies. Type REPLACE to continue.`) !== 'REPLACE') return;
      if (o.mode === 'merge' && o.delete_missing && importReport && importReport.removed.length && !confirm(`${importReport.removed.length} items will be removed. Continue?`)) return;
      const res = await api('import', { data, ...o, dry_run: false });
      toast(res.ok ? res.msg : 'Error: ' + res.error, res.ok);
      if (res.ok) { importData = null; importReport = null; applyConfig(res.config); }
      return;
    }
    if (act === 'defaults') {
      const pre = await api('reset_defaults', { dry_run: true });
      if (!pre.ok) return toast('Error: ' + pre.error, false);
      const msg = 'Reset to the built-in defaults (assets/grading-defaults.json)?\n\nThis replaces all labels, templates and profiles'
        + (C.graded ? ` and deletes the point grades of ${C.graded} copies (simple labels are kept).` : '.')
        + '\nSystems are re-assigned to profiles by name.';
      if (!confirm(msg)) return;
      if (C.graded && prompt('Type RESET to continue.') !== 'RESET') return;
      const res = await api('reset_defaults', { dry_run: false });
      toast(res.ok ? 'Grading system reset to defaults.' : 'Error: ' + res.error, res.ok);
      if (res.ok) applyConfig(res.config);
    }
  });
}

window.addEventListener('beforeunload', e => {
  if (dirty.labels || (dirty.profile && selP !== 'new') || (dirty.template && selT !== 'new')) { e.preventDefault(); e.returnValue = ''; }
});

(async function init() {
  initLabels(); initProfiles(); initTemplates(); initIO();
  const res = await fetch(API + '?action=config').then(r => r.json()).catch(e => ({ ok: false, error: e.message }));
  if (!res.ok) { $('#ga-labels').innerHTML = `<p class="ga-msg bad">Could not load grading settings: ${esc(res.error)}</p>`; return; }
  applyConfig(res.config);
})();
})();
