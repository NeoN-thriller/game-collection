/* ═══════════════════════════════════════════
   SETTINGS › Activity log (admin): filters, the day-grouped list with expandable rows,
   "Load older events", the CSV link, and the hints of the Log settings card.
   Data: CP.log = {page (first page from activityPage()), users, cats}; more from api/activity.php.
   ═══════════════════════════════════════════ */
(() => {
  const L = CP.log;
  if (!L) return;
  const API = `${CP.base}/api/activity.php`;
  const $ = id => document.getElementById(id);
  const st = { q: '', period: '30', user: 0, types: new Set(L.cats), admin: false };
  let rows = [], counts = {}, total = 0, hasMore = false, seq = 0;

  // Square avatar: the initial, coloured from a fixed palette by a hash of the name
  const PALETTE = ['--mint', '--blue', '--orange', '--accent', '--pink', '--wiiu', '--green', '--red'];
  const avatarVar = name => { let h = 0; for (const ch of name) h = (h * 31 + ch.codePointAt(0)) >>> 0; return PALETTE[h % PALETTE.length]; };

  function params(extra = {}) {
    const p = new URLSearchParams({ period: st.period, q: st.q, admin: st.admin ? 1 : 0, types: [...st.types].join(','), limit: 50, ...extra });
    if (st.user) p.set('user', st.user);
    return p;
  }

  async function load(more = false) {
    const my = ++seq;
    const res = await fetch(`${API}?${params(more && rows.length ? { before: rows[rows.length - 1].id } : {})}`)
      .then(r => r.json()).catch(e => ({ ok: false, error: e.message }));
    if (my !== seq) return;   // a newer filter change won
    if (!res.ok) { toast(tRaw('common.err_prefix', { error: res.error || '' }), true); return; }
    apply(res, more);
  }

  function apply(page, more) {
    rows = more ? rows.concat(page.rows) : page.rows;
    counts = page.counts; total = page.total; hasMore = page.has_more;
    $('log-admin-n').textContent = fmtNum(page.admin_count);
    renderTypes(); renderList();
    $('log-export').href = `${CP.base}/api/activity_export.php?${params()}`;
  }

  // ── Filters ──
  function renderUsers() {
    const box = $('log-users');
    if (L.users.length > 6) {
      box.innerHTML = `<select class="log-select" id="log-user-sel" aria-label="${t('log.filter_user')}">
        <option value="0">${t('log.all_users')}</option>
        ${L.users.map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('')}</select>`;
      $('log-user-sel').addEventListener('change', e => { st.user = +e.target.value; load(); });
      return;
    }
    const chip = (id, label) => `<button type="button" class="log-chip" data-user="${id}" aria-pressed="${st.user === id}">${label}</button>`;
    box.innerHTML = chip(0, t('log.all_users')) + L.users.map(u => chip(u.id, esc(u.name))).join('');
    box.querySelectorAll('[data-user]').forEach(b => b.addEventListener('click', () => {
      st.user = +b.dataset.user;
      box.querySelectorAll('[data-user]').forEach(x => x.setAttribute('aria-pressed', x === b));
      load();
    }));
  }

  function renderTypes() {
    $('log-types').innerHTML = L.cats.map(c => `<button type="button" class="log-chip log-c-${c}" data-type="${c}" aria-pressed="${st.types.has(c)}">
        ${t('log.cat.' + c)} <span class="log-count">${fmtNum(counts[c] || 0)}</span></button>`).join('');
    $('log-types').querySelectorAll('[data-type]').forEach(b => b.addEventListener('click', () => {
      const c = b.dataset.type;
      st.types.has(c) ? st.types.delete(c) : st.types.add(c);
      load();
    }));
  }

  // ── The list ──
  function changeHtml(c) {
    const label = c.label ? `<span class="log-ch-label">${esc(c.label)}</span>` : '';
    if (c.hidden) return `<li>${label}<em class="log-ch-hidden">🔒 ${esc(c.to)}</em></li>`;   // someone else's price / notes
    if (c.from === null) return `<li>${label}<span class="log-ch-to">${esc(c.to)}</span></li>`;
    return `<li>${label}<s class="log-ch-from">${esc(c.from)}</s><span class="log-ch-arrow" aria-hidden="true">→</span><span class="log-ch-to">${esc(c.to)}</span></li>`;
  }

  function rowHtml(r) {
    const tags = (r.admin ? ` <span class="log-tag log-tag-admin">${t('log.tag_admin')}</span>` : '')
               + (r.warn ? ` <span class="log-tag log-tag-warn">⚠ ${t('log.tag_warning')}</span>` : '');
    const extra = [];
    if (r.target) extra.push(`<li><span class="log-ch-label">${t('log.target_user')}</span><span class="log-ch-to">${esc(r.target)}</span></li>`);
    if (r.ip)     extra.push(`<li><span class="log-ch-label">${t('log.ip_label')}</span><span class="log-ch-to">${esc(r.ip)}</span></li>`);
    const lines = r.changes.map(changeHtml).concat(extra);
    return `<div class="log-item">
      <button type="button" class="log-head" aria-expanded="false" aria-controls="log-d-${r.id}">
        <span class="log-time">${esc(r.time)}</span>
        <span class="log-user"><span class="log-av" style="--av:var(${avatarVar(r.user)})" aria-hidden="true">${esc([...r.user][0] || '?').toUpperCase()}</span><span class="log-uname">${esc(r.user)}</span></span>
        <span class="log-type log-c-${r.category}">${t('log.cat.' + r.category)}</span>
        <span class="log-sum">${esc(r.summary)}${tags}</span>
        <span class="log-chev" aria-hidden="true">▸</span>
      </button>
      <div class="log-detail" id="log-d-${r.id}" hidden>
        ${lines.length ? `<ul class="log-changes">${lines.join('')}</ul>` : `<p class="sb-sub">${t('log.no_changes')}</p>`}
        ${r.note ? `<p class="log-note">${esc(r.note)}</p>` : ''}
        <p class="sb-sub">${t('log.event_no', { id: r.id, time: r.full })}</p>
        ${r.link ? `<a class="btn-ghost log-btn log-open" href="${esc(r.link.url)}">${esc(r.link.label)} →</a>` : ''}
      </div>
    </div>`;
  }

  function renderList() {
    const list = $('log-list');
    const byDay = [];
    rows.forEach(r => {
      const last = byDay[byDay.length - 1];
      if (last && last.day === r.day) last.rows.push(r); else byDay.push({ day: r.day, label: r.day_label, rows: [r] });
    });
    list.innerHTML = byDay.map(d => `<section class="log-day">
        <h3 class="log-day-head">${esc(d.label)} <span class="sb-sub">· ${tn('log.n_events', d.rows.length)}</span></h3>
        ${d.rows.map(rowHtml).join('')}</section>`).join('');
    list.querySelectorAll('.log-head').forEach(b => b.addEventListener('click', () => {
      const open = b.getAttribute('aria-expanded') !== 'true';
      b.setAttribute('aria-expanded', open);
      $(b.getAttribute('aria-controls')).hidden = !open;
    }));
    $('log-empty').hidden = rows.length > 0;
    $('log-more').hidden = !hasMore;
    $('log-result').textContent = tRaw('log.showing', { n: fmtNum(rows.length), m: fmtNum(total) });
  }

  // ── Wiring ──
  let qTimer = null;
  $('log-q').addEventListener('input', e => { clearTimeout(qTimer); qTimer = setTimeout(() => { st.q = e.target.value.trim(); load(); }, 300); });
  $('log-period').addEventListener('change', e => { st.period = e.target.value; load(); });
  $('log-admin').addEventListener('click', e => {
    st.admin = !st.admin;
    e.currentTarget.setAttribute('aria-pressed', st.admin);
    load();
  });
  $('log-more').addEventListener('click', () => load(true));
  $('log-clear').addEventListener('click', () => {
    Object.assign(st, { q: '', period: '30', user: 0, types: new Set(L.cats), admin: false });
    $('log-q').value = ''; $('log-period').value = '30';
    $('log-admin').setAttribute('aria-pressed', 'false');
    renderUsers();
    load();
  });

  // Log settings: the hint under the retention / IP choices follows the selection
  const hint = (name, el) => {
    const set = () => { const c = document.querySelector(`input[name="${name}"]:checked`); $(el).textContent = c ? c.dataset.hint : ''; };
    document.querySelectorAll(`input[name="${name}"]`).forEach(i => i.addEventListener('change', set));
    set();
  };
  hint('log_retention_days', 'log-keep-hint');
  hint('log_ip_mode', 'log-ip-hint');

  renderUsers();
  apply(L.page, false);
})();
