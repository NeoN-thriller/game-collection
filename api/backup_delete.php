<?php
require_once __DIR__ . '/../config.php';
$user  = requireAuth();
$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$token = $body['token'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) jsonOut(['ok'=>false,'error'=>tRaw('api.invalid_token')], 400);

$st = db()->prepare("SELECT filename FROM user_backups WHERE token=? AND user_id=?");
$st->execute([$token, $user['id']]);
$filename = $st->fetchColumn();
if ($filename === false) jsonOut(['ok'=>false,'error'=>tRaw('api.not_found')], 404);

@unlink(backupFilePath($user['id'], $filename));
db()->prepare("DELETE FROM user_backups WHERE token=? AND user_id=?")->execute([$token, $user['id']]);

jsonOut(['ok'=>true]);
