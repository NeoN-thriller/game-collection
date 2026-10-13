<?php
// Site backups (admins): start one, carry it on step by step, delete one (Settings › Site Backup),
// and "auto": continue a running backup or start a scheduled one that's due (siteBackupAutoScript()).
require_once __DIR__ . '/../boot.php';
$admin = auth();
if (!$admin || $admin['role'] !== 'admin') jsonOut(['ok' => false, 'error' => tRaw('sbackup.admins_only')], 403);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string)($body['action'] ?? '');
$id     = (int)($body['id'] ?? 0);
// Steps can take a while: don't hold the session lock, so the admin's other tabs keep working
session_write_close();
siteBackupTable();

switch ($action) {
    case 'start':
        [$ok, $res] = siteBackupStart((array)($body['parts'] ?? []), 'manual', (int)$admin['id']);
        jsonOut($ok ? ['ok' => true, 'id' => $res] : ['ok' => false, 'error' => $res], $ok ? 200 : 409);

    case 'step':
        jsonOut(siteBackupStep($id));

    case 'delete':
        @set_time_limit(90); // waits for a running step to finish
        [$ok, $err] = siteBackupDelete($id);
        jsonOut(['ok' => $ok, 'error' => $err], $ok ? 200 : 409);

    case 'auto':
        $run = siteBackupRunning();
        if (!$run) {
            siteBackupAdopt();   // zips without a row (e.g. after restoring the database) count toward "keep the last"
            siteBackupPrune();
        }
        if (!$run && siteBackupDue()) {
            [$ok, $res] = siteBackupStart(siteBackupParts(setting('backup_parts')), 'schedule', (int)$admin['id']);
            if (!$ok) {
                // Recorded as a failed scheduled backup, so it's retried in 6 hours instead of on every page view
                if (!in_array($res, [tRaw('sbackup.err_busy'), tRaw('sbackup.err_running')], true)) {
                    db()->prepare("INSERT INTO site_backups (filename, parts, trigger_type, status, error, app_version, created_by, finished_at)
                                   VALUES (?, ?, 'schedule', 'failed', ?, ?, ?, NOW())")
                        ->execute(['failed_'.bin2hex(random_bytes(8)), setting('backup_parts'), mb_substr($res, 0, 500), APP_VERSION, (int)$admin['id']]);
                }
                jsonOut(['ok' => false, 'error' => $res]);
            }
            $run = ['id' => $res];
        }
        jsonOut($run ? siteBackupStep((int)$run['id']) : ['ok' => true, 'status' => 'idle']);
}
jsonOut(['ok' => false, 'error' => tRaw('common.err_unknown_action')], 400);
