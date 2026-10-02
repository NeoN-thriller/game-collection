/* ═══════════════════════════════════════════
   EDITIONS (admin) — Settings › Catalogue: edition suggestions, manual linking in the
   game list (selection bar + link dialog), and the "possible edition groups" notice
   after an import (also used by pc_import.php and import_games.php).
   Talks to api/editions.php. Needs BASE (base URL) from the page.
   ═══════════════════════════════════════════ */
const ED_API = BASE + '/api/editions.php';

function edEsc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
/** A title, linked to its PriceCharting page when the import gave one (opens in a new tab). */
function edTitleHtml(title, link) {
  return link ? `<a href="${edEsc(link)}" target="_blank" rel="noopener" class="ed-pc-link" title="${t('ed.view_pc')}">${edEsc(title)}</a>` : edEsc(title);
}
function edToast(msg, err = false) { if (typeof toast === 'function') toast(msg, err); }

async function edPost(action, data = {}) {
  try {
    const res = await fetch(ED_API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action, ...data }) }).then(r => r.json());
    if (!res.ok) edToast(res.error || tRaw('common.err_unknown'), true);
    return res;
  } catch (e) {
    edToast(tRaw('common.err_prefix', { error: e.message }), true);
    return { ok: false };
  }
}

/** Same rules as editionBase() / editionDefaultLabel() in editions.php. */
function edBase(title)  { return String(title).replace(/\s*\[[^\[\]]*\]\s*$/u, '').replace(/\s+/g, ' ').trim(); }
function edBracket(title) { const m = String(title).match(/\[([^\[\]]*)\]\s*$/u); return m && m[1].trim() ? m[1].trim() : null; }
function edDefaultLabel(title) { return edBracket(title) ?? tRaw('ed.original'); }

/** "Original (main), Platinum" */
function edEditionsText(group) {
  return group.members.map(m => m.edition_label + (+m.game_id === +group.main_game_id ? ` (${tRaw('ed.main_tag')})` : '')).join(', ');
}

// ══════════ SUGGESTIONS ══════════
const edSug = { list: [], done: {} };   // done: key → {text, undo}
const ED_LEVEL_CHIP = { exact: 'chip-y', similar: 'chip-blue', partial: 'chip-up' };

async function edLoadSuggestions() {
  const box = document.getElementById('ed-sug-list');
  box.innerHTML = `<p class="ga-desc">${t('ed.sug_scanning')}</p>`;
  const res = await edPost('suggest', { system_id: +document.getElementById('ed-sug-sys').value || null });
  edSug.list = res.ok ? res.suggestions : [];
  edSug.done = {};
  edRenderSuggestions();
}

function edRenderSuggestions() {
  const box  = document.getElementById('ed-sug-list');
  const all  = +document.getElementById('ed-sug-sys').value === 0;
  const open = edSug.list.filter(s => !edSug.done[s.key]);
  const exact = open.filter(s => s.level === 'exact').length;

  const count = document.getElementById('ed-sug-count');
  count.hidden = !open.length;
  count.textContent = tRaw('ed.sug_to_review', { n: fmtNum(open.length) });
  const acc = document.getElementById('ed-accept-exact');
  acc.hidden = !exact;
  acc.textContent = tRaw('ed.sug_accept_exact', { n: fmtNum(exact) });

  if (!edSug.list.length) { box.innerHTML = `<p class="ga-desc">${t('ed.sug_none')}</p>`; return; }
  box.innerHTML = edSug.list.map((s, i) => {
    const d = edSug.done[s.key];
    if (d) return `<div class="ed-sug ed-sug-done"><span>${edEsc(d.text)}</span><button class="btn-ghost btn-sm" type="button" data-undo="${i}">${t('ed.undo')}</button></div>`;
    const rows = s.members.map(m => `
      <tr${m.existing ? ' class="ed-existing"' : ''}>
        <td><input type="radio" name="ed-main-${i}" value="${m.game_id}" ${+m.game_id === +s.main_game_id ? 'checked' : ''} aria-label="${t('ed.main')}: ${edEsc(m.title)}"></td>
        <td>${edTitleHtml(m.title, m.pc_link)}</td>
        <td><input type="text" class="ed-label" data-game="${m.game_id}" value="${edEsc(m.edition_label)}" maxlength="100" aria-label="${t('ed.col_label')}: ${edEsc(m.title)}"></td>
        <td style="text-align:right;white-space:nowrap">${m.cib_price !== null ? money(m.cib_price) : '—'}</td>
      </tr>`).join('');
    return `
    <article class="ed-sug" data-i="${i}">
      <div class="ed-sug-top">
        ${s.group_id ? `<span class="ed-sug-add">${t('ed.sug_add_to', { title: s.group_title })}</span>` : ''}
        <input type="text" class="ed-sug-name" value="${edEsc(s.title)}" maxlength="255" aria-label="${t('ed.game_name')}">
        <span class="chip ${ED_LEVEL_CHIP[s.level]}">${t('ed.conf_' + s.level)}</span>
        <span class="ga-desc" style="margin:0">${t('ed.why_' + s.level)}${all ? ' · ' + edEsc(s.system_name) : ''}</span>
      </div>
      <table class="admin-table">
        <thead><tr><th>${t('ed.main')}</th><th>${t('ed.col_title')}</th><th>${t('ed.col_label')}</th><th style="text-align:right">${t('common.col.cib_price')}</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <div class="ed-sug-actions">
        <button class="btn-ghost btn-sm" type="button" data-ignore="${i}">${t('ed.not_editions')}</button>
        <button class="btn btn-sm" type="button" data-link="${i}">${t('ed.link')}</button>
      </div>
    </article>`;
  }).join('');
}

