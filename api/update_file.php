<?php
// Sends a release zip that Settings › Updates fetched into uploads/updates/ (admins only).
require_once __DIR__ . '/../boot.php';
requireAdmin();
$path = updateZipPath((string)($_GET['f'] ?? ''));
if (!$path) { http_response_code(404); exit(tRaw('api.file_not_found')); }

// Don't hold the session lock during the download, and drop every output buffer so nothing corrupts the zip
session_write_close();
while (ob_get_level()) ob_end_clean();
@set_time_limit(0);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-cache, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
