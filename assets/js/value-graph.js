/* ═══════════════════════════════════════════
   DASHBOARD — collection value over time (api/value_history.php)
   The big graph (Total / By system / Share %) with a time window chosen by two handles on an overview
   strip or the 3M / 6M / 1Y / All presets, and a small trend line in every system card that follows the
   same window. Hand-drawn SVG + HTML (no chart library). Needs appScript(['dashboard','val']) and
   the #vg section from dashboard.php, which only renders both when the user's graphs are switched on
   (Settings › Language & appearance). Hide / Show graphs folds the card away or opens it again, remembered per user
   (api/value_pref.php {collapsed}); folded, the cards have no trend row.
   ═══════════════════════════════════════════ */
(() => {
  const root = document.getElementById('vg');
  if (!root) return;
  const BASE = root.dataset.base;
  const NS = 'http://www.w3.org/2000/svg';
  const W = 1000, H = 240;   // chart viewBox (stretched to the box; strokes keep their width)
  // Categorical colours, assigned in system id order (a colour never changes when values move).
  // They differ in lightness as well as hue; beyond 12 systems the list repeats lighter.
  const PALETTE = ['#4e79a7', '#f28e2b', '#59a14f', '#e15759', '#b07aa1', '#edc948',
                   '#76b7b2', '#ff9da7', '#9c755f', '#bab0ac', '#2f4b7c', '#a05195'];
  const PRESETS = { '3m': 3, '6m': 6, '1y': 12, all: 0 };

  const S = {
    total: Number(root.dataset.total) || 0,   // the Collection value tile
    open: root.dataset.open === '1',          // not folded away with Hide graphs
    view: 'total', preset: '6m', li: 0, ri: 0, focus: null,
    data: null, loading: false, error: false, built: false,
  };
  const $ = sel => root.querySelector(sel);
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const el = (tag, cls, html) => { const e = document.createElement(tag); if (cls) e.className = cls; if (html !== undefined) e.innerHTML = html; return e; };
  const svgEl = (tag, attrs) => { const e = document.createElementNS(NS, tag); for (const k in attrs) e.setAttribute(k, attrs[k]); return e; };
  const m0 = v => money(v, 0);
  const day = ts => fmtDate(new Date(ts));
  const cents = v => Math.round(v * 100);
  /** "▲ +€45" / "▼ −€12" / "±€0" with its direction class */
  const change = (d, pctOf) => {
    const c = cents(d);
    const pct = pctOf ? ' (' + fmtNum(Math.abs(d) / pctOf * 100, 1) + '%)' : '';
    if (c === 0) return { cls: 'flat', text: '±' + m0(0) };
    return c > 0 ? { cls: 'up', text: '▲ +' + m0(d) + pct } : { cls: 'down', text: '▼ −' + m0(-d) + pct };
  };
  const lighten = (hex, f) => {
    const n = parseInt(hex.slice(1), 16), mix = c => Math.round(c + (255 - c) * f);
    return 'rgb(' + mix(n >> 16) + ',' + mix((n >> 8) & 255) + ',' + mix(n & 255) + ')';
  };

  // ── Data ─────────────────────────────────
  async function load() {
    S.loading = true;
    try {
      const r = await fetch(BASE + '/api/value_history.php').then(r => r.json());
      if (!r.ok) throw new Error(r.error || '');
      const pts = r.points.map(p => ({ ts: p.ts * 1000, source: p.source, total: Number(p.total), v: p.v || {} }));
      const byId = [...r.systems].sort((a, b) => a.id - b.id);
      byId.forEach((s, i) => {
        const base = PALETTE[i % PALETTE.length], round = Math.floor(i / PALETTE.length);
        s.color = round ? lighten(base, Math.min(.6, .3 * round)) : base;
      });
      const last = pts.length ? pts[pts.length - 1].v : {};
      // Stack / legend order: largest current value at the bottom (first), then descending
      const order = [...byId].sort((a, b) => (Number(last[b.id]) || 0) - (Number(last[a.id]) || 0) || a.id - b.id);
      S.data = { points: pts, systems: order };
      S.error = false;
    } catch {
      S.error = true;
    }
    S.loading = false;
  }
  const val = (p, id) => Number(p.v[id]) || 0;

  // ── Window ───────────────────────────────
  function applyPreset(key) {
    const P = S.data.points, n = P.length;
    S.preset = key;
    S.ri = n - 1;
    if (!PRESETS[key]) { S.li = 0; return; }
    const d = new Date(P[n - 1].ts);
    d.setMonth(d.getMonth() - PRESETS[key]);
    let li = -1;
    for (let i = 0; i < n; i++) if (P[i].ts <= d.getTime()) li = i;
    S.li = Math.min(Math.max(li, 0), n - 2);
    return li >= 0;   // false: the history is shorter than the preset
  }

  // ── Building ─────────────────────────────
  function build() {
    const body = $('#vg-body');
    body.innerHTML = '';
    S.built = false;
    const P = S.data ? S.data.points : [];
    $('#vg-views').hidden = P.length < 2 || S.error || !S.open;
    if (!S.open) { body.appendChild(el('p', 'vg-note', t('val.hidden_note'))); return; }
    if (S.error) { body.appendChild(el('p', 'vg-note', t('val.err_load'))); return; }
    if (!P.length) { body.appendChild(el('p', 'vg-note', t('val.empty_none'))); return; }
    if (P.length < 2) {
      const f = el('div', 'vg-empty');
      f.appendChild(el('span', 'vg-empty-dot'));
      f.appendChild(el('p', '', '<strong>' + t('val.empty_first', { date: day(P[0].ts) }) + '</strong><br>' + t('val.empty_text')));
      body.appendChild(f);
      return;
    }

    const chart = el('div', 'vg-chart');
    chart.appendChild(svgEl('svg', { class: 'vg-svg', viewBox: `0 0 ${W} ${H}`, preserveAspectRatio: 'none', role: 'img' }));
    chart.appendChild(el('div', 'vg-ylabels'));
    chart.appendChild(el('div', 'vg-dots'));
    const tip = el('div', 'vg-tip'); tip.hidden = true; tip.setAttribute('aria-hidden', 'true');
    chart.appendChild(tip);
    body.appendChild(chart);
    body.appendChild(el('div', 'vg-xlabels'));
    const legend = el('div', 'vg-legend'); legend.hidden = true;
    body.appendChild(legend);

    const row = el('div', 'vg-presets-row');
    const seg = el('div', 'vg-seg vg-presets'); seg.setAttribute('role', 'group'); seg.setAttribute('aria-label', tRaw('val.range_group'));
    for (const k of Object.keys(PRESETS)) {
      const b = el('button', 'vg-segbtn'); b.type = 'button'; b.dataset.preset = k; b.textContent = tRaw('val.p' + k);
      seg.appendChild(b);
    }
    row.appendChild(seg);
    row.appendChild(el('span', 'vg-hint', t('val.handles_hint')));
    body.appendChild(row);

    const strip = el('div', 'vg-strip');
    const plot = el('div', 'vg-strip-plot');
    plot.appendChild(svgEl('svg', { class: 'vg-strip-svg', viewBox: `0 0 ${W} 56`, preserveAspectRatio: 'none', 'aria-hidden': 'true' }));
    plot.appendChild(el('div', 'vg-dim vg-dim-l'));
    plot.appendChild(el('div', 'vg-dim vg-dim-r'));
    plot.appendChild(el('div', 'vg-win'));
    strip.appendChild(plot);
    for (const side of ['l', 'r']) {
      const inp = el('input', 'vg-handle vg-handle-' + side);
      Object.assign(inp, { type: 'range', min: 0, max: 1000, step: 1 });
      inp.setAttribute('aria-label', tRaw(side === 'l' ? 'val.handle_start' : 'val.handle_end'));
      strip.appendChild(inp);
    }
    body.appendChild(strip);
    const dates = el('div', 'vg-strip-dates');
    dates.appendChild(el('span', '', esc(day(P[0].ts))));
    dates.appendChild(el('span', '', esc(day(P[P.length - 1].ts))));
    body.appendChild(dates);

    drawStripLine();
    wire(body);
    S.built = true;
  }

  function wire(body) {
    body.querySelector('.vg-presets').addEventListener('click', e => {
      const b = e.target.closest('[data-preset]');
      if (!b) return;
      applyPreset(b.dataset.preset);
      render();
    });
    const [hl, hr] = body.querySelectorAll('.vg-handle');
    const P = S.data.points, n = P.length;
    const T0 = P[0].ts, span = Math.max(P[n - 1].ts - T0, 1);
    const nearest = v => {
      const t = T0 + v / 1000 * span;
      let best = 0;
      for (let i = 1; i < n; i++) if (Math.abs(P[i].ts - t) < Math.abs(P[best].ts - t)) best = i;
      return best;
    };
    const setIdx = (side, i) => {
      if (side === 'l') S.li = Math.max(0, Math.min(i, S.ri - 1));
      else S.ri = Math.min(n - 1, Math.max(i, S.li + 1));
      S.preset = null;
      render();
    };
    for (const [inp, side] of [[hl, 'l'], [hr, 'r']]) {
      inp.addEventListener('input', () => setIdx(side, nearest(Number(inp.value))));
      inp.addEventListener('pointerdown', () => { hl.style.zIndex = side === 'l' ? 3 : 2; hr.style.zIndex = side === 'r' ? 3 : 2; });
      // Keys move one data point at a time (the native step could be smaller than the gap between points)
      inp.addEventListener('keydown', e => {
        const cur = side === 'l' ? S.li : S.ri;
        const big = Math.max(1, Math.round(n / 10));
        const to = { ArrowLeft: cur - 1, ArrowDown: cur - 1, ArrowRight: cur + 1, ArrowUp: cur + 1,
                     PageDown: cur - big, PageUp: cur + big, Home: 0, End: n - 1 }[e.key];
        if (to === undefined) return;
        e.preventDefault();
        setIdx(side, to);
      });
    }
    // Tooltip: hover, keyboard focus or tap on a dot
    const dots = body.querySelector('.vg-dots');
    const show = e => { const b = e.target.closest('.vg-dot'); if (b) showTip(+b.dataset.i); };
    dots.addEventListener('pointerover', show);
    dots.addEventListener('focusin', show);
    dots.addEventListener('click', show);
    dots.addEventListener('pointerleave', hideTip);
    dots.addEventListener('focusout', hideTip);
    dots.addEventListener('keydown', e => { if (e.key === 'Escape') hideTip(); });
    document.addEventListener('click', e => { if (!e.target.closest('.vg-dot')) hideTip(); });
    body.querySelector('.vg-legend').addEventListener('click', e => {
      const b = e.target.closest('[data-sys]');
      if (!b) return;
      S.focus = S.focus === +b.dataset.sys ? null : +b.dataset.sys;
      renderChart();
    });
  }

  // ── Rendering ────────────────────────────
  function render() {
    renderHeader();
    if (S.built) { renderChart(); renderStrip(); }
    renderTrends();
  }

  function renderHeader() {
    $('#vg-big').textContent = m0(S.total);
    const P = S.data && !S.error ? S.data.points : [];
    const graphs = S.open && P.length >= 2;
    $('#vg-toggle').textContent = tRaw(S.open ? 'val.hide' : 'val.show');
    $('#vg-toggle').setAttribute('aria-expanded', String(S.open));
    const sinceEl = document.getElementById('vg-since-line');
    if (sinceEl && !graphs) sinceEl.hidden = true;
    const dEl = $('#vg-delta'), rEl = $('#vg-range'), sEl = $('#vg-split');
    dEl.hidden = rEl.hidden = sEl.hidden = !graphs;
    for (const b of root.querySelectorAll('#vg-views [data-view]')) b.setAttribute('aria-pressed', String(b.dataset.view === S.view));
    if (!graphs) return;
    const a = P[S.li], b = P[S.ri], d = b.total - a.total;
    const c = change(d, a.total > 0 ? a.total : 0);
    dEl.className = 'vg-delta ' + c.cls;
    dEl.textContent = c.text;
    rEl.textContent = day(a.ts) + ' → ' + day(b.ts);
    let prices = 0, games = 0;
    for (let k = S.li + 1; k <= S.ri; k++) {
      const step = P[k].total - P[k - 1].total;
      if (P[k].source === 'collection') games += step; else prices += step;
    }
    const sg = v => (cents(v) > 0 ? '+' : cents(v) < 0 ? '−' : '±') + m0(Math.abs(v));
    sEl.textContent = tRaw('val.start') + ' ' + m0(a.total) + ' · ' + tRaw('val.prices') + ' ' + sg(prices)
                    + ' · ' + tRaw('val.games_effect') + ' ' + sg(games);
    const since = document.getElementById('vg-since-line');
    if (since) { since.textContent = tRaw('val.since_line', { date: day(a.ts) }); since.hidden = false; }
  }

  /** Window helpers: x in % of the window, the window's points */
  const win = () => {
    const P = S.data.points, t0 = P[S.li].ts, t1 = P[S.ri].ts, span = Math.max(t1 - t0, 1);
    return { P, t0, t1, idx: Array.from({ length: S.ri - S.li + 1 }, (_, k) => S.li + k), x: ts => (ts - t0) / span * 100 };
  };

  function yScale(w) {
    if (S.view === 'share') return { lo: 0, hi: 100 };
    const totals = w.idx.map(i => w.P[i].total);
    const max = Math.max(...totals), min = Math.min(...totals);
    if (S.view === 'system') return { lo: 0, hi: Math.max(250, Math.ceil(max / 250) * 250) };
    const pad = max > min ? (max - min) * .25 : 50;
    const lo = Math.max(0, Math.floor((min - pad) / 50) * 50);
    let hi = Math.ceil((max + pad) / 50) * 50;
    if (hi <= lo) hi = lo + 50;
    return { lo, hi };
  }

  function renderChart() {
    const w = win(), { lo, hi } = yScale(w);
    const sys = S.data.systems;
    const chart = $('.vg-chart'), svg = chart.querySelector('.vg-svg');
    const X = ts => w.x(ts) * W / 100, Y = v => H - (v - lo) / (hi - lo) * H;
    svg.innerHTML = '';
    // Grid lines and Y labels
    const yl = chart.querySelector('.vg-ylabels');
    yl.innerHTML = '';
    for (let g = 0; g <= 4; g++) {
      const v = lo + (hi - lo) * g / 4;
      svg.appendChild(svgEl('line', { x1: 0, x2: W, y1: Y(v), y2: Y(v), class: 'vg-grid', 'vector-effect': 'non-scaling-stroke' }));
      const lab = el('span', 'vg-ylab vg-y-' + g);
      lab.textContent = S.view === 'share' ? fmtNum(v) + '%' : m0(v);
      lab.style.top = (100 - g * 25) + '%';
      yl.appendChild(lab);
    }
    const pts = w.idx.map(i => w.P[i]);
    const line = pts.map(p => X(p.ts).toFixed(1) + ',' + Y(p.total).toFixed(1));
    if (S.view === 'total') {
      svg.appendChild(svgEl('path', { d: 'M' + line.join(' L') + ` L${X(pts[pts.length - 1].ts).toFixed(1)},${H} L${X(pts[0].ts).toFixed(1)},${H} Z`, class: 'vg-area' }));
      svg.appendChild(svgEl('path', { d: 'M' + line.join(' L'), class: 'vg-line', 'vector-effect': 'non-scaling-stroke' }));
    } else {
      // Stacked bands, largest current value at the bottom
      const base = pts.map(() => 0);
      for (const s of sys) {
        const vals = pts.map(p => S.view === 'share' ? (p.total > 0 ? val(p, s.id) / p.total * 100 : 0) : val(p, s.id));
        const top = base.map((b, k) => b + vals[k]);
        if (vals.some(v => v > 0)) {
          const up = pts.map((p, k) => X(p.ts).toFixed(1) + ',' + Y(top[k]).toFixed(1));
          const dn = pts.map((p, k) => X(p.ts).toFixed(1) + ',' + Y(base[k]).toFixed(1)).reverse();
          const band = svgEl('path', { d: 'M' + up.join(' L') + ' L' + dn.join(' L') + ' Z', class: 'vg-band', fill: s.color, 'vector-effect': 'non-scaling-stroke' });
          if (S.focus !== null && S.focus !== s.id) band.setAttribute('opacity', '.25');
          svg.appendChild(band);
        }
        top.forEach((v, k) => { base[k] = v; });
      }
    }
    const a = w.P[S.li], b = w.P[S.ri];
    svg.setAttribute('aria-label', tRaw('val.chart_label', { a: m0(a.total), b: m0(b.total), d1: day(a.ts), d2: day(b.ts) }));

    // Dots: a real button at every data point (the tooltip reads it)
    const dots = chart.querySelector('.vg-dots');
    dots.innerHTML = '';
    const dotTop = p => S.view === 'share' ? 0 : (1 - (p.total - lo) / (hi - lo)) * 100;
    for (const i of w.idx) {
      const p = w.P[i], prev = i > 0 ? w.P[i - 1] : null;
      const bt = el('button', 'vg-dot');
      bt.type = 'button';
      bt.dataset.i = i;
      bt.style.left = w.x(p.ts) + '%';
      bt.style.top = dotTop(p) + '%';
      let label = day(p.ts) + ', ' + m0(p.total);
      if (prev) {
        const d = p.total - prev.total;
        if (cents(d) !== 0) label = tRaw('val.dot_label', { date: day(p.ts), total: m0(p.total), dir: tRaw(d > 0 ? 'val.up' : 'val.down'), amount: m0(Math.abs(d)) });
      }
      bt.setAttribute('aria-label', label);
      bt.appendChild(el('span', 'vg-dot-mark'));
      dots.appendChild(bt);
    }

    // X labels: month ticks on the 1st, every 1, 2 or 3 months; the year on the first tick and on January
    const xl = $('.vg-xlabels');
    xl.innerHTML = '';
    const months = (w.t1 - w.t0) / (30.44 * 864e5);
    const step = months <= 4 ? 1 : months <= 9 ? 2 : 3;
    const d = new Date(w.t0); d.setHours(0, 0, 0, 0); d.setDate(1);
    if (d.getTime() < w.t0) d.setMonth(d.getMonth() + 1);
    let first = true;
    for (; d.getTime() <= w.t1; d.setMonth(d.getMonth() + 1)) {
      if ((d.getFullYear() * 12 + d.getMonth()) % step) continue;
      const x = w.x(d.getTime());
      if (x < 4 || x > 96) continue;
      const lab = el('span', 'vg-xlab');
      lab.textContent = FMT.months[d.getMonth()] + (first || d.getMonth() === 0 ? ' ' + d.getFullYear() : '');
      lab.style.left = x + '%';
      xl.appendChild(lab);
      first = false;
    }

    // Legend (stacked views): every system, in stack order
    const lg = $('.vg-legend');
    lg.hidden = S.view === 'total';
    if (!lg.hidden) {
      lg.innerHTML = sys.map(s => `<button type="button" class="vg-leg" data-sys="${s.id}" aria-pressed="${S.focus === s.id}"><span class="vg-sw" style="background:${s.color}"></span>${esc(s.name)}</button>`).join('');
    }
    hideTip();
  }

  function showTip(i) {
    const chart = $('.vg-chart');
    if (!chart) return;
    const tip = chart.querySelector('.vg-tip'), w = win(), p = w.P[i], prev = i > 0 ? w.P[i - 1] : null;
    const d = prev ? p.total - prev.total : 0;
    const src = p.source === 'start' ? 'val.src_start' : p.source === 'import' ? 'val.src_import' : p.source === 'price' ? 'val.src_price'
              : d < 0 ? 'val.src_removed' : 'val.src_added';
    let html = `<div class="vg-tip-date">${esc(day(p.ts))} · ${t(src)}</div><div class="vg-tip-total">${esc(m0(p.total))}</div>`;
    if (prev) {
      const c = change(d);
      html += `<div class="vg-tip-ch ${c.cls}">${esc(c.text)} ${t('val.since', { date: day(prev.ts) })}</div>`;
    }
    if (S.view !== 'total') {
      html += '<div class="vg-tip-list">' + S.data.systems.filter(s => p.v[s.id] !== undefined).map(s => {
        const v = val(p, s.id), share = p.total > 0 ? v / p.total * 100 : 0;
        return `<div><span class="vg-sw" style="background:${s.color}"></span><span class="vg-tip-n">${esc(s.name)}</span><span>${esc(m0(v))}</span><span class="vg-tip-pct">${fmtNum(share)}%</span></div>`;
      }).join('') + '</div>';
    }
    tip.innerHTML = html;
    const x = w.x(p.ts);
    tip.style.left = x + '%';
    tip.classList.toggle('left', x > 60);
    tip.hidden = false;
    chart.querySelectorAll('.vg-dot').forEach(b => b.classList.toggle('on', +b.dataset.i === i));
  }
  function hideTip() {
    const chart = $('.vg-chart');
    if (!chart) return;
    chart.querySelector('.vg-tip').hidden = true;
    chart.querySelectorAll('.vg-dot.on').forEach(b => b.classList.remove('on'));
  }

  function drawStripLine() {
    const P = S.data.points, T0 = P[0].ts, span = Math.max(P[P.length - 1].ts - T0, 1);
    const totals = P.map(p => p.total), max = Math.max(...totals), min = Math.min(...totals);
    const pad = max > min ? (max - min) * .15 : Math.max(max * .1, 1);
    const Y = v => 52 - (v - (min - pad)) / ((max + pad) - (min - pad)) * 48;
    const line = P.map(p => ((p.ts - T0) / span * W).toFixed(1) + ',' + Y(p.total).toFixed(1));
    const svg = $('.vg-strip-svg');
    svg.innerHTML = '';
    svg.appendChild(svgEl('path', { d: 'M' + line.join(' L') + ` L${W},56 L0,56 Z`, class: 'vg-strip-area' }));
    svg.appendChild(svgEl('path', { d: 'M' + line.join(' L'), class: 'vg-strip-line', 'vector-effect': 'non-scaling-stroke' }));
  }

  function renderStrip() {
    const P = S.data.points, T0 = P[0].ts, span = Math.max(P[P.length - 1].ts - T0, 1);
    const pos = i => (P[i].ts - T0) / span;
    const l = pos(S.li) * 100, r = pos(S.ri) * 100;
    $('.vg-dim-l').style.width = l + '%';
    $('.vg-dim-r').style.left = r + '%';
    const wv = $('.vg-win');
    wv.style.left = l + '%';
    wv.style.width = (r - l) + '%';
    const [hl, hr] = root.querySelectorAll('.vg-handle');
    hl.value = Math.round(pos(S.li) * 1000);
    hr.value = Math.round(pos(S.ri) * 1000);
    hl.setAttribute('aria-valuetext', day(P[S.li].ts));
    hr.setAttribute('aria-valuetext', day(P[S.ri].ts));
    // The left handle on top when both sit at the right end, so it can still be pulled back
    hl.style.zIndex = l > 50 ? 3 : 2;
    hr.style.zIndex = l > 50 ? 2 : 3;
    for (const b of root.querySelectorAll('[data-preset]')) b.setAttribute('aria-pressed', String(b.dataset.preset === S.preset));
  }

  // ── System cards: the small trend line (display-only) ──
  function trendBlocks() {
    if (!S.open) {
      document.querySelectorAll('.vg-trend').forEach(b => b.remove());
      return [];
    }
    const out = [];
    for (const card of document.querySelectorAll('.sys-card[data-sys]')) {
      let b = card.querySelector('.vg-trend');
      if (!b) {
        b = el('div', 'vg-trend');
        b.dataset.sys = card.dataset.sys;
        (card.querySelector('.sys-stats') || card).appendChild(b);
      }
      out.push(b);
    }
    return out;
  }

  function renderTrends() {
    const blocks = trendBlocks();
    if (!blocks.length) return;
    const P = S.data && !S.error ? S.data.points : [];
    if (P.length < 2) {
      for (const b of blocks) {
        if (S.loading) continue;
        if (P.length === 1) b.innerHTML = `<div class="vg-trend-cap"><span>${t('val.trend')}</span></div><div class="vg-trend-note">${t('val.collecting')}</div>`;
        else b.remove();
      }
      return;
    }
    const w = win();
    for (const b of blocks) {
      const id = +b.dataset.sys;
      const sname = b.closest('.sys-card')?.querySelector('.sys-name')?.textContent || '';
      const vals = w.idx.map(i => val(w.P[i], id));
      const a = vals[0], z = vals[vals.length - 1];
      const max = Math.max(...vals), min = Math.min(...vals);
      const pad = (max - min) * .15;
      const lo = min - pad, hi = max + pad;
      const Y = v => hi > lo ? 40 - (v - lo) / (hi - lo) * 36 : 22;
      const d = 'M' + w.idx.map((i, k) => (w.x(w.P[i].ts) * 2).toFixed(1) + ',' + Y(vals[k]).toFixed(1)).join(' L');
      const c = change(z - a, a > 0 ? a : 0);
      const label = tRaw('val.card_label', { system: sname, a: m0(a), d1: day(w.t0), b: m0(z), d2: day(w.t1) });
      b.innerHTML = `<div class="vg-trend-cap"><span>${t('val.card_since', { date: day(w.t0) })}</span><span class="vg-delta ${c.cls}">${esc(c.text)}</span></div>`
        + `<div class="vg-spark"><svg viewBox="0 0 200 44" preserveAspectRatio="none" role="img" aria-label="${esc(label)}"><path d="${d}" vector-effect="non-scaling-stroke"/></svg>`
        + `<span class="vg-spark-dot" style="top:${(Y(z) / 44 * 100).toFixed(1)}%"></span></div>`;
    }
  }

  root.querySelectorAll('#vg-views [data-view]').forEach(b => b.addEventListener('click', () => {
    S.view = b.dataset.view;
    S.focus = null;
    render();
  }));

  // ── Hide / Show graphs: fold the card away or open it again (remembered per user) ──
  $('#vg-toggle').addEventListener('click', async () => {
    const open = !S.open;
    const btn = $('#vg-toggle');
    btn.disabled = true;
    const r = await fetch(BASE + '/api/value_pref.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ collapsed: open ? 0 : 1 }) })
      .then(r => r.json()).catch(() => ({ ok: false }));
    btn.disabled = false;
    if (!r.ok) {
      const tst = document.getElementById('dash-toast');
      if (tst) { tst.textContent = r.error || tRaw('common.error'); tst.classList.add('show'); setTimeout(() => tst.classList.remove('show'), 1800); }
      return;
    }
    S.open = open;
    if (open && !S.data) await start();
    else { build(); render(); }
  });

  async function start() {
    await load();   // the cards keep their "Value trend" placeholder until the data is in
    if (S.data && S.data.points.length >= 2 && !applyPreset('6m')) S.preset = 'all';
    build();
    render();
  }

  if (S.open) start();
  else { build(); render(); }
})();
