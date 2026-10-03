/* ═══════════════════════════════════════════
   PHOTO TAGS in the edit drawer (collection.php only) — which part unit, and which of its
   recorded defects, each photo of a point-graded copy shows. See photo_tags.php.

   Two ways in, one set of tags (nothing is written until the drawer's Save):
     · from the photo: the tag button under a tile opens the tag editor (#d-pt-editor)
     · from the defect: under each recorded defect in the grading section, its photos and a picker
       (rendered through the grading editor's defectExtra option)
   A photo belongs to one part unit (or is a general / overview photo) and can show several of its defects.

   PhotoTags.init({editor, src})   editor: the GradingUI.Editor · src: file → image URL
   PhotoTags.load(copy)            copy: {id, photo_items:[{id, file}], photo_tags:{photo_id: tag}}
   PhotoTags.setItems(items)       after an upload or delete
   PhotoTags.tileHtml(i)           placeholder under photo tile i (filled by refresh())
   PhotoTags.refresh()             redraws everything except the grading editor
   PhotoTags.defectExtra(ref, unitNo, defectId)
   PhotoTags.payload()             {photo_tags: {photo_id: tag | null}} for changed photos, or {}
   PhotoTags.viewerInfo(pid)       {part, defects, jump} for the lightbox, from the drawer's current tags and grading
   PhotoTags.savedViewerInfo(copy) photo id → {part, defects} for a saved copy (photos opened from the table)
   ═══════════════════════════════════════════ */
