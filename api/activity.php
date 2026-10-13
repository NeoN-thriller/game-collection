<?php
// Activity log reader (GET, JSON).
//   Admins: the site-wide log with filters (Settings › Activity log, assets/js/activity.js):
//     period (7|30|90|all) · user (id) · types (comma list) · admin (0|1) · q · before (id, paging) · limit (max 100)
//     → {rows, total, counts:{type: n}, admin_count, has_more}
//   Everyone: scope=copy&entry_id=… → the History of one of their own copies (edit drawer), only their own events,
//     while Log settings allows it.
require_once __DIR__ . '/../boot.php';
$user = requireAuth();
session_write_close();
$uid = (int)$user['id'];

// ── One copy's History ──
if (($_GET['scope'] ?? '') === 'copy') {
    if (setting('log_user_history') !== '1') jsonOut(['ok'=>false, 'error'=>tRaw('log.err_history_off')], 403);
    $entryId = (int)($_GET['entry_id'] ?? 0);
    $st = db()->prepare("SELECT 1 FROM collection_entries WHERE id=? AND user_id=?");
    $st->execute([$entryId, $uid]);
    if (!$st->fetchColumn()) jsonOut(['ok'=>false, 'error'=>tRaw('api.not_found')], 404);
    // Its own events, plus "Added n games" rows that include it (ticked OWN for several games in a row)
    $rows = activityFetch("user_id = ? AND ((subject_type = 'copy' AND subject_id = ?)
                           OR (action = 'games.add_many' AND JSON_CONTAINS(JSON_EXTRACT(data, '$.p.ids'), ?)))",
                          [$uid, $entryId, (string)$entryId], 200);
    jsonOut(['ok'=>true, 'rows'=>activityRowsOut($rows, $user, false)]);
}

// ── The site-wide log (admins) ──
if ($user['role'] !== 'admin') jsonOut(['ok'=>false, 'error'=>tRaw('gapi.admins_only')], 403);
jsonOut(['ok'=>true] + activityPage(activityFiltersFromRequest($_GET), $user));
