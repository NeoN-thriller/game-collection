/* ═══════════════════════════════════════════
   COMPILATIONS (admin) — Settings › Catalogue: compilation suggestions, the overview, and the
   editor dialog (also opened from the "Contents" button in the game list).
   Talks to api/compilations.php. Needs editions-admin.js (edEsc, edToast, edTitleHtml, edRefreshList) and BASE.
   ═══════════════════════════════════════════ */
const COMP_API = BASE + '/api/compilations.php';

async function compPost(action, data = {}) {
  try {
    const res = await fetch(COMP_API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action, ...data }) }).then(r => r.json());
    if (!res.ok) edToast(res.error || tRaw('common.err_unknown'), true);
    return res;
  } catch (e) {
    edToast(tRaw('common.err_prefix', { error: e.message }), true);
    return { ok: false };
  }
}

/** Reloads the overview and the game list (chips), keeping the rest of the page as it is. */
async function compRefreshPage() {
  try {
    const html = await fetch(location.href).then(r => r.text());
    const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('comp-existing');
    if (fresh) document.getElementById('comp-existing').innerHTML = fresh.innerHTML;
  } catch { /* a page reload shows the changes */ }
  if (typeof edRefreshList === 'function') await edRefreshList();
}

// ══════════ SUGGESTIONS ══════════
const compSug = { list: [], done: {} };   // done: compilation game id → {text, undo}

async function compLoadSuggestions() {
  const box = document.getElementById('comp-sug-list');
  box.innerHTML = `<p class="ga-desc">${t('ed.sug_scanning')}</p>`;
  const res = await compPost('suggest', { system_id: +document.getElementById('comp-sug-sys').value || null });
  compSug.list = res.ok ? res.suggestions : [];
  compSug.done = {};
  compRenderSuggestions();
}

function compRenderSuggestions() {
  const box = document.getElementById('comp-sug-list');
  const all = +document.getElementById('comp-sug-sys').value === 0;
  const open = compSug.list.filter(s => !compSug.done[s.game_id]);
  const count = document.getElementById('comp-sug-count');
  count.hidden = !open.length;
  count.textContent = tRaw('ed.sug_to_review', { n: fmtNum(open.length) });

  if (!compSug.list.length) { box.innerHTML = `<p class="ga-desc">${t('comp.sug_none')}</p>`; return; }
  box.innerHTML = compSug.list.map((s, i) => {
    const d = compSug.done[s.game_id];
    if (d) return `<div class="ed-sug ed-sug-done"><span>${edEsc(d.text)}</span><button class="btn-ghost btn-sm" type="button" data-cundo="${i}">${t('ed.undo')}</button></div>`;
    const items = s.items.length
      ? `<ul class="comp-sug-items">${s.items.map(it => `<li><label><input type="checkbox" class="comp-pick" value="${it.game_id}" checked> ${edTitleHtml(it.title, it.pc_link)}</label></li>`).join('')}</ul>`
      : `<p class="ga-desc" style="margin:0">${t('comp.sug_no_items')}</p>`;
    return `
    <article class="ed-sug" data-ci="${i}">
      <div class="ed-sug-top">
        <strong>${edTitleHtml(s.title, s.pc_link)}</strong>
        <span class="chip ${s.keyword ? 'chip-y' : 'chip-blue'}">${t(s.keyword ? 'comp.why_keyword' : 'comp.why_names')}</span>
        ${all ? `<span class="ga-desc" style="margin:0">${edEsc(s.system_name)}</span>` : ''}
      </div>
      <div class="ga-desc" style="margin:0">${t('comp.sug_contains')}</div>
      ${items}
      <div class="ed-sug-actions">
        <button class="btn-ghost btn-sm" type="button" data-cdismiss="${i}">${t('comp.not_compilation')}</button>
        <button class="btn-ghost btn-sm" type="button" data-cedit="${i}">${t('comp.edit')}</button>
        <button class="btn btn-sm" type="button" data-csave="${i}">${t('comp.save_compilation')}</button>
      </div>
    </article>`;
  }).join('');
}

function compMarkSaved(s, n) {
  compSug.done[s.game_id] = {
    text: tRaw('comp.saved_result', { title: s.title, n: fmtNum(n) }),
    undo: () => compPost('save', { game_id: s.game_id, items: [] }),
  };
}

async function compSaveSuggestion(i) {
  const s = compSug.list[i];
  const card = document.querySelector(`.ed-sug[data-ci="${i}"]`);
  const ids = [...card.querySelectorAll('.comp-pick:checked')].map(c => +c.value);
  if (!ids.length) { edToast(tRaw('comp.err_none'), true); return false; }
  if (!(await compPost('save', { game_id: s.game_id, items: ids })).ok) return false;
  compMarkSaved(s, ids.length);
  return true;
}

async function compDismissSuggestion(i) {
  const s = compSug.list[i];
  if (!(await compPost('dismiss', { game_id: s.game_id })).ok) return false;
  compSug.done[s.game_id] = { text: tRaw('comp.dismissed_result', { title: s.title }), undo: () => compPost('undismiss', { game_id: s.game_id }) };
  return true;
}

