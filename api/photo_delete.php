<?php
// photo_delete.php
require_once __DIR__ . '/../config.php';
$user = requireAuth();
$body = json_decode(file_get_contents('php://input'), true);
$entryId  = (int)($body['entry_id'] ?? 0);
$filename = $body['filename'] ?? '';
if (!is_string($filename) || $filename === '') jsonOut(['ok'=>false,'error'=>tRaw('api.missing_filename')],400);

$chk = db()->prepare("SELECT id FROM collection_entries WHERE id=? AND user_id=?");
$chk->execute([$entryId, $user['id']]);
if (!$chk->fetch()) jsonOut(['ok'=>false,'error'=>tRaw('api.not_found')],404);

$st = db()->prepare("DELETE FROM copy_photos WHERE entry_id=? AND user_id=? AND filename=?");
$st->execute([$entryId, $user['id'], $filename]);
// Only touch the disk if this really was one of the user's photos
if ($st->rowCount() === 0) jsonOut(['ok'=>false,'error'=>tRaw('api.photo_not_found')],404);

// ...and only if the path stays inside the user's own upload folder
$base     = realpath(UPLOAD_DIR . $user['id']);
$fullPath = realpath(UPLOAD_DIR . $filename);
if ($base && $fullPath && str_starts_with($fullPath, $base . DIRECTORY_SEPARATOR)) @unlink($fullPath);

jsonOut(['ok'=>true]);
