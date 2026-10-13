/* ═══════════════════════════════════════════
   BARCODES — shared by scan.php, the edit drawer (barcodes-drawer.js), Settings › Barcodes and the header search.
   Needs appScript() with the "bc" texts. The page sets window.BARCODE_CONFIG before loading this file:
     { base, isAdmin, canEdit, trackVariants, variants: [labels] }

   Barcode        normalize / format / formatName / expectedCheck, api(action, body)
   BarcodeCamera  start({ video, frame, onCode, onError }) → camera controller (BarcodeDetector, or the bundled
                  pure-JavaScript ZXing reader in assets/js/vendor/zxing.min.js for Safari and Firefox)
   Barcode.capture()  a small camera dialog that resolves with one scanned code (or null)
   BarcodeLink    open({ code, gameId, addLabel, fromAdmin, onSaved }) → the "Link barcode" dialog
   ═══════════════════════════════════════════ */
const Barcode = (() => {
  const config = Object.assign({ base: '', isAdmin: false, canEdit: true, trackVariants: false, variants: [] }, window.BARCODE_CONFIG || {});

  /** Digits only. 12 digits (UPC-A) become 13 with a leading 0. null unless EAN-13 / EAN-8 with a correct check digit (same as barcodes.php). */
  function normalize(raw) {
    let d = String(raw ?? '').replace(/\D+/g, '');
    if (d.length === 12) d = '0' + d;
    const n = d.length;
    if (n !== 8 && n !== 13) return null;
    return expectedCheck(d.slice(0, -1)) === +d[n - 1] ? d : null;
  }

  /** The check digit for the first 7 or 12 digits of an EAN-8 / EAN-13. */
  function expectedCheck(body) {
    const n = body.length + 1;
    let sum = 0;
    for (let i = 0; i < n - 1; i++) sum += +body[i] * ((n - 1 - i) % 2 === 1 ? 3 : 1);
    return (10 - sum % 10) % 10;
  }

  /**
   * Why typed digits are not a barcode: {kind: 'length'} or {kind: 'check', expected} (the digit it should end with), or null when valid.
   * A 12-digit UPC-A is checked as its 13-digit form.
   */
  function problem(raw) {
    let d = String(raw ?? '').replace(/\D+/g, '');
    if (d.length === 12) d = '0' + d;
    if (d.length !== 8 && d.length !== 13) return { kind: 'length' };
    const exp = expectedCheck(d.slice(0, -1));
    return exp === +d[d.length - 1] ? null : { kind: 'check', expected: exp };
  }

  const format = c => c.length === 13 ? `${c[0]} ${c.slice(1, 7)} ${c.slice(7)}` : c.length === 8 ? `${c.slice(0, 4)} ${c.slice(4)}` : c;
  const formatName = c => c.length === 8 ? 'EAN-8' : 'EAN-13';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /**
   * POST api/barcode.php (or api/barcode_admin.php with admin = true). Resolves with the JSON plus its HTTP status.
   * signal: an AbortSignal; a cancelled request resolves with {ok: false, aborted: true}.
   */
  async function api(action, body = {}, admin = false, signal = null) {
    try {
      const r = await fetch(`${config.base}/api/barcode${admin ? '_admin' : ''}.php`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...body }), signal,
      });
      const text = await r.text();
      let data;
      try { data = JSON.parse(text); } catch (e) {
        // Not JSON: a PHP error page, a login page or a proxy error. Say what came back.
        const msg = text.replace(/<(script|style)[\s\S]*?<\/(script|style)>/gi, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
        data = { ok: false, error: tRaw('bc.err_http', { status: r.status }) + (msg ? ' ' + msg : '') };
      }
      return { status: r.status, ...data };
    } catch (e) {
      if (e.name === 'AbortError') return { ok: false, status: 0, aborted: true };
      return { ok: false, status: 0, error: tRaw('bc.err_network', { error: e.message || '' }) };
    }
  }

  function store(key, value) {
    try { if (value === undefined) return localStorage.getItem('bc_' + key); localStorage.setItem('bc_' + key, value); } catch (e) {}
    return null;
  }

  return { config, normalize, expectedCheck, problem, format, formatName, esc, api, store };
})();

