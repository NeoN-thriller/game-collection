/* ═══════════════════════════════════════════
   EDITIONS in the edit drawer (collection.php and wishlist.php)
   Fills the placeholders #d-ed-edition, #d-ed-variant, #d-ed-others, #d-ed-wish-any and
   #d-ed-prices-of for one copy, and adds the edition fields to the save payload.

   EdDrawer.load({
     group,          // {title, members:[{id, title, edition_label}]} or null (not linked)
     game,           // the game (edition) of this copy: {id, title, edition_label, …}
     copy,           // the copy's entry ({} for a new copy)
     trackVariants,  // bool — show the Variant select
     variants,       // the user's variant labels
     wishDefault,    // 'any' | 'exact' — users.edition_wishlist
     canEdit,        // false on a public wishlist
     status,         // gameId → {owned, wished, price}
     onOpen,         // gameId → open that edition's drawer (null: not possible here)
     canOpen,        // optional gameId → bool: whether onOpen works for that edition
     onWish,         // async gameId → toggles copy 1 of that edition on the wishlist
   })
   EdDrawer.payload() → {variant?, wishlist_any?, move_to_game_id?}
   ═══════════════════════════════════════════ */
const EdDrawer = (() => {
  let st = null;
  const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const el = id => document.getElementById(id);
  const label = g => g.edition_label || g.title;

  function section(id, html) {
    const box = el(id);
    if (!box) return;
    box.innerHTML = html;
    box.hidden = !html;
  }

  function renderOthers() {
    const others = st.group ? st.group.members.filter(m => +m.id !== +st.game.id) : [];
    if (!others.length) { section('d-ed-others', ''); return; }
    const rows = others.map(m => {
      const s = st.status(m.id) || {};
      const name = st.onOpen && (!st.canOpen || st.canOpen(m.id))
        ? `<button type="button" class="ed-other-name" data-open="${m.id}">${e(label(m))}</button>`
        : `<span class="ed-other-name">${e(label(m))}</span>`;
      const info = [s.owned ? tRaw('ed.owned') : tRaw('ed.not_owned'), s.price != null ? money(s.price) : null].filter(Boolean).join(' · ');
      const wish = st.canEdit && st.onWish
        ? `<button type="button" class="chip ${s.wished ? 'chip-wish' : 'chip-n'}" data-wish="${m.id}" aria-pressed="${s.wished ? 'true' : 'false'}">♥ ${e(tRaw(s.wished ? 'ed.wishlisted' : 'ed.wish_add'))}</button>`
        : (s.wished ? `<span class="chip chip-wish">♥ ${e(tRaw('ed.wishlisted'))}</span>` : '');
      return `<div class="ed-other">${name}<span class="ed-other-info">${e(info)}</span>${wish}</div>`;
    }).join('');
    section('d-ed-others', `<div class="section-label">${t('ed.other_editions')}</div>${rows}`);
  }

  function load(opts) {
    st = opts;
    const { group, game, copy } = opts;

    // Edition: "This copy is the …" — changing it moves the copy on save
    section('d-ed-edition', group ? `
      <div class="section-label">${t('ed.edition')}</div>
      <div class="field">
        <label for="d-edition">${t('ed.this_copy_is')}</label>
        <select id="d-edition">${group.members.map(m => `<option value="${m.id}"${+m.id === +game.id ? ' selected' : ''}>${e(label(m))}</option>`).join('')}</select>
      </div>
      <p class="d-hint">${t('ed.move_hint')}</p>` : '');

    // Variant (only while the user tracks variants); a stored value that is no longer in the list stays selectable
    if (opts.trackVariants) {
      const cur = copy.variant || '';
      const list = [...opts.variants];
      if (cur && !list.includes(cur)) list.push(cur);
      section('d-ed-variant', `
        <div class="section-label">${t('ed.variant')}</div>
        <div class="field">
          <label for="d-variant">${t('ed.variant_label')}</label>
          <select id="d-variant"><option value="">${t('ed.no_variant')}</option>${list.map(v => `<option${v === cur ? ' selected' : ''}>${e(v)}</option>`).join('')}</select>
        </div>
        <p class="d-hint">${t('ed.variant_hint')}</p>`);
    } else section('d-ed-variant', '');

    // Prices belong to this edition
    section('d-ed-prices-of', group && game.edition_label ? `<p class="d-hint">${t('ed.prices_of', { label: game.edition_label })}</p>` : '');

    // Wishlist: any edition, or exactly this one
    const anyOn = copy.wishlist ? !!copy.wishlist_any : opts.wishDefault === 'any';
    section('d-ed-wish-any', group ? `
      <div class="toggle-row" style="margin-top:8px">
        <label class="toggle"><input type="checkbox" id="d-wish-any"${anyOn ? ' checked' : ''}${opts.canEdit ? '' : ' disabled'}><span class="toggle-slider"></span></label>
        <label class="toggle-label" for="d-wish-any">${t('ed.wish_any_toggle')}</label>
      </div>
      <p class="d-hint">${t('ed.wish_any_hint')}</p>` : '');

    renderOthers();
  }

  function payload() {
    if (!st) return {};
    const out = {};
    const v = el('d-variant');
    if (st.trackVariants && v) out.variant = v.value;
    if (st.group) {
      const any = el('d-wish-any');
      if (any) out.wishlist_any = any.checked ? 1 : 0;
      const ed = el('d-edition');
      if (ed && +ed.value !== +st.game.id) out.move_to_game_id = +ed.value;
    }
    return out;
  }

  /** "PAL PlayStation · Platinum edition" */
  function subtitle(base, game, group) {
    return group && game.edition_label ? `${base} · ${tRaw('ed.edition_of', { label: game.edition_label })}` : base;
  }

  document.addEventListener('click', async ev => {
    if (!st) return;
    const open = ev.target.closest('#d-ed-others [data-open]');
    if (open) { st.onOpen(+open.dataset.open); return; }
    const wish = ev.target.closest('#d-ed-others [data-wish]');
    if (wish) {
      wish.disabled = true;
      await st.onWish(+wish.dataset.wish);
      renderOthers();
    }
  });

  return { load, payload, subtitle, refresh: () => st && renderOthers() };
})();
