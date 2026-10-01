/* ═══════════════════════════════════════════
   LABEL TEMPLATES (settings/s_labels.php) and LABEL SIZES (settings/s_label_sizes.php, admin)
   Data: CP.labels = {templates, sizes, default_id, sample, is_admin} (from the page).
   Talks to api/label_templates.php. Needs labels.js, settings.js (BASE, toast, esc).
   ═══════════════════════════════════════════ */
const LBL_API = BASE + '/api/label_templates.php';

async function lblPost(action, data = {}) {
  try {
    const res = await fetch(LBL_API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action, ...data }) }).then(r => r.json());
    if (!res.ok) toast(res.error || tRaw('common.err_unknown'), true);
    return res;
  } catch (e) {
    toast(tRaw('common.err_prefix', { error: e.message }), true);
    return { ok: false };
  }
}

// ══════════ TEMPLATE EDITOR ══════════
const LE = {
  d: null,         // CP.labels (refreshed after each save)
  cur: null,       // the template being edited (a copy)
  dirty: false,
};
const leEl = id => document.getElementById(id);
const leClone = o => JSON.parse(JSON.stringify(o));
const leMm = v => String(Math.round(+v * 10) / 10);

function leSize(id) { return LE.d.sizes.find(s => s.id === +id) || null; }

function leBlank() {
  const s = LE.d.sizes[0] || { id: null, width_mm: 89, height_mm: 36 };
  const o = Labels.natural(s);
  return { id: null, name: tRaw('lbl.new_name'), kind: 'own', editable: true, size_id: s.id, width_mm: s.width_mm, height_mm: s.height_mm,
           orientation: o, layout: o === 'portrait' ? 'stacked' : 'horizontal', fields: Labels.defaultFields(), colour: true, cut_line: true, shared: false };
}

function leSelect(tpl) {
  LE.cur = leClone(tpl);
  LE.dirty = false;
  leRenderList();
  leRenderEditor();
}

function leConfirmDiscard() {
  return !LE.dirty || confirm(tRaw('lbl.confirm_discard'));
}

function leChanged() {
  LE.dirty = true;
  leRenderPreview();
}

function leRenderList() {
  const list = leEl('lbl-list');
  list.innerHTML = LE.d.templates.map(tp => {
    const tags = [];
    if (tp.id === LE.d.default_id) tags.push(`<span class="chip chip-y">${t('lbl.tag_default')}</span>`);
    if (tp.kind === 'site') tags.push(`<span class="chip chip-blue">${t('lbl.tag_site')}</span>`);
    if (tp.kind === 'shared') tags.push(`<span class="chip chip-n">${t('lbl.tag_shared_by', { user: tp.owner_name || '' })}</span>`);
    if (tp.kind === 'own' && tp.shared) tags.push(`<span class="chip chip-n">${t('lbl.tag_shared')}</span>`);
    const on = LE.cur && LE.cur.id === tp.id;
    return `<button type="button" class="lbl-item${on ? ' on' : ''}" data-id="${tp.id}" aria-pressed="${on}">
      <span class="lbl-item-name">${esc(tp.name)}</span>
      <span class="lbl-item-meta">${esc(tp.size_name)} · ${leMm(tp.width_mm)} × ${leMm(tp.height_mm)} mm</span>
      <span class="lbl-item-tags">${tags.join('')}</span>
    </button>`;
  }).join('') + (LE.cur && !LE.cur.id ? `<div class="lbl-item on"><span class="lbl-item-name">${esc(LE.cur.name)}</span><span class="lbl-item-meta">${t('lbl.unsaved')}</span></div>` : '');
}

function leRenderSizes() {
  const c = LE.cur;
  leEl('lbl-sizes').innerHTML = LE.d.sizes.map(s => {
    const on = s.id === c.size_id;
    return `<button type="button" class="lbl-size${on ? ' on' : ''}" role="radio" aria-checked="${on}" data-size="${s.id}"${c.editable ? '' : ' disabled'}>
      <span>${esc(s.name)}</span><span class="lbl-size-mm">${leMm(s.width_mm)} × ${leMm(s.height_mm)} mm</span>
      ${s.site ? '' : `<span class="lbl-size-own">${t('lbl.my_size')}</span>`}
    </button>`;
  }).join('') + (c.editable ? `<button type="button" class="lbl-size lbl-size-add" id="lbl-size-open">+ ${t('lbl.add_a_size')}</button>` : '');
}

