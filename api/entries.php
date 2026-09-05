<?php
require_once __DIR__ . '/../config.php';
$user   = requireAuth();
$sysId  = (int)($_GET['system_id'] ?? 0);
$gameId = (int)($_GET['game_id']   ?? 0);

if ($gameId) {
    $st = db()->prepare("
        SELECT ce.*,
               GROUP_CONCAT(DISTINCT CONCAT(cp.sort_order,'::',cp.filename) ORDER BY cp.sort_order SEPARATOR '||') AS photos_raw
        FROM collection_entries ce
        LEFT JOIN copy_photos cp ON cp.entry_id = ce.id
        WHERE ce.user_id = ? AND ce.game_id = ?
        GROUP BY ce.id
    ");
    $st->execute([$user['id'], $gameId]);
} else {
    $st = db()->prepare("
        SELECT ce.*,
               GROUP_CONCAT(DISTINCT CONCAT(cp.sort_order,'::',cp.filename) ORDER BY cp.sort_order SEPARATOR '||') AS photos_raw
        FROM collection_entries ce
        LEFT JOIN copy_photos cp ON cp.entry_id = ce.id
        JOIN games g ON g.id = ce.game_id
        WHERE ce.user_id = ? AND g.system_id = ?
        GROUP BY ce.id
    ");
    $st->execute([$user['id'], $sysId]);
}
$rows = $st->fetchAll();

foreach ($rows as &$row) {
    $row['owned']    = (bool)$row['owned'];
    $row['upgrade']  = (bool)$row['upgrade'];
    $row['wishlist'] = (bool)$row['wishlist'];
    $photos = [];
    if ($row['photos_raw']) {
        foreach (explode('||', $row['photos_raw']) as $p) {
            $parts = explode('::', $p, 2);
            $photos[] = $parts[1] ?? $p;
        }
    }
    $row['photos'] = $photos;
    unset($row['photos_raw']);
}

jsonOut(['ok'=>true,'entries'=>$rows]);
