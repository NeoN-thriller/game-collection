/* ═══════════════════════════════════════════
   COPY HISTORY — drawer section on collection.php (#d-history): the signed-in user's own changes
   to this copy, from the activity log (api/activity.php?scope=copy). Only while Log settings
   lets users see their history (LOG_HISTORY on the page).

   HistoryDrawer.load({entryId})
   ═══════════════════════════════════════════ */
const HistoryDrawer = (() => {
  const SHOW = 5;
  let entry = null, rows = [], open = false;
  const box = () => document.getElementById('d-history');
  const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  async function load({ entryId }) {
    const el = box();
    if (!el) return;
    entry = entryId; rows = []; open = false;
    if (!entryId) { el.hidden = true; el.innerHTML = ''; return; }
    el.hidden = false;
    el.innerHTML = `<div class="section-label">${t('log.history')}</div><p class="d-hint">${t('cr.loading')}</p>`;
    const res = await fetch(`${BASE}/api/activity.php?scope=copy&entry_id=${encodeURIComponent(entryId)}`)
      .then(r => r.json()).catch(() => ({ ok: false }));
    if (entry !== entryId) return;   // another copy was opened meanwhile
    if (!res.ok) { el.hidden = true; el.innerHTML = ''; return; }
    rows = res.rows || [];
    render();
  }

  function chip(c) {
    const label = c.label ? `${e(c.label)} · ` : '';
    return c.from === null
      ? `<span class="hist-chip">${label}${e(c.to)}</span>`
      : `<span class="hist-chip">${label}<s>${e(c.from)}</s> → ${e(c.to)}</span>`;
  }

  function render() {
    const el = box();
    if (!rows.length) { el.innerHTML = `<div class="section-label">${t('log.history')}</div><p class="d-hint">${t('log.history_none')}</p>`; return; }
    const shown = open ? rows : rows.slice(0, SHOW);
    el.innerHTML = `
      <div class="section-label hist-head"><span>${t('log.history')}</span><span>${tn('log.n_changes', rows.length)}</span></div>
      <ol class="hist-list">
        ${shown.map(r => `<li class="hist-item log-c-${e(r.category)}">
          <span class="hist-dot" aria-hidden="true"></span>
          <div class="hist-when">${e(r.day_label)} ${e(r.time)} · ${t('log.you')}</div>
          <div>${e(r.summary)}</div>
          ${r.changes.length ? `<div class="hist-chips">${r.changes.map(chip).join('')}</div>` : ''}
          ${r.note ? `<div class="hist-note">${e(r.note)}</div>` : ''}
        </li>`).join('')}
      </ol>
      ${rows.length > SHOW ? `<button type="button" class="btn-ghost hist-more" aria-expanded="${open}">${open ? t('log.show_fewer') : tn('log.show_older', rows.length - SHOW)}</button>` : ''}
      <p class="hist-note">${t('log.history_note')}</p>`;
    el.querySelector('.hist-more')?.addEventListener('click', () => { open = !open; render(); });
  }

  return { load };
})();
