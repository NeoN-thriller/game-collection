<?php
// photo_delete.php
require_once __DIR__ . '/../config.php';
$user = requireAuth();
$body = json_decode(file_get_contents('php://input'), true);
$entryId  = (int)($body['entry_id'] ?? 0);
$filename = $body['filename'] ?? '';

$chk = db()->prepare("SELECT id FROM collection_entries WHERE id=? AND user_id=?");
$chk->execute([$entryId, $user['id']]);
if (!$chk->fetch()) jsonOut(['ok'=>false,'error'=>'Not found'],404);

$st = db()->prepare("DELETE FROM copy_photos WHERE entry_id=? AND user_id=? AND filename=?");
$st->execute([$entryId, $user['id'], $filename]);

$fullPath = UPLOAD_DIR.$filename;
if (file_exists($fullPath)) @unlink($fullPath);

jsonOut(['ok'=>true]);
