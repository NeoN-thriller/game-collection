/* ═══════════════════════════════════════════
   IGDB GAME INFO in the edit drawer (collection.php and wishlist.php)
   Fills #d-igdb (developer, publisher, genres, release date, ratings) from api/igdb_info.php and adds an
   "IGDB" link to the drawer's links list (#d-links-list). Nothing shows for a game without IGDB info,
   except for admins: a link to find it in Settings › IGDB.

   A compilation also lists the games inside it; without a match of its own its info is combined from them.

   Admins also get a cover switch (site default / uploaded / IGDB) when a game has both covers.

   IgdbDrawer.load(gameId, { onCover })   — call after the links list has been filled;
                                            onCover(gameId, coverUrl) after an admin switched the cover
   IgdbDrawer.releaseText(i) — the release date as shown (also the collection's Release column)
   ═══════════════════════════════════════════ */
const IgdbDrawer = (() => {
  const cache = new Map();
  let current = null, onCover = null;
  const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const LINK_STYLE = 'font-size:.75rem;color:var(--text2);text-decoration:none';

  /** "12 Mar 1987" (the user's date format) · "Mar 1987" · "Q2 1987" · "1987", plus "(NA)" for another region's date. */
  function releaseText(i) {
    if (!i.release_date) return '';
    const [y, m, d] = i.release_date.split('-').map(Number);
    const txt = i.release_prec === 'day' ? fmtDate(new Date(y, m - 1, d))
              : i.release_prec === 'month' ? `${FMT.months[m - 1] || m} ${y}`
              : i.release_prec === 'quarter' ? `Q${Math.ceil(m / 3)} ${y}`
              : String(y);
    return e(txt) + (i.release_kind === 'other' && i.release_region
      ? ` <span class="sb-sub" title="${t('igdb.other_region_title')}">(${t('igdb.region.' + i.release_region)})</span>` : '');
  }

  const score = (v, n, key) => v == null ? '' : `<strong>${Math.round(v)}</strong> <span class="sb-sub">(${tn(key, n)})</span>`;

  /** Admins: which cover this game shows — the site default, the uploaded one or IGDB's (only when it has both). */
  function coverSwitch(c) {
    if (!c || !c.cover_uploaded || !c.cover_igdb) return '';
    const opt = (v, label, url) => `<button type="button" class="ig-cover-opt" data-cover="${v}" aria-pressed="${(c.cover_source || '') === v}">
        ${url ? `<img src="${e(url)}" alt="" loading="lazy">` : `<span class="ig-cover-auto">${t('igdb.cover_auto_short')}</span>`}<span>${label}</span></button>`;
    return `<div class="ig-cover-sw" role="group" aria-label="${t('igdb.cover_label')}">
      <span class="sb-sub">${t('igdb.cover_label')}</span>
      ${opt('', t('igdb.cover_site', { source: tRaw('igdb.cover_src_' + c.default) }), null)}
      ${opt('uploaded', t('igdb.cover_src_uploaded'), c.cover_uploaded)}
      ${opt('igdb', t('igdb.cover_src_igdb'), c.cover_igdb)}</div>`;
  }

  function render(gameId, res) {
    if (gameId !== current) return;
    const box = document.getElementById('d-igdb');
    if (!box) return;
    const i = res.info;
    if (!i) {
      box.innerHTML = res.manage ? `<div class="section-label">${t('igdb.drawer_title')}</div>
        <p class="sb-sub" style="margin:0">${t('igdb.drawer_none')} <a href="${e(res.manage)}">${t('igdb.drawer_find')} →</a></p>${coverSwitch(res.cover)}` : '';
      box.hidden = !res.manage;
      return;
    }
    const rows = [
      [t('igdb.f_developer'), e(i.developers)],
      [t('igdb.f_publisher'), e(i.publishers)],
      [t('igdb.f_genres'),    e(i.genres)],
      [t('igdb.f_release'),   releaseText(i)],
      [t('igdb.f_users'),     score(i.rating, i.rating_count, 'igdb.votes')],
      [t('igdb.f_critics'),   score(i.critic_rating, i.critic_count, 'igdb.reviews')],   // only when there is one
    ].filter(r => r[1]);
    const combined = i.from === 'contents';
    const foot = [
      combined ? t('igdb.drawer_combined') : i.from ? t('igdb.drawer_from', { edition: i.from.label }) : '',
      i.url ? `${t('igdb.credit')} <a href="${e(i.url)}" target="_blank" rel="noopener">IGDB.com ↗</a>` : `${t('igdb.credit')} IGDB.com`,
      res.manage ? `<a href="${e(res.manage)}">${t('igdb.drawer_change')}</a>` : '',
    ].filter(Boolean).join(' · ');
    // A compilation: every game inside it with its own info
    const inside = (i.contents || []).map(c => {
      const bits = [e(c.developers), c.year, c.rating != null ? `${Math.round(c.rating)} (${tn('igdb.votes', c.rating_count)})` : '',
                    c.critic_rating != null ? `${t('igdb.f_critics')} ${Math.round(c.critic_rating)}` : ''].filter(Boolean).join(' · ');
      const name = c.url ? `<a href="${e(c.url)}" target="_blank" rel="noopener">${e(c.title)} ↗</a>` : e(c.title);
      return `<li>${name}<br><span class="sb-sub">${bits || t('igdb.contents_none')}</span></li>`;
    }).join('');
    box.innerHTML = `<div class="section-label">${t('igdb.drawer_title')}</div>
      ${rows.length ? `<dl class="ig-info">${rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl>` : (inside ? '' : `<p class="sb-sub" style="margin:0">${t('igdb.drawer_empty')}</p>`)}
      ${inside ? `<div class="section-label" style="margin-top:4px">${t('igdb.drawer_contents')}</div><ul class="ig-inside">${inside}</ul>` : ''}
      ${coverSwitch(res.cover)}
      <p class="sb-sub ig-foot">${foot}</p>`;
    box.hidden = false;

    const list = document.getElementById('d-links-list');
    if (list && i.url && !list.querySelector('[data-igdb]'))
      list.insertAdjacentHTML('beforeend', `<a data-igdb href="${e(i.url)}" target="_blank" rel="noopener" style="${LINK_STYLE}">🎮 IGDB: ${e(i.name)}</a>`);
  }

  async function load(gameId, opts = {}) {
    current = gameId = Number(gameId);
    onCover = opts.onCover || null;
    const box = document.getElementById('d-igdb');
    if (box) { box.hidden = true; box.innerHTML = ''; }
    if (cache.has(gameId)) { render(gameId, cache.get(gameId)); return; }
    try {
      const res = await fetch(`${BASE}/api/igdb_info.php?game_id=${gameId}`).then(r => r.json());
      if (!res.ok) return;
      cache.set(gameId, res);
      render(gameId, res);
    } catch (x) { /* no game info: the rest of the drawer works as before */ }
  }

  // The cover switch: save, show the new choice, and let the page update its cover
  document.addEventListener('click', async ev => {
    const b = ev.target.closest('#d-igdb [data-cover]');
    if (!b || b.disabled) return;
    const gameId = current, res = cache.get(gameId);
    if (!res?.cover) return;
    b.closest('.ig-cover-sw').querySelectorAll('button').forEach(x => x.disabled = true);
    try {
      const r = await fetch(`${BASE}/api/igdb.php`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'set_cover', game_id: gameId, source: b.dataset.cover }) }).then(x => x.json());
      if (!r.ok) throw new Error(r.error || '');
      Object.assign(res.cover, { cover_url: r.cover_url, cover_source: r.cover_source });
      if (onCover) onCover(gameId, r.cover_url);
    } catch (x) { alert(x.message || tRaw('common.error')); }
    render(gameId, res);
  });

  return { load, releaseText };
})();
