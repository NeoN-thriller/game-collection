<?php
require_once __DIR__ . '/../config.php';
$user = requireAuth();

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) jsonOut(['ok'=>false,'error'=>'No data'], 400);

$gameId       = (int)($body['game_id']      ?? 0);
$copyNum      = max(1, (int)($body['copy_number'] ?? 1));
$owned        = isset($body['owned'])        ? (int)(bool)$body['owned']    : 0;
$quality      = $body['quality']             ?? '';
$complete     = $body['completeness']        ?? '';
$played       = $body['played_status']       ?? '';
$wishlist     = isset($body['wishlist'])     ? (int)(bool)$body['wishlist']  : 0;
$pricePaid    = isset($body['price_paid'])   && $body['price_paid']   !== '' ? (float)$body['price_paid']   : null;
$chartPrice   = isset($body['chart_price'])  && $body['chart_price']  !== '' ? (float)$body['chart_price']  : null;
$priceMin     = isset($body['price_min'])    && $body['price_min']    !== '' ? (float)$body['price_min']    : null;
$priceMax     = isset($body['price_max'])    && $body['price_max']    !== '' ? (float)$body['price_max']    : null;
$upgrade      = isset($body['upgrade'])      ? (int)(bool)$body['upgrade']   : 0;
$upReason     = $body['upgrade_reason']      ?? null;
$notes        = $body['notes']               ?? null;
$tag          = $body['tag']                 ?? null;
$valuePriceType = in_array($body['value_price_type']??'', ['loose','cib','new']) ? $body['value_price_type'] : 'cib';
$primaryPhoto = isset($body['primary_photo']) && $body['primary_photo'] !== '' ? $body['primary_photo'] : null;

if (!$gameId) jsonOut(['ok'=>false,'error'=>'Invalid game'], 400);

$chk = db()->prepare("SELECT id FROM games WHERE id=?");
$chk->execute([$gameId]);
if (!$chk->fetch()) jsonOut(['ok'=>false,'error'=>'Game not found'], 404);

$st = db()->prepare("
    INSERT INTO collection_entries
        (user_id, game_id, copy_number, owned, quality, completeness, played_status,
         wishlist, price_paid, chart_price, price_min, price_max,
         upgrade, upgrade_reason, notes, tag, value_price_type, primary_photo)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
        owned          = VALUES(owned),
        quality        = VALUES(quality),
        completeness   = VALUES(completeness),
        played_status  = VALUES(played_status),
        wishlist       = VALUES(wishlist),
        price_paid     = VALUES(price_paid),
        chart_price    = VALUES(chart_price),
        price_min      = VALUES(price_min),
        price_max      = VALUES(price_max),
        upgrade        = VALUES(upgrade),
        upgrade_reason = VALUES(upgrade_reason),
        notes          = VALUES(notes),
        tag            = VALUES(tag),
        value_price_type = VALUES(value_price_type),
        primary_photo  = VALUES(primary_photo),
        updated_at     = NOW()
");
$st->execute([
    $user['id'], $gameId, $copyNum,
    $owned, $quality, $complete, $played,
    $wishlist, $pricePaid, $chartPrice, $priceMin, $priceMax,
    $upgrade, $upReason, $notes, $tag, $valuePriceType, $primaryPhoto
]);

$st2 = db()->prepare("
    SELECT ce.*, GROUP_CONCAT(cp.filename ORDER BY cp.sort_order SEPARATOR '||') AS photos_raw
    FROM collection_entries ce
    LEFT JOIN copy_photos cp ON cp.entry_id = ce.id
    WHERE ce.user_id=? AND ce.game_id=? AND ce.copy_number=?
    GROUP BY ce.id
");
$st2->execute([$user['id'], $gameId, $copyNum]);
$entry = $st2->fetch();
$entry['owned']    = (bool)$entry['owned'];
$entry['upgrade']  = (bool)$entry['upgrade'];
$entry['wishlist'] = (bool)$entry['wishlist'];
$entry['photos']   = $entry['photos_raw'] ? explode('||', $entry['photos_raw']) : [];
unset($entry['photos_raw']);

jsonOut(['ok'=>true,'entry'=>$entry]);
