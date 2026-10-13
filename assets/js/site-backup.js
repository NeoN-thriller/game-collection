/* ═══════════════════════════════════════════
   SETTINGS › Site Backup (admin): start a backup and keep it going step by step
   (api/site_backup.php), with a progress bar; delete backups.
   Needs window.CP from settings.php and CP.sbRunning (the backup in progress, or null).
   ═══════════════════════════════════════════ */
const SB_API = `${CP.base}/api/site_backup.php`;
let sbStopping = false; // set while a running backup is being deleted: its loop then ends quietly

function sbPost(data) {
  return fetch(SB_API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data) })
    .then(r => r.json())
    .catch(e => ({ ok:false, error: tRaw('common.err_server', {error: e.message}) }));
}

/** Shows a backup's progress (see siteBackupProgress() in site_backup.php). */
function sbShow(p) {
  document.getElementById('sb-progress').hidden = false;
  document.getElementById('sb-start').disabled = true;
  const pct = p.phase === 'finish' ? 100 : (p.total ? Math.floor(p.done / p.total * 100) : (p.phase === 'files' ? 100 : 0));
  document.getElementById('sb-bar').style.width = (p.phase === 'db' ? 2 : Math.max(2, pct)) + '%';
  document.getElementById('sb-text').textContent =
      p.busy             ? tRaw('sbackup.progress_wait')
    : p.phase === 'db'   ? tRaw('sbackup.progress_db')
    : p.phase === 'files'? tRaw('sbackup.progress_files', {done: fmtNum(p.done), total: fmtNum(p.total)})
    :                      tRaw('sbackup.progress_finish');
  const cell = document.querySelector(`[data-sb-status="${p.id}"]`);
  if (cell) cell.textContent = tRaw('sbackup.status_running') + ' ' + (p.phase === 'db' ? '' : pct + '%');
}

/** Keeps calling "step" until the backup is ready or failed. */
async function sbRun(id) {
  let p = CP.sbRunning && CP.sbRunning.id === id ? CP.sbRunning : { id, phase:'db', done:0, total:0 };
  for (;;) {
    sbShow(p);
    p = await sbPost({ action:'step', id });
    if (p.busy) { sbShow(p); await new Promise(f => setTimeout(f, 3000)); continue; }
    if (!p.ok || p.status !== 'running') break;
  }
  if (sbStopping) return;
  if (p.ok && p.status === 'ready') {
    let msg = tRaw('sbackup.done', {size: sbSize(p.size)});
    if (p.missing) msg += ' ' + tnRaw('sbackup.missing', p.missing);
    toast(msg);
  } else {
    toast(tRaw('common.err_prefix', {error: p.error || tRaw('common.err_unknown')}), true);
  }
  setTimeout(() => window.location.reload(), 1800);
}

async function sbStart() {
  const parts = [...document.querySelectorAll('#sb-parts input:checked')].map(i => i.value);
  if (!parts.length) { toast(tRaw('sbackup.err_no_parts'), true); return; }
  const btn = document.getElementById('sb-start');
  btn.disabled = true;
  const res = await sbPost({ action:'start', parts });
  if (!res.ok) { btn.disabled = false; toast(res.error || tRaw('common.err_unknown'), true); return; }
  sbRun(res.id);
}

async function sbDelete(id, running) {
  if (!confirm(tRaw(running ? 'sbackup.confirm_stop' : 'sbackup.confirm_delete'))) return;
  if (running) { sbStopping = true; toast(tRaw('sbackup.stopping')); }
  const res = await sbPost({ action:'delete', id });
  if (res.ok) { toast(tRaw('sbackup.deleted')); setTimeout(() => window.location.reload(), 800); }
  else { sbStopping = false; toast(res.error || tRaw('common.err_unknown'), true); }
}

function sbSize(b) {
  return b >= 1024 ** 3 ? fmtNum(b / 1024 ** 3, 2) + ' GB'
       : b >= 1024 ** 2 ? fmtNum(b / 1024 ** 2, 1) + ' MB'
       : fmtNum(Math.max(1, b / 1024)) + ' KB';
}

// A backup that's still being built carries on as soon as the page is open
if (CP.sbRunning) sbRun(CP.sbRunning.id);
