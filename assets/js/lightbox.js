/* ═══════════════════════════════════════════
   LIGHTBOX — one photo viewer for collection.php, wishlist.php and grade.php.
   Builds its own markup on first use (styles: .lightbox in main.css).

   Lightbox.open(items, start, opts)
     items  [{src, caption?, part?, defects?, jump?}]   (all text is set as text, never as HTML)
              part:    {name, score, weight, color} → a chip plus "Score 84 · weight 35%"
              defects: [{name, count, deduction}]   → one row each
              jump:    element id of a <details> → "See <part> in the breakdown" closes the viewer and opens it;
                       or a function (called after closing), with jumpLabel as the button text
     opts   dots:     show a dot per photo
            onRotate: async (index, degrees) → new src (or null); shows the rotate buttons
            onCrop:   async (index, src) → new src (or null); shows the ✂ Crop button
            onShow:   index → called whenever a photo is shown
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
      <div class="lb-stage"><img class="lb-img" alt=""></div>
      <div class="lb-caption" hidden></div>
      <div class="lb-info" hidden></div>
      <div class="lb-nav">
        <button type="button" class="lb-btn lb-prev">← ${tx('drawer.prev', 'Previous')}</button>
        <span class="lb-label"></span>
        <button type="button" class="lb-btn lb-next">${tx('drawer.next', 'Next')} →</button>
      </div>
      <div class="lb-dots" hidden></div>
      <div class="lb-nav lb-rotate" hidden>
        <button type="button" class="lb-btn" data-rot="-90">↺ ${tx('drawer.rotate_left', 'Rotate left')}</button>
        <button type="button" class="lb-btn" data-rot="90">↻ ${tx('drawer.rotate_right', 'Rotate right')}</button>
        <button type="button" class="lb-btn" data-crop>✂ ${tx('crop.apply', 'Crop')}</button>
      </div>`;
    document.body.appendChild(el);
    el.addEventListener('click', async e => {
      if (e.target === el || e.target.classList.contains('lb-stage') || e.target.closest('.lb-close')) return close();
      if (e.target.closest('.lb-prev')) return go(-1);
      if (e.target.closest('.lb-next')) return go(1);
      if (e.target.closest('.lb-jump')) {
        const j = items[idx]?.jump;
        if (typeof j === 'function') { close(); return j(); }
        return jump(j);
      }
      const dot = e.target.closest('[data-dot]');
      if (dot) { idx = +dot.dataset.dot; return show(); }
      const crop = e.target.closest('[data-crop]');
      if (crop && opts.onCrop) {
        crop.disabled = true;
        const src = await opts.onCrop(idx, items[idx].src);
        crop.disabled = false;
        if (src) setSrc(idx, src);
        return;
      }
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
    renderInfo(it);
    el.querySelector('.lb-label').textContent = `${idx + 1} / ${items.length}`;
    const many = items.length > 1;
    el.querySelectorAll('.lb-prev, .lb-next').forEach(b => { b.hidden = !many; });
    const dots = el.querySelector('.lb-dots');
    dots.hidden = !(opts.dots && many);
    if (!dots.hidden) dots.innerHTML = items.map((_, i) =>
      `<button type="button" class="lb-dot${i === idx ? ' on' : ''}" data-dot="${i}" aria-label="${i + 1} / ${items.length}"${i === idx ? ' aria-current="true"' : ''}></button>`).join('');
    if (opts.onShow) opts.onShow(idx);
  }

  /** Part chip, score line, defects and the jump button of a photo (condition report). */
  function renderInfo(it) {
    const box = el.querySelector('.lb-info');
    box.replaceChildren();
    const node = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
    if (it.part) {
      const head = node('div', 'lb-part');
      const chip = node('span', 'qbadge', it.part.name);
      if (it.part.color) {
        chip.style.color = it.part.color;
        chip.style.background = `color-mix(in srgb, ${it.part.color} 18%, transparent)`;
        chip.style.border = `1px solid color-mix(in srgb, ${it.part.color} 40%, transparent)`;
      }
      const w = Math.round(+it.part.weight * 10) / 10;
      head.append(chip, node('span', 'lb-score', tx('cr.score_weight', `Score ${it.part.score} · weight ${w}%`, { score: it.part.score, weight: typeof fmtNum === 'function' ? fmtNum(w, w % 1 ? 1 : 0) : w })));
      box.append(head);
    }
    if (it.defects && it.defects.length) {
      const ul = node('ul', 'lb-defects');
      it.defects.forEach(d => {
        const li = node('li');
        const nm = node('span');
        // With an explanation the name opens it (assets/js/defect-info.js builds the button with escaped text)
        if (d.id && typeof DefectInfo !== 'undefined' && DefectInfo.has(d.id)) nm.insertAdjacentHTML('beforeend', DefectInfo.button(d.id, tx('di.about', 'About ' + d.name, { name: d.name }), d.name));
        else nm.textContent = d.name;
        if (d.count > 1) nm.append(' ×' + d.count);
        li.append(nm, node('span', 'lb-ded', '−' + d.deduction));
        ul.append(li);
      });
      box.append(ul);
    }
    if (it.jump && it.part) {
      const b = node('button', 'lb-btn lb-jump', (it.jumpLabel || tx('cr.see_in_breakdown', `See ${it.part.name} in the breakdown`, { part: it.part.name })) + ' ↓');
      b.type = 'button';
      box.append(b);
    }
    box.hidden = !box.childNodes.length;
  }

  /** Closes the viewer, opens the <details> with that id and scrolls to it. */
  function jump(id) {
    const d = id && document.getElementById(id);
    close();
    if (!d) return;
    d.open = true;
    d.scrollIntoView({ behavior: 'smooth', block: 'start' });
    d.querySelector('summary')?.focus({ preventScroll: true });
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
    el.querySelector('.lb-rotate').hidden = !opts.onRotate && !opts.onCrop;
    el.querySelectorAll('[data-rot]').forEach(b => { b.hidden = !opts.onRotate; });
    el.querySelector('[data-crop]').hidden = !opts.onCrop;
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
