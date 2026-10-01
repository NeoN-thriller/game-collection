/* ═══════════════════════════════════════════
   LIGHTBOX — one photo viewer for collection.php, wishlist.php and grade.php.
   Builds its own markup on first use (styles: .lightbox in main.css).

   Lightbox.open(items, start, opts)
     items  [{src, caption?}]
     opts   dots:     show a dot per photo
            onRotate: async (index, degrees) → new src (or null); shows the rotate buttons
   Lightbox.close(), Lightbox.isOpen(), Lightbox.setSrc(index, src)
   Keys while open: ← → to browse, Esc to close (other Escape handlers on the page don't fire).
   ═══════════════════════════════════════════ */
const Lightbox = (() => {
  let el = null, items = [], idx = 0, opts = {}, returnFocus = null;
  const tx = (key, fallback, vars) => (typeof tRaw === 'function' && typeof LANG !== 'undefined' && key in LANG) ? tRaw(key, vars) : fallback;

  function build() {
    el = document.createElement('div');
    el.className = 'lightbox';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.innerHTML = `
      <button type="button" class="lb-close" aria-label="${tx('common.close', 'Close')}">✕</button>
      <img class="lb-img" alt="">
      <div class="lb-caption" hidden></div>
      <div class="lb-nav">
        <button type="button" class="lb-btn lb-prev">← ${tx('drawer.prev', 'Previous')}</button>
        <span class="lb-label"></span>
        <button type="button" class="lb-btn lb-next">${tx('drawer.next', 'Next')} →</button>
      </div>
      <div class="lb-dots" hidden></div>
      <div class="lb-nav lb-rotate" hidden>
        <button type="button" class="lb-btn" data-rot="-90">↺ ${tx('drawer.rotate_left', 'Rotate left')}</button>
        <button type="button" class="lb-btn" data-rot="90">↻ ${tx('drawer.rotate_right', 'Rotate right')}</button>
      </div>`;
    document.body.appendChild(el);
    el.addEventListener('click', async e => {
      if (e.target === el || e.target.closest('.lb-close')) return close();
      if (e.target.closest('.lb-prev')) return go(-1);
      if (e.target.closest('.lb-next')) return go(1);
      const dot = e.target.closest('[data-dot]');
      if (dot) { idx = +dot.dataset.dot; return show(); }
      const rot = e.target.closest('[data-rot]');
      if (rot && opts.onRotate) {
        rot.disabled = true;
        const src = await opts.onRotate(idx, +rot.dataset.rot);
        rot.disabled = false;
        if (src) setSrc(idx, src);
      }
    });
    // Capture phase on window: runs before the page's own keydown handlers and stops them while open
    window.addEventListener('keydown', e => {
      if (!isOpen()) return;
      if (e.key === 'Escape') close();
      else if (e.key === 'ArrowLeft') go(-1);
      else if (e.key === 'ArrowRight') go(1);
      else return;
      e.preventDefault();
      e.stopPropagation();
    }, true);
  }

  function show() {
    const it = items[idx] || {};
    el.querySelector('.lb-img').src = it.src || '';
    const cap = el.querySelector('.lb-caption');
    cap.textContent = it.caption || '';
    cap.hidden = !it.caption;
    el.querySelector('.lb-label').textContent = `${idx + 1} / ${items.length}`;
    const many = items.length > 1;
    el.querySelectorAll('.lb-prev, .lb-next').forEach(b => { b.hidden = !many; });
    const dots = el.querySelector('.lb-dots');
    dots.hidden = !(opts.dots && many);
    if (!dots.hidden) dots.innerHTML = items.map((_, i) =>
      `<button type="button" class="lb-dot${i === idx ? ' on' : ''}" data-dot="${i}" aria-label="${i + 1} / ${items.length}"${i === idx ? ' aria-current="true"' : ''}></button>`).join('');
  }

  function go(step) {
    if (items.length < 2) return;
    idx = (idx + step + items.length) % items.length;
    show();
  }

  function open(list, start = 0, options = {}) {
    if (!list || !list.length) return;
    if (!el) build();
    items = list.map(x => typeof x === 'string' ? { src: x } : { ...x });
    idx = Math.max(0, Math.min(items.length - 1, start | 0));
    opts = options;
    el.querySelector('.lb-rotate').hidden = !opts.onRotate;
    returnFocus = document.activeElement;
    show();
    el.classList.add('open');
    el.querySelector('.lb-close').focus();
  }

  function close() {
    if (!el) return;
    el.classList.remove('open');
    returnFocus?.focus?.();
  }

  function isOpen() { return !!el && el.classList.contains('open'); }

  function setSrc(i, src) {
    if (!items[i]) return;
    items[i].src = src;
    if (i === idx && isOpen()) el.querySelector('.lb-img').src = src;
  }

  return { open, close, isOpen, setSrc, current: () => idx };
})();