/* ── CAMERA ─────────────────────────────── */
const BarcodeCamera = (() => {
  const FORMATS = ['ean_13', 'ean_8', 'upc_a'];
  let readerKind = null;   // 'native' | 'zxing' | 'none'
  let zxingLoad = null;

  /** Which reader this browser uses: the built-in BarcodeDetector when it reads EAN-13, else the bundled one. */
  async function reader() {
    if (readerKind) return readerKind;
    try {
      if ('BarcodeDetector' in window) {
        const f = await window.BarcodeDetector.getSupportedFormats();
        if (f.includes('ean_13')) return (readerKind = 'native');
      }
    } catch (e) {}
    zxingLoad ??= new Promise(res => {
      if (window.ZXing) return res(true);
      const s = document.createElement('script');
      s.src = `${Barcode.config.base}/assets/js/vendor/zxing.min.js`;
      s.onload = () => res(!!window.ZXing);
      s.onerror = () => res(false);
      document.head.appendChild(s);
    });
    return (readerKind = (await zxingLoad) ? 'zxing' : 'none');
  }

  /** Why the camera can't be used, as a bc.err_* key; null when it might work. */
  function blocked() {
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) return 'err_insecure';
    const pol = document.permissionsPolicy || document.featurePolicy;
    if (pol?.allowsFeature && !pol.allowsFeature('camera')) return 'err_blocked_policy';
    return null;
  }

  function errorKey(e) {
    const b = blocked();
    if (b) return b;
    switch (e?.name) {
      case 'NotAllowedError': case 'SecurityError': return 'err_permission';
      case 'NotFoundError': case 'OverconstrainedError': case 'DevicesNotFoundError': return 'err_no_camera';
      case 'NotReadableError': case 'TrackStartError': case 'AbortError': return 'err_in_use';
      default: return 'err_camera';
    }
  }

  /**
   * Starts the camera into opts.video and calls opts.onCode(code) for every valid code (the same code again within
   * 3 seconds is ignored). opts.frame: the element marking the scan area (decoding is limited to it).
   * Returns a controller: pause(), resume(), stop(), setTorch(on), torch (supported), devices(), use(deviceId).
   */
  async function start(opts) {
    const b = blocked();
    if (b) { opts.onError?.(b); return null; }
    const kind = await reader();
    if (kind === 'none') { opts.onError?.('err_no_reader'); return null; }

    let stream = null, timer = null, paused = false, stopped = false, last = { code: '', at: 0 }, pending = '';
    let deviceId = Barcode.store('camera') || '';
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    let detector = null, zx = null;
    if (kind === 'native') detector = new window.BarcodeDetector({ formats: FORMATS });
    else {
      const Z = window.ZXing;
      const hints = new Map();
      hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.EAN_13, Z.BarcodeFormat.EAN_8, Z.BarcodeFormat.UPC_A]);
      hints.set(Z.DecodeHintType.TRY_HARDER, true);
      zx = new Z.MultiFormatReader();
      zx.setHints(hints);
    }

    async function open() {
      const video = deviceId
        ? { deviceId: { exact: deviceId }, width: { ideal: 1280 }, height: { ideal: 720 } }
        : { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } };
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video, audio: false });
      } catch (e) {
        if (deviceId && (e.name === 'OverconstrainedError' || e.name === 'NotFoundError')) {   // the remembered camera is gone
          deviceId = ''; Barcode.store('camera', '');
          return open();
        }
        throw e;
      }
      opts.video.srcObject = stream;
      opts.video.muted = true;
      opts.video.setAttribute('playsinline', '');
      await opts.video.play().catch(() => {});
      ctl.torch = !!stream.getVideoTracks()[0]?.getCapabilities?.().torch;
      opts.onReady?.(ctl);
    }

    function close() {
      clearTimeout(timer);
      timer = null;
      stream?.getTracks().forEach(t => t.stop());
      stream = null;
      opts.video.srcObject = null;
    }

    /** The part of the video inside the frame element, as a canvas. */
    function grab() {
      const v = opts.video, vw = v.videoWidth, vh = v.videoHeight;
      if (!vw || !vh) return false;
      let sx = 0, sy = 0, sw = vw, sh = vh;
      if (opts.frame) {
        const vr = v.getBoundingClientRect(), fr = opts.frame.getBoundingClientRect();
        // object-fit: cover — map screen pixels to video pixels
        const scale = Math.max(vr.width / vw, vr.height / vh);
        const offX = (vw * scale - vr.width) / 2, offY = (vh * scale - vr.height) / 2;
        sx = Math.max(0, (fr.left - vr.left + offX) / scale);
        sy = Math.max(0, (fr.top - vr.top + offY) / scale);
        sw = Math.min(vw - sx, fr.width / scale);
        sh = Math.min(vh - sy, fr.height / scale);
        if (sw < 40 || sh < 20) { sx = 0; sy = 0; sw = vw; sh = vh; }
      }
      const w = Math.min(960, Math.round(sw)), h = Math.round(sh * w / sw);
      canvas.width = w; canvas.height = h;
      ctx.drawImage(v, sx, sy, sw, sh, 0, 0, w, h);
      return true;
    }

    async function decode() {
      if (kind === 'native') {
        const found = await detector.detect(canvas);
        return found.map(f => Barcode.normalize(f.rawValue)).find(Boolean) || null;
      }
      try {
        const Z = window.ZXing;
        const bmp = new Z.BinaryBitmap(new Z.HybridBinarizer(new Z.HTMLCanvasElementLuminanceSource(canvas)));
        const code = Barcode.normalize(zx.decodeWithState(bmp).getText());   // decode() would drop the hints
        zx.reset();
        if (!code) return null;
        // The bundled reader misreads more often: the same code twice in a row counts
        if (code !== pending) { pending = code; return null; }
        pending = '';
        return code;
      } catch (e) { zx.reset(); return null; }
    }

    async function tick() {
      timer = null;
      if (stopped || !stream) return;
      if (!paused && grab()) {
        const code = await decode().catch(() => null);
        if (code && !paused && !stopped) {
          const now = Date.now();
          if (code !== last.code || now - last.at > 3000) {
            last = { code, at: now };
            navigator.vibrate?.(40);
            opts.onCode(code);
          }
        }
      }
      if (!stopped && stream && timer === null) timer = setTimeout(tick, 100);   // about 10 reads a second (one loop only)
    }

    // The camera goes off when the tab is hidden or the page is left, and comes back with the tab
    const onVis = () => {
      if (stopped) return;
      if (document.hidden) close();
      else if (!stream) open().then(() => { timer ??= setTimeout(tick, 100); }).catch(e => opts.onError?.(errorKey(e)));
    };
    const onHide = () => ctl.stop();

    const ctl = {
      kind, torch: false,
      pause()  { paused = true; },
      resume() { paused = false; pending = ''; },
      stop() {
        stopped = true;
        close();
        document.removeEventListener('visibilitychange', onVis);
        window.removeEventListener('pagehide', onHide);
      },
      async setTorch(on) {
        try { await stream?.getVideoTracks()[0]?.applyConstraints({ advanced: [{ torch: !!on }] }); return true; } catch (e) { return false; }
      },
      async devices() {
        try { return (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'videoinput'); } catch (e) { return []; }
      },
      currentDevice: () => stream?.getVideoTracks()[0]?.getSettings?.().deviceId || deviceId,
      async use(id) {
        deviceId = id || '';
        Barcode.store('camera', deviceId);
        close();
        try { await open(); timer ??= setTimeout(tick, 100); } catch (e) { opts.onError?.(errorKey(e)); }
      },
    };

    try { await open(); } catch (e) { opts.onError?.(errorKey(e)); return null; }
    document.addEventListener('visibilitychange', onVis);
    window.addEventListener('pagehide', onHide);
    timer = setTimeout(tick, 100);
    return ctl;
  }

  return { start, reader, blocked };
})();

