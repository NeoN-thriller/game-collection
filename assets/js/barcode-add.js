/* ═══════════════════════════════════════════
   ADD BARCODES (barcode_add.php). Needs barcode.js.
   One system's games as a long list. Pick a game, then keep scanning: every valid code is linked to that game
   (api/barcode.php "link" with ask_other) and appears next to it. A code that is already on another game asks first.
   Codes the user added in the last BARCODE_UNDO_HOURS hours have a ✕ (undo_own).
   Input: the digits field (typing, or a USB / Bluetooth scanner: digits + Enter) and optionally the camera.
   ↑ / ↓ in the digits field move to the previous / next game.
   ═══════════════════════════════════════════ */
(() => {
  const e = Barcode.esc;
  const $ = id => document.getElementById(id);
  const list = $('ba-list'), input = $('ba-code'), status = $('ba-status');
  const st = { sys: 0, games: [], byId: new Map(), selected: null, pending: null, busy: false, seq: 0, cam: null };

  function toast(msg, err = false) {
    const el = $('toast');
    el.textContent = msg;
    el.style.borderColor = err ? 'var(--red)' : 'var(--accent2)';
    el.style.color = err ? 'var(--red)' : 'var(--accent)';
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 2500);
  }

  /** The line under the digits field: kind = ok | same | err | info | ask. */
  function say(kind, html) {
    status.className = 'ba-status ba-status-' + kind;
    status.innerHTML = html;
  }

  /**
   * The result of one read, unmistakable: a coloured card with the code in large digits that flashes, the code's chip in
   * the list flashes, and (when sound is on) a beep. kind: ok (linked) | same (already on this game) | err.
   */
  function result(kind, code, text) {
    const icon = { ok: '✓', same: '↺', err: '✕' }[kind];
    status.className = 'ba-status ba-result ba-result-' + kind;
    status.innerHTML = `<span class="ba-result-icon" aria-hidden="true">${icon}</span>
      <span class="ba-result-body">${code ? `<span class="ba-result-code">${e(Barcode.format(code))}</span>` : ''}<span>${text}</span></span>`;
    void status.offsetWidth;   // restart the flash, also for the same result twice in a row
    status.classList.add('ba-flash');
    if (code) {
      const chip = list.querySelector(`.ba-row[data-id="${st.selected}"] .ba-code[data-code="${code}"]`);
      if (chip) { chip.classList.remove('ba-flash'); void chip.offsetWidth; chip.classList.add('ba-flash'); }
    }
    beep(kind);
  }

  /* ── Sound (optional, remembered on this device) ── */
  let audio = null;
  const soundOn = () => $('ba-sound').getAttribute('aria-pressed') === 'true';
  // Browsers only play sound after the user did something on the page: get it ready on the first click or key
  const unlock = () => {
    try { audio ??= new (window.AudioContext || window.webkitAudioContext)(); audio.resume(); } catch (err) {}
  };
  document.addEventListener('pointerdown', unlock, { once: true });
  document.addEventListener('keydown', unlock, { once: true });
  /** ok: one high beep · same: two short mid beeps · err: one low buzz. */
  function beep(kind) {
    if (!soundOn()) return;
    try {
      audio ??= new (window.AudioContext || window.webkitAudioContext)();
      if (audio.state === 'suspended') audio.resume();
      const tones = { ok: [[1320, 0, .12]], same: [[880, 0, .07], [880, .11, .07]], err: [[220, 0, .25]] }[kind] || [];
      tones.forEach(([freq, at, len]) => {
        const o = audio.createOscillator(), g = audio.createGain();
        o.type = kind === 'err' ? 'square' : 'sine';
        o.frequency.value = freq;
        const t0 = audio.currentTime + at;
        g.gain.setValueAtTime(0.0001, t0);
        g.gain.exponentialRampToValueAtTime(0.25, t0 + 0.01);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + len);
        o.connect(g).connect(audio.destination);
        o.start(t0); o.stop(t0 + len + 0.02);
      });
    } catch (err) { /* no sound in this browser */ }
  }

  /**
   * The cursor goes back to the digits field, so a USB scanner keeps typing there. Not on touch screens: there it would
   * open the on-screen keyboard, which hides half the page (and unpins the panel); phones scan with the camera.
   */
  const touchOnly = window.matchMedia('(pointer: coarse)').matches && !window.matchMedia('(any-pointer: fine)').matches;
  function focusInput() {
    if (!touchOnly) input.focus();
  }

  // The rows scroll into view below the pinned panel, not under it
  const panel = $('ba-panel');
  const panelHeight = () => document.documentElement.style.setProperty('--ba-panel-h', panel.offsetHeight + 'px');
  if (window.ResizeObserver) new ResizeObserver(panelHeight).observe(panel);
  panelHeight();

  /* ── Loading and drawing the list ── */
  async function load() {
    const my = ++st.seq;
    st.selected = null;
    st.pending = null;
    list.innerHTML = `<li class="ba-empty">${t('bc.add_loading')}</li>`;
    current();
    const res = await Barcode.api('system_games', { system_id: st.sys });
    if (my !== st.seq) return;
    if (!res.ok) { list.innerHTML = `<li class="ba-empty bc-err-text">${e(res.error || tRaw('common.error'))}</li>`; return; }
    st.games = res.games;
    st.byId = new Map(res.games.map(g => [g.id, g]));
    draw();
  }

  /** A game is shown when it matches the search and (with "only without a barcode") has none, or is the one being scanned. */
  function visible(g) {
    const q = $('ba-q').value.trim().toLowerCase();
    if (q && !q.split(/\s+/).every(w => g.label.toLowerCase().includes(w))) return false;
    return !$('ba-missing').checked || !g.codes.length || g.id === st.selected;
  }

  function rowHtml(g) {
    const codes = g.codes.map(c => `<span class="ba-code${c.new ? ' ba-code-new' : ''}" data-code="${e(c.barcode)}">${e(c.display)}${c.mine
      ? `<button type="button" class="ba-undo" data-undo="${c.id}" aria-label="${e(tRaw('bc.add_undo_aria', { code: c.display }))}">✕</button>` : ''}</span>`).join('');
    return `<span class="ba-num">#${String(g.num).padStart(3, '0')}</span>
      <span class="ba-title">${e(g.label)}</span>
      <span class="ba-codes">${codes || `<span class="ba-none">${t('bc.column_none')}</span>`}</span>`;
  }

  function draw() {
    const shown = st.games.filter(visible);
    list.innerHTML = shown.length
      ? shown.map(g => `<li class="ba-row${g.id === st.selected ? ' ba-sel' : ''}${g.codes.length ? ' ba-has' : ''}" data-id="${g.id}" tabindex="-1" aria-selected="${g.id === st.selected}">${rowHtml(g)}</li>`).join('')
      : `<li class="ba-empty">${t(st.games.length ? 'bc.none_found' : 'bc.add_no_games')}</li>`;
    const without = st.games.filter(g => !g.codes.length).length;
    $('ba-count').textContent = tRaw('bc.add_count', { n: fmtNum(st.games.length), m: fmtNum(without) });
  }

  function redrawRow(g) {
    const li = list.querySelector(`.ba-row[data-id="${g.id}"]`);
    if (!li) return;
    li.innerHTML = rowHtml(g);
    li.classList.toggle('ba-has', g.codes.length > 0);
    const without = st.games.filter(x => !x.codes.length).length;
    $('ba-count').textContent = tRaw('bc.add_count', { n: fmtNum(st.games.length), m: fmtNum(without) });
  }

  /** The "Scanning for" line above the digits field. */
  function current() {
    const g = st.byId.get(st.selected);
    $('ba-current').innerHTML = g ? `${t('bc.add_scanning_for')} <strong>${e(g.label)}</strong>` : t('bc.add_pick_first');
  }

  /* ── Lookup window: the chosen link (as in the edit drawer) for the selected game ── */
  let lookupWin = null;
  function lookupUrl(g) {
    const kind = $('ba-open').value;
    const title = g.title.replace(/['"]/g, '');
    const q = encodeURIComponent(title);
    if (kind === 'price') return g.price_link || '';   // only games with a price link
    if (kind === 'wiki')  return `https://wikipedia.org/w/index.php?search=${q}`;
    if (kind.startsWith('site:')) {
      const site = window.BA.sites[+kind.slice(5)];
      const sys = window.BA.systems[st.sys] || {};
      return site ? site.url_template.replace('{system}', encodeURIComponent((sys.short || '').toLowerCase()))
                                     .replace('{title}', q).replace('{region}', sys.region || '') : '';
    }
    return '';
  }
  /**
   * One window for all lookups. Opened once (that takes the focus away, click back on this page once); after that it is
   * only pointed at the next game, which leaves the focus here, so a USB scanner keeps typing into this page.
   */
  function openLookup(g) {
    const url = lookupUrl(g);
    if (!/^https?:\/\//i.test(url)) {
      if ($('ba-open').value === 'price') say('info', t('bc.add_no_price_link'));
      return;
    }
    if (lookupWin && !lookupWin.closed) {
      try { lookupWin.location.href = url; return; } catch (err) { /* fall through: open it again */ }
    }
    lookupWin = window.open(url, 'gc_lookup', 'popup,width=1100,height=850');
    if (!lookupWin) say('err', t('bc.add_popup_blocked'));
  }

  function select(id, focus = true) {
    const before = st.selected;
    st.selected = id;
    st.pending = null;
    list.querySelectorAll('.ba-sel').forEach(li => { li.classList.remove('ba-sel'); li.setAttribute('aria-selected', 'false'); });
    const li = list.querySelector(`.ba-row[data-id="${id}"]`);
    if (li) { li.classList.add('ba-sel'); li.setAttribute('aria-selected', 'true'); li.scrollIntoView({ block: 'nearest' }); }
    current();
    say('info', '');
    if (id !== before && st.byId.get(id)) openLookup(st.byId.get(id));
    // "Only without a barcode": the game left behind drops out now that it has a code
    if ($('ba-missing').checked && before && st.byId.get(before)?.codes.length) {
      list.querySelector(`.ba-row[data-id="${before}"]`)?.remove();
    }
    if (focus) focusInput();
  }

  /** ↑ / ↓: the previous / next game in the list as shown. */
  function step(dir) {
    const rows = [...list.querySelectorAll('.ba-row')];
    if (!rows.length) return;
    const i = rows.findIndex(li => +li.dataset.id === st.selected);
    const next = rows[i < 0 ? 0 : Math.min(rows.length - 1, Math.max(0, i + dir))];
    select(+next.dataset.id);
  }

  /* ── A code came in ── */
  async function submit(raw, fromCamera = false) {
    const g = st.byId.get(st.selected);
    if (!g) { say('err', t('bc.add_pick_first')); return; }
    if (st.pending) { say('ask', status.innerHTML); return; }   // answer the question first
    const p = Barcode.problem(raw);
    if (p) {
      result('err', null, `${e(String(raw).replace(/\D+/g, ''))} · ` + (p.kind === 'check' ? t('bc.err_check', { expected: p.expected }) : t('bc.err_length')));
      return;
    }
    const code = Barcode.normalize(raw);
    input.value = '';
    // Read again (camera still on the case, or scanned twice): say so clearly, nothing is added
    if (g.codes.some(c => c.barcode === code)) { result('same', code, t('bc.add_same', { title: g.label })); return; }
    await link(g, code, false);
  }

  async function link(g, code, confirm) {
    if (st.busy) return;
    st.busy = true;
    const res = await Barcode.api('link', { code, game_ids: [g.id], ask_other: true, confirm_cross_system: confirm });
    st.busy = false;
    if (res.status === 409 && res.conflict) {
      const o = res.games[0] || {};
      st.pending = { gameId: g.id, code };
      say('ask', `${t('bc.add_elsewhere', { code: Barcode.format(code), title: o.label || '', system: o.system || '' })}
        <span class="ba-ask"><button type="button" class="btn btn-sm" data-ask="yes">${t('bc.add_link_too')}</button>
        <button type="button" class="btn-ghost btn-sm" data-ask="skip">${t('bc.skip_short')}</button></span>`);
      status.querySelector('[data-ask="yes"]').focus();
      beep('same');
      return;
    }
    if (!res.ok) { result('err', code, e(res.error || tRaw('common.error'))); return; }
    (res.rows || []).forEach(r => g.codes.push({ id: r.id, barcode: res.code, display: res.display, mine: true, new: true }));
    if (!(res.rows || []).length) g.codes.push({ id: 0, barcode: res.code, display: res.display, mine: false, new: true });
    redrawRow(g);
    result('ok', res.code, t('bc.add_added_to', { title: g.label }));
  }

  async function undo(rowId) {
    const res = await Barcode.api('undo_own', { ids: [rowId] });
    if (!res.ok) { toast(res.error || tRaw('common.error'), true); return; }
    for (const g of st.games) {
      const i = g.codes.findIndex(c => c.id === rowId);
      if (i >= 0) { g.codes.splice(i, 1); redrawRow(g); break; }
    }
    say('info', t('bc.removed'));
    focusInput();
  }

  /* ── Camera (optional, remembered on this device) ── */
  async function camera(on) {
    $('ba-cam-toggle').setAttribute('aria-pressed', on);
    $('ba-cam').hidden = !on;
    Barcode.store('add_cam', on ? '1' : '0');
    if (!on) { st.cam?.stop(); st.cam = null; return; }
    if (st.cam) return;
    $('ba-cam-msg').hidden = true;
    st.cam = await BarcodeCamera.start({
      video: $('ba-video'), frame: $('ba-frame'),
      onCode: code => submit(code, true),
      onError: key => { const m = $('ba-cam-msg'); m.hidden = false; m.textContent = tRaw('bc.' + key); },
    });
    if (st.cam && $('ba-cam').hidden) { st.cam.stop(); st.cam = null; }
  }

  /* ── Wiring ── */
  list.addEventListener('click', ev => {
    const u = ev.target.closest('[data-undo]');
    if (u) { undo(+u.dataset.undo); return; }
    const li = ev.target.closest('.ba-row');
    if (li) select(+li.dataset.id);
  });
  status.addEventListener('click', ev => {
    const b = ev.target.closest('[data-ask]');
    if (!b || !st.pending) return;
    const p = st.pending;
    st.pending = null;
    if (b.dataset.ask === 'yes') { const g = st.byId.get(p.gameId); if (g) link(g, p.code, true); }
    else say('info', t('bc.add_skipped'));
    focusInput();
  });
  input.addEventListener('keydown', ev => {
    if (ev.key === 'Enter')     { ev.preventDefault(); if (input.value.trim()) submit(input.value); }
    else if (ev.key === 'ArrowDown') { ev.preventDefault(); step(1); }
    else if (ev.key === 'ArrowUp')   { ev.preventDefault(); step(-1); }
  });
  $('ba-sys').addEventListener('change', ev => { st.sys = +ev.target.value; Barcode.store('add_sys', String(st.sys)); load(); });
  let qTimer = null;
  $('ba-q').addEventListener('input', () => { clearTimeout(qTimer); qTimer = setTimeout(draw, 200); });
  $('ba-missing').addEventListener('change', () => { Barcode.store('add_missing', $('ba-missing').checked ? '1' : '0'); draw(); });
  $('ba-sound').addEventListener('click', () => {
    const on = !soundOn();
    $('ba-sound').setAttribute('aria-pressed', on);
    Barcode.store('add_sound', on ? '1' : '0');
    if (on) beep('ok');   // a sample, and browsers only allow sound after a click
    focusInput();
  });
  $('ba-open').addEventListener('change', ev => { Barcode.store('add_open', ev.target.value); focusInput(); });
  $('ba-cam-toggle').addEventListener('click', () => camera($('ba-cam-toggle').getAttribute('aria-pressed') !== 'true'));

  // Start: the system and filter used last on this device
  const lastSys = Barcode.store('add_sys');
  if (lastSys && [...$('ba-sys').options].some(o => o.value === lastSys)) $('ba-sys').value = lastSys;
  $('ba-missing').checked = Barcode.store('add_missing') === '1';
  $('ba-sound').setAttribute('aria-pressed', Barcode.store('add_sound') !== '0');   // on unless switched off
  const lastOpen = Barcode.store('add_open');
  if (lastOpen && [...$('ba-open').options].some(o => o.value === lastOpen)) $('ba-open').value = lastOpen;
  st.sys = +$('ba-sys').value;
  load();
  if (Barcode.store('add_cam') === '1' && !BarcodeCamera.blocked()) camera(true);
})();
