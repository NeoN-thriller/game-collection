/* ═══════════════════════════════════════════
   DEFECT INFO — the ⓘ explanation of a grading defect (admin-managed: a description and up to
   3 example photos). Hover shows the text as a tooltip; a click or tap opens a small popup with the
   text and the photos (a photo opens larger inside the popup). Phones need the tap.
   The trigger is the defect's name itself (with a small ⓘ after it); hovering it shows a hover card
   with the text and the photos, like the condition hover card in the collection table.
   Used by grading.js (the drawer, collection.php / wishlist.php), grade.php and lightbox.js.

   Data: the defects in window.GRADING.templates (drawer pages), or
         window.DEFECT_INFO = {defect_id: {name, description, photos:[{url}]}} (the public report).
   DefectInfo.has(id)            → true when the defect has an explanation or photos
   DefectInfo.button(ids, label, text) → HTML of the trigger for one defect, or several (a pick-one level group):
                                   the text (e.g. the defect name) plus ⓘ; '' when none has info (show the plain name then)
   ═══════════════════════════════════════════ */
const DefectInfo = (() => {
  let byId = null, pop = null, anchor = null, ids = [], hover = null, hoverTimer = null;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const tx = (key, fallback, vars) => (typeof tRaw === 'function' && typeof LANG !== 'undefined' && key in LANG) ? tRaw(key, vars) : fallback;

  function index() {
    if (byId) return byId;
    byId = Object.assign({}, window.DEFECT_INFO || {});
    Object.values(window.GRADING?.templates || {}).forEach(tp => (tp.categories || []).forEach(c => (c.defects || []).forEach(d => {
      if (!byId[d.id]) byId[d.id] = { name: d.name, description: d.description || '', photos: d.photos || [] };
    })));
    return byId;
  }
  const info = id => index()[id] || null;
  function has(id) {
    const i = info(id);
    return !!i && (!!(i.description || '').trim() || (i.photos || []).length > 0);
  }

  function button(list, label, text = '') {
    const ok = [].concat(list).map(Number).filter(has);
    if (!ok.length) return '';
    return `<button type="button" class="di-name" data-di="${ok.join(',')}" aria-label="${esc(label)}" aria-haspopup="dialog" aria-expanded="false">`
      + `${text ? `<span class="di-text">${esc(text)}</span>` : ''}<span class="di-i" aria-hidden="true">ⓘ</span></button>`;
  }

  // ── hover card (mouse only) ──
  const canHover = () => window.matchMedia && window.matchMedia('(hover: hover)').matches;

  function showHover(btn) {
    hideHover();
    const list = btn.dataset.di.split(',').map(Number).filter(has);
    if (!list.length || pop) return;
    hover = document.createElement('div');
    hover.className = 'di-hover';
    hover.setAttribute('aria-hidden', 'true');
    // Text only; the photos are in the popup (click), which the grey hint at the bottom says
    const photos = list.reduce((n, id) => n + (info(id).photos || []).length, 0);
    hover.innerHTML = list.map(id => {
      const i = info(id);
      return `<div class="di-entry"><strong>${esc(i.name)}</strong>${i.description ? `<p>${esc(i.description)}</p>` : ''}</div>`;
    }).join('') + (photos ? `<p class="di-hint">${esc(tx('di.click_photos', 'Click to view photos', { n: photos }))}</p>` : '');
    document.body.appendChild(hover);
    const r = btn.getBoundingClientRect(), w = hover.offsetWidth, h = hover.offsetHeight;
    let left = r.left, top = r.bottom + 6;
    if (left + w > window.innerWidth - 8) left = window.innerWidth - w - 8;
    if (top + h > window.innerHeight - 8) top = r.top - h - 6;
    hover.style.left = Math.max(8, left) + 'px';
    hover.style.top = Math.max(8, top) + 'px';
  }

  function hideHover() {
    clearTimeout(hoverTimer);
    if (hover) { hover.remove(); hover = null; }
  }

  document.addEventListener('mouseover', e => {
    const b = e.target.closest && e.target.closest('[data-di]');
    if (!b || !canHover()) return;
    if (e.relatedTarget && b.contains(e.relatedTarget)) return;   // moving inside the same trigger
    clearTimeout(hoverTimer);
    hoverTimer = setTimeout(() => showHover(b), 200);
  });
  document.addEventListener('mouseout', e => {
    const b = e.target.closest && e.target.closest('[data-di]');
    if (b && !(e.relatedTarget && b.contains(e.relatedTarget))) hideHover();
  });

  // ── popup ──
  function entriesHtml() {
    return ids.map(id => {
      const i = info(id);
      return `<div class="di-entry">
        <strong>${esc(i.name)}</strong>
        ${i.description ? `<p>${esc(i.description)}</p>` : ''}
        ${(i.photos || []).length ? `<div class="di-photos">${i.photos.map((p, n) =>
          `<button type="button" class="di-photo" data-di-big="${esc(p.url)}" aria-label="${esc(tx('di.example_n', 'Example ' + (n + 1), { n: n + 1 }))}"><img src="${esc(p.url)}" alt="" loading="lazy"></button>`).join('')}</div>` : ''}
      </div>`;
    }).join('');
  }

  function render(bigUrl = null) {
    pop.innerHTML = `
      <div class="di-head">
        ${bigUrl ? `<button type="button" class="btn-ghost btn-sm" data-di-back>← ${esc(tx('di.back', 'Back'))}</button>` : `<span class="di-title">${esc(tx('di.title', 'What does this mean?'))}</span>`}
        <button type="button" class="di-close" data-di-close aria-label="${esc(tx('common.close', 'Close'))}">✕</button>
      </div>
      ${bigUrl ? `<img class="di-big" src="${esc(bigUrl)}" alt="">` : entriesHtml()}`;
    place();
  }

  function place() {
    if (!pop || !anchor) return;
    const r = anchor.getBoundingClientRect(), vw = window.innerWidth, vh = window.innerHeight;
    const w = Math.min(340, vw - 16);
    pop.style.width = w + 'px';
    let left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), vw - w - 8);
    let top = r.bottom + 6;
    const h = pop.offsetHeight;
    if (top + h > vh - 8 && r.top - h - 6 > 8) top = r.top - h - 6;
    pop.style.left = left + 'px';
    pop.style.top = Math.max(8, Math.min(top, vh - h - 8)) + 'px';
  }

  function open(btn) {
    hideHover();
    close();
    ids = btn.dataset.di.split(',').map(Number).filter(has);
    if (!ids.length) return;
    anchor = btn;
    pop = document.createElement('div');
    pop.className = 'di-pop';
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-label', info(ids[0]).name);
    document.body.appendChild(pop);
    btn.setAttribute('aria-expanded', 'true');
    render();
    pop.querySelector('[data-di-close]').focus();
  }

  function close() {
    if (!pop) return;
    pop.remove();
    pop = null;
    if (anchor) { anchor.setAttribute('aria-expanded', 'false'); if (document.body.contains(anchor)) anchor.focus({ preventScroll: true }); }
    anchor = null;
  }

  document.addEventListener('click', e => {
    const b = e.target.closest('[data-di]');
    if (b) { e.preventDefault(); e.stopPropagation(); return pop && anchor === b ? close() : open(b); }
    if (!pop) return;
    // A click outside only closes the popup (so it doesn't also close the drawer or viewer behind it)
    if (!pop.contains(e.target)) { e.preventDefault(); e.stopPropagation(); return close(); }
    if (e.target.closest('[data-di-close]')) return close();
    if (e.target.closest('[data-di-back]')) return render();
    const big = e.target.closest('[data-di-big]');
    if (big) render(big.dataset.diBig);
  }, true);
  // Escape closes the popup only (not the drawer or viewer behind it)
  window.addEventListener('keydown', e => {
    if (pop && e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
  }, true);
  window.addEventListener('resize', place);
  window.addEventListener('scroll', () => { hideHover(); place(); }, true);

  return { has, button, close };
})();