/** Links suggestion i with the values in its card. */
async function edLinkSuggestion(i) {
  const s = edSug.list[i];
  const card = document.querySelector(`.ed-sug[data-i="${i}"]`);
  if (!s || !card) return false;
  const main = card.querySelector(`input[name="ed-main-${i}"]:checked`);
  const res = await edPost('link', {
    system_id: s.system_id, group_id: s.group_id, title: card.querySelector('.ed-sug-name').value,
    main_game_id: main ? +main.value : s.main_game_id,
    members: [...card.querySelectorAll('.ed-label')].map(inp => ({ game_id: +inp.dataset.game, edition_label: inp.value })),
  });
  if (!res.ok) return false;
  const added = s.members.filter(m => !m.existing).map(m => m.game_id);
  edSug.done[s.key] = {
    text: tRaw('ed.linked_result', { title: res.group.title, editions: edEditionsText(res.group) }),
    undo: s.group_id ? () => edPost('remove_member', { game_ids: added })
                     : () => edPost('unlink_group', { group_id: res.group.id }),
  };
  return true;
}

async function edIgnoreSuggestion(i) {
  const s = edSug.list[i];
  const ids = {
    system_id: s.system_id,
    game_ids: s.members.filter(m => !m.existing).map(m => m.game_id),
    with_ids: s.members.filter(m => m.existing).map(m => m.game_id),
  };
  const res = await edPost('ignore', ids);
  if (!res.ok) return false;
  edSug.done[s.key] = { text: tRaw('ed.ignored_result'), undo: () => edPost('unignore', ids) };
  return true;
}

async function edAfterChange() {
  edRenderSuggestions();
  await edRefreshList();
}

function edInitSuggestions() {
  const box = document.getElementById('ed-sug-list');
  box.addEventListener('click', async e => {
    const b = e.target.closest('button');
    if (!b) return;
    b.disabled = true;
    if (b.dataset.link !== undefined)   { if (await edLinkSuggestion(+b.dataset.link)) await edAfterChange(); }
    if (b.dataset.ignore !== undefined) { if (await edIgnoreSuggestion(+b.dataset.ignore)) await edAfterChange(); }
    if (b.dataset.undo !== undefined) {
      const s = edSug.list[+b.dataset.undo];
      const res = await edSug.done[s.key].undo();
      if (res.ok) { delete edSug.done[s.key]; await edAfterChange(); }
    }
    b.disabled = false;
  });
  document.getElementById('ed-sug-scan').addEventListener('click', edLoadSuggestions);
  document.getElementById('ed-sug-sys').addEventListener('change', edLoadSuggestions);
  document.getElementById('ed-accept-exact').addEventListener('click', async e => {
    e.target.disabled = true;
    for (let i = 0; i < edSug.list.length; i++) {
      const s = edSug.list[i];
      if (s.level === 'exact' && !edSug.done[s.key]) await edLinkSuggestion(i);
    }
    e.target.disabled = false;
    await edAfterChange();
  });
  edLoadSuggestions();
}

// ══════════ GAME LIST: filter, selection, link dialog ══════════
let edCat = { system_id: 0, games: {}, groups: {} };

