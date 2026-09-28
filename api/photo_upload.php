<?php
require_once __DIR__ . '/../config.php';
$user = requireAuth();

$entryId = (int)($_POST['entry_id'] ?? 0);
if (!$entryId) jsonOut(['ok'=>false,'error'=>tRaw('api.no_entry')], 400);

$chk = db()->prepare("SELECT id FROM collection_entries WHERE id=? AND user_id=?");
$chk->execute([$entryId, $user['id']]);
if (!$chk->fetch()) jsonOut(['ok'=>false,'error'=>tRaw('api.not_found')], 404);

if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    jsonOut(['ok'=>false,'error'=>tRaw('api.upload_error')], 400);
}

$file = $_FILES['photo'];
if ($file['size'] > MAX_FILE_SIZE) jsonOut(['ok'=>false,'error'=>tRaw('api.too_large')], 400);

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
if (!in_array($mime, ALLOWED_TYPES)) jsonOut(['ok'=>false,'error'=>tRaw('api.bad_type')], 400);

// Load image settings
$cfg     = imageSettings();
$maxW    = $cfg['max_width'];
$maxH    = $cfg['max_height'];
$qual    = $cfg['quality'];

$userDir = UPLOAD_DIR . $user['id'] . '/';
if (!is_dir($userDir)) mkdir($userDir, 0755, true);

// Always save as JPEG for photos (saves space, good quality)
$filename = $user['id'].'_'.$entryId.'_'.uniqid().'.jpg';
$fullPath = $userDir.$filename;

// Process image with GD
$src = null;
switch ($mime) {
    case 'image/jpeg': $src = imagecreatefromjpeg($file['tmp_name']); break;
    case 'image/png':  $src = imagecreatefrompng($file['tmp_name']);  break;
    case 'image/gif':  $src = imagecreatefromgif($file['tmp_name']);  break;
    case 'image/webp': $src = imagecreatefromwebp($file['tmp_name']); break;
}

if (!$src) {
    // GD failed, save original
    move_uploaded_file($file['tmp_name'], $fullPath);
} else {
    $origW = imagesx($src);
    $origH = imagesy($src);

    // Check EXIF orientation (JPEG only)
    $angle = 0;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $orient = $exif['Orientation'] ?? 1;
        $angle = match((int)$orient) { 3=>180, 6=>-90, 8=>90, default=>0 };
    }
    if ($angle !== 0) {
        $src  = imagerotate($src, $angle, 0);
        if ($angle === 90 || $angle === -90) { $origW = imagesy($src); $origH = imagesx($src); }
        else { $origW = imagesx($src); $origH = imagesy($src); }
    }

    // Scale down if needed
    $scale = min(1, $maxW/$origW, $maxH/$origH);
    $newW  = (int)round($origW * $scale);
    $newH  = (int)round($origH * $scale);

    $dst = imagecreatetruecolor($newW, $newH);
    // White background for transparency
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagejpeg($dst, $fullPath, $qual);
    imagedestroy($src);
    imagedestroy($dst);
}

// Get next sort order
$st = db()->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM copy_photos WHERE entry_id=?");
$st->execute([$entryId]);
$sortOrd = (int)$st->fetchColumn();

db()->prepare("INSERT INTO copy_photos (entry_id, user_id, filename, sort_order) VALUES (?,?,?,?)")
    ->execute([$entryId, $user['id'], $user['id'].'/'.$filename, $sortOrd]);

jsonOut(['ok'=>true,'filename'=>$user['id'].'/'.$filename]);
