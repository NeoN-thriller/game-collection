/* ═══════════════════════════════════════════
   ADMIN — condition grading editors (settings.php?s=grading-system)
   Grade labels · Format profiles · Component templates · Export / import
   Talks to api/grading_admin.php. Needs window.GA_BASE.
   ═══════════════════════════════════════════ */
(function () {
'use strict';

const API = window.GA_BASE + '/api/grading_admin.php';
const KINDS = { each: tRaw('ga.kind_each'), once: tRaw('ga.kind_once'), max: tRaw('ga.kind_max'), level: tRaw('ga.kind_level') };
const err = res => tRaw('common.err_prefix', { error: res.error });
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
  if (!L.length) return tRaw('ga.err_keep_one');
  if (!mins.includes(0)) return tRaw('ga.err_lowest');
  if (new Set(mins).size !== mins.length) return tRaw('ga.err_same');
  if (L.some(l => !String(l.name).trim())) return tRaw('ga.err_name');
  if (mins.some(m => isNaN(m) || m < 0 || m > 100)) return tRaw('ga.err_range');
  return '';
}
function upTo(l) {
  const higher = L.filter(x => +x.min_score > +l.min_score).map(x => +x.min_score);
  return higher.length ? Math.max(+l.min_score, Math.min(...higher) - 1) : 100;
}
function sortLabels() { L.sort((a, b) => b.min_score - a.min_score); }
function weightMsg(total) { return tRaw('ga.weight_total', {n: total}) + (total === 100 ? ' ✓' : ' — ' + tRaw('ga.should_be_100')); }

function renderLabels() {
  const el = $('#ga-labels'); if (!el) return;
  const rows = L.map((l, i) => `
    <div class="ga-lrow" data-i="${i}">
      <input type="color" value="${esc(l.color)}" data-f="color" aria-label="${t('ga.colour_of', {name: l.name})}">
      <input type="text" value="${esc(l.name)}" data-f="name" maxlength="50" aria-label="${t('ga.label_name')}" class="ga-lname">
      <input type="text" value="${esc(l.short)}" data-f="short" maxlength="6" aria-label="${t('ga.short_code')}" class="ga-short" placeholder="${esc((l.name || '?').charAt(0))}" title="${t('ga.short_code_title')}">
      <span class="ga-from">${t('ga.from')} <input type="number" min="0" max="100" value="${esc(l.min_score)}" data-f="min_score" aria-label="${t('ga.starts_at')}"> <span class="ga-upto">– ${t('ga.up_to', {n: upTo(l)})}</span></span>
      <span class="ga-prev">${badge(l)}</span>
      <span class="ga-used">${l.used ? tn('ga.simple_copies', l.used) : ''}</span>
      <button type="button" class="btn-danger" data-act="rm" aria-label="${t('grading.remove', {name: l.name})}">✕</button>
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
      <div>${t('ga.moves_intro')}</div>
      ${labelMoves.map(m => `<div class="ga-move">“${esc(m.name)}” (${tn('ga.n_copies', m.count)}) →
        <select data-move="${m.id}">${targets.map(t => `<option value="${esc(t.v)}"${m.to === t.v ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select></div>`).join('')}
      <div class="ga-actions"><button type="button" class="btn-ghost" data-act="move-cancel">${t('common.cancel')}</button><button type="button" class="btn btn-sm" data-act="move-save">${t('ga.move_save')}</button></div>
    </div>`;
  }
  el.innerHTML = `
    <p class="ga-desc">${t('ga.labels_desc')}</p>
    <div class="ga-lhead"><span>${t('ga.col_colour')}</span><span>${t('grading.name')}</span><span>${t('admin.sys.short')}</span><span>${t('ga.starts_at')}</span><span>${t('admin.site.preview')}</span></div>
    <div class="ga-lrows">${rows}</div>
    <div class="ga-scale">${scale}</div>
    <div class="ga-scale-axis"><span>0</span><span>100</span></div>
    <div class="ga-msg ${prob ? 'bad' : 'good'}" id="ga-label-msg">${prob ? esc(prob) : tRaw('ga.n_labels', {n: L.length}) + ' ✓'}</div>
    ${moveBox}
    <div class="ga-actions">
      <button type="button" class="btn-ghost" data-act="add">+ ${t('ga.add_label')}</button>
      <span class="ga-dirty">${dirty.labels ? t('ga.unsaved') : ''}</span>
      <button type="button" class="btn btn-sm" data-act="save"${prob ? ' disabled' : ''}>${t('ga.save_labels')}</button>
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
    msg.textContent = prob || tRaw('ga.n_labels', {n: L.length}) + ' ✓'; msg.className = 'ga-msg ' + (prob ? 'bad' : 'good');
    el.querySelector('[data-act="save"]').disabled = !!prob;
    el.querySelector('.ga-dirty').textContent = tRaw('ga.unsaved');
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
      if (L.length <= 1) return toast(tRaw('ga.err_keep_one'), false);
      L.splice(L.indexOf(l), 1); dirty.labels = true; renderLabels();
    }
    if (act === 'add') {
      // New label in the middle of the largest gap
      const ms = L.map(l => +l.min_score).sort((a, b) => a - b);
      let best = -1, at = 50;
      ms.forEach((m, i) => { const end = i < ms.length - 1 ? ms[i + 1] : 100; if (end - m > best) { best = end - m; at = Math.round((m + end) / 2); } });
      L.push({ id: null, name: tRaw('ga.new_label'), short: '', min_score: ms.length ? at : 0, color: '#b0a898', used: 0 });
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
  toast(res.ok ? res.msg : err(res), res.ok);
  if (res.ok) applyConfig(res.config, { profile: selP, template: selT });
}

// ══ FORMAT PROFILES ═══════════════════════
function loadProfile(id) {
  selP = id;
  const src = C.profiles.find(p => p.id === id);
  P = src ? clone(src) : (id === 'new' ? { id: null, name: tRaw('ga.new_profile'), systems: [], components: [] } : null);
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
      <small>${p.systems.map(sid => (C.systems.find(s => s.id === sid) || {}).short).filter(Boolean).map(esc).join(' · ') || t('ga.no_systems')}</small>
    </button>`).join('') + `<button type="button" class="ga-item ga-new${selP === 'new' ? ' on' : ''}" data-act="pick" data-id="new">+ ${t('ga.new_profile')}</button>`;
  const unassigned = C.systems.filter(s => !s.profile_id);
  let edit = `<p class="ga-desc">${t('ga.pick_profile')}</p>`;
  if (P) {
    const total = P.components.reduce((a, c) => a + (parseInt(c.weight, 10) || 0), 0);
    const sysChips = P.systems.map(sid => { const s = C.systems.find(x => x.id === sid); return s ? `<span class="ga-chip">${esc(s.short)} <button type="button" data-act="sys-rm" data-id="${sid}" aria-label="${t('grading.remove', {name: s.short})}">✕</button></span>` : ''; }).join('');
    const sysOpts = C.systems.filter(s => !P.systems.includes(s.id)).map(s => {
      const cur = s.profile_id ? C.profiles.find(p => p.id === s.profile_id) : null;
      return `<option value="${s.id}">${esc(s.short)} — ${esc(s.name)}${cur ? ` (${t('ga.now', {name: cur.name})})` : ''}</option>`;
    }).join('');
    const rows = P.components.map((c, i) => `
      <tr data-i="${i}">
        <td><input type="text" value="${esc(c.label)}" data-f="label" maxlength="100" aria-label="${t('ga.col_part')}"></td>
        <td><input type="text" value="${esc(c.abbr)}" data-f="abbr" maxlength="8" class="ga-short" aria-label="${t('admin.sys.short')}" title="${t('ga.abbr_title')}"></td>
        <td><select data-f="template_id" aria-label="${t('ga.col_template')}">${templateOptions(c.template_id)}</select></td>
        <td><input type="number" min="0" max="100" value="${esc(c.weight)}" data-f="weight" class="ga-num" aria-label="${t('ga.col_weight')}"> %</td>
        <td><input type="number" min="0" max="9" value="${esc(c.default_qty)}" data-f="default_qty" class="ga-num" aria-label="${t('ga.col_start_qty')}" title="${t('ga.start_qty_title')}"></td>
        <td class="ga-used">${c.used ? t('ga.n_graded', {n: c.used}) : ''}</td>
        <td class="ga-ord"><button type="button" data-act="up" aria-label="${t('ga.move_up')}"${i ? '' : ' disabled'}>↑</button><button type="button" data-act="down" aria-label="${t('ga.move_down')}"${i < P.components.length - 1 ? '' : ' disabled'}>↓</button><button type="button" class="btn-danger" data-act="c-rm" aria-label="${t('ga.remove_part')}">✕</button></td>
      </tr>`).join('');
    edit = `
      <div class="field"><label>${t('ga.profile_name')}</label><input type="text" value="${esc(P.name)}" data-pf="name" maxlength="100"></div>
      <div class="field"><label>${t('ga.default_for')}</label>
        <div class="ga-chips">${sysChips || `<span class="ga-desc">${t('ga.no_systems_yet')}</span>`}
          ${sysOpts ? `<select data-act-sel="sys-add" aria-label="${t('admin.sys.add')}" class="ga-sys-add"><option value="">+ ${t('common.system')}</option>${sysOpts}</select>` : ''}</div>
      </div>
      <table class="admin-table ga-ctable">
        <thead><tr><th>${t('ga.col_part')}</th><th>${t('admin.sys.short')}</th><th>${t('ga.col_template')}</th><th>${t('ga.col_weight')}</th><th>${t('ga.col_start_qty')}</th><th></th><th></th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <div class="ga-actions">
        <button type="button" class="btn-ghost" data-act="c-add">+ ${t('ga.add_part')}</button>
        <span class="ga-msg ${total === 100 ? 'good' : 'warn'}">${weightMsg(total)}</span>
      </div>
      <p class="ga-desc">${t('ga.qty_desc')}
        ${t('ga.own_start')} <input type="number" min="1" max="100" value="${esc(C.own_weight)}" class="ga-num" id="ga-own-w" aria-label="${t('ga.own_weight')}"> %
        <button type="button" class="btn-ghost" data-act="own-save">${t('admin.users.set')}</button></p>
      <div class="ga-actions">
        ${P.id ? `<button type="button" class="btn-danger" data-act="p-del">${t('ga.delete_profile')}</button>` : ''}
        <span class="ga-dirty">${dirty.profile ? t('ga.unsaved') : ''}</span>
        <button type="button" class="btn btn-sm" data-act="p-save">${t('ga.save_profile')}</button>
      </div>`;
  }
  el.innerHTML = `
    <div class="ga-split">
      <div class="ga-list">${list}</div>
      <div class="ga-edit">${edit}</div>
    </div>
    <div class="ga-box">
      <div>${unassigned.length ? t('ga.unassigned', {systems: unassigned.map(s => s.short).join(', ')})
                               : t('ga.all_assigned')}</div>
      <button type="button" class="btn-ghost" data-act="auto">${t('ga.auto_assign')}</button>
    </div>`;
}

function initProfiles() {
  const el = $('#ga-profiles'); if (!el) return;
  const markDirty = () => { dirty.profile = true; const d = el.querySelector('.ga-dirty'); if (d) d.textContent = tRaw('ga.unsaved'); };
  el.addEventListener('input', e => {
    if (e.target.dataset.pf === 'name') { P.name = e.target.value; return markDirty(); }
    const tr = e.target.closest('tr[data-i]'), f = e.target.dataset.f;
    if (!tr || !f) return;
    P.components[+tr.dataset.i][f] = ['weight', 'default_qty', 'template_id'].includes(f) ? parseInt(e.target.value, 10) || 0 : e.target.value;
    markDirty();
    if (f === 'weight') {
      const total = P.components.reduce((a, c) => a + (parseInt(c.weight, 10) || 0), 0), m = el.querySelector('.ga-msg');
      m.textContent = weightMsg(total); m.className = 'ga-msg ' + (total === 100 ? 'good' : 'warn');
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
      if (dirty.profile && !confirm(tRaw('ga.confirm_discard_profile'))) return;
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
      if (c.used && !confirm(tRaw('ga.confirm_remove_part', {name: c.label, n: c.used}))) return;
      P.components.splice(i, 1); markDirty(); return renderProfiles();
    }
    if (act === 'up' || act === 'down') {
      const j = act === 'up' ? i - 1 : i + 1;
      [P.components[i], P.components[j]] = [P.components[j], P.components[i]];
      markDirty(); return renderProfiles();
    }
    if (act === 'own-save') {
      const res = await api('save_settings', { own_weight: +$('#ga-own-w').value });
      toast(res.ok ? res.msg : err(res), res.ok);
      if (res.ok) C.own_weight = res.config.own_weight;
      return;
    }
    if (act === 'p-save') {
      const res = await apiConfirm('save_profile', { profile: { id: P.id, name: P.name, systems: P.systems,
        components: P.components.map(c => ({ id: c.id, label: c.label, abbr: c.abbr, template_id: c.template_id, weight: +c.weight, default_qty: +c.default_qty })) } });
      toast(res.ok ? res.msg : err(res), res.ok);
      if (res.ok) applyConfig(res.config, { profile: res.saved_id, template: selT });
      return;
    }
    if (act === 'p-del') {
      if (!confirm(tRaw('ga.confirm_delete_profile', {name: P.name}))) return;
      const res = await apiConfirm('delete_profile', { id: P.id });
      toast(res.ok ? res.msg : err(res), res.ok);
      if (res.ok) { selP = null; applyConfig(res.config, { template: selT }); }
      return;
    }
    if (act === 'auto') {
      const res = await api('auto_assign');
      toast(res.ok ? res.msg : err(res), res.ok);
      if (res.ok) applyConfig(res.config, { profile: selP, template: selT });
    }
  });
}

// ══ COMPONENT TEMPLATES ═══════════════════
function loadTemplate(id) {
  selT = id;
  const src = C.templates.find(t => t.id === id);
  T = src ? clone(src) : (id === 'new' ? { id: null, name: tRaw('ga.new_template'), categories: [{ id: null, name: tRaw('common.col.quality'), max_points: 100, defects: [] }] } : null);
  dirty.template = id === 'new';
}
function renderTemplates() {
  const el = $('#ga-templates'); if (!el) return;
  const tabs = C.templates.map(t => `<button type="button" class="ga-tab${t.id === selT ? ' on' : ''}" data-act="pick" data-id="${t.id}">${esc(t.name)}</button>`).join('')
    + `<button type="button" class="ga-tab ga-new${selT === 'new' ? ' on' : ''}" data-act="pick" data-id="new">+ ${t('ga.new_template')}</button>`;
  let edit = `<p class="ga-desc">${t('ga.pick_template')}</p>`;
  if (T) {
    const total = T.categories.reduce((a, c) => a + (parseInt(c.max_points, 10) || 0), 0);
    const usedBy = C.profiles.filter(p => p.components.some(c => c.template_id === T.id)).map(p => p.name);
    const cats = T.categories.map((c, ci) => `
      <div class="ga-cat" data-ci="${ci}">
        <div class="ga-cat-head">
          <input type="text" value="${esc(c.name)}" data-cf="name" maxlength="100" aria-label="${t('ga.category_name')}">
          <label class="ga-max">${t('ga.max')} <input type="number" min="0" max="100" value="${esc(c.max_points)}" data-cf="max_points" class="ga-num" aria-label="${t('ga.max_points')}"></label>
          <span class="ga-ord"><button type="button" data-act="cat-up" aria-label="${t('ga.move_up')}"${ci ? '' : ' disabled'}>↑</button><button type="button" data-act="cat-down" aria-label="${t('ga.move_down')}"${ci < T.categories.length - 1 ? '' : ' disabled'}>↓</button><button type="button" class="btn-danger" data-act="cat-rm" aria-label="${t('ga.remove_category')}">✕</button></span>
        </div>
        ${c.defects.map((d, di) => `
          <div class="ga-def" data-di="${di}">
            <input type="text" value="${esc(d.name)}" data-df="name" maxlength="100" aria-label="${t('ga.defect_name')}" placeholder="${t(d.kind === 'level' ? 'ga.defect_ph_level' : 'ga.defect_ph')}">
            <select data-df="kind" aria-label="${t('ga.kind')}">${Object.entries(KINDS).map(([k, v]) => `<option value="${k}"${d.kind === k ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select>
            ${d.kind === 'max' ? `<label class="ga-sm">${t('ga.max')} <input type="number" min="1" max="99" value="${esc(d.max_count ?? 1)}" data-df="max_count" class="ga-num" aria-label="${t('ga.max_count')}"></label>` : ''}
            ${d.kind === 'level' ? `<label class="ga-sm">${t('ga.group')} <input type="text" value="${esc(d.level_group ?? '')}" data-df="level_group" maxlength="50" class="ga-grp" aria-label="${t('ga.level_group')}" placeholder="${t('ga.group_ph')}"></label>` : ''}
            <label class="ga-sm">−<input type="number" min="0" max="100" value="${esc(d.penalty)}" data-df="penalty" class="ga-num" aria-label="${t('ga.deduction')}"></label>
            <button type="button" class="btn-danger" data-act="def-rm" aria-label="${t('ga.remove_defect')}">✕</button>
          </div>`).join('')}
        <button type="button" class="gr-add" data-act="def-add">+ ${t('ga.add_defect')}</button>
      </div>`).join('');
    edit = `
      <div class="ga-trow">
        <div class="field" style="flex:1"><label>${t('ga.template_name')}</label><input type="text" value="${esc(T.name)}" data-tf="name" maxlength="100"></div>
        <p class="ga-desc">${usedBy.length ? t('ga.used_by', {profiles: usedBy.join(', ')}) : t('ga.not_used')}</p>
      </div>
      <p class="ga-desc">${t('ga.kinds_desc')}</p>
      <div class="ga-cats">${cats}</div>
      <div class="ga-actions">
        <button type="button" class="btn-ghost" data-act="cat-add">+ ${t('ga.add_category')}</button>
        <span class="ga-msg ${total === 100 ? 'good' : 'warn'}" id="ga-tpl-total">${t('ga.cat_total', {n: total})}${total === 100 ? ' ✓' : ''}</span>
      </div>
      <div class="ga-actions">
        ${T.id ? `<button type="button" class="btn-danger" data-act="t-del">${t('ga.delete_template')}</button>` : ''}
        <button type="button" class="btn-ghost" data-act="recalc" title="${t('ga.recalc_title')}">${t('ga.recalc')}</button>
        <span class="ga-dirty">${dirty.template ? t('ga.unsaved') : ''}</span>
        <button type="button" class="btn btn-sm" data-act="t-save"${total === 100 ? '' : ' disabled'}>${t('ga.save_template')}</button>
      </div>`;
  }
  el.innerHTML = `<div class="ga-tabs">${tabs}</div>${edit}`;
}

function initTemplates() {
  const el = $('#ga-templates'); if (!el) return;
  const markDirty = () => { dirty.template = true; const d = el.querySelector('.ga-dirty'); if (d) d.textContent = tRaw('ga.unsaved'); };
  const updTotal = () => {
    const total = T.categories.reduce((a, c) => a + (parseInt(c.max_points, 10) || 0), 0), m = $('#ga-tpl-total');
    m.textContent = tRaw('ga.cat_total', {n: total}) + (total === 100 ? ' ✓' : ''); m.className = 'ga-msg ' + (total === 100 ? 'good' : 'warn');
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
    if (d.kind === 'level' && !d.level_group) d.level_group = (c.defects.find(x => x !== d && x.kind === 'level') || {}).level_group || tRaw('ga.level_default');
    markDirty(); renderTemplates();
  });
  el.addEventListener('click', async e => {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const act = b.dataset.act, catEl = b.closest('.ga-cat'), ci = catEl ? +catEl.dataset.ci : -1, defEl = b.closest('.ga-def');
    if (act === 'pick') {
      const id = b.dataset.id === 'new' ? 'new' : +b.dataset.id;
      if (id === selT) return;
      if (dirty.template && !confirm(tRaw('ga.confirm_discard_template'))) return;
      loadTemplate(id); return renderTemplates();
    }
    if (act === 'cat-add') { T.categories.push({ id: null, name: '', max_points: 0, defects: [] }); markDirty(); return renderTemplates(); }
    if (act === 'cat-rm') { if (!confirm(tRaw('ga.confirm_remove_category'))) return; T.categories.splice(ci, 1); markDirty(); return renderTemplates(); }
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
      toast(res.ok ? res.msg : err(res), res.ok);
      if (res.ok) applyConfig(res.config, { profile: selP, template: res.saved_id });
      return;
    }
    if (act === 't-del') {
      if (!confirm(tRaw('ga.confirm_delete_template', {name: T.name}))) return;
      const res = await apiConfirm('delete_template', { id: T.id });
      toast(res.ok ? res.msg : err(res), res.ok);
      if (res.ok) { selT = null; applyConfig(res.config, { profile: selP }); }
      return;
    }
    if (act === 'recalc') {
      const res = await api('recalc');
      toast(res.ok ? res.msg : err(res), res.ok);
    }
  });
}

// ══ EXPORT / IMPORT ═══════════════════════
function reportHtml(r) {
  const block = (title, items, cls = '') => items && items.length
    ? `<details class="ga-rep ${cls}"${cls ? ' open' : ''}><summary>${title} (${items.length})</summary><ul>${items.map(x => `<li>${esc(x)}</li>`).join('')}</ul></details>` : '';
  return block(t('ga.rep_problems'), r.errors, 'bad') + block(t('ga.rep_warnings'), r.warnings, 'warn')
    + block(t('ga.rep_added'), r.added) + block(t('ga.rep_updated'), r.updated) + block(t('ga.rep_removed'), r.removed, r.removed.length ? 'warn' : '')
    + block(t('ga.rep_kept'), r.kept) + block(t('ga.rep_systems'), r.systems);
}
function renderIO() {
  const el = $('#ga-io'); if (!el) return;
  const summary = importData
    ? `<b>${esc(importData._file)}</b>: ${t('ga.file_summary', {labels: (importData.labels || []).length, templates: (importData.templates || []).length, profiles: (importData.profiles || []).length})}`
    : t('ga.no_file');
  el.innerHTML = `
    <p class="ga-desc">${t('ga.io_desc')}</p>
    <div class="ga-actions" style="justify-content:flex-start">
      <a class="btn btn-sm" href="${esc(API)}?action=export" style="text-decoration:none">⬇ ${t('ga.export')}</a>
      <label class="btn-ghost" style="cursor:pointer">⬆ ${t('ga.choose_file')}<input type="file" accept=".json,application/json" id="ga-file" style="display:none"></label>
      <button type="button" class="btn-ghost" data-act="defaults">${t('ga.reset_defaults')}</button>
    </div>
    <div class="ga-box" style="${importData ? '' : 'display:none'}">
      <div>${summary}</div>
      <div class="ga-modes">
        <label><input type="radio" name="ga-mode" value="merge" checked> ${t('ga.mode_merge')}</label>
        <label class="ga-sub"><input type="checkbox" id="ga-delmissing"> ${t('ga.mode_delmissing')}</label>
        <label><input type="radio" name="ga-mode" value="replace"> ${t('ga.mode_replace')}</label>
      </div>
      <div class="ga-actions" style="justify-content:flex-start">
        <button type="button" class="btn-ghost" data-act="preview">${t('pc.preview')}</button>
        <button type="button" class="btn btn-sm" data-act="apply"${importReport ? '' : ' disabled'}>${t('ga.apply')}</button>
        <button type="button" class="btn-ghost" data-act="clear">${t('common.cancel')}</button>
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
      catch { toast(tRaw('ga.err_json'), false); return; }
      if (importData.format !== 'game-collection-grading') { toast(tRaw('ga.err_format'), false); importData = null; return; }
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
      if (o.mode === 'replace' && C.graded && prompt(tRaw('ga.prompt_replace', {n: C.graded})) !== 'REPLACE') return;
      if (o.mode === 'merge' && o.delete_missing && importReport && importReport.removed.length && !confirm(tRaw('ga.confirm_removed', {n: importReport.removed.length}))) return;
      const res = await api('import', { data, ...o, dry_run: false });
      toast(res.ok ? res.msg : err(res), res.ok);
      if (res.ok) { importData = null; importReport = null; applyConfig(res.config); }
      return;
    }
    if (act === 'defaults') {
      const pre = await api('reset_defaults', { dry_run: true });
      if (!pre.ok) return toast(err(pre), false);
      const msg = tRaw(C.graded ? 'ga.confirm_reset_graded' : 'ga.confirm_reset', {n: C.graded});
      if (!confirm(msg)) return;
      if (C.graded && prompt(tRaw('ga.prompt_reset')) !== 'RESET') return;
      const res = await api('reset_defaults', { dry_run: false });
      toast(res.ok ? tRaw('ga.reset_done') : err(res), res.ok);
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
  if (!res.ok) { $('#ga-labels').innerHTML = `<p class="ga-msg bad">${t('ga.err_load', {error: res.error})}</p>`; return; }
  applyConfig(res.config);
})();
})();