function edInitList() {
  const wrap = document.getElementById('games-admin-wrap');
  try { edCat = JSON.parse(document.getElementById('ed-cat-data').textContent); } catch { /* keep the old data */ }
  document.getElementById('game-filter').addEventListener('input', edFilter);
  document.getElementById('ed-link-filter').addEventListener('change', edFilter);
  wrap.querySelector('#games-admin-table').addEventListener('change', e => { if (e.target.matches('.ed-pick')) edUpdateSelection(); });
  document.getElementById('ed-sel-clear').addEventListener('click', () => {
    wrap.querySelectorAll('.ed-pick:checked').forEach(c => { c.checked = false; });
    edUpdateSelection();
  });
  document.getElementById('ed-sel-link').addEventListener('click', edLinkSelection);
  if (typeof bindAjaxForms === 'function') bindAjaxForms(wrap);
  edFilter();
  edUpdateSelection();
}

/** Reloads the game list (after linking), keeping the filter text and link filter. */
async function edRefreshList() {
  const wrap = document.getElementById('games-admin-wrap');
  if (!wrap) return;
  const q = document.getElementById('game-filter').value, lf = document.getElementById('ed-link-filter').value;
  try {
    const html = await fetch(location.href).then(r => r.text());
    const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('games-admin-wrap');
    if (!fresh) return;
    wrap.innerHTML = fresh.innerHTML;
    document.getElementById('game-filter').value = q;
    document.getElementById('ed-link-filter').value = lf;
    edInitList();
  } catch { /* the list stays as it is; a page reload shows the changes */ }
}

function edFilter() {
  const q  = document.getElementById('game-filter').value.toLowerCase();
  const lf = document.getElementById('ed-link-filter').value;
  const tbody = document.querySelector('#games-admin-table tbody');
  const headerHit = {};
  tbody.querySelectorAll('tr.ed-group-row').forEach(tr => {
    headerHit[tr.dataset.groupId] = !!q && (tr.querySelector('strong')?.textContent.toLowerCase() || '').includes(q);
  });
  const visibleIn = {};
  tbody.querySelectorAll('tr[data-game-id]').forEach(tr => {
    const linked = tr.dataset.linked === '1';
    const title  = tr.querySelector('.ed-title')?.textContent.toLowerCase() || '';
    const show = (lf === 'all' || (lf === 'linked') === linked)
              && (!q || title.includes(q) || (linked && headerHit[tr.dataset.groupId]));
    tr.style.display = show ? '' : 'none';
    if (show && linked) visibleIn[tr.dataset.groupId] = true;
  });
  tbody.querySelectorAll('tr.ed-group-row').forEach(tr => { tr.style.display = visibleIn[tr.dataset.groupId] ? '' : 'none'; });
}

/** Ticked games and the linked groups among them. */
function edSelection() {
  const ids = [...document.querySelectorAll('#games-admin-table .ed-pick:checked')].map(c => +c.value);
  const groups = [...new Set(ids.map(id => edCat.games[id]?.group_id).filter(Boolean))];
  return { ids, groups };
}

function edUpdateSelection() {
  const { ids, groups } = edSelection();
  const bar = document.getElementById('ed-selbar');
  bar.hidden = !ids.length;
  document.getElementById('ed-sel-count').textContent = tRaw('ed.n_selected', { n: fmtNum(ids.length) });
  const hint = groups.length > 1 ? tRaw('ed.hint_one_group') : ids.length < 2 ? tRaw('ed.hint_more') : '';
  const btn = document.getElementById('ed-sel-link');
  btn.hidden = !!hint;
  btn.textContent = groups.length ? tRaw('ed.dlg_add', { title: edCat.groups[groups[0]]?.title ?? '' }) : tRaw('ed.link');
  document.getElementById('ed-sel-hint').textContent = hint;
}

function edLinkSelection() {
  const { ids, groups } = edSelection();
  if (groups.length > 1 || ids.length < 2) return;
  const gid = groups[0] || null;
  const grp = gid ? edCat.groups[gid] : null;
  const memberIds = [...new Set([...(grp ? grp.members : []), ...ids])];
  let main = grp?.main_game_id;
  if (!main) {
    const sorted = [...memberIds].sort((a, b) => (edBracket(edCat.games[a].title) !== null) - (edBracket(edCat.games[b].title) !== null) || a - b);
    main = sorted[0];
  }
  edOpenDialog({ groupId: gid, title: grp ? grp.title : edBase(edCat.games[main].title), main, memberIds });
}

function edEditGroup(gid) {
  const grp = edCat.groups[gid];
  if (grp) edOpenDialog({ groupId: +gid, title: grp.title, main: grp.main_game_id, memberIds: grp.members, edit: true });
}

let edDlgState = null, edDlgReturn = null;

