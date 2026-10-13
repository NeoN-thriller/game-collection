<?php
require_once __DIR__ . '/../boot.php';
$user  = requireAuth();
$token = $_GET['token'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) { http_response_code(400); exit(tRaw('api.invalid_token')); }

// The token only selects a DB row owned by this user; the file path always comes from the DB
$st = db()->prepare("
    SELECT ub.*, s.short_name
    FROM user_backups ub
    JOIN systems s ON s.id = ub.system_id
    WHERE ub.token=? AND ub.user_id=? AND ub.status='ready' AND ub.expires_at > NOW()
");
$st->execute([$token, $user['id']]);
$backup = $st->fetch();
if (!$backup) { http_response_code(404); exit(tRaw('api.backup_gone')); }

$safePath = realpath(backupFilePath($user['id'], $backup['filename']));
$safeBase = realpath(BACKUP_DIR);
if (!$safePath || !$safeBase || !str_starts_with($safePath, $safeBase . DIRECTORY_SEPARATOR) || !is_file($safePath)) {
    http_response_code(404); exit(tRaw('api.file_not_found'));
}

$sysName      = preg_replace('/[^a-z0-9_-]+/', '_', strtolower($backup['short_name'])) ?: 'system';
$downloadName = 'photos_' . $sysName . '_' . date('Ymd', strtotime($backup['created_at'])) . '.zip';

// Don't hold the session lock during a long download, and drop every output buffer
// (config.php starts one) so nothing corrupts the zip
session_write_close();
while (ob_get_level()) ob_end_clean();
@set_time_limit(0);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($safePath));
header('Cache-Control: no-cache, no-store');
header('X-Content-Type-Options: nosniff');
readfile($safePath);
exit;
