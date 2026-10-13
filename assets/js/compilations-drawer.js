/* ═══════════════════════════════════════════
   COMPILATIONS in the edit drawer (collection.php and wishlist.php)
   Fills #d-comp with what a compilation contains, and which compilations a game is in.

   CompDrawer.load({
     contains,   // [{id, title, owned}] — this game is a compilation of these games
     alsoIn,     // [{id, title, owned}] — compilations this game is in
     onOpen,     // gameId → open that game's drawer, or null (not possible on this page)
     canOpen,    // optional gameId → bool: whether onOpen works for that game
   })
   ═══════════════════════════════════════════ */
const CompDrawer = (() => {
  let st = null;
  const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function list(items) {
    return `<ul class="comp-list-d">${items.map(i => `<li>
      ${st.onOpen && (!st.canOpen || st.canOpen(i.id)) ? `<button type="button" class="ed-other-name" data-comp-open="${i.id}">${e(i.title)}</button>` : `<span>${e(i.title)}</span>`}
      ${i.owned ? `<span class="chip chip-y">✓ ${t('comp.owned')}</span>` : ''}
    </li>`).join('')}</ul>`;
  }

  function load({ contains = [], alsoIn = [], onOpen = null, canOpen = null }) {
    st = { onOpen, canOpen };
    const box = document.getElementById('d-comp');
    if (!box) return;
    let html = '';
    if (contains.length) html += `<div class="section-label">${t('comp.contains_n', { n: fmtNum(contains.length) })}</div>${list(contains)}`;
    if (alsoIn.length)   html += `<div class="section-label"${contains.length ? ' style="margin-top:10px"' : ''}>${t('comp.also_in')}</div>${list(alsoIn)}`;
    box.innerHTML = html;
    box.hidden = !html;
  }

  document.addEventListener('click', ev => {
    const b = ev.target.closest('#d-comp [data-comp-open]');
    if (b && st?.onOpen) st.onOpen(+b.dataset.compOpen);
  });

  return { load };
})();
