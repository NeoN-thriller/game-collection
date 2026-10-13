<?php
// Crops one of the user's copy photos (assets/js/photo-crop.js) and replaces it, like photo_rotate.php.
// POST JSON {entry_id, filename, x, y, width, height, nw, nh}: the box in pixels of the image as the
// browser had it (nw × nh), scaled to the file's real size here. Answers {ok, filename, ts}.
require_once __DIR__ . '/../boot.php';
$user = requireAuth();

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$entryId  = (int)($body['entry_id'] ?? 0);
$filename = (string)($body['filename'] ?? '');
if (!$entryId || $filename === '') jsonOut(['ok'=>false,'error'=>tRaw('api.missing_params')], 400);

// The copy and the photo must be this user's
$chk = db()->prepare("SELECT 1 FROM copy_photos cp JOIN collection_entries ce ON ce.id = cp.entry_id
                      WHERE cp.entry_id=? AND cp.user_id=? AND cp.filename=? AND ce.user_id=?");
$chk->execute([$entryId, $user['id'], $filename, $user['id']]);
if (!$chk->fetchColumn()) jsonOut(['ok'=>false,'error'=>tRaw('api.photo_not_found')], 404);

$fullPath = UPLOAD_DIR . $filename;
$base = realpath(UPLOAD_DIR . $user['id']);
$real = realpath($fullPath);
if (!$base || !$real || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) jsonOut(['ok'=>false,'error'=>tRaw('api.file_not_found')], 404);

$src = match ((new finfo(FILEINFO_MIME_TYPE))->file($real)) {
    'image/jpeg' => @imagecreatefromjpeg($real),
    'image/png'  => @imagecreatefrompng($real),
    'image/webp' => @imagecreatefromwebp($real),
    default      => false,
};
if (!$src) jsonOut(['ok'=>false,'error'=>tRaw('api.unsupported')], 400);

// Box → the file's pixels (the browser may have had a scaled or older size)
$W = imagesx($src); $H = imagesy($src);
$sx = (int)($body['nw'] ?? 0) > 0 ? $W / (int)$body['nw'] : 1;
$sy = (int)($body['nh'] ?? 0) > 0 ? $H / (int)$body['nh'] : 1;
$x = max(0, (int)round((float)($body['x'] ?? 0) * $sx));
$y = max(0, (int)round((float)($body['y'] ?? 0) * $sy));
$w = min($W - $x, (int)round((float)($body['width'] ?? 0) * $sx));
$h = min($H - $y, (int)round((float)($body['height'] ?? 0) * $sy));
if ($w < 10 || $h < 10) { imagedestroy($src); jsonOut(['ok'=>false,'error'=>tRaw('crop.err_small')], 400); }

$out = imagecrop($src, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
imagedestroy($src);
if (!$out) jsonOut(['ok'=>false,'error'=>tRaw('api.could_not_save')], 500);

// Saved as JPEG under the same name, so tags, the primary photo and shared reports keep pointing at it
$ok = imagejpeg($out, $real, imageSettings()['quality']);
imagedestroy($out);
if (!$ok) jsonOut(['ok'=>false,'error'=>tRaw('api.could_not_save')], 500);

jsonOut(['ok'=>true, 'filename'=>$filename, 'ts'=>time()]);