function leRenderFields() {
  const c = LE.cur, ro = !c.editable;
  leEl('lbl-fields').innerHTML = c.fields.map((f, i) => {
    const pct = Math.round(f.scale * 100);
    return `<div class="lbl-field${f.on ? '' : ' off'}" data-i="${i}">
      <label class="lbl-check"><input type="checkbox" data-f="on"${f.on ? ' checked' : ''}${ro ? ' disabled' : ''}> ${t('lbl.f_' + f.id)}</label>
      <span class="lbl-scale">
        <button type="button" class="lbl-sq" data-f="minus" aria-label="${t('lbl.smaller')}"${ro || f.scale <= 0.5 ? ' disabled' : ''}>−</button>
        <span class="lbl-pct${pct !== 100 ? ' changed' : ''}">${pct}%</span>
        <button type="button" class="lbl-sq" data-f="plus" aria-label="${t('lbl.bigger')}"${ro || f.scale >= 2 ? ' disabled' : ''}>+</button>
      </span>
      <span class="lbl-move">
        <button type="button" class="lbl-sq" data-f="up" aria-label="${t('lbl.move_up')}"${ro || i === 0 ? ' disabled' : ''}>↑</button>
        <button type="button" class="lbl-sq" data-f="down" aria-label="${t('lbl.move_down')}"${ro || i === c.fields.length - 1 ? ' disabled' : ''}>↓</button>
      </span>
    </div>`;
  }).join('');
}

function leRenderEditor() {
  const c = LE.cur, ro = !c.editable;
  leEl('lbl-editor-title').textContent = c.id ? c.name : tRaw('lbl.new_template');
  const roNote = leEl('lbl-readonly');
  roNote.hidden = !ro;
  roNote.textContent = ro ? tRaw(c.kind === 'site' ? 'lbl.readonly_site' : 'lbl.readonly_shared', { user: c.owner_name || '' }) : '';
  leEl('lbl-name').value = c.name;
  leEl('lbl-name').disabled = ro;
  document.querySelectorAll('input[name="lbl-orient"]').forEach(r => { r.checked = r.value === c.orientation; r.disabled = ro; });
  document.querySelectorAll('input[name="lbl-layout"]').forEach(r => { r.checked = r.value === c.layout; r.disabled = ro; });
  leEl('lbl-colour').checked = !!c.colour; leEl('lbl-colour').disabled = ro;
  leEl('lbl-cut').checked = !!c.cut_line;  leEl('lbl-cut').disabled = ro;
  leEl('lbl-reset-sizes').disabled = ro;
  leEl('lbl-add-size').hidden = true;
  leRenderSizes();
  leRenderFields();

  // Actions
  leEl('lbl-share-wrap').hidden = c.kind !== 'own';
  leEl('lbl-shared').checked = !!c.shared;
  leEl('lbl-save').hidden = ro;
  leEl('lbl-duplicate').hidden = !c.id;
  leEl('lbl-default').hidden = !c.id || c.id === LE.d.default_id;
  leEl('lbl-delete').hidden = !c.id || ro;
  leRenderPreview();
}

function leRenderPreview() {
  const paper = leEl('lbl-paper'), el = leEl('lbl-preview');
  const [W, H] = Labels.dims(LE.cur);
  const maxW = Math.max(160, (paper.clientWidth || 320) - 32), maxH = 340;
  const ppm = Math.min(maxW / W, maxH / H, 6);
  const fits = Labels.render(el, LE.cur, LE.d.sample, ppm);
  leEl('lbl-fit').hidden = fits;
}

/** Applies the server's lists and selects a template. */
function leApply(res, selectId) {
  LE.d.templates = res.templates;
  LE.d.sizes = res.sizes;
  LE.d.default_id = res.default_id;
  const pick = LE.d.templates.find(t => t.id === selectId) || LE.d.templates.find(t => t.id === LE.d.default_id) || LE.d.templates[0];
  if (pick) leSelect(pick);
}

async function leSave() {
  const c = LE.cur;
  if (!c.editable) return null;
  const res = await lblPost('save', { template: { ...c, name: leEl('lbl-name').value } });
  if (!res.ok) return null;
  leApply(res, res.id);
  toast(tRaw('lbl.saved'));
  return res.id;
}

