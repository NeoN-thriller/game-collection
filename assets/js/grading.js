/* ═══════════════════════════════════════════
   CONDITION GRADING — shared by collection.php and wishlist.php
   Needs window.GRADING (gradingClientConfig() in grading.php).
   GradingCore must give exactly the same numbers as the PHP scoring
   in grading.php (gradeUnitScore / gradeOverallScore).
   ═══════════════════════════════════════════ */
(function () {
'use strict';

const G = window.GRADING || { labels: [], templates: {}, profiles: [], system_profiles: {}, own_weight: 5, max_qty: 9, mode: 'simple', default: 'simple' };
const QTY_LCM = 2520; // divisible by every qty 1–9

/**
 * A defect's (or level group's) name: with an explanation (assets/js/defect-info.js) the whole name is the
 * trigger for its hover card and popup; otherwise the plain name. (const globals aren't on window.)
 */
const infoName = (ids, name) => (typeof DefectInfo !== 'undefined' && DefectInfo.button(ids, tRaw('di.about', {name}), name)) || esc(name);

const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');

const profilesById = {}, componentsById = {}, labelsById = {};
G.profiles.forEach(p => { profilesById[p.id] = p; p.components.forEach(c => { componentsById[c.id] = c; }); });
G.labels.forEach(l => { labelsById[l.id] = l; });

// ── SCORING ──────────────────────────────
const Core = {
  labels: G.labels,
  labelFor(score) { for (const l of G.labels) if (score >= l.min_score) return l; return null; },
  label(id)      { return labelsById[id] || null; },
  template(id)   { return G.templates[id] || null; },
  profile(id)    { return profilesById[id] || null; },
  component(id)  { return componentsById[id] || null; },
  systemProfile(systemId) { return G.system_profiles[systemId] || null; },

  /** Points lost by one defect row (for display). */
  deduction(def, n) {
    n = parseInt(n || 0, 10);
    if (n <= 0) return 0;
    if (def.kind === 'once' || def.kind === 'level') return def.penalty;
    if (def.kind === 'max') return def.penalty * Math.min(n, Math.max(1, def.max_count || 0));
    return def.penalty * n;
  },

  /** Score of one category: {score, lost}. */
  catScore(cat, d) {
    let lost = 0; const levels = {};
    for (const def of cat.defects) {
      const n = parseInt(d[def.id] || 0, 10);
      if (n <= 0) continue;
      if (def.kind === 'level') { const g = def.level_group || ''; levels[g] = Math.max(levels[g] || 0, def.penalty); }
      else lost += Core.deduction(def, n);
    }
    for (const g in levels) lost += levels[g];
    return { score: Math.max(0, cat.max_points - lost), lost };
  },

  /** Score of one unit (0–100) from its defect counts {defect_id: count}. */
  unitScore(tpl, d) {
    let sumCat = 0, sumMax = 0;
    for (const cat of tpl.categories) {
      sumCat += Core.catScore(cat, d || {}).score;
      sumMax += cat.max_points;
    }
    if (sumMax <= 0) return 100;
    if (sumMax === 100) return sumCat;
    return Math.floor((2 * sumCat * 100 + sumMax) / (2 * sumMax));
  },

  /** Weighted average over [[weight, qty, score]]; each unit weighs weight/qty. */
  overall(units) {
    let num = 0, den = 0;
    for (const [w, qty, s] of units) {
      const f = w * Math.floor(QTY_LCM / Math.max(1, qty));
      num += f * s; den += f;
    }
    return den > 0 ? Math.floor((2 * num + den) / (2 * den)) : null;
  },

  partSpec(p) {
    if (p.pc) {
      const c = componentsById[p.pc], t = c && G.templates[c.template_id];
      return t ? { weight: c.weight, tpl: t, label: c.label, abbr: c.abbr || '', own: false } : null;
    }
    const t = G.templates[p.tpl];
    if (!t) return null;
    const w = parseInt(p.w ?? G.own_weight, 10);
    return { weight: Math.max(0, Math.min(100, isNaN(w) ? G.own_weight : w)), tpl: t, label: p.name || '', abbr: '', own: true };
  },

  /** {score, units:[{pi, ui, base, name, abbr, weight, share, qty, s, tpl, own}]} */
  score(parts) {
    const units = [], list = [];
    (parts || []).forEach((p, pi) => {
      const spec = Core.partSpec(p);
      if (!spec) return;
      const qty = parseInt(p.qty || 0, 10);
      for (let ui = 0; ui < qty; ui++) {
        const d = (p.units && p.units[ui] && p.units[ui].d) || {};
        const s = Core.unitScore(spec.tpl, d);
        units.push([spec.weight, qty, s]);
        list.push({ pi, ui, base: spec.label, name: qty > 1 ? `${spec.label} #${ui + 1}` : spec.label,
                    abbr: spec.abbr, weight: spec.weight / qty, qty, s, tpl: spec.tpl, own: spec.own, d });
      }
    });
    const tw = list.reduce((a, u) => a + u.weight, 0);
    list.forEach(u => { u.share = tw ? u.weight / tw * 100 : 0; });
    return { score: Core.overall(units), units: list };
  },

  /**
   * Stable keys of parts for photo tags, in the same order: 'c<component id>', or 'o:<own item name>'
   * ('~2', '~3' … for a repeated name). Mirror of photoPartRefs() in photo_tags.php.
   */
  partRefs(parts) {
    const seen = {};
    return (parts || []).map(p => {
      if (p.pc) return 'c' + p.pc;
      const base = 'o:' + String(p.name || '').trim().toLowerCase().slice(0, 150);
      seen[base] = (seen[base] || 0) + 1;
      return seen[base] > 1 ? base + '~' + seen[base] : base;
    });
  },

  /**
   * Point-graded units for photo tags: [{ref, unit_no, key ('pi.ui'), name, score, weight (share %),
   * defects:[{id, name, deduction, count}]}]. Used for the drawer's current grading and for saved copies.
   */
  tagUnits(parts) {
    if (!parts || !parts.length) return [];
    const refs = Core.partRefs(parts);
    return Core.score(parts).units.map(u => {
      const defects = [];
      u.tpl.categories.forEach(cat => cat.defects.forEach(def => {
        const n = parseInt(u.d[def.id] || 0, 10);
        if (n > 0) defects.push({ id: def.id, name: def.name, deduction: Core.deduction(def, n), count: n });
      }));
      return { ref: refs[u.pi], unit_no: u.ui + 1, key: u.pi + '.' + u.ui, name: u.name, score: u.s,
               weight: Math.round(u.share * 10) / 10, defects };
    });
  },

  /** How a copy shows: {label, score, method}. score is only set for point-graded copies. */
  effective(copy) {
    if (!copy) return { label: null, score: null, method: null };
    const score = copy.grade_score !== null && copy.grade_score !== undefined && copy.grade_score !== '' ? parseInt(copy.grade_score, 10) : null;
    if (copy.grade_method === 'points' && score !== null) return { label: Core.labelFor(score), score, method: 'points' };
    return { label: Core.label(copy.grade_label_id), score: null, method: copy.grade_label_id ? 'simple' : null };
  },

  /** Sort key: the score for point grades, else the label's start score; -1 when ungraded. */
  sortValue(copy) {
    const e = Core.effective(copy);
    return e.score !== null ? e.score : (e.label ? e.label.min_score + 0.5 : -1);
  },
};

// English completeness names for the auto-suggest (always tried after the current language's)
const SUGGEST_EN = {
  'grading.suggest_cib':       ['cib', 'complete in box', 'complete', 'boxed'],
  'grading.suggest_no_manual': ['no manual', 'boxed, no manual'],
  'grading.suggest_no_box':    ['no box', 'game + manual'],
  'grading.suggest_loose':     ['loose', 'disc / cart only', 'cart only', 'disc only', 'game only'],
};

// ── SMALL UI HELPERS ─────────────────────
function rgba(hex, a) {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
  if (!m) return `rgba(176,168,152,${a})`;
  const n = parseInt(m[1], 16);
  return `rgba(${n >> 16},${(n >> 8) & 255},${n & 255},${a})`;
}
function badge(label) {
  if (!label) return '<span class="qbadge q-na">—</span>';
  return `<span class="qbadge" style="color:${esc(label.color)};background:${rgba(label.color, .18)};border:1px solid ${rgba(label.color, .4)}">${esc(label.name)}</span>`;
}
function scoreColor(s) { const l = s === null || s === undefined ? null : Core.labelFor(s); return l ? l.color : 'var(--text2)'; }
function bar(pct, color, cls = '') {
  return `<span class="gr-bar ${cls}"><i style="width:${Math.max(0, Math.min(100, pct))}%;background:${esc(color)}"></i></span>`;
}
function unitAbbr(u) { return (u.abbr || u.base.split(/[\s/]+/)[0].slice(0, 5)) + (u.qty > 1 ? '#' + (u.ui + 1) : ''); }

// ── TABLE CELL + HOVER CARD ──────────────
const registry = [];

/** Condition cell for the owned copies of a game (or a single copy). */
function cellHtml(copies, opts = {}) {
  copies = (copies || []).filter(Boolean);
  if (!copies.length) return '<span class="price-na">—</span>';
  const single = copies.length === 1;
  return `<div class="gr-cells${single ? '' : ' multi'}">${copies.map(c => {
    const e = Core.effective(c);
    if (e.score !== null) {
      const i = registry.push(c) - 1;
      let mini = '';
      if (single) {
        const units = Core.score(c.grading || []).units.slice().sort((a, b) => b.weight - a.weight);
        const shown = units.slice(0, 3).map(u => `${esc(unitAbbr(u))} <span style="color:${esc(scoreColor(u.s))}">${u.s}</span>`);
        if (units.length > 3) shown.push('+' + (units.length - 3));
        mini = shown.length ? `<div class="gr-mini">${shown.join(' · ')}</div>` : '';
      }
      return `<div class="gr-cell" data-gr-i="${i}"><div class="gr-cell-top"><span class="gr-num" style="color:${esc(e.label ? e.label.color : 'var(--text2)')}">${e.score}</span>${badge(e.label)}</div>${mini}</div>`;
    }
    if (e.label) {
      const hint = single && opts.switchLink && G.mode !== 'simple' && c.game_id
        ? `<div class="gr-mini">${t('grading.simple_grade')} · <a href="#" class="gr-to-points" data-game="${esc(c.game_id)}" data-copy="${esc(c.copy_number)}">${t('grading.switch_to_points')}</a></div>` : '';
      return `<div class="gr-cell">${badge(e.label)}${hint}</div>`;
    }
    return '<div class="gr-cell"><span class="qbadge q-na">—</span></div>';
  }).join('')}</div>`;
}

function beginRender() { registry.length = 0; }

let card = null;
function hoverCardHtml(copy) {
  const e = Core.effective(copy);
  const r = Core.score(copy.grading || []);
  const rows = r.units.map(u => `
    <div class="gr-hc-row"><span>${esc(u.name)} <em>${Math.round(u.share)}%</em></span>${bar(u.s, scoreColor(u.s))}<b style="color:${esc(scoreColor(u.s))}">${u.s}</b></div>`).join('');
  // Biggest deductions, per unit and category
  const hits = [];
  r.units.forEach(u => u.tpl.categories.forEach(cat => {
    const cs = Core.catScore(cat, u.d);
    const lost = Math.min(cat.max_points, cs.lost);
    if (lost > 0) hits.push({ txt: `${u.name} ${cat.name.toLowerCase()}`, lost });
  }));
  hits.sort((a, b) => b.lost - a.lost);
  const missing = (copy.grading || []).filter(p => p.pc && !(p.qty > 0)).map(p => (Core.component(p.pc) || {}).label).filter(Boolean);
  return `
    <div class="gr-hc-head"><span>${t('grading.breakdown')}</span><span class="gr-hc-score" style="color:${esc(e.label ? e.label.color : 'var(--text2)')}">${e.score} / 100</span></div>
    <div class="gr-hc-rows">${rows || `<span class="gr-hc-foot">${t('grading.nothing_included')}</span>`}</div>
    ${hits.length || missing.length ? `<div class="gr-hc-foot">
      ${hits.length ? t('grading.biggest_hits') + ' ' + hits.slice(0, 3).map(h => `${esc(h.txt)} (−${h.lost})`).join(', ') : t('grading.no_defects')}
      ${missing.length ? `<br><span>${t('grading.not_included', {parts: missing.join(', ')})}</span>` : ''}</div>` : ''}`;
}

document.addEventListener('mouseover', e => {
  const el = e.target.closest && e.target.closest('[data-gr-i]');
  if (!el) { if (card) card.style.display = 'none'; return; }
  const copy = registry[+el.dataset.grI];
  if (!copy) return;
  if (!card) { card = document.createElement('div'); card.className = 'gr-hovercard'; document.body.appendChild(card); }
  card.innerHTML = hoverCardHtml(copy);
  card.style.display = 'block';
  const r = el.getBoundingClientRect();
  const w = card.offsetWidth, h = card.offsetHeight;
  let left = r.left, top = r.bottom + 6;
  if (left + w > window.innerWidth - 8) left = window.innerWidth - w - 8;
  if (top + h > window.innerHeight - 8) top = r.top - h - 6;
  card.style.left = Math.max(8, left) + 'px';
  card.style.top  = Math.max(8, top) + 'px';
});
document.addEventListener('click', e => {
  const a = e.target.closest && e.target.closest('.gr-to-points');
  if (!a) return;
  e.preventDefault();
  if (typeof window.gradingSwitchToPoints === 'function') window.gradingSwitchToPoints(+a.dataset.game, +a.dataset.copy);
});

// ── DRAWER EDITOR ────────────────────────
class GradingEditor {
  /**
   * opts.root        — container element (its content is replaced)
   * opts.compField   — the page's Completeness .field (moved into the editor's top row)
   * opts.compSelect  — the Completeness <select> (for auto-suggest)
   * opts.readOnly    — public wishlist view
   * opts.onChange    — optional; called after the grading body is drawn (the parts may have changed)
   * opts.defectExtra — optional (ref, unitNo, defectId) → HTML shown under a recorded defect (photo tags)
   */
  constructor(opts) {
    this.root = opts.root;
    this.readOnly = !!opts.readOnly;
    this.onChangeCb = opts.onChange || null;
    this.defectExtra = opts.defectExtra || null;
    this.compSelect = opts.compSelect || null;
    this.root.classList.add('gr-editor');
    this.root.innerHTML = `
      <div class="gr-head"><div class="section-label">${t('common.col.quality')}</div><div class="gr-switch" role="group" aria-label="${t('grading.method')}"></div></div>
      <div class="field-row gr-top"><div class="gr-left"></div><div class="gr-comp"></div></div>
      <div class="gr-body"></div>
      <div class="gr-foot"></div>`;
    if (opts.compField) this.root.querySelector('.gr-comp').appendChild(opts.compField);
    this.root.addEventListener('click',  e => this.onClick(e));
    this.root.addEventListener('change', e => this.onChange(e));
    this.root.addEventListener('input',  e => this.onInput(e));
    if (this.compSelect) this.compSelect.addEventListener('change', () => { this.compTouched = true; this.suggestMsg = ''; });
    this.load({}, {});
  }

  $(sel) { return this.root.querySelector(sel); }

  load(copy, ctx = {}) {
    this.copy = copy || {};
    this.systemId = ctx.systemId || null;
    const hasParts = Array.isArray(this.copy.grading) && this.copy.grading.length > 0;
    this.mode = G.mode;
    this.method = this.copy.grade_method || (hasParts ? 'points' : (this.copy.grade_label_id ? 'simple' : G.default));
    if (this.readOnly) this.view = Core.effective(this.copy).score !== null ? 'points' : 'simple';
    else this.view = this.mode === 'both' ? this.method : this.mode;
    this.labelId = this.copy.grade_label_id ? String(this.copy.grade_label_id) : '';
    this.profileId = this.copy.grade_profile_id || Core.systemProfile(this.systemId) || (G.profiles[0] ? G.profiles[0].id : null);
    this.parts = hasParts ? this.arrange(this.profileId, this.copy.grading, false).parts : null;
    this.touched = { method: false, label: false, parts: false };
    this.compTouched = false;
    this.adding = null;
    this.openKey = null;
    this.suggestMsg = '';
    // Simple-only users see a point-graded copy's derived label in the dropdown
    if (this.view === 'simple' && this.copy.grade_method === 'points') {
      const e = Core.effective(this.copy);
      if (e.label) this.labelId = String(e.label.id);
    }
    this.render();
  }

  /** Switches the open copy to Points (used by "switch to points" in the table). */
  showPoints() {
    if (this.readOnly || this.mode === 'simple') return;
    this.method = 'points'; this.view = 'points'; this.touched.method = true;
    this.render();
  }

  // ── parts ──
  fitUnits(p) {
    p.units = (p.units || []).slice(0, p.qty).map(u => ({ d: Object.assign({}, (u && u.d) || {}) }));
    while (p.units.length < p.qty) p.units.push({ d: {} });
  }

  /** Lays out parts for a profile, carrying over matching parts from `old`. */
  arrange(profileId, old, useDefaults) {
    old = old || [];
    const prof = Core.profile(profileId), used = new Set(), out = [];
    const take = pred => {
      const i = old.findIndex((p, j) => !used.has(j) && p.pc && pred(p, Core.component(p.pc)));
      if (i < 0) return null;
      used.add(i); return old[i];
    };
    (prof ? prof.components : []).forEach(c => {
      // Same part, or a part with the same name in the old profile (e.g. Manual → Manual)
      const src = take(p => p.pc === c.id)
               || take((p, oc) => oc && oc.label.toLowerCase() === c.label.toLowerCase());
      const oc = src ? Core.component(src.pc) : null;
      const part = { pc: c.id, qty: src ? (parseInt(src.qty, 10) || 0) : (useDefaults ? c.default_qty : 0),
                     units: src && oc && oc.template_id === c.template_id ? src.units : [] };
      this.fitUnits(part);
      out.push(part);
    });
    old.forEach(p => {
      if (p.pc) return;
      const part = { pc: null, name: p.name, tpl: p.tpl, w: p.w, qty: parseInt(p.qty, 10) || 0, units: p.units };
      this.fitUnits(part);
      out.push(part);
    });
    const dropped = old.filter((p, j) => p.pc && !used.has(j));
    return { parts: out, dropped };
  }

  hasDefects(units) { return (units || []).some(u => u && u.d && Object.values(u.d).some(n => n > 0)); }

  startPoints() {
    this.parts = this.arrange(this.profileId, [], true).parts;
    this.touched.parts = true;
    this.suggestCompleteness();
    this.render();
  }

  suggestCompleteness() {
    if (!this.compSelect || this.compTouched || !this.parts) return;
    const has = re => this.parts.some(p => { if (!(p.qty > 0)) return false; const s = Core.partSpec(p); return s && re.test(s.tpl.name); });
    const media = has(/cart|disc|umd|console/i), box = has(/box|case/i), manual = has(/manual/i);
    // Candidate option names per situation: the language's own list, then the English one
    const names = key => tRaw(key).toLowerCase().split('|').concat(key in SUGGEST_EN ? SUGGEST_EN[key] : []).map(s => s.trim()).filter(Boolean);
    let cands = null;
    if (media && box && manual) cands = names('grading.suggest_cib');
    else if (media && box)      cands = names('grading.suggest_no_manual');
    else if (media && manual)   cands = names('grading.suggest_no_box');
    else if (media)             cands = names('grading.suggest_loose');
    if (!cands) return;
    const opts = [...this.compSelect.options].filter(o => o.value);
    for (const c of cands) {
      const o = opts.find(x => x.value.trim().toLowerCase() === c);
      if (!o) continue;
      if (this.compSelect.value !== o.value) { this.compSelect.value = o.value; this.suggestMsg = tRaw('grading.suggest_msg', {value: o.value}); }
      return;
    }
  }

  /**
   * The drawer's current (possibly unsaved) point-graded units, for photo tags:
   * [{ref, unit_no, name, score, defects:[{id, name, deduction, count}]}]. Empty for simple grading.
   */
  units() {
    if (this.view !== 'points' || !this.parts) return [];
    return Core.tagUnits(this.parts);
  }

  /** Opens one unit's defects (key 'pi.ui') in the grading section and scrolls to it. */
  openUnit(key) {
    this.openKey = key;
    this.renderBody();
    const el = this.root.querySelector('.gr-unit.open');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // ── payload for entry_save.php ──
  getPayload() {
    if (this.readOnly) return undefined;
    const out = {};
    const isNew = !this.copy.grade_method;
    if (this.mode === 'both') out.method = this.method;
    else if (this.mode === 'simple' && (this.touched.label || isNew)) out.method = 'simple';
    else if (this.mode === 'points' && (this.parts || isNew)) out.method = 'points';
    if (this.touched.label) out.label_id = this.labelId ? +this.labelId : null;
    if (this.parts && this.touched.parts) {
      out.profile_id = this.profileId;
      out.parts = this.parts.map(p => ({ pc: p.pc, name: p.name, tpl: p.tpl, w: p.w, qty: p.qty, units: p.units.map(u => ({ d: u.d })) }));
    }
    return Object.keys(out).length ? out : undefined;
  }

  // ── rendering ──
  render() {
    this.renderSwitch();
    this.renderLeft();
    this.renderBody();
    const foot = this.$('.gr-foot');
    foot.innerHTML = !this.readOnly && this.mode === 'both'
      ? `${t('grading.your_default')} <b>${t(G.default === 'points' ? 'grading.points' : 'grading.simple')}</b> · <a href="${esc(G.base || '')}/settings.php">${t('grading.change_in_settings')}</a>` : '';
  }

  renderSwitch() {
    const sw = this.$('.gr-switch');
    if (this.readOnly || this.mode !== 'both') { sw.innerHTML = ''; return; }
    sw.innerHTML = ['simple', 'points'].map(m =>
      `<button type="button" data-act="method" data-m="${m}" aria-pressed="${this.view === m}" class="${this.view === m ? 'on' : ''}">${t(m === 'simple' ? 'grading.simple' : 'grading.points')}</button>`).join('');
  }

  renderLeft() {
    const left = this.$('.gr-left');
    const dis = this.readOnly ? ' disabled' : '';
    if (this.view === 'simple') {
      left.innerHTML = `<div class="field"><label>${t('grading.quality')}</label><select data-gr="label"${dis}>
        <option value="">— ${t('grading.na')} —</option>
        ${G.labels.map(l => `<option value="${l.id}"${String(l.id) === this.labelId ? ' selected' : ''}>${esc(l.name)}</option>`).join('')}
      </select></div>`;
    } else {
      left.innerHTML = `<div class="field"><label>${t('grading.profile')}</label><select data-gr="profile"${dis}>
        ${G.profiles.map(p => `<option value="${p.id}"${p.id == this.profileId ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}
        ${G.profiles.length ? '' : `<option value="">${t('grading.no_profiles')}</option>`}
      </select></div>`;
    }
  }

  renderBody() {
    const body = this.$('.gr-body');
    if (this.view === 'simple') body.innerHTML = this.simpleNote();
    else if (!this.parts) body.innerHTML = this.startPanel();
    else {
      this.refs = Core.partRefs(this.parts);
      const r = Core.score(this.parts);
      body.innerHTML = this.scoreCard(r) + this.includedHtml() + this.unitsHtml(r);
    }
    if (this.onChangeCb) this.onChangeCb();
  }

  /** Extra HTML under a recorded defect (photo tags), or ''. */
  extraFor(u, defId) {
    return this.defectExtra && !this.readOnly ? (this.defectExtra(this.refs[u.pi], u.ui + 1, defId) || '') : '';
  }

  simpleNote() {
    const e = Core.effective(Object.assign({}, this.copy, { grade_method: 'points' }));
    if (this.copy.grade_score === null || this.copy.grade_score === undefined || !this.copy.grading) return '';
    if (this.readOnly) return '';
    if (this.mode === 'simple' && this.copy.grade_method === 'points') {
      return `<div class="gr-note">${t('grading.note_was_points', {score: e.score}).replace('{badge}', badge(e.label))}</div>`;
    }
    return `<div class="gr-note">${t(this.mode === 'both' ? 'grading.note_kept_both' : 'grading.note_kept', {score: e.score}).replace('{badge}', badge(e.label))}</div>`;
  }

  startPanel() {
    if (this.readOnly) return `<div class="gr-note">${t('grading.not_points')}</div>`;
    const cur = Core.label(this.copy.grade_label_id);
    return `<div class="gr-start">
      <p>${t('grading.start_intro')}</p>
      ${cur ? `<p class="gr-muted">${t('grading.current_simple').replace('{badge}', badge(cur))}</p>` : ''}
      <button type="button" class="btn btn-sm" data-act="start"${G.profiles.length ? '' : ' disabled'}>${t('grading.start')}</button>
    </div>`;
  }

  scoreCard(r) {
    const label = r.score !== null ? Core.labelFor(r.score) : null;
    return `<div class="gr-card">
      <div class="gr-card-main">
        <span class="gr-big" style="color:${esc(label ? label.color : 'var(--muted)')}">${r.score !== null ? r.score : '—'}</span><span class="gr-of">/100</span>
        ${r.score !== null ? badge(label) : `<span class="gr-muted">${t('grading.nothing_yet')}</span>`}
      </div>
      <div class="gr-card-bars">${r.units.map(u => `
        <div class="gr-card-row"><span title="${esc(u.name)}">${esc(u.name)}</span>${bar(u.s, scoreColor(u.s))}<b style="color:${esc(scoreColor(u.s))}">${u.s}</b></div>`).join('')}
      </div>
      ${this.suggestMsg ? `<div class="gr-suggest">${esc(this.suggestMsg)}</div>` : ''}
    </div>`;
  }

  includedHtml() {
    const dis = this.readOnly ? ' disabled' : '';
    const rows = this.parts.map((p, pi) => {
      const s = Core.partSpec(p);
      if (!s) return '';
      if (this.readOnly && !(p.qty > 0)) return '';
      return `<div class="gr-part ${p.qty > 0 ? 'on' : 'off'}">
        <span class="gr-part-name" title="${esc(s.label)}">${esc(s.label)}${s.own ? ` <em class="gr-own">${t('grading.own')}</em>` : ''}</span>
        <span class="gr-step">
          <button type="button" data-act="qty" data-pi="${pi}" data-d="-1" aria-label="${t('grading.fewer', {name: s.label})}"${dis}>−</button>
          <b>${p.qty}</b>
          <button type="button" data-act="qty" data-pi="${pi}" data-d="1" aria-label="${t('grading.more', {name: s.label})}"${dis}>+</button>
        </span>
        ${s.own && !this.readOnly ? `<button type="button" class="gr-x" data-act="rm-own" data-pi="${pi}" aria-label="${t('grading.remove', {name: s.label})}">✕</button>` : ''}
      </div>`;
    }).join('');
    let add = '';
    if (!this.readOnly) {
      add = this.adding ? `
        <div class="gr-addform">
          <div class="field"><label>${t('grading.name')}</label><input type="text" data-gr="add-name" maxlength="100" value="${esc(this.adding.name)}" placeholder="${t('grading.add_name_ph')}"></div>
          <div class="field-row">
            <div class="field"><label>${t('grading.grade_as')}</label><select data-gr="add-tpl">${Object.values(G.templates).map(t =>
              `<option value="${t.id}"${t.id == this.adding.tpl ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select></div>
            <div class="field"><label>${t('grading.weight')}</label><input type="number" data-gr="add-w" min="1" max="100" value="${esc(this.adding.w)}"></div>
          </div>
          <div class="gr-addform-btns"><button type="button" class="btn-ghost" data-act="add-cancel">${t('common.cancel')}</button><button type="button" class="btn btn-sm" data-act="add-ok">${t('common.add')}</button></div>
        </div>`
        : `<button type="button" class="gr-add" data-act="add-open">+ ${t('grading.add_other')}</button>`;
    }
    return `<div class="gr-sub">${t('grading.included')} <span>${t('grading.included_hint')}</span></div>
      <div class="gr-parts">${rows}</div>${add}`;
  }

  unitsHtml(r) {
    if (!r.units.length) return '';
    const rows = r.units.map(u => {
      const key = u.pi + '.' + u.ui, open = this.openKey === key;
      return `<div class="gr-unit${open ? ' open' : ''}">
        <button type="button" class="gr-unit-head" data-act="toggle" data-key="${key}" aria-expanded="${open}">
          <span class="gr-unit-name">${esc(u.name)}${u.own ? ` <em class="gr-own">${t('grading.own')}</em>` : ''}</span>
          <span class="gr-unit-w">${Math.round(u.share)}%</span>
          ${bar(u.s, scoreColor(u.s))}
          <b style="color:${esc(scoreColor(u.s))}">${u.s}</b>
          <span class="gr-chev">${open ? '▾' : '▸'}</span>
        </button>
        ${open ? this.unitBody(u) : ''}
      </div>`;
    }).join('');
    return `<div class="gr-sub">${t('grading.grading')} <span>${t('grading.grading_hint')}</span></div>
      <div class="gr-units">${rows}</div>
      ${this.readOnly ? '' : `<div class="gr-tools"><button type="button" class="btn-ghost" data-act="clear">${t('grading.clear')}</button></div>`}`;
  }

  unitBody(u) {
    const key = u.pi + '.' + u.ui, d = this.parts[u.pi].units[u.ui].d;
    const dis = this.readOnly ? ' disabled' : '';
    return `<div class="gr-unit-body">${u.tpl.categories.map((cat, ci) => {
      const cs = Core.catScore(cat, d);
      const pct = cat.max_points ? Math.round(cs.score / cat.max_points * 100) : 100;
      const groups = [];
      cat.defects.forEach(def => {
        if (def.kind !== 'level') return;
        let g = groups.find(x => x.name === (def.level_group || ''));
        if (!g) groups.push(g = { name: def.level_group || '', defs: [] });
        g.defs.push(def);
      });
      const levelRows = groups.map((g, gi) => {
        const picked = g.defs.find(x => d[x.id] > 0);
        const opt = (def, text) => {
          const on = def ? picked && picked.id === def.id : !picked;
          return `<button type="button" data-act="level" data-key="${key}" data-ci="${ci}" data-gi="${gi}" data-def="${def ? def.id : 0}" aria-pressed="${!!on}" class="${on ? 'on' : ''}"${dis}>${text}</button>`;
        };
        return `<div class="gr-level"><span class="gr-def-name">${infoName(g.defs.map(x => x.id), g.name)}</span>
          <div class="gr-seg" role="group" aria-label="${esc(g.name)}">${opt(null, t('grading.none'))}${g.defs.map(def => opt(def, `${esc(def.name)} −${def.penalty}`)).join('')}</div></div>`
          + (picked ? this.extraFor(u, picked.id) : '');
      }).join('');
      const defRows = cat.defects.filter(def => def.kind !== 'level').map(def => {
        const n = parseInt(d[def.id] || 0, 10), ded = Core.deduction(def, n);
        const pen = def.kind === 'once' ? t('grading.pen_once', {n: def.penalty}) : def.kind === 'max' ? t('grading.pen_max', {n: def.penalty, max: def.max_count}) : t('grading.pen_each', {n: def.penalty});
        const cap = def.kind === 'once' ? 1 : def.kind === 'max' ? (def.max_count || 1) : 99;
        return `<div class="gr-def${n > 0 ? ' hit' : ''}">
          <span class="gr-def-name">${infoName(def.id, def.name)} <small>${pen}</small></span>
          <span class="gr-ded">${ded ? '−' + ded : ''}</span>
          <span class="gr-step">
            <button type="button" data-act="def" data-key="${key}" data-def="${def.id}" data-d="-1" aria-label="${t('grading.fewer', {name: def.name})}"${n <= 0 ? ' disabled' : dis}>−</button>
            <b>${n}</b>
            <button type="button" data-act="def" data-key="${key}" data-def="${def.id}" data-d="1" aria-label="${t('grading.more', {name: def.name})}"${n >= cap ? ' disabled' : dis}>+</button>
          </span>
        </div>` + (n > 0 ? this.extraFor(u, def.id) : '');
      }).join('');
      return `<div class="gr-cat">
        <div class="gr-cat-head"><span>${esc(cat.name)}</span><b style="color:${esc(scoreColor(pct))}">${cs.score}<small>/${cat.max_points}</small></b></div>
        ${bar(pct, scoreColor(pct), 'thin')}
        ${levelRows}${defRows}
      </div>`;
    }).join('')}</div>`;
  }

  // ── events ──
  unitAt(key) { const [pi, ui] = key.split('.').map(Number); return this.parts[pi] && this.parts[pi].units[ui]; }

  onClick(e) {
    const b = e.target.closest('[data-act]');
    if (!b || !this.root.contains(b) || this.readOnly) return;
    const act = b.dataset.act;
    if (act === 'method') {
      this.method = this.view = b.dataset.m;
      this.touched.method = true;
      return this.render();
    }
    if (act === 'start') return this.startPoints();
    if (act === 'toggle') { this.openKey = this.openKey === b.dataset.key ? null : b.dataset.key; return this.renderBody(); }
    if (act === 'qty') {
      const p = this.parts[+b.dataset.pi], next = Math.max(0, Math.min(G.max_qty, p.qty + (+b.dataset.d)));
      if (next < p.qty && this.hasDefects(p.units.slice(next))) {
        const s = Core.partSpec(p);
        if (!confirm(tRaw('grading.confirm_remove_unit', {name: s ? s.label : tRaw('grading.this_part'), n: p.qty}))) return;
      }
      p.qty = next; this.fitUnits(p);
      this.touched.parts = true;
      this.suggestCompleteness();
      return this.renderBody();
    }
    if (act === 'rm-own') {
      const p = this.parts[+b.dataset.pi];
      if (this.hasDefects(p.units) && !confirm(tRaw('grading.confirm_remove_own', {name: p.name}))) return;
      this.parts.splice(+b.dataset.pi, 1);
      this.openKey = null; this.touched.parts = true;
      this.suggestCompleteness();
      return this.renderBody();
    }
    if (act === 'add-open') {
      const first = Object.values(G.templates).find(t => /paper/i.test(t.name)) || Object.values(G.templates)[0];
      this.adding = { name: '', tpl: first ? first.id : '', w: G.own_weight };
      this.renderBody();
      const inp = this.$('[data-gr="add-name"]'); if (inp) inp.focus();
      return;
    }
    if (act === 'add-cancel') { this.adding = null; return this.renderBody(); }
    if (act === 'add-ok') {
      const a = this.adding, name = (a.name || '').trim();
      if (!name) { const inp = this.$('[data-gr="add-name"]'); if (inp) inp.focus(); return; }
      if (!G.templates[a.tpl]) return;
      const w = Math.max(1, Math.min(100, parseInt(a.w, 10) || G.own_weight));
      const part = { pc: null, name, tpl: +a.tpl, w, qty: 1, units: [] };
      this.fitUnits(part);
      this.parts.push(part);
      this.adding = null; this.touched.parts = true;
      this.openKey = (this.parts.length - 1) + '.0';
      return this.renderBody();
    }
    if (act === 'def') {
      const u = this.unitAt(b.dataset.key); if (!u) return;
      const id = b.dataset.def, n = Math.max(0, (parseInt(u.d[id] || 0, 10)) + (+b.dataset.d));
      if (n) u.d[id] = n; else delete u.d[id];
      this.touched.parts = true;
      return this.renderBody();
    }
    if (act === 'level') {
      const u = this.unitAt(b.dataset.key); if (!u) return;
      const [pi] = b.dataset.key.split('.').map(Number);
      const tpl = Core.partSpec(this.parts[pi]).tpl, cat = tpl.categories[+b.dataset.ci];
      const groupNames = [];
      cat.defects.forEach(x => { if (x.kind === 'level' && !groupNames.includes(x.level_group || '')) groupNames.push(x.level_group || ''); });
      const g = groupNames[+b.dataset.gi];
      cat.defects.forEach(x => { if (x.kind === 'level' && (x.level_group || '') === g) delete u.d[x.id]; });
      if (+b.dataset.def) u.d[b.dataset.def] = 1;
      this.touched.parts = true;
      return this.renderBody();
    }
    if (act === 'clear') {
      if (!this.parts.some(p => this.hasDefects(p.units))) return;
      if (!confirm(tRaw('grading.confirm_clear'))) return;
      this.parts.forEach(p => p.units.forEach(u => { u.d = {}; }));
      this.touched.parts = true;
      return this.renderBody();
    }
  }

  onChange(e) {
    const t = e.target, k = t.dataset && t.dataset.gr;
    if (!k || this.readOnly) return;
    if (k === 'label') { this.labelId = t.value; this.touched.label = true; return; }
    if (k === 'profile') {
      const pid = +t.value;
      if (this.parts) {
        const res = this.arrange(pid, this.parts, true);
        const lost = res.dropped.filter(p => this.hasDefects(p.units)).map(p => (Core.component(p.pc) || {}).label).filter(Boolean);
        if (lost.length && !confirm(tRaw('grading.confirm_profile', {parts: lost.join(', ')}))) {
          t.value = this.profileId; return;
        }
        this.parts = res.parts;
        this.touched.parts = true;
        this.openKey = null;
      }
      this.profileId = pid;
      this.suggestCompleteness();
      return this.renderBody();
    }
    if (k === 'add-tpl' && this.adding) this.adding.tpl = t.value;
  }

  onInput(e) {
    const t = e.target, k = t.dataset && t.dataset.gr;
    if (!this.adding) return;
    if (k === 'add-name') this.adding.name = t.value;
    if (k === 'add-w') this.adding.w = t.value;
  }
}

window.GradingCore = Core;
window.GradingUI = { esc, rgba, badge, bar, scoreColor, cellHtml, beginRender, Editor: GradingEditor, config: G };
})();
