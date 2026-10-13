<?php
// Sends a finished site backup (admins only). Also works while database updates are waiting.
require_once __DIR__ . '/../boot.php';
requireAdmin();
$row = siteBackupRow((int)($_GET['id'] ?? 0));
$path = $row && $row['status'] === 'ready' ? realpath(siteBackupPath($row['filename'])) : false;
$base = realpath(SITE_BACKUP_DIR);
if (!$path || !$base || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404); exit(tRaw('api.file_not_found'));
}

// e.g. game-collection_backup_2026-10-03_1405.zip
$slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(siteName())) ?? '', '-') ?: 'site';
$name = $slug.'_backup_'.date('Y-m-d_Hi', strtotime((string)$row['created_at']) ?: time()).'.zip';

// Don't hold the session lock during a long download, and drop every output buffer so nothing corrupts the zip
session_write_close();
while (ob_get_level()) ob_end_clean();
@set_time_limit(0);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-cache, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