function compInitSuggestions() {
  document.getElementById('comp-sug-list').addEventListener('click', async e => {
    const b = e.target.closest('button');
    if (!b) return;
    const d = b.dataset;
    if (d.cedit !== undefined) {
      const s = compSug.list[+d.cedit];
      const card = b.closest('.ed-sug');
      compOpenEditor(s.game_id, { preset: [...card.querySelectorAll('.comp-pick:checked')].map(c => +c.value) });
      return;
    }
    b.disabled = true;
    let changed = false;
    if (d.csave !== undefined)    changed = await compSaveSuggestion(+d.csave);
    if (d.cdismiss !== undefined) changed = await compDismissSuggestion(+d.cdismiss);
    if (d.cundo !== undefined) {
      const s = compSug.list[+d.cundo];
      if ((await compSug.done[s.game_id].undo()).ok) { delete compSug.done[s.game_id]; changed = true; }
    }
    b.disabled = false;
    if (changed) { compRenderSuggestions(); await compRefreshPage(); }
  });
  document.getElementById('comp-sug-scan').addEventListener('click', compLoadSuggestions);
  document.getElementById('comp-sug-sys').addEventListener('change', compLoadSuggestions);
  compLoadSuggestions();
}

// ══════════ EDITOR DIALOG ══════════
let compDlg = { gameId: null, title: '', items: [], games: [], wasComp: false };

/** Opens the editor for a game. opts.preset: the contents to start with (from a suggestion card). */
async function compOpenEditor(gameId, opts = {}) {
  const res = await compPost('get', { game_id: gameId });
  if (!res.ok) return;
  // Titles that occur twice in a system get their id, so the picker can tell them apart
  const seen = {};
  res.games.forEach(g => { seen[g.title] = (seen[g.title] || 0) + 1; });
  const games = res.games.map(g => ({ ...g, label: seen[g.title] > 1 ? `${g.title} · #${g.id}` : g.title }));
  compDlg = { gameId, title: res.game.title, items: (opts.preset ?? res.items).slice(), games, wasComp: res.items.length > 0 };
  document.getElementById('comp-dlg-title').textContent = tRaw('comp.dlg_title', { title: res.game.title });
  document.getElementById('comp-dlg-games').innerHTML = games.map(g => `<option value="${edEsc(g.label)}"></option>`).join('');
  document.getElementById('comp-dlg-add').value = '';
  document.getElementById('comp-dlg-clear').hidden = !compDlg.wasComp;
  compRenderEditor();
  document.getElementById('comp-dlg').classList.add('open');
  document.getElementById('comp-dlg-add').focus();
}

function compCloseEditor() { document.getElementById('comp-dlg').classList.remove('open'); }

function compRenderEditor() {
  const byId = Object.fromEntries(compDlg.games.map(g => [g.id, g]));
  document.getElementById('comp-dlg-items').innerHTML = compDlg.items.length
    ? compDlg.items.map(id => `<li><span>${edEsc(byId[id]?.label ?? '#' + id)}</span>
        <button class="btn-icon" type="button" data-cremove="${id}" aria-label="${t('common.delete')}">✕</button></li>`).join('')
    : `<li class="ga-desc" style="border:0">${t('comp.dlg_empty')}</li>`;
}

function compAddFromInput() {
  const inp = document.getElementById('comp-dlg-add');
  const v = inp.value.trim();
  if (!v) return;
  const g = compDlg.games.find(x => x.label === v) || compDlg.games.find(x => x.label.toLowerCase() === v.toLowerCase());
  if (!g) { edToast(tRaw('comp.err_pick'), true); return; }
  if (!compDlg.items.includes(g.id)) compDlg.items.push(g.id);
  inp.value = '';
  compRenderEditor();
}

async function compSaveEditor() {
  const { gameId, items } = compDlg;
  if (!(await compPost('save', { game_id: gameId, items })).ok) return;
  compCloseEditor();
  edToast(items.length ? tRaw('comp.saved_result', { title: compDlg.title, n: fmtNum(items.length) }) : tRaw('comp.cleared', { title: compDlg.title }));
  // A suggestion for this game is settled now
  const s = compSug.list.find(x => x.game_id === gameId);
  if (s && items.length) { compMarkSaved(s, items.length); compRenderSuggestions(); }
  await compRefreshPage();
}

async function compClearEditor() {
  if (!confirm(tRaw('comp.confirm_clear', { title: compDlg.title }))) return;
  const { gameId, title } = compDlg;
  if (!(await compPost('save', { game_id: gameId, items: [] })).ok) return;
  await compPost('dismiss', { game_id: gameId });   // and don't suggest it again
  compCloseEditor();
  edToast(tRaw('comp.cleared', { title }));
  await compRefreshPage();
}

function compInitEditor() {
  document.getElementById('comp-dlg-items').addEventListener('click', e => {
    const b = e.target.closest('[data-cremove]');
    if (!b) return;
    compDlg.items = compDlg.items.filter(id => id !== +b.dataset.cremove);
    compRenderEditor();
  });
  document.getElementById('comp-dlg-add-btn').addEventListener('click', compAddFromInput);
  document.getElementById('comp-dlg-add').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); compAddFromInput(); } });
  document.getElementById('comp-dlg-save').addEventListener('click', compSaveEditor);
  document.getElementById('comp-dlg-clear').addEventListener('click', compClearEditor);
  document.getElementById('comp-dlg').addEventListener('click', e => { if (e.target.id === 'comp-dlg') compCloseEditor(); });
}

// ── START ──
if (document.getElementById('comp-dlg')) compInitEditor();
if (document.getElementById('comp-sug-list')) compInitSuggestions();
