/* ═══════════════════════════════════════════
   SCAN PAGE (scan.php). Needs barcode.js.
   A code (camera, typed, USB scanner, ?code=) is looked up; then, by the chosen action:
     Look up       → the game and the user's copies
     Add as owned  → a copy is made at once, with Undo
     Wishlist      → on the wishlist, with Undo
   Several games on one code: the user picks one. An unknown code can be linked to a game.
   While a result is shown the camera keeps running but nothing is read until "Scan the next one".
   ═══════════════════════════════════════════ */
(() => {
  const BASE = Barcode.config.base;
  const e = Barcode.esc;
  const $ = id => document.getElementById(id);
  const panel = $('scan-result');

  let mode = ['look', 'own', 'wish'].includes(Barcode.store('mode')) ? Barcode.store('mode') : 'look';
  let tab  = 'camera';
  let cam  = null, starting = false;
  let undo = null;        // what the last add can undo (until the next scan)
  let current = null;     // {code, display, matches, can_edit} of the scan on screen
  let busy = false;

  function toast(msg, err = false) {
    const el = $('toast');
    el.textContent = msg;
    el.style.borderColor = err ? 'var(--red)' : 'var(--accent2)';
    el.style.color = err ? 'var(--red)' : 'var(--accent)';
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 2500);
  }

  async function post(path, body) {
    try {
      const r = await fetch(BASE + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      return await r.json();
    } catch (err) { return { ok: false, error: tRaw('common.error') }; }
  }

  /* ── Tabs, mode ── */
  function setTab(which, focus = true) {
    tab = which;
    $('scan-tab-camera').setAttribute('aria-pressed', which === 'camera');
    $('scan-tab-type').setAttribute('aria-pressed', which === 'type');
    $('scan-camera').hidden = which !== 'camera';
    $('scan-type').hidden = which !== 'type';
    Barcode.store('tab', which);
    if (which === 'camera') startCamera();
    else { stopCamera(); if (focus) $('scan-digits').focus(); }
  }

  function setMode(m) {
    mode = m;
    Barcode.store('mode', m);
    document.querySelectorAll('[data-mode]').forEach(b => b.setAttribute('aria-checked', b.dataset.mode === m));
  }

  /* ── Camera ── */
  function camMessage(key) {
    const box = $('scan-cam-msg');
    if (!key) { box.hidden = true; return; }
    box.hidden = false;
    box.innerHTML = `<p>${t('bc.' + key)}</p><button type="button" class="btn-ghost btn-sm" data-act="to-type">${t('bc.tab_type')}</button>`;
    $('scan-live').hidden = true;
  }

  async function startCamera() {
    if (cam || starting) return;
    starting = true;
    camMessage(null);
    cam = await BarcodeCamera.start({
      video: $('scan-video'), frame: $('scan-frame'),
      onCode: code => handleCode(code, 'camera'),
      onError: key => camMessage(key),
      onReady: ready,
    });
    starting = false;
    if (cam && tab !== 'camera') stopCamera();
    if (cam && current) cam.pause();   // a result is still on screen
  }

  async function ready(c) {
    $('scan-live').hidden = false;
    $('scan-torch').hidden = !c.torch;
    $('scan-torch').setAttribute('aria-pressed', 'false');
    if (c.torch && Barcode.store('torch') === '1') { await c.setTorch(true); $('scan-torch').setAttribute('aria-pressed', 'true'); }
    const devs = await c.devices();
    const pick = $('scan-camera-pick');
    pick.hidden = devs.length < 2;
    if (devs.length > 1) {
      const cur = c.currentDevice();
      pick.innerHTML = devs.map((d, i) => `<option value="${e(d.deviceId)}"${d.deviceId === cur ? ' selected' : ''}>${e(d.label || tRaw('bc.camera_n', { n: i + 1 }))}</option>`).join('');
    }
  }

  function stopCamera() {
    cam?.stop();
    cam = null;
    $('scan-live').hidden = true;
  }

  /* ── A code came in ── */
  async function handleCode(code, source) {
    if (busy) return;
    busy = true;
    cam?.pause();
    undo = null;
    panel.hidden = false;
    panel.className = 'bc-result';
    panel.innerHTML = `<p class="ga-desc">${t('bc.looking_up')} ${e(Barcode.format(code))}…</p>`;
    const res = await Barcode.api('lookup', { code, source });
    busy = false;
    if (!res.ok) {
      current = { code };
      show('err', `<p class="bc-err-text">${e(res.error || tRaw('common.error'))}</p>${nextButtons()}`);
      return;
    }
    current = res;
    if (!res.matches.length) return newBarcode();
    if (res.matches.length > 1) return pickList();
    act(res.matches[0]);
  }

  /** The result panel: kind = match (mint) | several (orange) | new (gold) | err (red). */
  function show(kind, html) {
    panel.hidden = false;
    panel.className = 'bc-result bc-result-' + kind;
    panel.innerHTML = html;
    if (window.innerWidth <= 700) panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  const nextButtons = (extra = '') => `<div class="bc-actions">${extra}<button type="button" class="btn" data-act="next">${t('bc.scan_next')}</button></div>`;

  function codeLine() {
    return `<span class="bc-code-small">${e(current.display)}</span> <span class="chip">${e(current.format)}</span>`;
  }

  /** Notes for a match that can't be opened normally: switched off by the admin, or a system the user hides. */
  function notes(m) {
    if (!m.game_active) return `<p class="bc-note-warn">${t('bc.game_hidden')}</p>`;
    if (!m.system_visible) return `<div class="bc-note-warn"><p>${t('bc.system_hidden', { system: m.system_name })}</p>
      <button type="button" class="btn-ghost btn-sm" data-act="show-system" data-sys="${m.system_id}">${t('bc.show_system')}</button></div>`;
    return '';
  }

  function openUrl(m) {
    return m.first_entry_id ? `${BASE}/collection.php?entry=${m.first_entry_id}` : `${BASE}/collection.php?game=${m.game_id}`;
  }
  const openButton = m => m.game_active && m.system_visible ? `<a class="btn-ghost" href="${e(openUrl(m))}">${t(m.owned ? 'bc.open_copy' : 'bc.open_game')}</a>` : '';

  function gameHead(m) {
    const img = m.image ? `<img class="bc-cover" src="${e(m.image)}" alt="">` : `<div class="bc-cover bc-cover-none" aria-hidden="true">▥</div>`;
    const ed = m.edition_label && !m.title.toLowerCase().includes(m.edition_label.toLowerCase()) ? ' · ' + e(m.edition_label) : '';
    return `<div class="bc-game">${img}<div>
      <div class="bc-game-title">${e(m.title)}</div>
      <div class="sb-sub">${e(m.system_name)}${ed}</div>
      <div>${codeLine()}${m.variant ? ` <span class="chip chip-gold">${t('bc.variant_is', { v: m.variant })}</span>` : ''}</div>
    </div></div>`;
  }

  function ownLine(m) {
    if (m.owned) {
      const bits = [tn('bc.you_own', m.owned)];
      if (m.condition) bits.push(e(m.condition));
      if (m.completeness) bits.push(e(m.completeness));
      return `<p class="bc-own">✓ ${bits.join(' · ')}</p>`;
    }
    if (m.owned_other) return `<p class="bc-own">${t('bc.own_other_edition')}</p>`;
    return `<p class="bc-own bc-own-none">${t(m.wishlist ? 'bc.on_wishlist' : 'bc.not_owned')}</p>`;
  }

  function reportLink(m) {
    if (m.flagged) return `<p class="sb-sub bc-reported">${t('bc.reported')}</p>`;
    return `<button type="button" class="bc-linkbtn" data-act="report" data-id="${m.barcode_id}">${t('bc.report_wrong')}</button>`;
  }

  /* ── What a match does, by mode ── */
  function act(m) {
    if (mode === 'own' && m.game_active) return addOwned(m);
    if (mode === 'wish' && m.game_active) return addWish(m);
    lookResult(m);
  }

  function lookResult(m, extra = '') {
    const add = m.game_active ? `<button type="button" class="btn-ghost" data-act="add-copy" data-game="${m.game_id}">${t(m.owned ? 'bc.add_another' : 'bc.add_own')}</button>` : '';
    show('match', `<span class="bc-badge">${t('bc.match')}</span>${gameHead(m)}${ownLine(m)}${notes(m)}${extra}
      ${nextButtons(openButton(m) + add)}${reportLink(m)}`);
  }

  /** Copies of a game: [{id, copy_number, owned, wishlist, variant, photos, grade_method, grade_label_id}]. */
  async function copiesOf(gameId) {
    try {
      const r = await fetch(`${BASE}/api/entries.php?game_id=${gameId}`).then(x => x.json());
      return r.ok ? r.entries : null;
    } catch (err) { return null; }
  }

  /** The barcode's variant, when the user tracks variants and has that label (in the user's own spelling). */
  function variantFor(m) {
    if (!m.variant || !Barcode.config.trackVariants) return '';
    return Barcode.config.variants.find(v => v.toLowerCase() === m.variant.toLowerCase()) || '';
  }

  async function addOwned(m) {
    const copies = await copiesOf(m.game_id);
    if (!copies) return show('err', `<p class="bc-err-text">${t('common.error')}</p>${nextButtons()}`);
    // Reuse an empty not-owned row (e.g. a wishlist entry) instead of leaving a gap; never a copy with photos or a grade
    const reuse = copies.filter(c => !c.owned && !(c.photos || []).length && !c.grade_method && !c.grade_label_id)
                        .sort((a, b) => a.copy_number - b.copy_number)[0];
    const copyNumber = reuse ? +reuse.copy_number : Math.max(0, ...copies.map(c => +c.copy_number)) + 1;
    const variant = variantFor(m);
    const body = { game_id: m.game_id, copy_number: copyNumber, owned: 1 };
    if (variant && !(reuse && reuse.variant)) body.variant = variant;
    const res = await post('/api/entry_save.php', body);
    if (!res.ok) return show('err', `<p class="bc-err-text">${e(res.error || tRaw('common.error'))}</p>${nextButtons()}`);
    undo = { kind: 'own', m, entryId: +res.entry.id, copyNumber, reused: !!reuse, prevVariant: reuse ? reuse.variant : '', setVariant: 'variant' in body };
    m.owned += 1;
    m.first_entry_id ??= +res.entry.id;
    show('match', `<span class="bc-badge">✓ ${t('bc.added_own', { n: copyNumber })}</span>${gameHead(m)}${variant && body.variant ? `<p class="sb-sub">${t('bc.variant_set', { v: variant })}</p>` : ''}${notes(m)}
      ${nextButtons(`<button type="button" class="btn-ghost" data-act="undo">${t('bc.undo')}</button>` + openButton({ ...m, first_entry_id: +res.entry.id }))}`);
  }

  async function addWish(m, anyway = false) {
    if (!anyway && (m.owned || m.owned_other)) {
      return lookResult(m, `<div class="bc-note-warn"><p>${t(m.owned ? 'bc.already_own' : 'bc.already_own_other')}</p>
        <button type="button" class="btn-ghost btn-sm" data-act="wish-anyway" data-game="${m.game_id}">${t('bc.wish_anyway')}</button></div>`);
    }
    if (m.wishlist) return lookResult(m, `<p class="bc-note-warn">${t('bc.already_wish')}</p>`);
    const res = await post('/api/entry_save.php', { game_id: m.game_id, copy_number: 1, wishlist: 1 });
    if (!res.ok) return show('err', `<p class="bc-err-text">${e(res.error || tRaw('common.error'))}</p>${nextButtons()}`);
    undo = { kind: 'wish', m };
    m.wishlist = true;
    show('match', `<span class="bc-badge">♥ ${t('bc.added_wish')}</span>${gameHead(m)}${notes(m)}
      ${nextButtons(`<button type="button" class="btn-ghost" data-act="undo">${t('bc.undo')}</button>` + openButton(m))}`);
  }

  async function doUndo() {
    const u = undo;
    if (!u) return;
    let res;
    if (u.kind === 'wish') {
      res = await post('/api/entry_save.php', { game_id: u.m.game_id, copy_number: 1, wishlist: 0 });
      if (res.ok) u.m.wishlist = false;
    } else if (u.reused) {
      // The row was there before (e.g. a wishlist entry): only take "owned" back off
      const body = { game_id: u.m.game_id, copy_number: u.copyNumber, owned: 0 };
      if (u.setVariant) body.variant = u.prevVariant || '';
      res = await post('/api/entry_save.php', body);
    } else {
      // A new copy: removed again, but only while it is still the newest (removing renumbers the ones after it)
      const copies = await copiesOf(u.m.game_id);
      const newest = copies ? Math.max(0, ...copies.map(c => +c.copy_number)) : 0;
      if (!copies || newest !== u.copyNumber) { toast(tRaw('bc.undo_gone'), true); undo = null; refreshUndo(); return; }
      res = await post('/api/entry_delete.php', { entry_id: u.entryId });
    }
    if (!res.ok) { toast(res.error || tRaw('common.error'), true); return; }
    if (u.kind === 'own') { u.m.owned = Math.max(0, u.m.owned - 1); if (u.m.first_entry_id === u.entryId) u.m.first_entry_id = null; }
    undo = null;
    toast(tRaw('bc.undone'));
    lookResult(u.m);
  }

  function refreshUndo() {
    panel.querySelector('[data-act="undo"]')?.remove();
  }

  /* ── Several games on one code ── */
  function pickList() {
    const ms = current.matches;
    show('several', `<span class="bc-badge">${t('bc.several_title', { n: ms.length })}</span>
      <p class="ga-desc" style="margin:4px 0 8px">${t('bc.several_hint')} ${codeLine()}</p>
      <div class="bc-radio-list" role="radiogroup" aria-label="${t('bc.several_hint')}">
        ${ms.map((m, i) => `<label class="bc-radio"><input type="radio" name="scan-pick" value="${i}"${i === 0 ? ' checked' : ''}>
          <span><strong>${e(m.label)}</strong><br><span class="sb-sub">${e(m.system_name)} · ${m.owned ? tn('bc.you_own', m.owned) : t(m.wishlist ? 'bc.on_wishlist' : 'bc.not_owned')}</span></span></label>`).join('')}
      </div>
      <div class="bc-actions">
        <button type="button" class="btn" data-act="pick">${t('bc.continue')}</button>
        ${current.can_edit ? `<button type="button" class="btn-ghost" data-act="neither">${t('bc.neither')}</button>` : ''}
        <button type="button" class="btn-ghost" data-act="next">${t('bc.skip')}</button>
      </div>`);
  }

  /* ── Unknown code ── */
  function newBarcode() {
    if (!current.can_edit) {
      return show('new', `<span class="bc-badge">${t('bc.new_title')}</span><p>${codeLine()}</p><p>${t('bc.only_admins')}</p>${nextButtons()}`);
    }
    show('new', `<span class="bc-badge">${t('bc.new_title')}</span><p>${codeLine()}</p><p class="ga-desc">${t('bc.new_text')}</p>
      <div class="bc-actions"><button type="button" class="btn" data-act="link">${t('bc.link_it')}</button>
      <button type="button" class="btn-ghost" data-act="next">${t('bc.skip')}</button></div>`);
  }

  function openLink() {
    const code = current.code;
    BarcodeLink.open({
      code,
      addLabel: mode === 'own' ? tRaw('bc.save_add') : mode === 'wish' ? tRaw('bc.save_wish') : '',
      onSaved: async ({ gameIds, andAdd }) => {
        toast(tRaw('bc.linked'));
        const res = await Barcode.api('lookup', { code, source: 'search' });
        if (!res.ok) return;
        current = res;
        const m = res.matches.find(x => x.game_id === gameIds[0]) || res.matches[0];
        if (!m) return;
        if (andAdd) act(m); else lookResult(m);
      },
    });
  }

  function next() {
    undo = null;
    current = null;
    panel.hidden = true;
    panel.innerHTML = '';
    cam?.resume();
    if (tab === 'type') { $('scan-digits').value = ''; $('scan-digits').focus(); }
  }

  /* ── Typed digits (also a USB / Bluetooth scanner: digits + Enter) ── */
  function typed() {
    const raw = $('scan-digits').value;
    const msg = $('scan-digits-msg');
    if (!raw.replace(/\D/g, '')) return;
    const p = Barcode.problem(raw);
    msg.hidden = !p;
    if (p) {
      msg.innerHTML = `<span class="bc-check-tag">${t('bc.check_tag')}</span> ` + (p.kind === 'check' ? t('bc.err_check', { expected: p.expected }) : t('bc.err_length'));
      return;
    }
    // Cleared for the next code (a USB scanner types straight on); the result shows the code
    $('scan-digits').value = '';
    handleCode(Barcode.normalize(raw), 'typed');
  }

  /* ── Clicks ── */
  document.addEventListener('click', async ev => {
    const b = ev.target.closest('[data-act]');
    if (!b) return;
    const act_ = b.dataset.act;
    const byGame = id => current?.matches?.find(m => m.game_id === +id);
    switch (act_) {
      case 'next': next(); break;
      case 'to-type': setTab('type'); break;
      case 'undo': b.disabled = true; await doUndo(); if (undo) b.disabled = false; break;
      case 'add-copy': { const m = byGame(b.dataset.game); if (m) { b.disabled = true; addOwned(m); } break; }
      case 'wish-anyway': { const m = byGame(b.dataset.game); if (m) addWish(m, true); break; }
      case 'pick': {
        const i = +(panel.querySelector('input[name="scan-pick"]:checked')?.value ?? 0);
        const m = current.matches[i];
        if (m) act(m);
        break;
      }
      case 'neither': case 'link': openLink(); break;
      case 'show-system': {
        b.disabled = true;
        const res = await Barcode.api('show_system', { system_id: +b.dataset.sys });
        if (!res.ok) { toast(res.error || tRaw('common.error'), true); b.disabled = false; break; }
        current?.matches?.forEach(m => { if (m.system_id === +b.dataset.sys) m.system_visible = true; });
        toast(tRaw('bc.system_shown'));
        b.closest('.bc-note-warn')?.remove();
        // The game can be opened now: offer the button in the result that is on screen
        const m = current?.matches?.find(x => x.system_id === +b.dataset.sys);
        const actions = panel.querySelector('.bc-actions');
        if (m && actions && !actions.querySelector('a[href*="collection.php"]')) actions.insertAdjacentHTML('afterbegin', openButton(m));
        break;
      }
      case 'report': {
        b.outerHTML = `<div class="bc-report"><label for="bc-report-why" class="bc-label">${t('bc.report_why')}</label>
          <div class="bc-inline"><input type="text" id="bc-report-why" maxlength="200">
          <button type="button" class="btn-ghost btn-sm" data-act="report-send" data-id="${b.dataset.id}">${t('bc.report_send')}</button></div></div>`;
        $('bc-report-why').focus();
        break;
      }
      case 'report-send': {
        const res = await Barcode.api('flag', { ids: [+b.dataset.id], reason: $('bc-report-why').value.trim() });
        if (!res.ok) { toast(res.error || tRaw('common.error'), true); break; }
        current?.matches?.forEach(m => { if (m.barcode_id === +b.dataset.id) m.flagged = true; });
        b.closest('.bc-report').outerHTML = `<p class="sb-sub bc-reported">${t('bc.reported')}</p>`;
        break;
      }
    }
  });

  $('scan-tab-camera').addEventListener('click', () => setTab('camera'));
  $('scan-tab-type').addEventListener('click', () => setTab('type'));
  document.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => setMode(b.dataset.mode)));
  $('scan-lookup').addEventListener('click', typed);
  $('scan-digits').addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); typed(); } });
  $('scan-digits').addEventListener('input', () => { $('scan-digits-msg').hidden = true; });
  $('scan-torch').addEventListener('click', async () => {
    const on = $('scan-torch').getAttribute('aria-pressed') !== 'true';
    if (await cam?.setTorch(on)) { $('scan-torch').setAttribute('aria-pressed', on); Barcode.store('torch', on ? '1' : '0'); }
  });
  $('scan-camera-pick').addEventListener('change', ev => cam?.use(ev.target.value));
  // The page's own keys: Esc closes a result
  document.addEventListener('keydown', ev => {
    if (ev.key === 'Escape' && current && !document.querySelector('.modal-backdrop.open')) next();
  });

  setMode(mode);
  // Start: a code from the URL → typed tab and look it up; a page that can't use the camera → typed tab
  if (window.SCAN?.startCode) {
    setTab('type', false);
    history.replaceState(null, '', location.pathname);
    typed();
  } else if (BarcodeCamera.blocked() || Barcode.store('tab') === 'type') {
    setTab('type');
  } else {
    setTab('camera');
  }
})();
