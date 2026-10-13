/* ═══════════════════════════════════════════
   SETTINGS › Barcodes (settings/s_barcodes.php). Needs barcode.js (Barcode, BarcodeLink, BarcodeCamera).
   Tabs over api/barcode_admin.php "list" with paging, row actions, CSV import and the camera check.
   ═══════════════════════════════════════════ */
(() => {
  if (!document.getElementById('bca-list')) return;
  const e = Barcode.esc;
  const $ = id => document.getElementById(id);
  const st = { tab: 'all', q: '', sys: 0, offset: 0, rows: [], total: 0, seq: 0, editRow: null, editGame: null, checked: false };
  const PER = 50;
  const HELP = { all: 'bc.help_all', flagged: 'bc.help_flagged', unknown: 'bc.help_unknown', shared: 'bc.help_shared', missing: 'bc.help_missing' };

  async function load(reset = true) {
    if (reset) { st.offset = 0; st.rows = []; }
    const my = ++st.seq;
    const res = await Barcode.api('list', { tab: st.tab, q: st.q, system_id: st.sys, offset: st.offset, per_page: PER }, true);
    if (my !== st.seq) return;
    if (!res.ok) { $('bca-list').innerHTML = `<p class="bc-err-text">${e(res.error || tRaw('common.error'))}</p>`; return; }
    st.rows = reset ? res.rows : st.rows.concat(res.rows);
    st.total = res.total;
    counts(res.counts);
    render();
  }

  function counts(c) {
    $('bca-st-linked').textContent  = fmtNum(c.barcodes);
    $('bca-st-games').textContent   = tRaw('bc.n_of', { n: fmtNum(c.games_with), total: fmtNum(c.games_total) });
    $('bca-st-unknown').textContent = fmtNum(c.unknown);
    $('bca-st-flagged').textContent = fmtNum(c.flagged);
    $('bca-n-all').textContent      = fmtNum(c.rows);
    $('bca-n-flagged').textContent  = fmtNum(c.flagged);
    $('bca-n-unknown').textContent  = fmtNum(c.unknown);
    $('bca-n-shared').textContent   = fmtNum(c.shared);
    $('bca-n-missing').textContent  = fmtNum(Math.max(0, c.games_total - c.games_with));
  }

  const table = (head, body) => `<table class="admin-table bc-table"><thead><tr>${head.map(h => `<th>${h}</th>`).join('')}</tr></thead><tbody>${body}</tbody></table>`;
  const code = r => `<span class="bc-code-small">${e(r.display)}</span>`;
  const btn = (act, label, data, cls = 'btn-icon') => `<button type="button" class="${cls}" data-a="${act}" ${data}>${label}</button>`;

  function render() {
    $('bca-tab-help').textContent = tRaw(HELP[st.tab]);
    $('bca-sys').hidden = !['all', 'flagged', 'missing'].includes(st.tab);
    const list = $('bca-list');
    if (!st.rows.length) {
      list.innerHTML = `<p class="ga-desc" style="margin:8px 0">${t(st.q ? 'bc.none_found' : 'bc.empty_' + st.tab)}</p>`;
    } else if (st.tab === 'all' || st.tab === 'flagged') {
      list.innerHTML = table(
        [t('bc.col_barcode'), t('bc.col_game'), t('bc.col_variant'), st.tab === 'flagged' ? t('bc.col_reported') : t('bc.col_added'), ''],
        st.rows.map((r, i) => `<tr${r.flagged ? ' class="bc-row-flagged"' : ''}>
          <td>${code(r)}</td>
          <td>${e(r.label)} <span class="sb-sub">${e(r.system)}</span>${r.game_active ? '' : ` <span class="chip">${t('bc.game_off')}</span>`}${r.note ? `<br><span class="sb-sub">${t('bc.note_is', { note: r.note })}</span>` : ''}</td>
          <td>${r.variant ? e(r.variant) : `<span class="sb-sub">${t('bc.all_variants')}</span>`}</td>
          <td class="sb-sub">${st.tab === 'flagged'
            ? `${e(r.flagged_username)} · ${e(r.flagged)}${r.flag_reason ? `<br>“${e(r.flag_reason)}”` : ''}`
            : `${e(r.added_username || '—')} · ${e(r.created)}${r.source === 'import' ? ' · ' + t('bc.src_import') : ''}${r.flagged ? `<br><span class="chip chip-orange">${t('bc.flagged')}</span>` : ''}`}</td>
          <td class="bc-row-actions">
            ${r.flagged ? btn('keep', t('bc.keep'), `data-i="${i}"`) : ''}
            ${btn('edit', t('common.edit'), `data-i="${i}"`)}
            ${btn('remove', t('bc.remove'), `data-i="${i}"`, 'btn-icon ed-danger')}
          </td></tr>`).join(''));
    } else if (st.tab === 'unknown') {
      list.innerHTML = table(
        [t('bc.col_barcode'), t('bc.col_times'), t('bc.col_seen'), ''],
        st.rows.map((r, i) => `<tr>
          <td>${code(r)} <span class="chip">${e(r.format)}</span></td>
          <td>${fmtNum(r.times)}</td>
          <td class="sb-sub">${t('bc.seen_first', { user: r.first_username || '—', date: r.first_seen })}${r.times > 1 ? `<br>${t('bc.seen_last', { user: r.last_username || '—', date: r.last_seen })}` : ''}</td>
          <td class="bc-row-actions">${btn('link', t('bc.link_it'), `data-i="${i}"`, 'btn btn-sm')} ${btn('dismiss', t('bc.dismiss'), `data-i="${i}"`)}</td></tr>`).join(''));
    } else if (st.tab === 'shared') {
      list.innerHTML = table(
        [t('bc.col_barcode'), t('bc.col_games'), ''],
        st.rows.map((r, i) => `<tr${r.cross_system ? ' class="bc-row-cross"' : ''}>
          <td>${code(r)}${r.cross_system ? `<br><span class="chip chip-orange">${t('bc.cross_system')}</span>` : ''}</td>
          <td>${r.games.map(g => `<span class="bc-shared-game">${e(g.label)} <span class="sb-sub">${e(g.system)}${g.variant ? ' · ' + e(g.variant) : ''}</span></span>`).join('')}</td>
          <td class="bc-row-actions">${btn('review', t('bc.review'), `data-i="${i}"`)}</td></tr>`).join(''));
    } else {
      list.innerHTML = table(
        [t('bc.col_game'), t('common.col.system'), ''],
        st.rows.map((r, i) => `<tr><td>${e(r.label)}</td><td class="sb-sub">${e(r.system)}</td>
          <td class="bc-row-actions">${btn('add', t('bc.add_title'), `data-i="${i}"`)}</td></tr>`).join(''));
    }
    $('bca-showing').textContent = st.total ? tRaw('bc.showing', { n: fmtNum(st.rows.length), total: fmtNum(st.total) }) : '';
    $('bca-more').hidden = st.rows.length >= st.total;
  }

  function setTab(tab, q = '') {
    st.tab = tab;
    st.q = q;
    $('bca-q').value = q;
    document.querySelectorAll('[data-tab]').forEach(b => b.setAttribute('aria-pressed', b.dataset.tab === tab));
    load();
  }

  /* ── Row actions ── */
  async function onRow(b) {
    const r = st.rows[+b.dataset.i];
    if (!r) return;
    switch (b.dataset.a) {
      case 'remove': {
        if (!confirm(tRaw('bc.remove_confirm', { labels: r.label }))) return;
        const res = await Barcode.api('remove', { ids: [r.id] });
        toast(res.ok ? tRaw('bc.removed') : (res.error || tRaw('common.error')), !res.ok);
        if (res.ok) load();
        break;
      }
      case 'keep': {
        const res = await Barcode.api('keep', { ids: [r.id] }, true);
        toast(res.ok ? tRaw('bc.kept') : (res.error || tRaw('common.error')), !res.ok);
        if (res.ok) load();
        break;
      }
      case 'edit': openEdit(r); break;
      case 'dismiss': {
        const res = await Barcode.api('dismiss', { barcode: r.barcode }, true);
        if (res.ok) load(); else toast(res.error || tRaw('common.error'), true);
        break;
      }
      case 'link':
        BarcodeLink.open({ code: r.barcode, fromAdmin: true, onSaved: () => { toast(tRaw('bc.linked')); load(); } });
        break;
      case 'review': setTab('all', r.barcode); break;
      case 'add': {
        const res = await Barcode.api('game_barcodes', { game_id: r.game_id });
        BarcodeLink.open({
          code: '', fromAdmin: true,
          game: { game_id: r.game_id, label: r.label, system: r.system, editions: res.ok ? res.editions : [] },
          onSaved: () => { toast(tRaw('bc.linked')); load(); },
        });
        break;
      }
    }
  }

  /* ── Edit dialog ── */
  let moveTimer = null;
  function openEdit(r) {
    st.editRow = r;
    st.editGame = null;
    $('bca-edit-code').textContent = r.display;
    $('bca-edit-game').textContent = `${r.label} · ${r.system}`;
    $('bca-edit-move').value = '';
    $('bca-edit-results').innerHTML = '';
    $('bca-edit-variant').value = r.variant;
    $('bca-edit-note').value = r.note;
    $('bca-edit-err').hidden = true;
    $('bca-edit').classList.add('open');
    $('bca-edit-variant').focus();
  }
  $('bca-edit-move').addEventListener('input', ev => {
    clearTimeout(moveTimer);
    moveTimer = setTimeout(async () => {
      const q = ev.target.value.trim();
      const box = $('bca-edit-results');
      if (q.length < 2) { box.innerHTML = ''; return; }
      const res = await Barcode.api('search_games', { q });
      if (!res.ok) return;
      box.innerHTML = res.games.map(g => `<label class="bc-radio"><input type="radio" name="bca-move" value="${g.game_id}">
        <span><strong>${e(g.label)}</strong> <span class="sb-sub">${e(g.system_name)}</span></span></label>`).join('') || `<p class="ga-desc">${t('bc.no_games')}</p>`;
    }, 250);
  });
  $('bca-edit-save').addEventListener('click', async () => {
    const r = st.editRow;
    const move = document.querySelector('input[name="bca-move"]:checked');
    const res = await Barcode.api('update', { id: r.id, variant: $('bca-edit-variant').value.trim(), note: $('bca-edit-note').value.trim(), game_id: move ? +move.value : 0 }, true);
    if (!res.ok) { $('bca-edit-err').textContent = res.error || tRaw('common.error'); $('bca-edit-err').hidden = false; return; }
    $('bca-edit').classList.remove('open');
    toast(tRaw('bc.saved'));
    load();
  });

  /* ── CSV import ── */
  function importResult(res) {
    const box = $('bca-import-result');
    if (!res.ok) { box.innerHTML = `<p class="bc-err-text">${e(res.error || tRaw('common.error'))}</p>`; return; }
    box.innerHTML = `<p class="bc-import-sum">${t(res.dry_run ? 'bc.import_sum_check' : 'bc.import_sum_done', { added: fmtNum(res.added), skipped: fmtNum(res.skipped), problems: fmtNum(res.problem_count) })}</p>
      ${res.problems.length ? `<table class="admin-table"><thead><tr><th>${t('bc.import_line')}</th><th>${t('bc.col_barcode')}</th><th>${t('bc.import_problem')}</th></tr></thead><tbody>
        ${res.problems.map(p => `<tr><td>${fmtNum(p.line)}</td><td>${e(p.code)}</td><td>${e(p.reason)}</td></tr>`).join('')}</tbody></table>
        ${res.problem_count > res.problems.length ? `<p class="sb-sub">${t('bc.import_more_problems', { n: fmtNum(res.problem_count - res.problems.length) })}</p>` : ''}` : ''}`;
  }
  $('bca-import-open').addEventListener('click', () => {
    $('bca-import-result').innerHTML = '';
    $('bca-import-go').disabled = true;
    $('bca-import').classList.add('open');
    $('bca-csv').focus();
  });
  $('bca-csv').addEventListener('input', () => { $('bca-import-go').disabled = true; });
  $('bca-file').addEventListener('change', ev => {
    const f = ev.target.files[0];
    if (!f) return;
    const rd = new FileReader();
    rd.onload = () => { $('bca-csv').value = rd.result; $('bca-import-go').disabled = true; };
    rd.readAsText(f);
    ev.target.value = '';
  });
  $('bca-check').addEventListener('click', async () => {
    const res = await Barcode.api('import', { csv: $('bca-csv').value, dry_run: true }, true);
    importResult(res);
    $('bca-import-go').disabled = !(res.ok && res.added > 0);
  });
  $('bca-import-go').addEventListener('click', async () => {
    $('bca-import-go').disabled = true;
    const res = await Barcode.api('import', { csv: $('bca-csv').value, dry_run: false }, true);
    importResult(res);
    if (res.ok) { toast(tRaw('bc.import_done', { n: fmtNum(res.added) })); load(); }
  });

  /* ── Camera check (in this browser, no server round trip) ── */
  async function cameraCheck() {
    const ok = v => `<span class="bc-ok">✓ ${v}</span>`, bad = v => `<span class="bc-bad">✕ ${v}</span>`;
    $('bca-cam-https').innerHTML = window.isSecureContext ? ok(t('bc.cam_yes')) : bad(t('bc.cam_no'));
    try {
      const r = await fetch(location.href, { method: 'HEAD', cache: 'no-store' });
      const pol = r.headers.get('Permissions-Policy') || '';
      const blockedPol = /camera=\(\s*\)/.test(pol);
      $('bca-cam-policy').innerHTML = blockedPol ? bad(t('bc.cam_blocked')) : ok(t('bc.cam_allowed'));
      $('bca-cam-fix').hidden = !blockedPol;
    } catch (err) { $('bca-cam-policy').textContent = '—'; }
    const kind = await BarcodeCamera.reader();
    $('bca-cam-reader').innerHTML = kind === 'native' ? ok(t('bc.cam_reader_native')) : kind === 'zxing' ? ok(t('bc.cam_reader_bundled')) : bad(t('bc.cam_reader_none'));
  }

  /* ── Wiring ── */
  document.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => setTab(b.dataset.tab)));
  let qTimer = null;
  $('bca-q').addEventListener('input', ev => { clearTimeout(qTimer); qTimer = setTimeout(() => { st.q = ev.target.value.trim(); load(); }, 300); });
  $('bca-sys').addEventListener('change', ev => { st.sys = +ev.target.value; load(); });
  $('bca-more').addEventListener('click', () => { st.offset = st.rows.length; load(false); });
  $('bca-list').addEventListener('click', ev => { const b = ev.target.closest('[data-a]'); if (b) onRow(b); });
  document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => $(b.dataset.close).classList.remove('open')));
  document.querySelectorAll('#bca-import, #bca-edit').forEach(m => m.addEventListener('click', ev => { if (ev.target === m) m.classList.remove('open'); }));
  document.addEventListener('keydown', ev => { if (ev.key === 'Escape') document.querySelectorAll('#bca-import.open, #bca-edit.open').forEach(m => m.classList.remove('open')); });
  document.querySelectorAll('input[name="barcode_who"]').forEach(r => r.addEventListener('change', () => { $('bca-who-hint').textContent = r.dataset.hint; }));

  load();
  cameraCheck();
})();
