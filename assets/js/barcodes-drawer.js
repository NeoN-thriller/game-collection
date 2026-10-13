/* ═══════════════════════════════════════════
   BARCODES in the edit drawer (collection.php). Needs barcode.js.
   Fills #d-barcodes with the codes of the game and of the other editions in its group. Folded by default.
   Adding (when the user may link barcodes) and removing (admins) take effect at once, outside the drawer's Save.
   Users who may not remove can report a code for removal.

   BcDrawer.load({ gameId, onCount })   onCount(gameId, rowsOfThisGame) after an add or remove
   ═══════════════════════════════════════════ */
const BcDrawer = (() => {
  const e = Barcode.esc;
  let st = null, open = false, seq = 0;

  const box = () => document.getElementById('d-barcodes');

  async function load({ gameId, onCount = null }) {
    const el = box();
    if (!el) return;
    const my = ++seq;
    st = { gameId, onCount, codes: [], editions: [], canEdit: false, canRemove: false, confirm: null, cross: null };
    el.hidden = true;
    const res = await Barcode.api('game_barcodes', { game_id: gameId });
    if (my !== seq || !res.ok) return;
    Object.assign(st, { codes: res.codes, editions: res.editions, canEdit: res.can_edit, canRemove: res.can_remove });
    render();
  }

  function render() {
    const el = box();
    // Nothing to show and nothing the user may add: no section at all
    if (!st.codes.length && !st.canEdit) { el.hidden = true; return; }
    el.hidden = false;
    const n = st.codes.length;
    el.innerHTML = `<button type="button" class="bc-d-head" aria-expanded="${open}" aria-controls="bc-d-body">
        <span class="section-label" style="margin:0">${t('bc.section')}</span>
        <span class="sb-sub">${n ? tn('bc.n_codes', n) : t('bc.column_none')} · ${t('bc.shared_all')}</span>
        <span aria-hidden="true">${open ? '▾' : '▸'}</span>
      </button>
      <div id="bc-d-body" ${open ? '' : 'hidden'}>
        ${st.codes.map(card).join('')}
        ${st.canEdit ? addBlock() : ''}
        <p class="ga-desc" style="margin:8px 0 0">${t(st.canRemove ? 'bc.upca_note_admin' : 'bc.upca_note')}</p>
      </div>`;
    if (open && st.canEdit) checkInput();
  }

  function card(c) {
    const many = st.editions.length > 1;
    const r0 = c.rows[0];
    const variants = [...new Set(c.rows.map(r => r.variant))];
    const flagged = c.rows.some(r => r.flagged);
    const ids = c.rows.map(r => r.id).join(',');
    let actions = '';
    if (st.confirm === c.barcode) {
      actions = `<div class="bc-confirm"><p>${t(many ? 'bc.remove_confirm_eds' : 'bc.remove_confirm', { labels: c.rows.map(r => r.label).join(', ') })}</p>
        <div class="bc-actions"><button type="button" class="btn-danger btn-sm" data-bcd="remove-yes" data-ids="${ids}">${t('bc.remove')}</button>
        <button type="button" class="btn-ghost btn-sm" data-bcd="remove-no">${t('common.cancel')}</button></div></div>`;
    } else if (st.confirm === 'flag:' + c.barcode) {
      actions = `<div class="bc-report"><label class="bc-label" for="bcd-why">${t('bc.report_why')}</label>
        <div class="bc-inline"><input type="text" id="bcd-why" maxlength="200">
        <button type="button" class="btn-ghost btn-sm" data-bcd="flag-send" data-ids="${ids}">${t('bc.report_send')}</button>
        <button type="button" class="btn-ghost btn-sm" data-bcd="remove-no">${t('common.cancel')}</button></div></div>`;
    }
    const btn = st.canRemove
      ? `<button type="button" class="bc-x" data-bcd="remove" data-code="${c.barcode}" aria-label="${e(tRaw('bc.remove_aria', { code: c.display }))}">✕</button>`
      : (flagged ? '' : `<button type="button" class="bc-linkbtn" data-bcd="flag" data-code="${c.barcode}">${t('bc.report')}</button>`);
    return `<div class="bc-card${flagged ? ' bc-card-flagged' : ''}">
      <div class="bc-card-top">
        <span class="bc-bars" aria-hidden="true">▥</span>
        <span class="bc-card-code">${e(c.display)}</span>
        ${btn}
      </div>
      <div class="bc-chips">
        ${many ? c.rows.map(r => `<span class="chip chip-blue">${e(r.label)}</span>`).join('') : ''}
        ${variants.map(v => `<span class="chip chip-gold">${v ? t('bc.variant_is', { v: v }) : t('bc.all_variants')}</span>`).join('')}
        ${flagged ? `<span class="chip chip-orange" title="${e(c.rows.find(r => r.flagged).flag_reason)}">${t('bc.flagged')}</span>` : ''}
      </div>
      <div class="sb-sub">${e(c.format)}${r0.added_username ? ' · ' + t('bc.added_by', { user: r0.added_username, date: fmtDate(new Date(r0.created * 1000)) }) : ''}${r0.note ? ' · ' + t('bc.note_is', { note: r0.note }) : ''}</div>
      ${actions}
    </div>`;
  }

  function addBlock() {
    const many = st.editions.length > 1;
    const c = Barcode.config;
    const variant = c.trackVariants && c.variants.length && !c.isAdmin
      ? `<select id="bcd-variant"><option value="">${t('bc.variant_any')}</option>${c.variants.map(v => `<option>${e(v)}</option>`).join('')}</select>`
      : `<input type="text" id="bcd-variant" maxlength="100" placeholder="${t('bc.variant_any')}">`;
    return `<div class="bc-add">
      <div class="section-label">${t('bc.add_title')}</div>
      <div class="bc-inline">
        <input type="text" id="bcd-code" class="bc-digits-in" inputmode="numeric" autocomplete="off" aria-label="${t('bc.digits')}" placeholder="${t('bc.digits')}">
        <button type="button" class="btn-ghost btn-sm" data-bcd="scan">${t('bc.scan')}</button>
      </div>
      <p class="bc-check" id="bcd-msg" hidden></p>
      ${many ? `<span class="bc-label">${t('bc.applies_to')}</span><div class="bc-checks">${st.editions.map(ed =>
        `<label class="bc-check-row"><input type="checkbox" class="bcd-ed" value="${ed.game_id}"${ed.game_id === st.gameId ? ' checked' : ''}> ${e(ed.label)}</label>`).join('')}</div>` : ''}
      <div class="field" style="margin:8px 0 0"><label for="bcd-variant">${t('bc.variant_opt')}</label>${variant}</div>
      <div class="bc-conflict" id="bcd-cross" hidden></div>
      <div class="bc-actions"><button type="button" class="btn btn-sm" data-bcd="add" disabled>${t('bc.add_btn')}</button></div>
    </div>`;
  }

  /** Validates the digits: the Add button only works for a valid code; an existing one says so. */
  function checkInput() {
    const inp = document.getElementById('bcd-code');
    const msg = document.getElementById('bcd-msg');
    const add = box().querySelector('[data-bcd="add"]');
    if (!inp) return;
    const raw = inp.value;
    const p = raw.replace(/\D/g, '') ? Barcode.problem(raw) : { kind: 'empty' };
    const code = p ? null : Barcode.normalize(raw);
    const known = code && st.codes.some(c => c.barcode === code);
    msg.hidden = !(p && p.kind !== 'empty') && !known;
    if (p?.kind === 'check')       msg.innerHTML = `<span class="bc-check-tag">${t('bc.check_tag')}</span> ${t('bc.err_check', { expected: p.expected })}`;
    else if (p?.kind === 'length') msg.innerHTML = `<span class="bc-check-tag">${t('bc.check_tag')}</span> ${t('bc.err_length')}`;
    else if (known)                msg.textContent = tRaw('bc.already_here');
    add.disabled = !code || known || !chosen().length;
  }

  function chosen() {
    const boxes = [...box().querySelectorAll('.bcd-ed')];
    return boxes.length ? boxes.filter(b => b.checked).map(b => +b.value) : [st.gameId];
  }

  async function add(confirmCross = false) {
    const code = Barcode.normalize(document.getElementById('bcd-code').value);
    if (!code) return;
    const res = await Barcode.api('link', { code, game_ids: chosen(), variant: document.getElementById('bcd-variant').value.trim(), confirm_cross_system: confirmCross });
    if (res.status === 409 && res.conflict === 'cross_system') {
      const g = res.games[0] || {};
      const cross = document.getElementById('bcd-cross');
      cross.hidden = false;
      cross.innerHTML = `<p>${t('bc.conflict', { title: g.label || '', system: g.system || '' })}</p>
        <div class="bc-actions"><button type="button" class="btn btn-sm" data-bcd="cross-yes">${t('bc.link_as_well')}</button>
        <button type="button" class="btn-ghost btn-sm" data-bcd="cross-no">${t('common.cancel')}</button></div>`;
      return;
    }
    if (!res.ok) { toastMsg(res.error || tRaw('common.error'), true); return; }
    toastMsg(tRaw('bc.linked'));
    await reload();
  }

  async function reload() {
    const res = await Barcode.api('game_barcodes', { game_id: st.gameId });
    if (!res.ok) return;
    Object.assign(st, { codes: res.codes, confirm: null });
    render();
    st.onCount?.(st.gameId, st.codes.reduce((n, c) => n + c.rows.filter(r => r.game_id === st.gameId).length, 0), st.codes);
  }

  function toastMsg(msg, err) {
    if (typeof toast === 'function') toast(msg, err);
  }

  document.addEventListener('click', async ev => {
    const b = ev.target.closest('#d-barcodes [data-bcd], #d-barcodes .bc-d-head');
    if (!b || !st) return;
    if (b.classList.contains('bc-d-head')) { open = !open; render(); return; }
    switch (b.dataset.bcd) {
      case 'remove': st.confirm = b.dataset.code; render(); break;
      case 'flag': st.confirm = 'flag:' + b.dataset.code; render(); document.getElementById('bcd-why')?.focus(); break;
      case 'remove-no': st.confirm = null; render(); break;
      case 'remove-yes': {
        const res = await Barcode.api('remove', { ids: b.dataset.ids.split(',').map(Number) });
        if (!res.ok) { toastMsg(res.error || tRaw('common.error'), true); break; }
        toastMsg(tRaw('bc.removed'));
        await reload();
        break;
      }
      case 'flag-send': {
        const res = await Barcode.api('flag', { ids: b.dataset.ids.split(',').map(Number), reason: document.getElementById('bcd-why').value.trim() });
        if (!res.ok) { toastMsg(res.error || tRaw('common.error'), true); break; }
        toastMsg(tRaw('bc.reported'));
        await reload();
        break;
      }
      case 'scan': {
        const code = await Barcode.capture();
        if (code) { document.getElementById('bcd-code').value = Barcode.format(code); checkInput(); }
        break;
      }
      case 'add': b.disabled = true; await add(); checkInput(); break;
      case 'cross-yes': document.getElementById('bcd-cross').hidden = true; await add(true); break;
      case 'cross-no': document.getElementById('bcd-cross').hidden = true; break;
    }
  });
  document.addEventListener('input', ev => { if (ev.target.id === 'bcd-code') checkInput(); });
  document.addEventListener('change', ev => { if (ev.target.classList?.contains('bcd-ed')) checkInput(); });
  document.addEventListener('keydown', ev => {
    if (ev.key === 'Enter' && ev.target.id === 'bcd-code') { ev.preventDefault(); box().querySelector('[data-bcd="add"]:not([disabled])')?.click(); }
  });

  return { load };
})();