const PhotoTags = (() => {
  let editor = null, srcFor = f => f;
  let st = { entryId: null, items: [], tags: {}, dirty: new Set(), sel: null, picker: null, fresh: new Set() };
  const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const el = id => document.getElementById(id);
  const color = s => (window.GradingUI ? GradingUI.scoreColor(s) : 'var(--text)');

  // ── state helpers ──
  const units  = () => (editor ? editor.units() : []);
  const active = () => !!st.entryId && st.items.length > 0 && units().length > 0;
  const unitOf = (list, ref, n) => list.find(u => u.ref === ref && u.unit_no === n) || null;
  const photoNo = pid => st.items.findIndex(i => String(i.id) === String(pid)) + 1;

  /** How a photo's tag shows against the current grading: null (none, or no longer valid), {overview}, or {unit, defects}. */
  function view(pid, list = units()) {
    const t = st.tags[pid];
    if (!t) return null;
    if (t.part_ref === '') return { overview: true, unit: null, defects: [] };
    const u = unitOf(list, t.part_ref, +t.unit_no);
    if (!u) return null;
    const hit = new Set(u.defects.map(d => d.id));
    return { overview: false, unit: u, defects: (t.defects || []).map(Number).filter(d => hit.has(d)) };
  }
  const sameUnit = (v, ref, n) => v && v.unit && v.unit.ref === ref && v.unit.unit_no === n;

  function setTag(pid, tag) {
    pid = String(pid);
    if (tag) st.tags[pid] = tag; else delete st.tags[pid];
    st.dirty.add(pid);
  }

  /** After a change: redraw the grading body (its defect photo lines); that calls refresh() for the rest. */
  function changed() {
    if (editor) editor.renderBody(); else refresh();
  }

  // ── public ──
  function init(opts) {
    editor = opts.editor || null;
    if (opts.src) srcFor = opts.src;
  }

  function load(copy) {
    copy = copy || {};
    const tags = {};
    Object.entries(copy.photo_tags || {}).forEach(([pid, t]) => {
      tags[pid] = { part_ref: t.part_ref || '', unit_no: +t.unit_no || 1, defects: (t.defects || []).map(Number) };
    });
    st = { entryId: copy.id || null, items: (copy.photo_items || []).slice(), tags, dirty: new Set(), sel: null, picker: null, fresh: new Set() };
    refresh();
  }

  function setItems(items) {
    st.items = (items || []).slice();
    const ids = new Set(st.items.map(i => String(i.id)));
    Object.keys(st.tags).forEach(pid => { if (!ids.has(pid)) delete st.tags[pid]; });
    st.dirty.forEach(pid => { if (!ids.has(pid)) st.dirty.delete(pid); });
    if (st.sel && !ids.has(st.sel)) st.sel = null;
    changed();
  }

  function payload() {
    if (!st.entryId || !st.dirty.size) return {};
    const out = {};
    st.dirty.forEach(pid => { out[pid] = st.tags[pid] || null; });
    return { photo_tags: out };
  }

  function tileHtml(i) {
    const it = st.items[i];
    return it ? `<div class="pt-tile" data-pt-tile="${it.id}"></div>` : '';
  }

  // ── lightbox ──
  /** {part:{name, score, weight, color}, defects:[{name, count, deduction}]} of a tag, an overview marker, or null. */
  function describe(list, tag) {
    if (!tag) return null;
    if (tag.part_ref === '') return { overview: true };
    const u = unitOf(list, tag.part_ref, +tag.unit_no);
    if (!u) return null;
    const ids = (tag.defects || []).map(Number);
    return { unit: u, part: { name: u.name, score: u.score, weight: u.weight, color: color(u.score) },
             defects: u.defects.filter(d => ids.includes(d.id)).map(d => ({ name: d.name, count: d.count, deduction: d.deduction })) };
  }

  function viewerInfo(pid) {
    if (!active()) return null;
    const info = describe(units(), st.tags[String(pid)]);
    if (!info || info.overview) return info;
    const key = info.unit.key;
    return { part: info.part, defects: info.defects, jumpLabel: tRaw('pt.see_in_grading', { part: info.part.name }),
             jump: () => { if (editor) editor.openUnit(key); } };
  }

  function savedViewerInfo(copy) {
    const out = {};
    if (!copy || copy.grade_method !== 'points' || !window.GradingCore) return out;
    const list = GradingCore.tagUnits(copy.grading || []);
    Object.entries(copy.photo_tags || {}).forEach(([pid, tag]) => {
      const info = describe(list, tag);
      if (info) out[pid] = info.overview ? info : { part: info.part, defects: info.defects };
    });
    return out;
  }

  // ── drawing ──
  function refresh() {
    const on = active(), list = on ? units() : [];
    const sum = el('d-pt-summary');
    document.querySelectorAll('[data-pt-tile]').forEach(box => {
      const item = box.closest('.img-preview-item');
      if (!on) { box.innerHTML = ''; item?.classList.remove('pt-untagged', 'pt-selected'); return; }
      const pid = box.dataset.ptTile, v = view(pid, list), n = photoNo(pid);
      const label = !v ? t('pt.no_tag') : v.overview ? t('pt.overview')
                  : e(v.unit.name) + (v.defects.length ? ' · ' + v.defects.length : '');
      box.innerHTML = `<button type="button" class="pt-bar${v ? '' : ' none'}" data-pt="select" data-pid="${pid}"
        aria-pressed="${st.sel === pid}" aria-label="${e(tRaw('pt.tag_photo', { n }))}"><span aria-hidden="true">🏷</span> <span>${label}</span></button>`;
      item?.classList.toggle('pt-untagged', !v);
      item?.classList.toggle('pt-selected', st.sel === pid);
    });
    if (sum) {
      sum.hidden = !on;
      if (on) sum.textContent = tRaw('pt.photos_summary', { n: fmtNum(st.items.length), m: fmtNum(st.items.filter(i => view(String(i.id), list)).length) });
    }
    renderEditor(on, list);
    renderMissing(on, list);
  }

  function renderEditor(on, list) {
    const box = el('d-pt-editor');
    if (!box) return;
    if (!on || !st.sel || !photoNo(st.sel)) { box.hidden = true; box.innerHTML = ''; return; }
    const pid = st.sel, n = photoNo(pid), item = st.items[n - 1], v = view(pid, list);
    const tag = st.tags[pid];
    const chip = (attrs, text, pressed) => `<button type="button" class="pt-chip${pressed ? ' on' : ''}" aria-pressed="${pressed}" ${attrs}>${text}</button>`;
    const chips = list.map(u => chip(`data-pt="part" data-ref="${e(u.ref)}" data-unit="${u.unit_no}"`,
      `${e(u.name)} <b style="color:${e(color(u.score))}">${u.score}</b>`, sameUnit(v, u.ref, u.unit_no))).join('')
      + chip('data-pt="overview"', t('pt.general_overview'), !!(v && v.overview));
    let step2 = '';
    if (v && v.overview) step2 = `<p class="d-hint">${t('pt.overview_note')}</p>`;
    else if (v && v.unit) {
      const defs = v.unit.defects;
      step2 = `<div class="pt-step">${t('pt.step_defects')}</div>` + (defs.length
        ? `<p class="d-hint">${t('pt.defects_optional')}</p><div class="pt-defs">${defs.map(d => {
            const on = v.defects.includes(d.id);
            return `<button type="button" class="pt-def${on ? ' on' : ''}" data-pt="def" data-def="${d.id}" aria-pressed="${on}">
              <span class="pt-box" aria-hidden="true">${on ? '✓' : ''}</span><span class="pt-def-name">${e(d.name)}${d.count > 1 ? ' ×' + d.count : ''}</span><span class="pt-ded">−${d.deduction}</span></button>`;
          }).join('')}</div>`
        : `<p class="d-hint">${t('pt.no_defects_part')}</p>`)
        + `<p class="d-hint">${t('pt.defects_from_grading')}</p>`;
    }
    box.hidden = false;
    box.innerHTML = `
      <div class="pt-card-head">
        <img src="${e(srcFor(item.file))}" alt="" class="pt-card-img">
        <strong>${t('pt.editor_title')} · ${t('pt.photo_n', { n })}</strong>
        ${tag ? `<button type="button" class="btn-ghost btn-sm" data-pt="remove">${t('pt.remove_tag')}</button>` : ''}
        <button type="button" class="btn-icon pt-close" data-pt="close" aria-label="${t('common.close')}">✕</button>
      </div>
      <div class="pt-step">${t('pt.step_part')}</div>
      <div class="pt-chips">${chips}</div>
      ${step2}`;
  }

  function renderMissing(on, list) {
    const box = el('d-pt-missing');
    if (!box) return;
    const hits = on ? list.flatMap(u => u.defects.map(d => ({ u, d }))) : [];
    if (!hits.length) { box.hidden = true; box.innerHTML = ''; return; }
    const covered = (u, d) => st.items.some(i => { const v = view(String(i.id), list); return sameUnit(v, u.ref, u.unit_no) && v.defects.includes(d.id); });
    const missing = hits.filter(h => !covered(h.u, h.d));
    box.hidden = false;
    box.innerHTML = `<div class="pt-step">${t('pt.missing_title')}</div>`
      + (missing.length
        ? `<div class="pt-missing">${missing.map(h => `<span class="chip pt-miss">${e(h.d.name)} · ${e(h.u.name)}</span>`).join('')}</div>
           <p class="d-hint">${t('pt.missing_hint')}</p>`
        : `<p class="d-hint">${t('pt.none_missing')}</p>`);
  }

  /** Under a recorded defect in the grading section: its photos, and the picker when open. */
  function defectExtra(ref, unitNo, defId) {
    if (!active()) return '';
    const list = units(), key = `${ref}#${unitNo}#${defId}`, open = st.picker === key;
    const unit = unitOf(list, ref, unitNo);
    const views = st.items.map(i => ({ i, pid: String(i.id), v: view(String(i.id), list) }));
    const linked = views.filter(x => sameUnit(x.v, ref, unitNo) && x.v.defects.includes(defId));
    const btn = open ? t('pt.done') : linked.length ? t('pt.edit_photos') : t('pt.add_photo');
    let html = `<div class="pt-defline">
      ${linked.map(x => `<img src="${e(srcFor(x.i.file))}" alt="${e(tRaw('pt.photo_n', { n: photoNo(x.pid) }))}" class="pt-mini">`).join('')}
      ${linked.length ? '' : `<span class="d-hint">${t('pt.no_photo_yet')}</span>`}
      <button type="button" class="btn-ghost btn-sm pt-pick-btn" data-pt="picker" data-key="${e(key)}" aria-expanded="${open}">${btn}</button>
    </div>`;
    if (!open) return html;
    const tiles = views.map(x => {
      const other = !!x.v && !sameUnit(x.v, ref, unitNo);
      const on = linked.includes(x);
      const n = photoNo(x.pid);
      const label = other ? (x.v.overview ? t('pt.overview') : e(x.v.unit.name))
                  : st.fresh.has(x.pid) ? t('pt.now_part', { part: unit ? unit.name : '' })
                  : x.v ? e(unit ? unit.name : '') : t('pt.no_tag_short');
      const title = other ? ` title="${e(tRaw('pt.other_part_locked', { part: x.v.overview ? tRaw('pt.overview') : x.v.unit.name }))}"` : '';
      return `<button type="button" class="pt-pick${on ? ' on' : ''}" data-pt="pick" data-pid="${x.pid}" data-ref="${e(ref)}" data-unit="${unitNo}" data-def="${defId}"
        aria-pressed="${on}"${other ? ' disabled' : ''}${title} aria-label="${e(tRaw('pt.photo_n', { n }))}">
        <span class="pt-pick-img"><img src="${e(srcFor(x.i.file))}" alt=""><span class="pt-pick-no">${n}</span>${on ? '<span class="pt-check" aria-hidden="true">✓</span>' : ''}</span>
        <span class="pt-pick-label">${label}</span></button>`;
    }).join('');
    const fresh = [...st.fresh].filter(pid => photoNo(pid)).map(pid =>
      `<p class="d-hint">${t('pt.picker_new_tag', { n: photoNo(pid), part: unit ? unit.name : '' })}</p>`).join('');
    return html + `<div class="pt-picker"><p class="d-hint">${t('pt.picker_hint')}</p><div class="pt-pick-grid">${tiles}</div>${fresh}</div>`;
  }

  // ── events (photos section and grading section) ──
  document.addEventListener('click', ev => {
    const b = ev.target.closest('[data-pt]');
    if (!b || b.disabled || !b.closest('#drawer-backdrop, .drawer')) return;
    const act = b.dataset.pt, pid = st.sel;
    ev.preventDefault();
    if (act === 'select') {
      st.sel = st.sel === b.dataset.pid ? null : b.dataset.pid;
      refresh();
      if (st.sel) el('d-pt-editor')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      return;
    }
    if (act === 'close') { st.sel = null; return refresh(); }
    if (act === 'remove' && pid) { setTag(pid, null); return changed(); }
    if (act === 'overview' && pid) { setTag(pid, { part_ref: '', unit_no: 1, defects: [] }); return changed(); }
    if (act === 'part' && pid) {
      const ref = b.dataset.ref, n = +b.dataset.unit, cur = st.tags[pid];
      if (cur && cur.part_ref === ref && +cur.unit_no === n) return;   // same part: keep its defects
      setTag(pid, { part_ref: ref, unit_no: n, defects: [] });
      return changed();
    }
    if (act === 'def' && pid) {
      const cur = st.tags[pid]; if (!cur) return;
      const d = +b.dataset.def, defs = (cur.defects || []).map(Number);
      setTag(pid, { ...cur, defects: defs.includes(d) ? defs.filter(x => x !== d) : [...defs, d] });
      return changed();
    }
    if (act === 'picker') {
      st.picker = st.picker === b.dataset.key ? null : b.dataset.key;
      st.fresh = new Set();
      return changed();
    }
    if (act === 'pick') {
      const p = b.dataset.pid, ref = b.dataset.ref, n = +b.dataset.unit, d = +b.dataset.def;
      const v = view(p);
      if (v && !sameUnit(v, ref, n)) return;   // tagged to another part
      if (!v) {
        setTag(p, { part_ref: ref, unit_no: n, defects: [d] });
        st.fresh.add(p);
      } else {
        const defs = (st.tags[p].defects || []).map(Number);
        setTag(p, { ...st.tags[p], defects: defs.includes(d) ? defs.filter(x => x !== d) : [...defs, d] });
      }
      return changed();
    }
  });

  return { init, load, setItems, tileHtml, refresh, defectExtra, payload, viewerInfo, savedViewerInfo };
})();
