<?php
require_once __DIR__ . '/../boot.php';
$user = requireAuth();

$body     = json_decode(file_get_contents('php://input'), true);
$entryId  = (int)($body['entry_id']  ?? 0);
$filename = $body['filename']         ?? '';
$degrees  = (int)($body['degrees']   ?? 90); // 90, 180, or 270

if (!$entryId || !$filename) jsonOut(['ok'=>false,'error'=>tRaw('api.missing_params')], 400);

// Verify ownership
$chk = db()->prepare("SELECT id FROM collection_entries WHERE id=? AND user_id=?");
$chk->execute([$entryId, $user['id']]);
if (!$chk->fetch()) jsonOut(['ok'=>false,'error'=>tRaw('api.not_found')], 404);

// Verify photo belongs to this entry
$chk2 = db()->prepare("SELECT id FROM copy_photos WHERE entry_id=? AND user_id=? AND filename=?");
$chk2->execute([$entryId, $user['id'], $filename]);
if (!$chk2->fetch()) jsonOut(['ok'=>false,'error'=>tRaw('api.photo_not_found')], 404);

$fullPath = UPLOAD_DIR . $filename;
if (!file_exists($fullPath)) jsonOut(['ok'=>false,'error'=>tRaw('api.file_not_found')], 404);

// Detect type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($fullPath);

$src = null;
switch ($mime) {
    case 'image/jpeg': $src = imagecreatefromjpeg($fullPath); break;
    case 'image/png':  $src = imagecreatefrompng($fullPath);  break;
    case 'image/webp': $src = imagecreatefromwebp($fullPath); break;
    default: jsonOut(['ok'=>false,'error'=>tRaw('api.unsupported')], 400);
}

// imagerotate rotates counter-clockwise, so negate
$rotated = imagerotate($src, -$degrees, 0);
imagedestroy($src);

// Save back as JPEG
if (!imagejpeg($rotated, $fullPath, 85)) {
    imagedestroy($rotated);
    jsonOut(['ok'=>false,'error'=>tRaw('api.could_not_save')], 500);
}
imagedestroy($rotated);

// Add cache-buster to URL
jsonOut(['ok'=>true, 'filename'=>$filename, 'ts'=>time()]);
