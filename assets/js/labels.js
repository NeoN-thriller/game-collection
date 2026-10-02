/* ═══════════════════════════════════════════
   LABELS — QR codes and the printable condition-report sticker.
   One renderer for the template preview (settings › Label templates), the drawer and print_label.php.
   Needs assets/js/vendor/qrcode.js (qrcode-generator, MIT — see vendor/LICENSE-qrcode.txt).

   Labels.qrSvg(text)                          → SVG markup (error correction M, 2-module quiet zone)
   Labels.render(el, tpl, data, pxPerMm|null)  → true when everything fits
       tpl:  {width_mm, height_mm, orientation, layout, fields:[{id,on,scale,join}], colour, cut_line}
             join (horizontal layout): the field goes in the same column as the visible field above it;
             otherwise it starts a new column. Stacked layout: one column, join is ignored.
       data: {score, cond:{name,color}|null, qr, title, meta, date, id}
       pxPerMm: px per mm for a screen preview; null = real millimetres (print)
   Labels.toPng(tpl, data, dpi = 300)          → Promise {blob, fits, width, height}: the same label as a PNG image
   ═══════════════════════════════════════════ */
const Labels = (() => {
  const FIELDS = ['score', 'cond', 'qr', 'title', 'meta', 'date', 'id'];
  const GROUP  = { score: 'score', cond: 'score', qr: 'qr', title: 'text', meta: 'text', date: 'text', id: 'text' };
  const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function qrSvg(text) {
    const q = qrcode(0, 'M');
    q.addData(String(text));
    q.make();
    const n = q.getModuleCount(), m = 2, size = n + 2 * m;
    let d = '';
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (q.isDark(r, c)) d += `M${c + m} ${r + m}h1v1h-1z`;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" shape-rendering="crispEdges" role="img" aria-label="QR"><rect width="${size}" height="${size}" fill="#fff"/><path d="${d}" fill="#000"/></svg>`;
  }

  /** Width and height in mm after orientation (landscape = long side across). */
  function dims(tpl) {
    const a = +tpl.width_mm || 89, b = +tpl.height_mm || 36;
    const long = Math.max(a, b), short = Math.min(a, b);
    return tpl.orientation === 'portrait' ? [short, long] : [long, short];
  }

  /** The orientation a size starts in. */
  function natural(size) {
    return size && +size.height_mm > +size.width_mm ? 'portrait' : 'landscape';
  }

  /**
   * Fills in a missing join flag the way labels were grouped before it existed: score + condition
   * share a column, the QR code has its own, the text fields share one. Mirror of labelNormaliseFields() in PHP.
   */
  function withJoins(fields) {
    let prevOn = null;
    return fields.map((f, i) => {
      const prev = f.on ? prevOn : (fields[i - 1] ? GROUP[fields[i - 1].id] : null);
      const join = typeof f.join === 'boolean' ? f.join : (i > 0 && prev === GROUP[f.id] && GROUP[f.id] !== 'qr');
      if (f.on) prevOn = GROUP[f.id];
      return { ...f, join };
    });
  }

  function defaultFields() {
    return withJoins(FIELDS.map(id => ({ id, on: true, scale: 1 })));
  }

  function render(el, tpl, data, pxPerMm) {
    const [W, H] = dims(tpl);
    const S = Math.min(W, H);
    const u = v => pxPerMm ? (v * pxPerMm).toFixed(2) + 'px' : v.toFixed(2) + 'mm';
    const stacked = tpl.layout === 'stacked';
    // Base sizes relative to the short side, times the field's own scale
    const k = stacked ? { score: .30, qr: .60, title: .075, small: .035 } : { score: .36, qr: .78, title: .105, small: .045 };

    const html = f => {
      const sc = +f.scale || 1;
      switch (f.id) {
        case 'score':
          return data.score === null || data.score === undefined ? '' : `<div class="lbl-score" style="font-size:${u(S * k.score * sc)}">${e(data.score)}</div>`;
        case 'cond': {
          if (!data.cond) return '';
          const c = data.cond.color || '#000';
          const style = tpl.colour
            ? `color:${e(c)};background:color-mix(in srgb, ${e(c)} 18%, transparent);border-color:color-mix(in srgb, ${e(c)} 40%, transparent)`
            : 'color:#000;border-color:#000;background:none';
          return `<div class="lbl-cond" style="font-size:${u(S * k.small * 1.25 * sc)};${style}">${e(data.cond.name)}</div>`;
        }
        case 'qr':
          return data.qr ? `<div class="lbl-qr" style="width:${u(S * k.qr * sc)};height:${u(S * k.qr * sc)}">${qrSvg(data.qr)}</div>` : '';
        case 'title':
          return data.title ? `<div class="lbl-title" style="font-size:${u(S * k.title * sc)}">${e(data.title)}</div>` : '';
        default:
          return data[f.id] ? `<div class="lbl-small" style="font-size:${u(S * k.small * sc)}">${e(data[f.id])}</div>` : '';
      }
    };

    // Horizontal: a field joins the column of the visible field above it, or starts a new column.
    // A column with a text field fills the free width (left-aligned); the others are centred.
    // Stacked: one centred column.
    const cols = [];
    withJoins(tpl.fields || []).filter(f => f.on).forEach(f => {
      if (stacked) { cols.length ? cols[0].items.push(f) : cols.push({ items: [f] }); return; }
      if (f.join && cols.length) cols[cols.length - 1].items.push(f);
      else cols.push({ items: [f] });
    });
    cols.forEach(c => {
      const groups = c.items.map(f => GROUP[f.id]);
      c.g = stacked ? 'stack' : groups.includes('text') ? 'text' : groups.includes('qr') ? 'qr' : 'score';
    });

    el.className = 'lbl' + (stacked ? ' lbl-stacked' : '') + (tpl.cut_line ? ' lbl-cut' : '');
    el.style.width = u(W);
    el.style.height = u(H);
    el.style.padding = u(S * 0.06);
    el.style.gap = u(S * 0.05);
    el.innerHTML = cols.map(c => `<div class="lbl-col lbl-col-${c.g}" style="gap:${u(S * 0.02)}">${c.items.map(html).join('')}</div>`).join('');

    // Fit check: anything cut off, on the label or inside a column? Heights compare the fields' boxes
    // with the room inside (scrollHeight would also count the score's ink above its tight line height).
    const over = x => {
      if (x.scrollWidth > x.clientWidth + 1) return true;
      const kids = [...x.children].map(k => k.getBoundingClientRect());
      if (!kids.length) return false;
      const cs = getComputedStyle(x);
      const room = x.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
      return Math.max(...kids.map(k => k.bottom)) - Math.min(...kids.map(k => k.top)) > room + 1;
    };
    return ![el, ...el.querySelectorAll('.lbl-col')].some(over);
  }

  // ── PNG ──
  // The label is laid out by render() (off screen, at the image's resolution) and then painted
  // onto a canvas element by element, so the image matches the print exactly.

  const MM_PER_CSS_PX = 25.4 / 96;

  /** "#rgb" / "#rrggbb" with an alpha, as rgba(); anything else is returned as is. */
  function withAlpha(hex, a) {
    let h = String(hex).replace('#', '');
    if (h.length === 3) h = h.replace(/./g, c => c + c);
    if (!/^[0-9a-f]{6}$/i.test(h)) return hex;
    return `rgba(${parseInt(h.slice(0, 2), 16)},${parseInt(h.slice(2, 4), 16)},${parseInt(h.slice(4, 6), 16)},${a})`;
  }

  function drawQr(ctx, text, r) {
    const q = qrcode(0, 'M');
    q.addData(String(text));
    q.make();
    const n = q.getModuleCount(), m = 2, cell = r.w / (n + 2 * m);
    ctx.fillStyle = '#fff';
    ctx.fillRect(r.x, r.y, r.w, r.w);
    ctx.fillStyle = '#000';
    for (let row = 0; row < n; row++) for (let col = 0; col < n; col++) {
      if (!q.isDark(row, col)) continue;
      // Whole pixels, so neighbouring modules don't leave hairline gaps
      const x0 = Math.round(r.x + (col + m) * cell), x1 = Math.round(r.x + (col + m + 1) * cell);
      const y0 = Math.round(r.y + (row + m) * cell), y1 = Math.round(r.y + (row + m + 1) * cell);
      ctx.fillRect(x0, y0, x1 - x0, y1 - y0);
    }
  }

  /** Paints one text element character by character at the positions the browser laid out. */
  function drawText(ctx, node, box) {
    const cs = getComputedStyle(node);
    ctx.font = `${cs.fontStyle} ${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
    ctx.fillStyle = cs.color;
    ctx.textBaseline = 'alphabetic';
    const fm = ctx.measureText('Hg');
    const asc = fm.fontBoundingBoxAscent ?? fm.actualBoundingBoxAscent;
    const desc = fm.fontBoundingBoxDescent ?? fm.actualBoundingBoxDescent;
    const upper = cs.textTransform === 'uppercase';
    // Clip to the column: the label hides what doesn't fit, so the image must too
    const clip = (node.closest('.lbl-col') || node).getBoundingClientRect();
    ctx.save();
    ctx.beginPath();
    ctx.rect(clip.left - box.left, clip.top - box.top, clip.width, clip.height);
    ctx.clip();
    const range = document.createRange();
    const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT);
    for (let tn; (tn = walker.nextNode());) {
      const s = tn.data;
      for (let i = 0; i < s.length;) {
        const len = s.codePointAt(i) > 0xffff ? 2 : 1;
        const ch = s.slice(i, i + len);
        range.setStart(tn, i);
        range.setEnd(tn, i + len);
        i += len;
        if (!ch.trim()) continue;
        const r = range.getClientRects()[0];
        if (!r || !r.width) continue;
        // The browser centres the font's ascent + descent in the glyph box
        const y = r.top - box.top + (r.height - (asc + desc)) / 2 + asc;
        ctx.fillText(upper ? ch.toUpperCase() : ch, r.left - box.left, y);
      }
    }
    ctx.restore();
  }

  /**
   * The label as a PNG image. Resolves {blob, fits, width, height}.
   * dpi: image resolution (300 = print quality: an 89 × 36 mm label becomes 1051 × 425 px).
   */
  async function toPng(tpl, data, dpi = 300) {
    const ppm = dpi / 25.4;
    const host = document.createElement('div');
    host.style.cssText = 'position:fixed;left:-100000px;top:0;pointer-events:none';
    const el = document.createElement('div');
    host.appendChild(el);
    document.body.appendChild(host);
    try {
      let fits = render(el, tpl, data, ppm);
      // Make sure every font the label uses is loaded before measuring (they start loading on layout)
      const fonts = [...el.querySelectorAll('.lbl-score, .lbl-cond, .lbl-title, .lbl-small')].map(n => {
        const cs = getComputedStyle(n);
        return `${cs.fontStyle} ${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
      });
      try { await Promise.all([...new Set(fonts)].map(f => document.fonts.load(f))); await document.fonts.ready; } catch { /* draw with what we have */ }
      fits = render(el, tpl, data, ppm);

      const box = el.getBoundingClientRect();
      const W = Math.round(box.width), H = Math.round(box.height);
      const cv = document.createElement('canvas');
      cv.width = W; cv.height = H;
      const ctx = cv.getContext('2d');
      const rel = n => { const r = n.getBoundingClientRect(); return { x: r.left - box.left, y: r.top - box.top, w: r.width, h: r.height }; };
      const hair = MM_PER_CSS_PX * ppm;   // 1 CSS px on paper, at this resolution
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, W, H);

      // Condition badge: tinted box (colour labels) or a black outline, like the CSS in main.css
      el.querySelectorAll('.lbl-cond').forEach(n => {
        const r = rel(n), c = data.cond?.color || '#000';
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(r.x + hair / 2, r.y + hair / 2, r.w - hair, r.h - hair, 2 * hair);
        else ctx.rect(r.x + hair / 2, r.y + hair / 2, r.w - hair, r.h - hair);
        if (tpl.colour) { ctx.fillStyle = withAlpha(c, .18); ctx.fill(); }
        ctx.strokeStyle = tpl.colour ? withAlpha(c, .4) : '#000';
        ctx.lineWidth = hair;
        ctx.stroke();
      });
      el.querySelectorAll('.lbl-qr').forEach(n => drawQr(ctx, data.qr, rel(n)));
      el.querySelectorAll('.lbl-score, .lbl-cond, .lbl-title, .lbl-small').forEach(n => drawText(ctx, n, box));

      // Outline: .3 mm solid, or the 1px dashed cut line
      const bw = tpl.cut_line ? hair : .3 * ppm;
      ctx.strokeStyle = '#000';
      ctx.lineWidth = bw;
      if (tpl.cut_line) ctx.setLineDash([bw * 4, bw * 3]);
      ctx.strokeRect(bw / 2, bw / 2, W - bw, H - bw);

      const blob = await new Promise((res, rej) => cv.toBlob(b => b ? res(b) : rej(new Error('PNG')), 'image/png'));
      return { blob, fits, width: W, height: H };
    } finally {
      host.remove();
    }
  }

  return { FIELDS, qrSvg, render, dims, natural, defaultFields, withJoins, toPng };
})();
