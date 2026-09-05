<?php
require_once __DIR__ . '/../config.php';
requireAdmin();

$body = json_decode(file_get_contents('php://input'), true);
$rows = $body['rows'] ?? [];
if (empty($rows)) jsonOut(['ok'=>false,'error'=>'No rows'], 400);

$items = [];

foreach ($rows as $row) {
    $pcId    = trim($row['pc_id']   ?? '');
    $console = strtoupper(trim($row['console'] ?? ''));
    $name    = trim($row['name']    ?? '');
    $cib     = isset($row['cib']) && $row['cib'] !== '' ? (float)$row['cib'] : null;
    $hasArt  = !empty($row['hasArt']);

    $game = null;
    $matchType = 'none';

    // 1. Try match by pc_id
    if ($pcId) {
        $st = db()->prepare("SELECT g.id, g.title, g.pc_id, g.pc_link, g.cib_price, g.default_image, g.cib_price_updated_at, s.short_name FROM games g JOIN systems s ON s.id=g.system_id WHERE g.pc_id=? LIMIT 1");
        $st->execute([$pcId]);
        $game = $st->fetch();
        if ($game) $matchType = 'pc_id';
    }

    // 2. Try match by console short_name + title
    if (!$game && $console && $name) {
        $st = db()->prepare("SELECT g.id, g.title, g.pc_id, g.pc_link, g.cib_price, g.default_image, g.cib_price_updated_at, s.short_name FROM games g JOIN systems s ON s.id=g.system_id WHERE s.short_name=? AND LOWER(g.title)=LOWER(?) LIMIT 1");
        $st->execute([$console, $name]);
        $game = $st->fetch();
        if ($game) $matchType = 'title';
    }

    if ($game) {
        $cibChanged  = $cib !== null && (float)$game['cib_price'] !== $cib;
        $artAction   = !empty($game['default_image']) ? 'skip' : ($hasArt ? 'set' : 'none');
        $items[] = [
            'status'       => 'match',
            'match_type'   => $matchType,
            'game_id'      => $game['id'],
            'game_title'   => $game['title'],
            'has_existing_art' => !empty($game['default_image']),
            'current_cib'  => $game['cib_price'],
            'cib_changed'  => $cibChanged,
            'last_updated' => $game['cib_price_updated_at'],
            'art_action'   => $artAction,
        ];
    } else {
        // Will be imported as new game — need system_id
        $st = db()->prepare("SELECT id FROM systems WHERE short_name=? AND active=1 LIMIT 1");
        $st->execute([$console]);
        $sys = $st->fetch();
        $items[] = [
            'status'     => 'new',
            'system_id'  => $sys ? $sys['id'] : null,
            'system_ok'  => $sys ? true : false,
        ];
    }
}

jsonOut(['ok'=>true, 'items'=>$items]);
