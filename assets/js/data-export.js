/* ═══════════════════════════════════════════
   SETTINGS › Database export (settings/s_data_export.php)
   Columns on/off and in order (drag, or the ↑ ↓ buttons), format and options. Every change updates the download link,
   the hidden dx_config of the Save form and the preview (api/catalogue_export.php?preview=1).
   Needs CP.dx: { cfg, defaults, prices, money, public, site }.
   ═══════════════════════════════════════════ */
(() => {
  const list = document.getElementById('dx-cols');
  if (!list || !CP.dx) return;
  const $ = id => document.getElementById(id);
  const savedJson = JSON.stringify(CP.dx.cfg);
  let cols = CP.dx.cfg.cols.map(c => ({ ...c }));

  // ── Columns ──
  function renderCols() {
    list.innerHTML = '';
    cols.forEach((c, i) => {
      const li = document.createElement('li');
      li.className = 'dx-col' + (c.on ? '' : ' dx-col-off');
      li.draggable = true;
      li.dataset.id = c.id;
      const label = tRaw('dx.col.' + c.id);
      li.innerHTML = `<span class="dx-grip" aria-hidden="true">⠿</span>
        <label class="toggle"><input type="checkbox" ${c.on ? 'checked' : ''} aria-label="${esc(label)}"><span class="toggle-slider"></span></label>
        <span class="dx-col-text"><strong>${esc(label)}</strong> <code>${esc(c.id)}</code><br><span class="sb-sub">${esc(tRaw('dx.colh.' + c.id))}</span></span>
        <span class="dx-move">
          <button type="button" class="btn-icon" data-move="-1" ${i === 0 ? 'disabled' : ''} aria-label="${esc(tRaw('dx.move_up', {col: label}))}">↑</button>
          <button type="button" class="btn-icon" data-move="1" ${i === cols.length - 1 ? 'disabled' : ''} aria-label="${esc(tRaw('dx.move_down', {col: label}))}">↓</button>
        </span>`;
      li.querySelector('input').addEventListener('change', e => { c.on = e.target.checked; li.classList.toggle('dx-col-off', !c.on); changed(); });
      li.querySelectorAll('[data-move]').forEach(b => b.addEventListener('click', () => move(i, i + Number(b.dataset.move), b.dataset.move)));
      list.appendChild(li);
    });
  }
  function move(from, to, dir) {
    if (to < 0 || to >= cols.length) return;
    cols.splice(to, 0, cols.splice(from, 1)[0]);
    renderCols();
    // Keep the focus on the moved column's button, so it can be moved again with the keyboard
    const btn = list.children[to]?.querySelector(`[data-move="${dir}"]`);
    (btn && !btn.disabled ? btn : list.children[to]?.querySelector('input'))?.focus();
    changed();
  }
  let dragId = null;
  list.addEventListener('dragstart', e => { const li = e.target.closest('.dx-col'); if (li) { dragId = li.dataset.id; li.classList.add('dx-dragging'); } });
  list.addEventListener('dragend',   e => { e.target.closest('.dx-col')?.classList.remove('dx-dragging'); dragId = null; });
  list.addEventListener('dragover',  e => { if (dragId) e.preventDefault(); });
  list.addEventListener('drop', e => {
    e.preventDefault();
    const li = e.target.closest('.dx-col');
    if (!dragId || !li || li.dataset.id === dragId) return;
    const from = cols.findIndex(c => c.id === dragId), to = cols.findIndex(c => c.id === li.dataset.id);
    cols.splice(to, 0, cols.splice(from, 1)[0]);
    renderCols();
    changed();
  });
  $('dx-reset').addEventListener('click', () => { cols = CP.dx.defaults.map(c => ({ ...c })); renderCols(); changed(); });
  $('dx-all-off').addEventListener('click', () => { cols.forEach(c => c.on = false); renderCols(); changed(); });

  // ── Options ──
  const radio = name => document.querySelector(`input[name="${name}"]:checked`)?.value;
  document.querySelectorAll('input[name="dx-format"], input[name="dx-sep"], input[name="dx-rows"], #dx-inactive').forEach(el => el.addEventListener('change', changed));
  const sysBox = $('dx-systems');
  sysBox.addEventListener('click', e => {
    const b = e.target.closest('[data-sys]');
    if (!b) return;
    const all = sysBox.querySelector('[data-sys="0"]');
    if (b === all) {
      sysBox.querySelectorAll('[data-sys]').forEach(x => x.setAttribute('aria-pressed', x === all ? 'true' : 'false'));
    } else {
      b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
      all.setAttribute('aria-pressed', sysBox.querySelector('[data-sys]:not([data-sys="0"])[aria-pressed="true"]') ? 'false' : 'true');
    }
    changed();
  });

  function config() {
    return {
      cols,
      format:   radio('dx-format'),
      sep:      radio('dx-sep'),
      rows:     radio('dx-rows'),
      systems:  [...sysBox.querySelectorAll('[data-sys]:not([data-sys="0"])[aria-pressed="true"]')].map(b => Number(b.dataset.sys)).sort((a, b) => a - b),
      inactive: $('dx-inactive').checked,
    };
  }
  function query(cfg) {
    const p = new URLSearchParams({ cols: cfg.cols.filter(c => c.on).map(c => c.id).join(','), format: cfg.format, rows: cfg.rows });
    if (cfg.format === 'csv') p.set('sep', cfg.sep);
    if (cfg.systems.length) p.set('sys', cfg.systems.join(','));
    if (cfg.inactive) p.set('inactive', '1');
    return p;
  }

  // ── Everything that follows a change ──
  let timer = null;
  function changed() {
    const cfg = config();
    const on  = cfg.cols.filter(c => c.on);
    $('dx-col-count').textContent = tRaw('dx.cols_on', { n: on.length, total: cfg.cols.length });
    $('dx-price-note').hidden = !on.some(c => CP.dx.prices.includes(c.id));
    $('dx-sep-wrap').hidden = cfg.format !== 'csv';
    $('dx-format-hint').textContent = tRaw('dx.format_hint_' + cfg.format);
    $('dx-rows-hint').textContent = document.querySelector('input[name="dx-rows"]:checked')?.dataset.hint || '';
    $('dx-config').value = JSON.stringify(cfg);
    $('dx-unsaved').hidden = JSON.stringify(cfg) === savedJson;
    const dl = $('dx-download');
    dl.href = `${CP.base}/api/catalogue_export.php?${query(cfg)}`;
    dl.classList.toggle('disabled', !on.length);
    dl.setAttribute('aria-disabled', on.length ? 'false' : 'true');
    clearTimeout(timer);
    timer = setTimeout(() => preview(cfg), 350);
  }
  $('dx-download').addEventListener('click', e => { if (e.currentTarget.getAttribute('aria-disabled') === 'true') { e.preventDefault(); toast(tRaw('dx.no_columns'), true); } });
  // Saving with no column on would bring back the defaults: say so instead
  $('dx-form').addEventListener('submit', e => { if (!cols.some(c => c.on)) { e.preventDefault(); toast(tRaw('dx.no_columns'), true); } }, true);

  // ── Preview ──
  let seq = 0;
  async function preview(cfg) {
    const box = $('dx-preview'), count = $('dx-preview-count');
    if (!cfg.cols.some(c => c.on)) { box.innerHTML = ''; count.textContent = tRaw('dx.no_columns'); return; }
    const mine = ++seq;
    count.textContent = tRaw('dx.preview_loading');
    try {
      const p = query(cfg); p.set('preview', '1');
      const res = await fetch(`${CP.base}/api/catalogue_export.php?${p}`).then(r => r.json());
      if (mine !== seq) return;   // a newer preview is on its way
      if (!res.ok) throw new Error(res.error || tRaw('common.error'));
      count.textContent = tRaw(res.count === 1 ? 'dx.preview_count_one' : 'dx.preview_count', { n: fmtN(res.count), shown: res.rows.length });
      const cell = (v, c) => Array.isArray(v) ? v.map(x => esc(x)).join('<span class="dx-list-sep"> | </span>')
        : v === null ? '' : typeof v === 'boolean' ? (v ? '1' : '0') : CP.dx.money.includes(c) ? Number(v).toFixed(2) : esc(v);
      box.innerHTML = res.rows.length
        ? `<table class="admin-table dx-table"><thead><tr>${res.columns.map(c => `<th scope="col">${esc(c)}</th>`).join('')}</tr></thead>
           <tbody>${res.rows.map(r => `<tr>${res.columns.map(c => `<td>${cell(r[c], c)}</td>`).join('')}</tr>`).join('')}</tbody></table>`
        : `<p class="sb-sub">${esc(tRaw('dx.preview_empty'))}</p>`;
    } catch (err) {
      if (mine !== seq) return;
      box.innerHTML = '';
      count.textContent = tRaw('common.err_prefix', { error: err.message });
    }
  }
  const fmtN = n => { try { return Number(n).toLocaleString(); } catch { return String(n); } };

  // ── Public links ──
  document.querySelectorAll('.dx-copy').forEach(b => b.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(b.dataset.url); toast(tRaw('dx.copied')); }
    catch { b.previousElementSibling.select(); toast(tRaw('dx.copy_failed'), true); }
  }));

  renderCols();
  changed();
})();
