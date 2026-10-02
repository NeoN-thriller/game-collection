/* ═══════════════════════════════════════════
   SHARE CONDITION REPORT — drawer section on collection.php and wishlist.php (#d-share).
   Only for saved, owned copies of the signed-in user. Talks to api/share.php.
   Needs labels.js (QR + label preview as a PNG) and BASE from the page.

   ShareDrawer.load({entryId, owned, canEdit})
   ═══════════════════════════════════════════ */
const ShareDrawer = (() => {
  let st = { entryId: null }, data = null, busy = false;
  let png = null, pngSeq = 0;   // current label image: {blob, url, name}
  const box = () => document.getElementById('d-share');
  const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const note = (msg, err = false) => { if (typeof toast === 'function') toast(msg, err); };

  async function post(action, body = {}) {
    try {
      const res = await fetch(`${BASE}/api/share.php`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...body }) }).then(r => r.json());
      if (!res.ok) note(res.error || tRaw('common.err_unknown'), true);
      return res;
    } catch (err) {
      note(tRaw('common.err_prefix', { error: err.message }), true);
      return { ok: false };
    }
  }

  async function load({ entryId, owned, canEdit }) {
    const el = box();
    if (!el) return;
    st = { entryId, owned, canEdit };
    data = null;
    if (!canEdit || !entryId || !owned) { el.hidden = true; el.innerHTML = ''; return; }
    el.hidden = false;
    el.innerHTML = `<div class="section-label">${t('cr.share_title')}</div><p class="d-hint">${t('cr.loading')}</p>`;
    const res = await post('get', { entry_id: entryId });
    if (st.entryId !== entryId) return;   // another copy was opened meanwhile
    if (!res.ok) { el.innerHTML = `<div class="section-label">${t('cr.share_title')}</div>`; return; }
    data = res;
    render();
  }

  function render() {
    const el = box();
    const s = data.share;
    let html = `<div class="section-label">${t('cr.share_title')}</div>`;
    if (!s) {
      html += `<p class="d-hint">${t('cr.share_intro')}</p>
        <div><button type="button" class="btn btn-sm" data-act="create">${t('cr.create_link')}</button></div>`;
      el.innerHTML = html;
      return;
    }
    const tpls = data.templates.map(tp => `<option value="${tp.id}"${tp.id === (st.tpl ?? data.default_template_id) ? ' selected' : ''}>${e(tp.name)}</option>`).join('');
    html += `
      <div class="cr-share-link">
        <input type="text" readonly value="${e(s.url)}" aria-label="${t('cr.link')}" id="d-share-url">
        <button type="button" class="btn-icon" data-act="copy">${t('cr.copy_link')}</button>
        <a class="btn-icon" href="${e(s.url)}" target="_blank" rel="noopener">${t('cr.open')} ↗</a>
      </div>
      <div class="cr-share-qr">
        <div class="cr-share-qr-img">${Labels.qrSvg(s.url)}</div>
        <div class="d-hint">${t('cr.report_id', { id: s.report_id })}</div>
      </div>
      <div class="field">
        <label for="d-share-tpl">${t('cr.label_template')}</label>
        <select id="d-share-tpl">${tpls}</select>
      </div>
      <div class="cr-share-png">
        <img id="d-share-png" alt="${t('cr.label_preview')}" hidden>
        <p class="d-hint" id="d-share-png-msg">${t('cr.loading')}</p>
      </div>
      <p class="d-hint" id="d-share-png-fit" style="color:var(--orange)" hidden>${t('cr.label_fit')}</p>
      <div class="cr-share-png-actions">
        <button type="button" class="btn btn-sm" data-act="print">${t('cr.print_label')}</button>
        <button type="button" class="btn-ghost btn-sm" data-act="png-download">⬇ ${t('cr.download_png')}</button>
        ${canShareFiles() ? `<button type="button" class="btn-ghost btn-sm" data-act="png-share">${t('cr.share_png')}</button>` : ''}
      </div>
      <div class="toggle-row">
        <label class="toggle"><input type="checkbox" id="d-share-live"${s.mode === 'live' ? ' checked' : ''}><span class="toggle-slider"></span></label>
        <label class="toggle-label" for="d-share-live">${t('cr.live_toggle')}</label>
      </div>
      <p class="d-hint">${t(s.mode === 'live' ? 'cr.live_hint' : 'cr.snapshot_hint')}</p>
      <div class="toggle-row">
        <label class="toggle"><input type="checkbox" id="d-share-sale"${s.for_sale ? ' checked' : ''}><span class="toggle-slider"></span></label>
        <label class="toggle-label" for="d-share-sale">${t('cr.for_sale_toggle')}</label>
      </div>
      ${s.mode === 'snapshot' ? `
      <div class="cr-share-update">
        <span class="d-hint">${t('cr.graded_on', { date: s.graded_fmt })}</span>
        <button type="button" class="btn-ghost btn-sm" data-act="update">${t('cr.update_report')}</button>
      </div>` : ''}
      <div class="cr-share-actions">
        <button type="button" class="btn-ghost btn-sm" data-act="regenerate">${t('cr.new_link')}</button>
        <button type="button" class="btn-danger btn-sm" data-act="revoke">${t('cr.stop_sharing')}</button>
      </div>`;
    el.innerHTML = html;
    updatePng();
  }

  // ── Label as a PNG: shown in the drawer, downloadable and shareable ──

  /** Whether this browser can hand image files to the system share sheet (phones, some desktops). */
  function canShareFiles() {
    try { return !!navigator.canShare?.({ files: [new File([''], 'label.png', { type: 'image/png' })] }); }
    catch { return false; }
  }

  async function updatePng() {
    const img = document.getElementById('d-share-png'), msg = document.getElementById('d-share-png-msg');
    const s = data?.share;
    if (!img || !s) return;
    const tpl = data.templates.find(tp => String(tp.id) === document.getElementById('d-share-tpl')?.value);
    if (!s.label || !tpl) { msg.textContent = tRaw('cr.png_failed'); return; }
    const seq = ++pngSeq;
    let res;
    try { res = await Labels.toPng(tpl, s.label); }
    catch { if (seq === pngSeq) msg.textContent = tRaw('cr.png_failed'); return; }
    if (seq !== pngSeq || !document.body.contains(img)) return;   // re-rendered meanwhile
    if (png) URL.revokeObjectURL(png.url);
    png = { blob: res.blob, url: URL.createObjectURL(res.blob), name: `label-${s.report_id}.png` };
    img.src = png.url;
    img.hidden = false;
    msg.hidden = true;
    document.getElementById('d-share-png-fit').hidden = res.fits;
  }

  function downloadPng() {
    if (!png) return;
    const a = document.createElement('a');
    a.href = png.url;
    a.download = png.name;
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  async function sharePng() {
    if (!png) return;
    const file = new File([png.blob], png.name, { type: 'image/png' });
    try { await navigator.share({ files: [file], title: data.share.label?.title || tRaw('cr.title') }); }
    catch (err) { if (err?.name !== 'AbortError') note(tRaw('common.err_prefix', { error: err.message }), true); }
  }

  async function act(action, extra = {}) {
    if (busy) return;
    busy = true;
    const res = await post(action, { share_id: data.share?.id, entry_id: st.entryId, ...extra });
    busy = false;
    if (!res.ok) return;
    data.share = res.share;
    render();
    return res;
  }

  async function copyLink() {
    const inp = document.getElementById('d-share-url');
    try { await navigator.clipboard.writeText(inp.value); }
    catch { inp.select(); document.execCommand('copy'); }
    note(tRaw('cr.link_copied'));
  }

  document.addEventListener('click', async ev => {
    const b = ev.target.closest('#d-share [data-act]');
    if (!b || !data) return;
    switch (b.dataset.act) {
      case 'create':     if (await act('create')) note(tRaw('cr.link_created')); break;
      case 'copy':       copyLink(); break;
      case 'png-download': downloadPng(); break;
      case 'png-share':  sharePng(); break;
      case 'print':      window.open(`${BASE}/print_label.php?share=${data.share.id}&tpl=${document.getElementById('d-share-tpl').value}`, '_blank'); break;
      case 'update':     if (await act('update_snapshot')) note(tRaw('cr.report_updated')); break;
      case 'regenerate': if (confirm(tRaw('cr.confirm_new_link')) && await act('regenerate')) note(tRaw('cr.link_created')); break;
      case 'revoke':     if (confirm(tRaw('cr.confirm_stop')) && await act('revoke')) note(tRaw('cr.sharing_stopped')); break;
    }
  });
  document.addEventListener('change', ev => {
    if (!data?.share) return;
    if (ev.target.id === 'd-share-live') act('set_mode', { mode: ev.target.checked ? 'live' : 'snapshot' });
    if (ev.target.id === 'd-share-sale') act('set_for_sale', { for_sale: ev.target.checked ? 1 : 0 });
    if (ev.target.id === 'd-share-tpl') { st.tpl = +ev.target.value; updatePng(); }
  });

  return { load };
})();