/* ── CAPTURE: one code from the camera (the drawer's "Scan" button) ── */
Barcode.capture = function () {
  return new Promise(resolve => {
    const bd = document.createElement('div');
    bd.className = 'modal-backdrop open bc-capture';
    bd.setAttribute('role', 'dialog');
    bd.setAttribute('aria-modal', 'true');
    bd.innerHTML = `<div class="modal">
      <div class="modal-header"><h3>${t('bc.title')}</h3>
        <button class="btn-icon bc-x" type="button" aria-label="${t('common.close')}">✕</button></div>
      <div class="modal-body">
        <div class="bc-view"><video playsinline muted></video><div class="bc-frame"><span class="bc-line"></span></div></div>
        <p class="ga-desc bc-cap-msg" style="margin:0">${t('bc.help_camera')}</p>
      </div></div>`;
    document.body.appendChild(bd);
    let cam = null;
    const done = code => { cam?.stop(); bd.remove(); document.removeEventListener('keydown', onKey); resolve(code); };
    const onKey = e => { if (e.key === 'Escape') done(null); };
    document.addEventListener('keydown', onKey);
    bd.querySelector('.bc-x').onclick = () => done(null);
    bd.addEventListener('click', e => { if (e.target === bd) done(null); });
    BarcodeCamera.start({
      video: bd.querySelector('video'), frame: bd.querySelector('.bc-frame'),
      onCode: code => done(code),
      onError: key => { const m = bd.querySelector('.bc-cap-msg'); m.textContent = tRaw('bc.' + key); m.classList.add('bc-err-text'); },
    }).then(c => { cam = c; if (!bd.isConnected) c?.stop(); });
  });
};

