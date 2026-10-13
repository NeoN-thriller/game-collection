<?php
require_once __DIR__ . '/../boot.php';
requireAuth();
$sysId = (int)($_GET['system_id'] ?? 0);
$st = db()->prepare("
    SELECT id, title, sort_title, sort_order, default_image,
           external_id, price_link,
           cib_price,   cib_price_updated_at,
           loose_price, loose_price_updated_at,
           new_price,   new_price_updated_at,
           group_id, edition_label, edition_sort
    FROM games WHERE system_id=? AND active=1 ORDER BY sort_title
");
$st->execute([$sysId]);
$games = $st->fetchAll();

// The price before the last price import that changed it (the ▲ / ▼ on the collection page);
// none while the columns don't exist yet
try {
    $pq = db()->prepare("SELECT id, loose_price_prev, cib_price_prev, new_price_prev FROM games WHERE system_id=? AND active=1");
    $pq->execute([$sysId]);
    $prev = [];
    foreach ($pq->fetchAll() as $r) $prev[(int)$r['id']] = $r;
} catch (PDOException) { $prev = []; }
foreach ($games as &$g) {
    foreach (['loose', 'cib', 'new'] as $k) $g[$k.'_price_prev'] = $prev[(int)$g['id']][$k.'_price_prev'] ?? null;
}
unset($g);

// Barcodes per game (the optional Barcodes column); 0 everywhere while the barcode tables don't exist yet
try {
    $bq = db()->prepare("SELECT b.game_id, COUNT(*) FROM game_barcodes b JOIN games g ON g.id = b.game_id WHERE g.system_id = ? GROUP BY b.game_id");
    $bq->execute([$sysId]);
    $bc = array_map('intval', $bq->fetchAll(PDO::FETCH_KEY_PAIR));
} catch (PDOException) { $bc = []; }
foreach ($games as &$g) $g['barcode_count'] = $bc[(int)$g['id']] ?? 0;
unset($g);

// IGDB info for the optional columns (release date, developer, …): own match, another edition's, or a compilation's contents
$ig = igdbColumns($sysId);
$games = withCovers($games);   // cover_url: the uploaded or the IGDB cover (Settings › IGDB › Covers)
foreach ($games as &$g) $g['igdb'] = $ig[(int)$g['id']] ?? null;
unset($g);

// Edition groups of this system. The client shows a group only when at least two of its games are active.
$gr = db()->prepare("SELECT id, title, sort_title, main_game_id FROM game_groups WHERE system_id=?");
$gr->execute([$sysId]);

// Compilations of this system and what they contain: [{id, items:[game ids]}]
jsonOut(['ok'=>true, 'games'=>$games, 'groups'=>$gr->fetchAll(), 'compilations'=>compilationsOfSystem($sysId)]);