function leExport() {
  const c = LE.cur, size = leSize(c.size_id) || { name: c.size_name, width_mm: c.width_mm, height_mm: c.height_mm };
  const data = {
    app: 'game-collection', type: 'label_template', v: 1,
    name: leEl('lbl-name').value || c.name, orientation: c.orientation, layout: c.layout,
    fields: c.fields, colour: !!c.colour, cut_line: !!c.cut_line,
    size: { name: size.name, width_mm: +size.width_mm, height_mm: +size.height_mm },
  };
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'label_' + (data.name.replace(/[^\w-]+/g, '_') || 'template') + '.json';
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

function leInit() {
  LE.d = CP.labels;
  const first = LE.d.templates.find(t => t.id === LE.d.default_id) || LE.d.templates[0];
  leSelect(first || leBlank());

  leEl('lbl-list').addEventListener('click', e => {
    const b = e.target.closest('[data-id]');
    if (!b || !leConfirmDiscard()) return;
    const tpl = LE.d.templates.find(t => t.id === +b.dataset.id);
    if (tpl) leSelect(tpl);
  });
  leEl('lbl-new').addEventListener('click', () => { if (leConfirmDiscard()) { LE.cur = leBlank(); LE.dirty = true; leRenderList(); leRenderEditor(); } });

  // Import .json
  leEl('lbl-import-btn').addEventListener('click', () => leEl('lbl-import').click());
  leEl('lbl-import').addEventListener('change', async e => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file || !leConfirmDiscard()) return;
    let data;
    try { data = JSON.parse(await file.text()); } catch { toast(tRaw('lbl.err_import'), true); return; }
    const res = await lblPost('import', { data });
    if (res.ok) { leApply(res, res.id); toast(tRaw('lbl.imported')); }
  });

  // Editor fields
  leEl('lbl-name').addEventListener('input', e => { LE.cur.name = e.target.value; LE.dirty = true; });
  document.querySelectorAll('input[name="lbl-orient"]').forEach(r => r.addEventListener('change', () => {
    LE.cur.orientation = r.value;
    LE.cur.layout = r.value === 'portrait' ? 'stacked' : 'horizontal';   // the matching layout; can be changed after
    document.querySelectorAll('input[name="lbl-layout"]').forEach(x => { x.checked = x.value === LE.cur.layout; });
    leChanged();
  }));
  document.querySelectorAll('input[name="lbl-layout"]').forEach(r => r.addEventListener('change', () => { LE.cur.layout = r.value; leChanged(); }));
  leEl('lbl-colour').addEventListener('change', e => { LE.cur.colour = e.target.checked; leChanged(); });
  leEl('lbl-cut').addEventListener('change', e => { LE.cur.cut_line = e.target.checked; leChanged(); });
  leEl('lbl-shared').addEventListener('change', e => { LE.cur.shared = e.target.checked; LE.dirty = true; });

  // Sizes: pick a tile (starts in its natural orientation) or add one of your own
  leEl('lbl-sizes').addEventListener('click', e => {
    if (e.target.closest('#lbl-size-open')) { leEl('lbl-add-size').hidden = false; leEl('lbl-size-name').focus(); return; }
    const b = e.target.closest('[data-size]');
    if (!b || !LE.cur.editable) return;
    const s = leSize(b.dataset.size);
    Object.assign(LE.cur, { size_id: s.id, size_name: s.name, width_mm: s.width_mm, height_mm: s.height_mm });
    LE.cur.orientation = Labels.natural(s);
    LE.cur.layout = LE.cur.orientation === 'portrait' ? 'stacked' : 'horizontal';
    leRenderEditor();
    LE.dirty = true;
  });
  leEl('lbl-size-cancel').addEventListener('click', () => { leEl('lbl-add-size').hidden = true; });
  leEl('lbl-size-add').addEventListener('click', async () => {
    const w = +leEl('lbl-size-w').value, h = +leEl('lbl-size-h').value, name = leEl('lbl-size-name').value.trim();
    if (!name) { toast(tRaw('lbl.err_size_name'), true); return; }
    if (!(w >= 15 && w <= 300 && h >= 15 && h <= 300)) { toast(tRaw('lbl.err_size_range'), true); return; }
    const res = await lblPost('add_size', { name, width_mm: w, height_mm: h });
    if (!res.ok) return;
    LE.d.sizes = res.sizes;
    const s = leSize(res.size_id);
    Object.assign(LE.cur, { size_id: s.id, size_name: s.name, width_mm: s.width_mm, height_mm: s.height_mm, orientation: Labels.natural(s) });
    LE.cur.layout = LE.cur.orientation === 'portrait' ? 'stacked' : 'horizontal';
    ['lbl-size-name', 'lbl-size-w', 'lbl-size-h'].forEach(id => { leEl(id).value = ''; });
    LE.dirty = true;
    leRenderEditor();
  });

  // Fields: on/off, size −/+, order ↑/↓
  leEl('lbl-fields').addEventListener('click', e => {
    const b = e.target.closest('button[data-f]');
    if (!b) return;
    const i = +b.closest('[data-i]').dataset.i, f = LE.cur.fields;
    if (b.dataset.f === 'minus') f[i].scale = Math.max(0.5, Math.round((f[i].scale - 0.1) * 10) / 10);
    if (b.dataset.f === 'plus')  f[i].scale = Math.min(2.0, Math.round((f[i].scale + 0.1) * 10) / 10);
    if (b.dataset.f === 'up'   && i > 0)            [f[i - 1], f[i]] = [f[i], f[i - 1]];
    if (b.dataset.f === 'down' && i < f.length - 1) [f[i + 1], f[i]] = [f[i], f[i + 1]];
    leRenderFields();
    leChanged();
  });
  leEl('lbl-fields').addEventListener('change', e => {
    if (e.target.dataset.f !== 'on') return;
    LE.cur.fields[+e.target.closest('[data-i]').dataset.i].on = e.target.checked;
    leRenderFields();
    leChanged();
  });
  leEl('lbl-reset-sizes').addEventListener('click', () => { LE.cur.fields.forEach(f => { f.scale = 1; }); leRenderFields(); leChanged(); });

  // Actions
  leEl('lbl-save').addEventListener('click', leSave);
  leEl('lbl-duplicate').addEventListener('click', () => {
    if (!leConfirmDiscard()) return;
    const copy = { ...leClone(LE.cur), id: null, kind: 'own', editable: true, shared: false, name: tRaw('lbl.copy_of', { name: LE.cur.name }) };
    if (!leSize(copy.size_id)) Object.assign(copy, { size_id: LE.d.sizes[0]?.id });   // another user's own size isn't yours to use
    LE.cur = copy; LE.dirty = true;
    leRenderList(); leRenderEditor();
  });
  leEl('lbl-print').addEventListener('click', async () => {
    let id = LE.cur.id;
    if (LE.cur.editable && (LE.dirty || !id)) id = await leSave();   // a test print needs the saved version
    if (id) window.open(`${BASE}/print_label.php?tpl=${id}`, '_blank');
  });
  leEl('lbl-export').addEventListener('click', leExport);
  leEl('lbl-default').addEventListener('click', async () => {
    const res = await lblPost('set_default', { id: LE.cur.id });
    if (res.ok) { leApply(res, LE.cur.id); toast(tRaw('lbl.default_set')); }
  });
  leEl('lbl-delete').addEventListener('click', async () => {
    if (!confirm(tRaw('lbl.confirm_delete', { name: LE.cur.name }))) return;
    const res = await lblPost('delete', { id: LE.cur.id });
    if (res.ok) { LE.dirty = false; leApply(res, null); toast(tRaw('lbl.deleted')); }
  });
  window.addEventListener('resize', () => leRenderPreview());
  window.addEventListener('beforeunload', e => { if (LE.dirty) { e.preventDefault(); e.returnValue = ''; } });
}

