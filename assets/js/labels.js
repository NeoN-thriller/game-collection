/* ═══════════════════════════════════════════
   LABELS — QR codes and the printable condition-report sticker.
   One renderer for the template preview (settings › Label templates), the drawer and print_label.php.
   Needs assets/js/vendor/qrcode.js (qrcode-generator, MIT — see vendor/LICENSE-qrcode.txt).

   Labels.qrSvg(text)                          → SVG markup (error correction M, 2-module quiet zone)
   Labels.render(el, tpl, data, pxPerMm|null)  → true when everything fits
       tpl:  {width_mm, height_mm, orientation, layout, fields:[{id,on,scale}], colour, cut_line}
       data: {score, cond:{name,color}|null, qr, title, meta, date, id}
       pxPerMm: px per mm for a screen preview; null = real millimetres (print)
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

  function defaultFields() {
    return FIELDS.map(id => ({ id, on: true, scale: 1 }));
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

    // Horizontal: score + cond share a column, the QR is its own column, text fields share the rest.
    // Stacked: one centred column.
    const cols = [];
    (tpl.fields || []).filter(f => f.on).forEach(f => {
      const g = stacked ? 'stack' : GROUP[f.id];
      const last = cols[cols.length - 1];
      if (last && last.g === g && g !== 'qr') last.items.push(f);
      else cols.push({ g, items: [f] });
    });

    el.className = 'lbl' + (stacked ? ' lbl-stacked' : '') + (tpl.cut_line ? ' lbl-cut' : '');
    el.style.width = u(W);
    el.style.height = u(H);
    el.style.padding = u(S * 0.06);
    el.style.gap = u(S * 0.05);
    el.innerHTML = cols.map(c => `<div class="lbl-col lbl-col-${c.g}" style="gap:${u(S * 0.02)}">${c.items.map(html).join('')}</div>`).join('');

    // Fit check: anything cut off, on the label or inside a column?
    const over = x => x.scrollHeight > x.clientHeight + 1 || x.scrollWidth > x.clientWidth + 1;
    return ![el, ...el.querySelectorAll('.lbl-col')].some(over);
  }

  return { FIELDS, qrSvg, render, dims, natural, defaultFields };
})();
