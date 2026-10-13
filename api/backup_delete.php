<?php
require_once __DIR__ . '/../boot.php';
$user  = requireAuth();
$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$token = $body['token'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) jsonOut(['ok'=>false,'error'=>tRaw('api.invalid_token')], 400);

$st = db()->prepare("SELECT ub.filename, s.short_name FROM user_backups ub JOIN systems s ON s.id=ub.system_id WHERE ub.token=? AND ub.user_id=?");
$st->execute([$token, $user['id']]);
$row = $st->fetch();
if (!$row) jsonOut(['ok'=>false,'error'=>tRaw('api.not_found')], 404);
$filename = $row['filename'];

@unlink(backupFilePath($user['id'], $filename));
db()->prepare("DELETE FROM user_backups WHERE token=? AND user_id=?")->execute([$token, $user['id']]);

logEvent('collection', 'backup.delete', ['actor' => $user, 'params' => ['system' => $row['short_name']]]);

jsonOut(['ok'=>true]);
