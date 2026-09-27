<?php
require_once __DIR__ . '/../config.php';
$user = requireAuth();

if (!class_exists('ZipArchive')) {
    jsonOut(['ok'=>false,'error'=>'The PHP zip extension is not installed on this server'], 500);
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$systemId = (int)($body['system_id'] ?? 0);
if (!$systemId) jsonOut(['ok'=>false,'error'=>'Missing system_id'], 400);

$st = db()->prepare("SELECT id, name, short_name FROM systems WHERE id=? AND active=1");
$st->execute([$systemId]);
$system = $st->fetch();
if (!$system) jsonOut(['ok'=>false,'error'=>'System not found'], 404);

// All of this user's photos for the system, with the game title for the zip folder
$photosSt = db()->prepare("
    SELECT cp.filename, g.title
    FROM copy_photos cp
    JOIN collection_entries ce ON ce.id = cp.entry_id
    JOIN games g ON g.id = ce.game_id
    WHERE ce.user_id = ? AND g.system_id = ?
    ORDER BY g.sort_title, ce.copy_number, cp.sort_order
");
$photosSt->execute([$user['id'], $systemId]);
$photos = $photosSt->fetchAll();
if (!$photos) jsonOut(['ok'=>false,'error'=>'No photos found for this system'], 404);

purgeExpiredBackups();

// Replace the existing backup for this user+system — unless one is being built right now
$existing = db()->prepare("SELECT filename, status, created_at > NOW() - INTERVAL 5 MINUTE AS recent
                           FROM user_backups WHERE user_id=? AND system_id=?");
$existing->execute([$user['id'], $systemId]);
if ($old = $existing->fetch()) {
    if ($old['status'] === 'generating' && $old['recent']) {
        jsonOut(['ok'=>false,'error'=>'A backup for this system is already being generated'], 409);
    }
    @unlink(backupFilePath($user['id'], $old['filename']));
    db()->prepare("DELETE FROM user_backups WHERE user_id=? AND system_id=?")->execute([$user['id'], $systemId]);
}

$backupDir = BACKUP_DIR . $user['id'] . '/';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

$token    = bin2hex(random_bytes(16));
$filename = 'sys_' . $systemId . '_' . $token . '.zip';
$zipPath  = $backupDir . $filename;

try {
    db()->prepare("
        INSERT INTO user_backups (user_id, system_id, token, filename, status, expires_at)
        VALUES (?, ?, ?, ?, 'generating', NOW() + INTERVAL 24 HOUR)
    ")->execute([$user['id'], $systemId, $token, $filename]);
} catch (PDOException $e) {
    // Unique key hit: a parallel request for the same system got there first
    jsonOut(['ok'=>false,'error'=>'A backup for this system is already being generated'], 409);
}

// Building can take a while: release the session lock so the user's other tabs keep working
session_write_close();
@set_time_limit(300);

function backupFailed(string $token, string $zipPath, string $error): never {
    @unlink($zipPath);
    db()->prepare("UPDATE user_backups SET status='failed' WHERE token=?")->execute([$token]);
    jsonOut(['ok'=>false,'error'=>$error], 500);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    backupFailed($token, $zipPath, 'Could not create zip file');
}

// Only files inside this user's own upload folder may be added
$userBase  = realpath(UPLOAD_DIR . $user['id']);
$sysFolder = zipSafeName($system['short_name']);
$added = 0; $missing = 0;
foreach ($photos as $p) {
    $fullPath = realpath(UPLOAD_DIR . $p['filename']);
    if (!$fullPath || !$userBase || !str_starts_with($fullPath, $userBase . DIRECTORY_SEPARATOR) || !is_file($fullPath)) {
        $missing++;
        continue;
    }
    // e.g. SNES/Super Mario Kart/12_345_abc.jpg
    $name = $sysFolder . '/' . zipSafeName($p['title']) . '/' . basename($fullPath);
    $zip->addFile($fullPath, $name);
    $zip->setCompressionName($name, ZipArchive::CM_STORE); // photos are already compressed
    $added++;
}

if ($added === 0) {
    $zip->close();
    backupFailed($token, $zipPath, 'None of the photo files could be found on the server');
}
if (!$zip->close()) {
    backupFailed($token, $zipPath, 'Writing the zip file failed (disk full?)');
}

$fileSize = filesize($zipPath);
db()->prepare("UPDATE user_backups SET status='ready', file_size=? WHERE token=?")->execute([$fileSize, $token]);

$exp = db()->prepare("SELECT expires_at FROM user_backups WHERE token=?");
$exp->execute([$token]);

jsonOut([
    'ok'          => true,
    'token'       => $token,
    'file_size'   => $fileSize,
    'photo_count' => $added,
    'missing'     => $missing,
    'expires_at'  => $exp->fetchColumn(),
]);
