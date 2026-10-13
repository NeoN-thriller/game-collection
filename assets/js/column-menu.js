/* ═══════════════════════════════════════════
   Column menu: a "Columns" dropdown in the toolbar of the collection and wishlist pages.
   Switch table columns on or off and change their order (drag, or the ↑ ↓ buttons).
   Every change is applied to the table at once and saved to api/column_prefs.php
   (the same preferences as Settings › Appearance › columns).

   ColumnMenu.mount({
     root,        // element the button and panel go into
     type,        // 'collection' | 'wishlist' (which preference is saved)
     columns,     // () => [{id, on}] in the current order — only the columns that can be offered
     defaults,    // () => [{id, on}] the page's default order and visibility
     apply,       // ([{id, on}]) => void — show/hide and reorder the table
     save,        // ([{id, on}]) => [{id, on}] — optional: the list to store (e.g. with columns not offered right now)
   })
   ═══════════════════════════════════════════ */
window.ColumnMenu = (() => {
  const escH = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const label = id => tRaw('common.col.' + id);

  function mount(opts) {
    const root = opts.root;
    if (!root) return;
    const panelId = 'col-menu-panel-' + opts.type;
    root.classList.add('col-menu');
    root.innerHTML = `
      <button type="button" class="filter-btn col-menu-btn" aria-haspopup="true" aria-expanded="false" aria-controls="${panelId}">☰ ${escH(tRaw('common.cols.button'))}</button>
      <div class="col-menu-panel" id="${panelId}" role="dialog" aria-label="${escH(tRaw('common.cols.title'))}" hidden>
        <div class="col-menu-head">
          <strong>${escH(tRaw('common.cols.title'))}</strong>
          <button type="button" class="btn-icon col-menu-reset">${escH(tRaw('common.cols.reset'))}</button>
        </div>
        <p class="col-menu-help">${escH(tRaw('common.cols.help'))}</p>
        <ul class="col-menu-list"></ul>
      </div>`;
    const btn   = root.querySelector('.col-menu-btn');
    const panel = root.querySelector('.col-menu-panel');
    const list  = root.querySelector('.col-menu-list');
    let cols = [];

    function render() {
      list.innerHTML = '';
      cols.forEach((c, i) => {
        const li = document.createElement('li');
        li.className = 'col-menu-item' + (c.on ? '' : ' col-menu-off');
        li.draggable = true;
        li.dataset.id = c.id;
        const name = label(c.id);
        li.innerHTML = `<span class="col-menu-grip" aria-hidden="true">⠿</span>
          <label class="toggle"><input type="checkbox" ${c.on ? 'checked' : ''} aria-label="${escH(name)}"><span class="toggle-slider"></span></label>
          <span class="col-menu-name">${escH(name)}</span>
          <span class="col-menu-move">
            <button type="button" class="btn-icon" data-move="-1" ${i === 0 ? 'disabled' : ''} aria-label="${escH(tRaw('common.cols.move_up', {col: name}))}">↑</button>
            <button type="button" class="btn-icon" data-move="1" ${i === cols.length - 1 ? 'disabled' : ''} aria-label="${escH(tRaw('common.cols.move_down', {col: name}))}">↓</button>
          </span>`;
        li.querySelector('input').addEventListener('change', e => { c.on = e.target.checked; li.classList.toggle('col-menu-off', !c.on); changed(); });
        li.querySelectorAll('[data-move]').forEach(b => b.addEventListener('click', () => move(i, i + Number(b.dataset.move), b.dataset.move)));
        list.appendChild(li);
      });
    }

    function move(from, to, dir) {
      if (to < 0 || to >= cols.length) return;
      cols.splice(to, 0, cols.splice(from, 1)[0]);
      render();
      // Keep the focus on the moved column's button, so it can be moved again with the keyboard
      const b = list.children[to]?.querySelector(`[data-move="${dir}"]`);
      (b && !b.disabled ? b : list.children[to]?.querySelector('input'))?.focus();
      changed();
    }

    let dragId = null;
    list.addEventListener('dragstart', e => { const li = e.target.closest('.col-menu-item'); if (li) { dragId = li.dataset.id; li.classList.add('col-menu-dragging'); } });
    list.addEventListener('dragend',   e => { e.target.closest('.col-menu-item')?.classList.remove('col-menu-dragging'); dragId = null; });
    list.addEventListener('dragover',  e => { if (dragId) e.preventDefault(); });
    list.addEventListener('drop', e => {
      e.preventDefault();
      const li = e.target.closest('.col-menu-item');
      if (!dragId || !li || li.dataset.id === dragId) return;
      const from = cols.findIndex(c => c.id === dragId), to = cols.findIndex(c => c.id === li.dataset.id);
      cols.splice(to, 0, cols.splice(from, 1)[0]);
      render();
      changed();
    });

    root.querySelector('.col-menu-reset').addEventListener('click', () => {
      cols = opts.defaults().map(c => ({...c}));
      render();
      changed();
    });

    // Apply at once; save shortly after the last change
    let saveTimer = null;
    function changed() {
      const snapshot = cols.map(c => ({id: c.id, on: c.on}));
      opts.apply(snapshot);
      clearTimeout(saveTimer);
      saveTimer = setTimeout(() => store(snapshot), 600);
    }
    async function store(snapshot) {
      try {
        const res = await fetch(`${BASE}/api/column_prefs.php`, {
          method: 'POST', headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({type: opts.type, cols: opts.save ? opts.save(snapshot) : snapshot}),
        }).then(r => r.json());
        if (!res.ok) throw new Error();
      } catch (e) {
        if (typeof toast === 'function') toast(tRaw('common.err_saving'), true);
      }
    }

    function open() {
      cols = opts.columns().map(c => ({...c}));
      render();
      panel.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      btn.classList.add('active-showall');
      list.querySelector('input')?.focus();
    }
    function close(focusBtn) {
      if (panel.hidden) return;
      panel.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
      btn.classList.remove('active-showall');
      if (focusBtn) btn.focus();
    }
    btn.addEventListener('click', () => panel.hidden ? open() : close(false));
    // composedPath, not contains(): the ↑ ↓ buttons are re-rendered (detached) before the click reaches the document
    document.addEventListener('click', e => { if (!e.composedPath().includes(root)) close(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) close(true); });
  }

  return { mount };
})();
