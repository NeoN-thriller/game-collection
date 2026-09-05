<?php
require_once __DIR__ . '/../config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $type = $body['type'] ?? 'collection';
    $cols = $body['cols'] ?? [];
    $field = $type === 'wishlist' ? 'column_prefs_wishlist' : 'column_prefs_collection';
    db()->prepare("UPDATE users SET {$field}=? WHERE id=?")
        ->execute([json_encode($cols), $user['id']]);
    jsonOut(['ok'=>true]);
} else {
    $rc = $user['column_prefs_collection'] ?? null;
    $rw = $user['column_prefs_wishlist']   ?? null;
    jsonOut([
        'ok'               => true,
        'cols_collection'  => $rc ? json_decode($rc, true) : null,
        'cols_wishlist'    => $rw ? json_decode($rw, true) : null,
    ]);
}
