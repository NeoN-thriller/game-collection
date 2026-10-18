/* ═══════════════════════════════════════════
   SETTINGS › IGDB (settings/s_igdb.php) over api/igdb.php.
   Systems table: IGDB platform, Match (games never looked up, in rounds of ~20 s) and Refresh (matched games again).
   Match all / Refresh all run those for every system that has something to do, one after another.
   Games list per state with paging; the pick dialog searches IGDB and links a game (confirmed).
   URL: ?s=igdb&sys=<id>&q=<title>&tab=all opens that system's list on that search (the drawer's "Change match").
   ═══════════════════════════════════════════ */
(() => {
  if (!document.getElementById('ig-list')) return;
  const $ = id => document.getElementById(id);
  const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const url = new URLSearchParams(location.search);
  const st = { systems: [], configured: false, all: null, sys: 0, tab: 'review', q: '', offset: 0, rows: [], total: 0, seq: 0,
               busy: false, stop: false, run: null, pickRow: null, platSys: null };
  const HELP = { review: 'igdb.help_review', matched: 'igdb.help_matched', none: 'igdb.help_none', pending: 'igdb.help_pending', all: 'igdb.help_all' };

  async function api(action, body = {}) {
    try {
      const r = await fetch(`${CP.base}/api/igdb.php`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...body }),
      });
      const text = await r.text();
      try { return JSON.parse(text); } catch (x) {
        const msg = text.replace(/<(script|style)[\s\S]*?<\/(script|style)>/gi, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
        return { ok: false, error: tRaw('igdb.err_http', { status: r.status }) + (msg ? ' ' + msg : '') };
      }
    } catch (x) { return { ok: false, error: tRaw('igdb.err_network') }; }
  }

  const sysById = id => st.systems.find(s => s.id == id);
  const link = (href, text) => `<a href="${e(href)}" target="_blank" rel="noopener">${e(text)} ↗</a>`;
  const btn = (act, label, data = '', cls = 'btn-icon') => `<button type="button" class="${cls}" data-a="${act}" ${data}>${label}</button>`;

  // ── Systems ──
  async function loadSystems() {
    const res = await api('systems');
    if (!res.ok) { $('ig-systems').innerHTML = `<p class="bc-err-text">${e(res.error)}</p>`; return; }
    st.systems = res.systems;
    st.configured = res.configured;
    renderSystems();
    counts();
  }

  /** ' disabled title="…"' (why Match / Refresh can't run now) or ''. */
  function why(s, n, emptyKey) {
    const key = !st.configured ? 'igdb.why_not_configured' : st.busy ? 'igdb.why_busy' : s.skip ? 'igdb.why_skipped'
              : !s.platform_id ? 'igdb.why_no_platform' : !n ? emptyKey : '';
    return key ? ` disabled title="${t(key)}"` : '';
  }

  /** Systems Match all / Refresh all go through: not set to Don't match, with a platform and something to do. */
  const allTodo = kind => st.systems.filter(s => !s.skip && s.platform_id && (kind === 'match' ? s.counts.pending : s.counts.matched) > 0);

  /** The Match all / Refresh all button, with how many systems it covers (or why it can't run). */
  function allBtn(kind) {
    const n = allTodo(kind).length;
    const key = !st.configured ? 'igdb.why_not_configured' : st.busy ? 'igdb.why_busy' : !n ? `igdb.why_all_none_${kind}` : '';
    return `<button type="button" class="btn-ghost btn-sm" data-a="${kind}-all" title="${t(key || `igdb.${kind}_all_help`)}"${key ? ' disabled' : ''}>
      ${t(`igdb.${kind}_all`)}${n ? ` <span class="sb-sub">${tn('igdb.n_systems', n)}</span>` : ''}</button>`;
  }

  function renderSystems() {
    if (!st.systems.length) { $('ig-systems').innerHTML = `<p class="ga-desc">${t('igdb.no_systems')}</p>`; return; }
    const spin = `<span class="ig-spin" aria-hidden="true"></span> `;
    $('ig-systems').innerHTML = `<div class="ig-all">${allBtn('match')}${allBtn('refresh')}</div>
      <table class="admin-table bc-table ig-sys-table"><thead><tr>
        <th>${t('common.col.system')}</th><th>${t('igdb.col_platform')}</th><th>${t('igdb.col_matched')}</th>
        <th>${t('igdb.tab_review')}</th><th>${t('igdb.tab_none')}</th><th>${t('igdb.tab_pending')}</th><th></th></tr></thead><tbody>
      ${st.systems.map(s => {
        const c = s.counts, off = !st.configured || st.busy, run = st.run && st.run.sys === s.id ? st.run.kind : '';
        const cls = [run && 'ig-running', s.skip && 'ig-skipped'].filter(Boolean).join(' ');
        return `<tr${cls ? ` class="${cls}"` : ''}>
          <td>${e(s.name)} <span class="sb-sub">${e(s.region)}</span>${s.skip ? ` <span class="chip">${t('igdb.skipped')}</span>` : ''}</td>
          <td>${s.platform_id ? `${e(s.platform_name || '#' + s.platform_id)} <span class="sb-sub">${s.platform_id}</span>` : `<span class="sb-sub">—</span>`}
              ${btn('plat', s.platform_id ? t('common.edit') : t('igdb.set_platform'), `data-sys="${s.id}"${off ? ' disabled' : ''}`)}</td>
          <td>${fmtNum(c.matched)} <span class="sb-sub">/ ${fmtNum(c.total)}</span></td>
          <td>${c.review ? `<button type="button" class="ed-other-name" data-a="show" data-sys="${s.id}" data-tab="review">${fmtNum(c.review)}</button>` : '0'}</td>
          <td>${fmtNum(c.none)}</td>
          <td>${s.skip ? '<span class="sb-sub">—</span>' : fmtNum(c.pending)}</td>
          <td class="bc-row-actions">
            ${btn('match', run === 'match' ? spin + t('igdb.matching_short') : t('igdb.match'), `data-sys="${s.id}"${why(s, c.pending, 'igdb.why_none_pending')}`)}
            ${btn('refresh', run === 'refresh' ? spin + t('igdb.refreshing_short') : t('igdb.refresh'), `data-sys="${s.id}"${why(s, c.matched, 'igdb.why_none_matched')}`)}
            ${btn('skip', s.skip ? t('igdb.skip_off') : t('igdb.skip_on'),
                  `data-sys="${s.id}" data-skip="${s.skip ? 0 : 1}" title="${t(s.skip ? 'igdb.skip_off_help' : 'igdb.skip_on_help')}"${off ? ' disabled' : ''}`)}
          </td></tr>`;
      }).join('')}</tbody></table>`;
  }

  // ── A running Match / Refresh: the panel above the table, the row's spinner, a timer and a warning on leaving ──
  const clock = sec => `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`;
  const warnLeave = ev => { ev.preventDefault(); ev.returnValue = ''; };

  function runStart(sysId, kind, title) {
    st.busy = true; st.stop = false;
    st.run = { sys: sysId, kind, t0: st.all?.t0 ?? Date.now() };
    $('ig-run-title').textContent = (st.all ? tRaw('igdb.all_step', { i: st.all.i, n: st.all.n }) + ' · ' : '') + title;
    $('ig-run-text').textContent = '';
    $('ig-run-fill').style.width = '0';
    $('ig-run-spin').hidden = false;
    $('ig-run-stop').hidden = false;
    $('ig-run-stop').disabled = false;
    $('ig-progress').classList.remove('ig-run-err');
    $('ig-progress').hidden = false;
    const tick = () => { $('ig-run-time').textContent = clock(Math.round((Date.now() - st.run.t0) / 1000)); };
    tick();
    st.run.timer = setInterval(tick, 1000);
    window.addEventListener('beforeunload', warnLeave);
    renderSystems();
    $('ig-progress').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function runStep(done, total, text) {
    $('ig-run-fill').style.width = (total ? Math.min(100, Math.round(100 * done / total)) : 0) + '%';
    $('ig-run-text').textContent = text;
    renderSystems();
  }

  async function runEnd(sysId, title, text, failed = false) {
    clearInterval(st.run.timer);
    window.removeEventListener('beforeunload', warnLeave);
    st.busy = false; st.run = null;
    $('ig-run-title').textContent = title;
    $('ig-run-text').textContent = text;
    $('ig-run-spin').hidden = true;
    $('ig-run-stop').hidden = true;
    $('ig-progress').classList.toggle('ig-run-err', failed);
    if (!failed) $('ig-run-fill').style.width = '100%';
    await loadSystems();
    if (st.sys == sysId) load();
  }

  async function runMatch(sysId) {
    const s = sysById(sysId);
    if (!s?.platform_id || s.skip || st.busy) return 'skipped';
    const total = s.counts.pending;
    let auto = 0, review = 0;
    runStart(sysId, 'match', tRaw('igdb.matching', { system: s.name }));
    runStep(0, total, tRaw('igdb.match_starting', { n: fmtNum(total) }));
    for (;;) {
      const res = await api('match', { system_id: sysId });
      if (!res.ok) return runEnd(sysId, tRaw('igdb.match_failed', { system: s.name }), res.error, true).then(() => 'failed');
      auto += res.auto; review += res.review;
      // The table follows along
      s.counts.matched += res.auto; s.counts.review += res.review; s.counts.pending = res.left;
      const text = tRaw('igdb.match_progress', { auto: fmtNum(auto), review: fmtNum(review), left: fmtNum(res.left) });
      if (!res.left) return runEnd(sysId, tRaw('igdb.match_done', { system: s.name }), text).then(() => 'done');
      if (st.stop)   return runEnd(sysId, tRaw('igdb.match_stopped', { system: s.name }), text).then(() => 'stopped');
      runStep(auto + review, total, text);
    }
  }

  async function runRefresh(sysId) {
    const s = sysById(sysId);
    if (!s?.platform_id || s.skip || st.busy) return 'skipped';
    const total = s.counts.matched;
    let offset = 0, done = 0;
    runStart(sysId, 'refresh', tRaw('igdb.refreshing', { system: s.name }));
    runStep(0, total, tRaw('igdb.refresh_progress', { n: fmtNum(0), total: fmtNum(total) }));
    for (;;) {
      const res = await api('refresh', { system_id: sysId, offset, log: 1, total: done });
      if (!res.ok) return runEnd(sysId, tRaw('igdb.refresh_failed', { system: s.name }), res.error, true).then(() => 'failed');
      done += res.updated;
      const text = tRaw('igdb.refresh_progress', { n: fmtNum(done), total: fmtNum(total) });
      if (res.next === null) return runEnd(sysId, tRaw('igdb.refresh_done', { system: s.name }), text).then(() => 'done');
      if (st.stop)           return runEnd(sysId, tRaw('igdb.refresh_stopped', { system: s.name }), text).then(() => 'stopped');
      offset = res.next;
      runStep(done, total, text);
    }
  }

  /** Match all / Refresh all: each system that has something to do, one after another, until done, stopped or failed. */
  async function runAll(kind) {
    if (st.busy) return;
    const ids = allTodo(kind).map(s => s.id);
    if (!ids.length) return;
    st.all = { i: 0, n: ids.length, t0: Date.now() };
    let last = 'done';
    for (const id of ids) {
      st.all.i++;
      last = await (kind === 'match' ? runMatch(id) : runRefresh(id));
      if (last === 'stopped' || last === 'failed') break;
    }
    const n = st.all.n;
    st.all = null;
    if (last !== 'stopped' && last !== 'failed') $('ig-run-title').textContent = tnRaw(`igdb.${kind}_all_done`, n);
  }

  // ── Games list ──
  function counts() {
    const c = sysById(st.sys)?.counts || { total: 0, matched: 0, review: 0, none: 0, pending: 0 };
    ['review', 'matched', 'none', 'pending'].forEach(k => $('ig-n-' + k).textContent = fmtNum(c[k]));
    $('ig-n-all').textContent = fmtNum(c.total);
  }

  async function load(reset = true) {
    if (reset) { st.offset = 0; st.rows = []; }
    const my = ++st.seq;
    const res = await api('list', { system_id: st.sys, tab: st.tab, q: st.q, offset: st.offset });
    if (my !== st.seq) return;
    if (!res.ok) { $('ig-list').innerHTML = `<p class="bc-err-text">${e(res.error)}</p>`; return; }
    st.rows = reset ? res.rows : st.rows.concat(res.rows);
    st.total = res.total;
    render();
  }

  function igdbCell(r) {
    if (r.status === 'auto' || r.status === 'confirmed') {
      const extra = [r.year, r.developers].filter(Boolean).map(e).join(' · ');
      return `${link(r.url, r.name)} <span class="chip ${r.status === 'confirmed' ? 'chip-y' : 'chip-n'}">${t('igdb.status_' + r.status)}</span>`
           + (extra ? `<br><span class="sb-sub">${extra}</span>` : '');
    }
    if (r.status === 'review') {
      if (!r.candidates.length) return `<span class="sb-sub">${t('igdb.no_candidates')}</span>`;
      return `<ul class="ig-cands">${r.candidates.map(c => `<li>${link(c.url, c.name)}${c.year ? ` <span class="sb-sub">${c.year}</span>` : ''}
        ${btn('use', t('igdb.use'), `data-g="${r.game_id}" data-id="${c.id}"`)}</li>`).join('')}</ul>`;
    }
    if (r.status === 'none') return `<span class="chip chip-n">${t('igdb.status_none')}</span>`;
    return `<span class="sb-sub">${t('igdb.status_pending')}</span>`;
  }

  function actions(r, i) {
    const d = `data-i="${i}"`;
    const matched = r.status === 'auto' || r.status === 'confirmed';
    return [
      btn('pick', matched ? t('igdb.change') : t('igdb.search_dots'), d),
      r.status !== 'none' && r.status !== null ? btn('none', t('igdb.not_on_igdb'), d) : '',
      btn('again', r.status === null ? t('igdb.look_up') : t('igdb.look_up_again'), d),
    ].join(' ');
  }

  function render() {
    $('ig-tab-help').textContent = tRaw(HELP[st.tab]);
    const list = $('ig-list');
    if (!st.rows.length) {
      list.innerHTML = `<p class="ga-desc" style="margin:8px 0">${t(st.q ? 'igdb.none_found' : 'igdb.empty_' + st.tab)}</p>`;
    } else {
      list.innerHTML = `<table class="admin-table bc-table"><thead><tr><th>${t('igdb.col_game')}</th><th>IGDB</th><th></th></tr></thead><tbody>
        ${st.rows.map((r, i) => `<tr>
          <td>${e(r.title)}${r.active ? '' : ` <span class="chip">${t('igdb.game_hidden')}</span>`}</td>
          <td>${igdbCell(r)}</td>
          <td class="bc-row-actions">${actions(r, i)}</td></tr>`).join('')}</tbody></table>`;
    }
    $('ig-redo').hidden = !(st.tab === 'review' && !st.q && st.total && st.configured && !st.busy);
    $('ig-showing').textContent = st.total ? tRaw('igdb.showing', { n: fmtNum(st.rows.length), total: fmtNum(st.total) }) : '';
    $('ig-more').hidden = st.rows.length >= st.total;
  }

  async function after(res) {
    if (!res.ok) { alert(res.error || tRaw('common.error')); return; }
    await loadSystems();
    load();
  }

  // ── Dialogs ──
  const open  = id => { $(id).classList.add('open'); };
  const close = id => { $(id).classList.remove('open'); };

  async function platSearch() {
    const q = $('ig-plat-q').value.trim();
    $('ig-plat-err').hidden = true;
    if (!q) return;
    $('ig-plat-results').innerHTML = `<p class="sb-sub">${t('igdb.searching')}</p>`;
    const res = await api('platforms', { q });
    if (!res.ok) { $('ig-plat-results').innerHTML = ''; $('ig-plat-err').textContent = res.error; $('ig-plat-err').hidden = false; return; }
    $('ig-plat-results').innerHTML = res.platforms.length
      ? `<ul class="ig-cands">${res.platforms.map(p => `<li><strong>${e(p.name)}</strong> <span class="sb-sub">${p.id}${p.extra ? ' · ' + e(p.extra) : ''}</span>
          ${btn('plat-use', t('igdb.use'), `data-id="${p.id}" data-name="${e(p.name)}"`)}</li>`).join('')}</ul>`
      : `<p class="sb-sub">${t('igdb.none_found')}</p>`;
  }

  async function setPlatform(id, name) {
    const s = st.platSys;
    if (s.platform_id && s.platform_id != id && (s.counts.matched || s.counts.review) && !confirm(tRaw('igdb.platform_confirm'))) return;
    const res = await api('set_platform', { system_id: s.id, platform_id: id, platform_name: name });
    if (!res.ok) { $('ig-plat-err').textContent = res.error; $('ig-plat-err').hidden = false; return; }
    close('ig-plat');
    after(res);
  }

  async function pickSearch() {
    const q = $('ig-pick-q').value.trim();
    $('ig-pick-err').hidden = true;
    if (!q) return;
    $('ig-pick-results').innerHTML = `<p class="sb-sub">${t('igdb.searching')}</p>`;
    const res = await api('search', { system_id: st.sys, q, any_platform: $('ig-pick-any').checked ? 1 : 0 });
    if (!res.ok) { $('ig-pick-results').innerHTML = ''; $('ig-pick-err').textContent = res.error; $('ig-pick-err').hidden = false; return; }
    $('ig-pick-results').innerHTML = res.results.length
      ? `<ul class="ig-cands">${res.results.map(c => `<li>${link(c.url, c.name)} <span class="sb-sub">${[c.year, c.platforms].filter(Boolean).map(e).join(' · ')}</span>
          ${btn('pick-use', t('igdb.use'), `data-id="${c.id}"`)}</li>`).join('')}</ul>`
      : `<p class="sb-sub">${t('igdb.none_found')}</p>`;
  }

  async function useGame(gameId, igdbId, fromDialog) {
    const res = await api('set', { game_id: gameId, igdb_id: igdbId });
    if (fromDialog) {
      if (!res.ok) { $('ig-pick-err').textContent = res.error; $('ig-pick-err').hidden = false; return; }
      close('ig-pick');
    }
    after(res);
  }

  // ── Events ──
  document.addEventListener('click', async ev => {
    const close_ = ev.target.closest('[data-close]');
    if (close_) { close(close_.dataset.close); return; }
    if (ev.target.classList.contains('modal-backdrop') && ev.target.id.startsWith('ig-')) { close(ev.target.id); return; }
    const b = ev.target.closest('[data-a]');
    if (!b || !b.closest('#ig-systems, #ig-list, #ig-progress, #ig-plat, #ig-pick')) return;
    const a = b.dataset.a;
    const row = b.dataset.i !== undefined ? st.rows[+b.dataset.i] : null;
    if (a === 'stop')    { st.stop = true; b.disabled = true; $('ig-run-text').textContent = tRaw('igdb.stopping'); }
    if (a === 'match')   runMatch(+b.dataset.sys);
    if (a === 'refresh') runRefresh(+b.dataset.sys);
    if (a === 'match-all')   runAll('match');
    if (a === 'refresh-all') runAll('refresh');
    if (a === 'skip')    { b.disabled = true; after(await api('set_skip', { system_id: +b.dataset.sys, skip: b.dataset.skip === '1' })); }
    if (a === 'show')    { $('ig-sys').value = b.dataset.sys; st.sys = +b.dataset.sys; setTab(b.dataset.tab); $('ig-list').scrollIntoView({ behavior: 'smooth' }); }
    if (a === 'plat') {
      st.platSys = sysById(+b.dataset.sys);
      $('ig-plat-sys').textContent = st.platSys.name;
      $('ig-plat-q').value = st.platSys.name;
      $('ig-plat-clear').style.display = st.platSys.platform_id ? '' : 'none';
      $('ig-plat-results').innerHTML = '';
      open('ig-plat');
      platSearch();
    }
    if (a === 'plat-use') setPlatform(+b.dataset.id, b.dataset.name);
    if (a === 'use')      useGame(+b.dataset.g, +b.dataset.id, false);
    if (a === 'pick-use') useGame(st.pickRow.game_id, +b.dataset.id, true);
    if (a === 'pick') {
      st.pickRow = row;
      $('ig-pick-game').textContent = row.title;
      $('ig-pick-q').value = row.title.replace(/\s*\[[^\]]*\]\s*/g, ' ').trim();
      $('ig-pick-any').checked = false;
      $('ig-pick-none').style.display = row.status === 'none' ? 'none' : '';
      $('ig-pick-results').innerHTML = '';
      open('ig-pick');
      pickSearch();
    }
    if (a === 'none')  after(await api('none', { game_id: row.game_id }));
    if (a === 'again') { b.disabled = true; after(await api('clear', { game_id: row.game_id })); }
  });

  $('ig-plat-go').addEventListener('click', platSearch);
  $('ig-plat-q').addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); platSearch(); } });
  $('ig-plat-clear').addEventListener('click', () => setPlatform(0, ''));
  $('ig-pick-go').addEventListener('click', pickSearch);
  $('ig-pick-q').addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); pickSearch(); } });
  $('ig-pick-any').addEventListener('change', pickSearch);
  $('ig-pick-none').addEventListener('click', async () => {
    const res = await api('none', { game_id: st.pickRow.game_id });
    close('ig-pick');
    after(res);
  });
  document.addEventListener('keydown', ev => { if (ev.key === 'Escape') ['ig-plat', 'ig-pick'].forEach(close); });

  function setTab(tab) {
    st.tab = tab;
    document.querySelectorAll('.log-chip[data-tab]').forEach(c => c.setAttribute('aria-pressed', c.dataset.tab === tab ? 'true' : 'false'));
    counts();
    load();
  }
  document.querySelectorAll('.log-chip[data-tab]').forEach(c => c.addEventListener('click', () => setTab(c.dataset.tab)));
  $('ig-sys').addEventListener('change', () => { st.sys = +$('ig-sys').value; counts(); load(); });
  let qTimer = null;
  $('ig-q').addEventListener('input', () => { clearTimeout(qTimer); qTimer = setTimeout(() => { st.q = $('ig-q').value.trim(); load(); }, 250); });
  $('ig-redo').addEventListener('click', async () => {
    if (st.busy || !confirm(tRaw('igdb.redo_confirm', { n: fmtNum(st.total) }))) return;
    const res = await api('reset_review', { system_id: st.sys });
    if (!res.ok) { alert(res.error || tRaw('common.error')); return; }
    await loadSystems();
    runMatch(st.sys);
  });
  $('ig-more').addEventListener('click', () => { st.offset = st.rows.length; load(false); });

  // Start: the system / search / tab from the URL (the drawer's "Change match"), else the first system
  if (url.get('sys') && [...$('ig-sys').options].some(o => o.value === url.get('sys'))) $('ig-sys').value = url.get('sys');
  st.sys = +$('ig-sys').value || 0;
  if (url.get('q')) { st.q = url.get('q'); $('ig-q').value = st.q; }
  const startTab = HELP[url.get('tab')] ? url.get('tab') : 'review';
  loadSystems().then(() => setTab(startTab));
})();
