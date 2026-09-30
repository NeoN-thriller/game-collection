<?php
// A photo of a shared condition report: grade_photo.php?t=<token>&p=<photo id>
// Only while the share is active, and only photos in the snapshot (or of the copy, in live mode),
// so stopping a share or making a new link also stops its photo links.
require_once __DIR__ . '/boot.php';

$share = shareFindActive((string)($_GET['t'] ?? ''));
$photoId = (int)($_GET['p'] ?? 0);

$notFound = function (): never {
    http_response_code(404);
    header('X-Robots-Tag: noindex');
    exit;
};
if (!$share || !$photoId) $notFound();

if ($share['mode'] !== 'live') {
    $snap = json_decode((string)$share['snapshot'], true);
    if (!in_array($photoId, array_map('intval', $snap['photos'] ?? []), true)) $notFound();
}
$st = db()->prepare("SELECT filename FROM copy_photos WHERE id=? AND entry_id=?");
$st->execute([$photoId, (int)$share['entry_id']]);
$file = $st->fetchColumn();
if (!$file) $notFound();

// The file must be inside the upload folder
$base = realpath(UPLOAD_DIR);
$path = realpath(UPLOAD_DIR . $file);
if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) $notFound();
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) $notFound();

session_write_close();
while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Robots-Tag: noindex');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
