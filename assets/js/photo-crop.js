/* ═══════════════════════════════════════════
   PHOTO CROP — free crop of one of the user's copy photos, opened from the photo viewer (✂ Crop).
   Uses Cropper.js 1.6.2 (assets/js/vendor/cropper.min.js + assets/css/vendor/cropper.min.css, MIT).
   The cut is done on the server (api/photo_crop.php) and replaces the photo, like rotating does.

   PhotoCrop.open(src) → Promise of {x, y, width, height, nw, nh} (pixels of the image as shown,
                          plus its natural size) or null when cancelled
   PhotoCrop.isOpen()
   Keys while open: Enter crops, Esc cancels (nothing behind it reacts).
   ═══════════════════════════════════════════ */
const PhotoCrop = (() => {
  let ov = null, cropper = null, resolveFn = null;
  const tx = (key, fallback, vars) => (typeof tRaw === 'function' && typeof LANG !== 'undefined' && key in LANG) ? tRaw(key, vars) : fallback;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function finish(result) {
    if (cropper) { cropper.destroy(); cropper = null; }
    if (ov) { ov.remove(); ov = null; }
    const r = resolveFn; resolveFn = null;
    if (r) r(result);
  }

  function apply() {
    if (!cropper) return;
    const d = cropper.getData(true), img = cropper.getImageData();
    if (d.width < 10 || d.height < 10) return;
    finish({ x: d.x, y: d.y, width: d.width, height: d.height, nw: Math.round(img.naturalWidth), nh: Math.round(img.naturalHeight) });
  }

  function open(src) {
    finish(null);
    return new Promise(resolve => {
      resolveFn = resolve;
      ov = document.createElement('div');
      ov.className = 'pc-overlay';
      ov.setAttribute('role', 'dialog');
      ov.setAttribute('aria-modal', 'true');
      ov.setAttribute('aria-label', tx('crop.title', 'Crop photo'));
      ov.innerHTML = `
        <div class="pc-stage"><img class="pc-img" alt=""></div>
        <p class="pc-hint">${esc(tx('crop.hint', 'Drag the corners or edges of the box. The photo is replaced by the part inside it.'))}</p>
        <div class="pc-bar">
          <button type="button" class="lb-btn" data-pc="cancel">${esc(tx('common.cancel', 'Cancel'))}</button>
          <button type="button" class="lb-btn" data-pc="reset">${esc(tx('crop.reset', 'Reset'))}</button>
          <button type="button" class="btn" data-pc="apply">✂ ${esc(tx('crop.apply', 'Crop'))}</button>
        </div>`;
      document.body.appendChild(ov);
      const img = ov.querySelector('img');
      img.addEventListener('load', () => {
        if (!ov) return;
        cropper = new Cropper(img, {
          viewMode: 1,            // the box stays inside the photo
          autoCropArea: 0.9,
          zoomable: false, rotatable: false, scalable: false, movable: false,
          background: false, toggleDragModeOnDblclick: false,
        });
      }, { once: true });
      img.src = src;
      ov.addEventListener('click', e => {
        const b = e.target.closest('[data-pc]');
        if (!b) return;
        if (b.dataset.pc === 'cancel') finish(null);
        if (b.dataset.pc === 'reset' && cropper) cropper.reset();
        if (b.dataset.pc === 'apply') apply();
      });
      ov.querySelector('[data-pc="apply"]').focus();
    });
  }

  // Registered at load, so it runs before the viewer's and the drawer's own key handlers
  window.addEventListener('keydown', e => {
    if (!ov) return;
    if (e.key === 'Escape') finish(null);
    else if (e.key === 'Enter' && !e.target.closest('[data-pc]')) apply();
    else if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
    e.preventDefault();
    e.stopImmediatePropagation();
  }, true);

  return { open, isOpen: () => !!ov };
})();
