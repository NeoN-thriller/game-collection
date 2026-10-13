/**
 * Search all systems: the search box in the header nav (markup from globalSearchHtml() in site.php).
 * Asks api/search.php as you type and lists the matches per system. Choosing one opens that game's drawer
 * in the collection (collection.php?game=<id>); a page can handle it itself with window.gsOpenHere(result) → true.
 * Keys: / focuses the box, ↑ ↓ pick, Enter opens, Esc closes.
 * A valid barcode (digits only: EAN-13, EAN-8, UPC-A) goes to scan.php?code=… on Enter, so a USB scanner works from any page.
 * window.GlobalSearch.search(text) fills the box and searches (the "Search all systems" link on the collection page).
 */
(function () {
  const wrap  = document.getElementById('gsearch');
  if (!wrap) return;
  const BASE_URL = wrap.dataset.base;
  const input = document.getElementById('gs-input');
  const panel = document.getElementById('gs-panel');
  const OWNED_KEY = 'gs_owned_only';

  let ownedOnly = false;
  try { ownedOnly = localStorage.getItem(OWNED_KEY) === '1'; } catch (e) {}

  let results = [], more = false, active = -1, timer = null, ctrl = null, lastQuery = null;

  /** The barcode in the box (normalised, as barcodeNormalize() in barcodes.php), or null. */
  function barcodeOf(text) {
    if (wrap.dataset.barcodes === '0' || !/^[\d\s-]+$/.test(text)) return null;   // barcode scanning switched off
    let d = text.replace(/\D+/g, '');
    if (d.length === 12) d = '0' + d;
    const n = d.length;
    if (n !== 8 && n !== 13) return null;
    let sum = 0;
    for (let i = 0; i < n - 1; i++) sum += +d[i] * ((n - 1 - i) % 2 === 1 ? 3 : 1);
    return (10 - sum % 10) % 10 === +d[n - 1] ? d : null;
  }
  const scanUrl = code => `${BASE_URL}/scan.php?code=${code}`;

  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function show(html) {
    panel.innerHTML = html;
    panel.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }
  function close() {
    panel.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    active = -1;
  }

  function bar(count) {
    return `<div class="gs-bar">
      <label><input type="checkbox" id="gs-owned"${ownedOnly ? ' checked' : ''}> ${t('common.gs.owned_only')}</label>
      <span class="gs-count">${count}</span>
    </div>`;
  }

  function badges(r) {
    const b = [];
    if (r.owned > 0) b.push(`<span class="gs-owned">✓ ${tn('common.gs.copies', r.owned)}</span>`);
    if (r.wishlist)  b.push(`<span class="gs-wish">♥ ${t('common.gs.wishlist')}</span>`);
    if (!b.length)   b.push(`<span class="gs-none">${t('common.gs.not_owned')}</span>`);
    return b.join('');
  }

  function render() {
    if (!results.length) {
      show(bar('') + `<div class="gs-msg">${t(ownedOnly ? 'common.gs.none_owned' : 'common.gs.none')}</div>`);
      return;
    }
    let html = bar(more ? t('common.gs.more', { n: results.length }) : tn('common.gs.n_results', results.length));
    let sys = null;
    results.forEach((r, i) => {
      if (r.system_id !== sys) {
        sys = r.system_id;
        html += `<div class="gs-sys" title="${esc(r.system_name)}">${esc(r.system_short || r.system_name)}</div>`;
      }
      // Titles usually carry their edition already; show the edition only when they don't
      const ed = r.edition && !r.title.toLowerCase().includes(r.edition.toLowerCase())
        ? ` <span class="gs-ed">· ${esc(r.edition)}</span>` : '';
      html += `<div class="gs-item${i === active ? ' active' : ''}" role="option" data-i="${i}">
        <span class="gs-title">${esc(r.title)}${ed}</span><span class="gs-badges">${badges(r)}</span>
      </div>`;
    });
    show(html);
  }

  function setActive(i) {
    const items = panel.querySelectorAll('.gs-item');
    if (!items.length) return;
    active = (i + items.length) % items.length;
    items.forEach((el, n) => el.classList.toggle('active', n === active));
    items[active].scrollIntoView({ block: 'nearest' });
  }

  async function run(force) {
    const q = input.value.trim();
    const key = q + '|' + (ownedOnly ? 1 : 0);
    if (q.length < 2) { results = []; lastQuery = null; if (q.length) show(`<div class="gs-msg">${t('common.gs.min_chars')}</div>`); else close(); return; }
    const code = barcodeOf(q);
    if (code) {
      results = []; lastQuery = null;
      show(`<a class="gs-item gs-barcode" href="${scanUrl(code)}">${t('common.gs.barcode', { code: q.trim() })}</a>`);
      return;
    }
    if (!force && key === lastQuery) { if (panel.hidden) render(); return; }
    lastQuery = key;
    if (ctrl) ctrl.abort();
    ctrl = new AbortController();
    try {
      const res = await fetch(`${BASE_URL}/api/search.php?q=${encodeURIComponent(q)}${ownedOnly ? '&owned=1' : ''}`, { signal: ctrl.signal });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || '');
      results = data.results || [];
      active = results.length ? 0 : -1;
      more = !!data.more;
      render();
    } catch (e) {
      if (e.name === 'AbortError') return;
      lastQuery = null;
      results = [];
      show(`<div class="gs-msg">${t('common.gs.error')}</div>`);
    }
  }

  function open(i) {
    const r = results[i];
    if (!r) return;
    close();
    if (typeof window.gsOpenHere === 'function' && window.gsOpenHere(r)) { input.blur(); return; }
    window.location = `${BASE_URL}/collection.php?game=${r.id}`;
  }

  input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => run(false), 200); });
  input.addEventListener('focus', () => { if (input.value.trim().length >= 2) run(false); });
  input.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown')      { e.preventDefault(); if (panel.hidden) run(false); else setActive(active + 1); }
    else if (e.key === 'ArrowUp')   { e.preventDefault(); setActive(active - 1); }
    else if (e.key === 'Enter')     {
      e.preventDefault(); clearTimeout(timer);
      const code = barcodeOf(input.value.trim());
      if (code) window.location = scanUrl(code);
      else if (!panel.hidden && active >= 0) open(active); else run(true);
    }
    else if (e.key === 'Escape')    { if (!panel.hidden) { e.preventDefault(); close(); } else input.blur(); }
  });

  panel.addEventListener('mousedown', e => { if (!e.target.closest('label')) e.preventDefault(); });   // keep the focus in the box
  panel.addEventListener('click', e => {
    const item = e.target.closest('.gs-item');
    if (item) open(+item.dataset.i);
  });
  panel.addEventListener('change', e => {
    if (e.target.id !== 'gs-owned') return;
    ownedOnly = e.target.checked;
    try { localStorage.setItem(OWNED_KEY, ownedOnly ? '1' : '0'); } catch (err) {}
    input.focus();
    run(true);
  });

  document.addEventListener('mousedown', e => { if (!wrap.contains(e.target)) close(); });
  document.addEventListener('keydown', e => {
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
    const el = document.activeElement;
    if (el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName))) return;
    e.preventDefault();
    input.focus();
    input.select();
  });

  window.GlobalSearch = {
    search(text) {
      input.value = text;
      input.focus();
      run(true);
      if (window.innerWidth <= 700) wrap.scrollIntoView({ block: 'start', behavior: 'smooth' });
    },
  };
})();
