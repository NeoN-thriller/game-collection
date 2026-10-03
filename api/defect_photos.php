<?php
// Admin: example photos of a grading defect (up to DEFECT_PHOTO_MAX), shown in its ⓘ explanation.
// upload: multipart POST {defect_id, photo} · delete: JSON POST {action:'delete', photo_id}
// Answers {ok, defect_id, photos:[{id, url}]}. CSRF is checked for every /api/* POST in core.php.
require_once __DIR__ . '/../boot.php';
$admin = requireAuth();
if ($admin['role'] !== 'admin') jsonOut(['ok'=>false,'error'=>tRaw('gapi.admins_only')], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok'=>false,'error'=>tRaw('common.err_unknown_action')], 405);

$pdo = db();

/** The defect's photos as the config gives them. */
function defectPhotosOut(int $defectId): array {
    $st = db()->prepare("SELECT id, filename FROM grade_defect_photos WHERE defect_id=? ORDER BY sort_order, id");
    $st->execute([$defectId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'url' => BASE_URL . '/uploads/defects/' . rawurlencode($r['filename'])], $st->fetchAll());
}

$body = json_decode(file_get_contents('php://input') ?: '', true);

// ── Delete ──
if (is_array($body) && ($body['action'] ?? '') === 'delete') {
    $st = $pdo->prepare("SELECT id, defect_id, filename FROM grade_defect_photos WHERE id=?");
    $st->execute([(int)($body['photo_id'] ?? 0)]);
    $ph = $st->fetch();
    if (!$ph) jsonOut(['ok'=>false,'error'=>tRaw('api.photo_not_found')], 404);
    $pdo->prepare("DELETE FROM grade_defect_photos WHERE id=?")->execute([(int)$ph['id']]);
    @unlink(DEFECT_PHOTO_DIR . basename($ph['filename']));
    jsonOut(['ok'=>true, 'defect_id'=>(int)$ph['defect_id'], 'photos'=>defectPhotosOut((int)$ph['defect_id']), 'msg'=>tRaw('gapi.photo_removed')]);
}

// ── Upload ──
$defectId = (int)($_POST['defect_id'] ?? 0);
$st = $pdo->prepare("SELECT id FROM grade_defects WHERE id=?");
$st->execute([$defectId]);
if (!$st->fetchColumn()) jsonOut(['ok'=>false,'error'=>tRaw('gapi.err_defect')], 404);
$st = $pdo->prepare("SELECT COUNT(*) FROM grade_defect_photos WHERE defect_id=?");
$st->execute([$defectId]);
if ((int)$st->fetchColumn() >= DEFECT_PHOTO_MAX) jsonOut(['ok'=>false,'error'=>tRaw('gapi.err_photo_max', ['n' => DEFECT_PHOTO_MAX])], 400);

$file = $_FILES['photo'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) jsonOut(['ok'=>false,'error'=>tRaw('api.upload_error')], 400);
if ($file['size'] > MAX_FILE_SIZE) jsonOut(['ok'=>false,'error'=>tRaw('api.too_large')], 400);
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
if (!in_array($mime, ALLOWED_TYPES, true)) jsonOut(['ok'=>false,'error'=>tRaw('api.bad_type')], 400);

$src = match ($mime) {
    'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
    'image/png'  => @imagecreatefrompng($file['tmp_name']),
    'image/gif'  => @imagecreatefromgif($file['tmp_name']),
    'image/webp' => @imagecreatefromwebp($file['tmp_name']),
    default      => false,
};
if (!$src) jsonOut(['ok'=>false,'error'=>tRaw('api.bad_type')], 400);

// Upright (EXIF), at most the site's photo size, saved as JPEG on a white background
if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
    $angle = match ((int)(@exif_read_data($file['tmp_name'])['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
    if ($angle) $src = imagerotate($src, $angle, 0);
}
$cfg = imageSettings();
$w = imagesx($src); $h = imagesy($src);
$scale = min(1, $cfg['max_width'] / $w, $cfg['max_height'] / $h);
$nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
$dst = imagecreatetruecolor($nw, $nh);
imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

if (!is_dir(DEFECT_PHOTO_DIR)) @mkdir(DEFECT_PHOTO_DIR, 0755, true);
$filename = 'd' . $defectId . '_' . bin2hex(random_bytes(6)) . '.jpg';
if (!imagejpeg($dst, DEFECT_PHOTO_DIR . $filename, $cfg['quality'])) jsonOut(['ok'=>false,'error'=>tRaw('api.upload_error')], 500);
imagedestroy($src); imagedestroy($dst);

$st = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM grade_defect_photos WHERE defect_id=?");
$st->execute([$defectId]);
$pdo->prepare("INSERT INTO grade_defect_photos (defect_id, filename, sort_order) VALUES (?,?,?)")->execute([$defectId, $filename, (int)$st->fetchColumn()]);
defectPhotoPurgeFiles();   // leftovers of defects that were removed

jsonOut(['ok'=>true, 'defect_id'=>$defectId, 'photos'=>defectPhotosOut($defectId), 'msg'=>tRaw('gapi.photo_saved')]);