/* ── LINK DIALOG ────────────────────────── */
const BarcodeLink = (() => {
  const e = Barcode.esc;
  let st = null, bd = null, searchTimer = null;

  function build() {
    bd = document.createElement('div');
    bd.className = 'modal-backdrop bc-link';
    bd.id = 'bc-link';
    bd.setAttribute('role', 'dialog');
    bd.setAttribute('aria-modal', 'true');
    bd.setAttribute('aria-labelledby', 'bcl-title');
    bd.innerHTML = `<div class="modal">
      <div class="modal-header"><h3 id="bcl-title">${t('bc.link_title')}</h3>
        <button class="btn-icon" type="button" data-bcl="close" aria-label="${t('common.close')}">✕</button></div>
      <div class="modal-body">
        <div class="bc-code-card" id="bcl-code-card"></div>
        <div class="field" id="bcl-code-field" hidden>
          <label for="bcl-code">${t('bc.digits')}</label>
          <div class="bc-inline"><input type="text" id="bcl-code" inputmode="numeric" autocomplete="off" class="bc-digits-in">
            <button class="btn-ghost btn-sm" type="button" data-bcl="scan">${t('bc.scan')}</button></div>
          <p class="bc-check" id="bcl-code-msg" hidden></p>
        </div>
        <div class="field" id="bcl-game-field">
          <label for="bcl-q">${t('bc.which_game')}</label>
          <input type="search" id="bcl-q" autocomplete="off" placeholder="${t('bc.search_ph')}">
          <div class="bc-radio-list" id="bcl-results" role="radiogroup" aria-label="${t('bc.which_game')}"></div>
        </div>
        <div class="field" id="bcl-ed-field" hidden>
          <span class="bc-label">${t('bc.belongs_to')}</span>
          <strong class="bc-game-name" id="bcl-game-name"></strong>
          <div id="bcl-editions" class="bc-checks"></div>
        </div>
        <div class="field">
          <label for="bcl-variant">${t('bc.variant_opt')}</label>
          <span id="bcl-variant-box"></span>
          <p class="ga-desc" style="margin:4px 0 0">${t('bc.variant_help')}</p>
        </div>
        <div class="field">
          <label for="bcl-note">${t('bc.note_opt')}</label>
          <input type="text" id="bcl-note" maxlength="200">
        </div>
        <div class="bc-conflict" id="bcl-conflict" hidden></div>
        <p class="bc-err-text" id="bcl-err" hidden></p>
      </div>
      <div class="modal-footer bc-link-foot">
        <span class="ga-desc" style="margin:0 auto 0 0">${t('bc.saved_for_all')}</span>
        <button class="btn-ghost btn-sm" type="button" data-bcl="save">${t('bc.save_only')}</button>
        <button class="btn btn-sm" type="button" data-bcl="save-add" hidden></button>
      </div></div>`;
    document.body.appendChild(bd);
    bd.addEventListener('click', onClick);
    bd.querySelector('#bcl-q').addEventListener('input', ev => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => search(ev.target.value), 300);
    });
    bd.querySelector('#bcl-code').addEventListener('input', codeChanged);
    bd.addEventListener('change', ev => {
      if (ev.target.name === 'bcl-game') pickGame(+ev.target.value);
      else updateButtons();
    });
    document.addEventListener('keydown', ev => { if (ev.key === 'Escape' && bd.classList.contains('open')) close(); });
  }

  function codeCard() {
    const card = bd.querySelector('#bcl-code-card');
    if (!st.code) { card.hidden = true; return; }
    card.hidden = false;
    card.innerHTML = `<span class="bc-label">${t('bc.scanned')}</span>
      <div class="bc-big">${e(Barcode.format(st.code))}</div>
      <span class="chip">${e(Barcode.formatName(st.code))}</span> <span class="chip chip-y">✓ ${t('bc.check_ok')}</span>`;
  }

  function codeChanged() {
    const raw = bd.querySelector('#bcl-code').value;
    const msg = bd.querySelector('#bcl-code-msg');
    const p = raw.replace(/\D/g, '') ? Barcode.problem(raw) : null;
    st.code = p || !raw.trim() ? '' : Barcode.normalize(raw);
    msg.hidden = !p;
    if (p) msg.innerHTML = p.kind === 'check'
      ? `<span class="bc-check-tag">${t('bc.check_tag')}</span> ${t('bc.err_check', { expected: p.expected })}`
      : `<span class="bc-check-tag">${t('bc.check_tag')}</span> ${t('bc.err_length')}`;
    updateButtons();
  }

  function variantBox() {
    const box = bd.querySelector('#bcl-variant-box');
    const c = Barcode.config;
    if (c.trackVariants && c.variants.length && !c.isAdmin) {
      box.innerHTML = `<select id="bcl-variant"><option value="">${t('bc.variant_any')}</option>${c.variants.map(v => `<option>${e(v)}</option>`).join('')}</select>`;
    } else {
      box.innerHTML = `<input type="text" id="bcl-variant" maxlength="100" placeholder="${t('bc.variant_any')}">`;
    }
  }

  let searchCtrl = null, lastQ = '';
  async function search(q) {
    const list = bd.querySelector('#bcl-results');
    q = q.trim();
    if (q === lastQ) return;
    lastQ = q;
    searchCtrl?.abort();   // only the newest search counts: an older, slower reply never overwrites it
    if (q.length < 2) { list.innerHTML = ''; return; }
    searchCtrl = new AbortController();
    list.setAttribute('aria-busy', 'true');   // the old results stay until the new ones are in (no flicker)
    const res = await Barcode.api('search_games', { q }, false, searchCtrl.signal);
    if (res.aborted) return;
    list.removeAttribute('aria-busy');
    if (!res.ok) { list.innerHTML = `<p class="bc-err-text">${e(res.error)}</p>`; return; }
    st.results = res.games;
    list.innerHTML = res.games.length ? res.games.map(g => `<label class="bc-radio">
        <input type="radio" name="bcl-game" value="${g.game_id}"${st.game?.game_id === g.game_id ? ' checked' : ''}>
        <span><strong>${e(g.label)}</strong><br><span class="sb-sub">${e(g.system_name)} · ${g.editions.length > 1 ? t('bc.n_editions', { n: g.editions.length }) + ' · ' : ''}${g.barcode_count ? tn('bc.n_barcodes', g.barcode_count) : t('bc.no_barcode_yet')}</span></span>
      </label>`).join('') : `<p class="ga-desc" style="margin:6px 0 0">${t('bc.no_games')}</p>`;
  }

  function pickGame(id, editions) {
    const g = (st.results || []).find(x => x.game_id === id) || st.game;
    if (!g || g.game_id !== id) return;
    st.game = g;
    const eds = editions || g.editions;
    const box = bd.querySelector('#bcl-editions');
    bd.querySelector('#bcl-ed-field').hidden = false;
    bd.querySelector('#bcl-game-name').textContent = `${g.title || g.label}${g.system ? ' · ' + g.system : ''}`;
    box.innerHTML = eds.length > 1
      ? eds.map(ed => `<label class="bc-check-row"><input type="checkbox" value="${ed.game_id}"${ed.game_id === id ? ' checked' : ''}> ${e(ed.label)}</label>`).join('')
      : `<label class="bc-check-row bc-disabled"><input type="checkbox" value="${id}" checked disabled> ${t('bc.one_edition')}</label>`;
    updateButtons();
  }

  function chosenIds() {
    return [...bd.querySelectorAll('#bcl-editions input:checked')].map(i => +i.value);
  }

  function updateButtons() {
    const ok = !!st.code && chosenIds().length > 0;
    bd.querySelectorAll('[data-bcl="save"],[data-bcl="save-add"]').forEach(b => { b.disabled = !ok; });
  }

  async function save(andAdd, confirmCross = false) {
    const ids = chosenIds();
    if (!st.code || !ids.length) return;
    const err = bd.querySelector('#bcl-err');
    err.hidden = true;
    const res = await Barcode.api('link', {
      code: st.code, game_ids: ids, variant: bd.querySelector('#bcl-variant').value.trim(),
      note: bd.querySelector('#bcl-note').value.trim(), confirm_cross_system: confirmCross, from_admin: !!st.fromAdmin,
    });
    if (res.status === 409 && res.conflict === 'cross_system') {
      const box = bd.querySelector('#bcl-conflict');
      const g = res.games[0] || {};
      box.hidden = false;
      box.innerHTML = `<p>${t('bc.conflict', { title: g.label || '', system: g.system || '' })}</p>
        <div class="bc-actions"><button class="btn btn-sm" type="button" data-bcl="cross-yes" data-add="${andAdd ? 1 : 0}">${t('bc.link_as_well')}</button>
        <button class="btn-ghost btn-sm" type="button" data-bcl="cross-no">${t('common.cancel')}</button></div>`;
      return;
    }
    if (!res.ok) { err.textContent = res.error || tRaw('common.error'); err.hidden = false; return; }
    const onSaved = st.onSaved;
    const game = st.game;
    close();
    onSaved?.({ code: res.code, gameIds: ids, game, andAdd, variant: '' });
  }

  async function onClick(ev) {
    const b = ev.target.closest('[data-bcl]');
    if (!b) { if (ev.target === bd) close(); return; }
    switch (b.dataset.bcl) {
      case 'close': close(); break;
      case 'save': save(false); break;
      case 'save-add': save(true); break;
      case 'cross-yes': bd.querySelector('#bcl-conflict').hidden = true; save(b.dataset.add === '1', true); break;
      case 'cross-no': bd.querySelector('#bcl-conflict').hidden = true; break;
      case 'scan': {
        const code = await Barcode.capture();
        if (code) { bd.querySelector('#bcl-code').value = Barcode.format(code); codeChanged(); }
        break;
      }
    }
  }

  /**
   * opts: code (normalised; empty = the user types it), game ({game_id, label, editions:[{game_id,label}]}) to preselect,
   * addLabel (text of the "Save and add" button; hidden when not given), fromAdmin, onSaved({code, gameIds, game, andAdd}).
   */
  function open(opts) {
    if (!bd) build();
    st = { code: opts.code || '', game: null, results: [], onSaved: opts.onSaved, fromAdmin: opts.fromAdmin };
    bd.querySelector('#bcl-code-field').hidden = !!opts.code;
    bd.querySelector('#bcl-code').value = '';
    bd.querySelector('#bcl-code-msg').hidden = true;
    bd.querySelector('#bcl-q').value = '';
    bd.querySelector('#bcl-results').innerHTML = '';
    searchCtrl?.abort();
    lastQ = '';
    bd.querySelector('#bcl-ed-field').hidden = true;
    bd.querySelector('#bcl-editions').innerHTML = '';
    bd.querySelector('#bcl-note').value = '';
    bd.querySelector('#bcl-conflict').hidden = true;
    bd.querySelector('#bcl-err').hidden = true;
    bd.querySelector('#bcl-game-field').hidden = !!opts.game;
    variantBox();
    codeCard();
    const add = bd.querySelector('[data-bcl="save-add"]');
    add.hidden = !opts.addLabel;
    add.textContent = opts.addLabel || '';
    if (opts.game) {
      st.results = [opts.game];
      pickGame(opts.game.game_id, opts.game.editions?.length ? opts.game.editions : [{ game_id: opts.game.game_id, label: opts.game.label }]);
    }
    updateButtons();
    bd.classList.add('open');
    (opts.code ? (opts.game ? bd.querySelector('#bcl-note') : bd.querySelector('#bcl-q')) : bd.querySelector('#bcl-code')).focus();
  }

  function close() {
    bd?.classList.remove('open');
  }

  return { open, close };
})();