function edOpenDialog(st) {
  edDlgState = st;
  edDlgReturn = document.activeElement;
  const dlg = document.getElementById('ed-dlg');
  document.getElementById('ed-dlg-title').textContent =
    st.edit ? st.title : st.groupId ? tRaw('ed.dlg_add', { title: st.title }) : tRaw('ed.link');
  document.getElementById('ed-dlg-save').textContent = st.edit ? tRaw('common.save') : tRaw('ed.btn_link');
  document.getElementById('ed-dlg-name').value = st.title;
  document.getElementById('ed-dlg-rows').innerHTML = st.memberIds.map(id => {
    const g = edCat.games[id];
    const label = g.group_id && g.edition_label ? g.edition_label : edDefaultLabel(g.title);
    return `<tr>
      <td><input type="radio" name="ed-dlg-main" value="${id}" ${+id === +st.main ? 'checked' : ''} aria-label="${t('ed.main')}: ${edEsc(g.title)}"></td>
      <td>${edTitleHtml(g.title, g.pc_link)}</td>
      <td><input type="text" class="ed-dlg-label" data-game="${id}" value="${edEsc(label)}" maxlength="100" aria-label="${t('ed.col_label')}: ${edEsc(g.title)}"></td>
    </tr>`;
  }).join('');
  dlg.classList.add('open');
  document.getElementById('ed-dlg-name').focus();
}

function edCloseDialog() {
  document.getElementById('ed-dlg').classList.remove('open');
  edDlgState = null;
  edDlgReturn?.focus?.();
}

async function edSaveDialog() {
  const st = edDlgState;
  if (!st) return;
  const main = document.querySelector('#ed-dlg input[name="ed-dlg-main"]:checked');
  const btn = document.getElementById('ed-dlg-save');
  btn.disabled = true;
  const res = await edPost('link', {
    system_id: edCat.system_id, group_id: st.groupId, title: document.getElementById('ed-dlg-name').value,
    main_game_id: main ? +main.value : st.main,
    members: [...document.querySelectorAll('#ed-dlg .ed-dlg-label')].map(inp => ({ game_id: +inp.dataset.game, edition_label: inp.value })),
  });
  btn.disabled = false;
  if (!res.ok) return;
  edToast(tRaw('ed.linked_result', { title: res.group.title, editions: edEditionsText(res.group) }));
  edCloseDialog();
  await edRefreshList();
  edLoadSuggestions();
}

async function edUnlinkGroup(gid) {
  const grp = edCat.groups[gid];
  if (!confirm(tRaw('ed.confirm_unlink', { title: grp?.title ?? '' }))) return;
  if ((await edPost('unlink_group', { group_id: +gid })).ok) { await edRefreshList(); edLoadSuggestions(); }
}

async function edRemoveMember(id) {
  if ((await edPost('remove_member', { game_id: +id })).ok) { await edRefreshList(); edLoadSuggestions(); }
}

function edInitDialog() {
  const dlg = document.getElementById('ed-dlg');
  document.getElementById('ed-dlg-save').addEventListener('click', edSaveDialog);
  dlg.addEventListener('click', e => { if (e.target === dlg) edCloseDialog(); });
  dlg.addEventListener('keydown', e => {
    if (e.key === 'Escape') edCloseDialog();
    if (e.key === 'Enter' && e.target.matches('input[type="text"]')) edSaveDialog();
  });
}

// ══════════ AFTER AN IMPORT ══════════
/**
 * Adds "n possible edition groups found" with a link to the suggestions to box.
 * systemIds: systems that got new games.
 */
async function edImportNotice(box, systemIds) {
  systemIds = [...new Set((systemIds || []).map(Number).filter(Boolean))];
  if (!box || !systemIds.length) return;
  const res = await fetch(ED_API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action: 'count', system_ids: systemIds }) })
    .then(r => r.json()).catch(() => ({ ok: false }));
  if (!res.ok || !res.count) return;
  // On the catalogue page the card is right here: rescan it
  if (document.getElementById('ed-sug-list')) {
    if (systemIds.length > 1) document.getElementById('ed-sug-sys').value = '0';
    edLoadSuggestions();
  }
  const url = `${BASE}/settings.php?s=catalogue&sys=${systemIds[0]}${systemIds.length > 1 ? '&esys=all' : ''}#edition-suggestions`;
  const div = document.createElement('div');
  div.className = 'warn';
  div.innerHTML = `• ${tn('ed.after_import', res.count)} <a href="${edEsc(url)}">${t('ed.after_import_link')} →</a>`;
  box.appendChild(div);
}

// ── START: only what the current page shows ──
if (document.getElementById('ed-sug-list')) edInitSuggestions();
if (document.getElementById('games-admin-wrap')) edInitList();
if (document.getElementById('ed-dlg')) edInitDialog();