// ══════════ ADMIN: SITE SIZES ══════════
function lsInit() {
  const table = leEl('ls-table');
  const row = b => b.closest('tr');
  const vals = tr => ({ name: tr.querySelector('.ls-name').value.trim(), width_mm: +tr.querySelector('.ls-w').value, height_mm: +tr.querySelector('.ls-h').value });
  table.addEventListener('click', async e => {
    const b = e.target.closest('[data-ls]');
    if (!b) return;
    const tr = row(b), id = +tr.dataset.id;
    if (b.dataset.ls === 'save') {
      const res = await lblPost('size_save', { id, ...vals(tr) });
      if (res.ok) toast(tRaw('common.saved'));
    }
    if (b.dataset.ls === 'delete') {
      if (+tr.dataset.used > 0) { tr.querySelector('.ls-replace').hidden = false; return; }   // pick a replacement first
      if (!confirm(tRaw('lbl.confirm_delete_size'))) return;
      if ((await lblPost('size_delete', { id })).ok) tr.remove();
    }
    if (b.dataset.ls === 'confirm-delete') {
      const res = await lblPost('size_delete', { id, replace_id: +tr.querySelector('.ls-replace-sel').value });
      if (res.ok) { toast(tRaw('lbl.size_deleted')); setTimeout(() => location.reload(), 600); }
    }
  });
  leEl('ls-add').addEventListener('click', async () => {
    const res = await lblPost('size_save', { name: leEl('ls-new-name').value.trim(), width_mm: +leEl('ls-new-w').value, height_mm: +leEl('ls-new-h').value });
    if (res.ok) { toast(tRaw('common.saved')); setTimeout(() => location.reload(), 600); }
  });
}

// ── START: only what the current section shows ──
if (leEl('lbl-editor')) leInit();
if (leEl('ls-table')) lsInit();
