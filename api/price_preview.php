<?php
require_once __DIR__ . '/../boot.php';
requireAdmin();

$body = json_decode(file_get_contents('php://input'), true);
$rows = $body['rows'] ?? [];
if (empty($rows)) jsonOut(['ok'=>false,'error'=>tRaw('api.no_rows')], 400);

$items = [];

foreach ($rows as $row) {
    $externalId = trim((string)($row['external_id'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9-]{1,20}$/', $externalId)) $externalId = '';   // ignored by the import too
    $console = strtoupper(trim($row['console'] ?? ''));
    $name    = trim($row['name']    ?? '');
    $cib     = isset($row['cib'])   && $row['cib']   !== '' ? (float)$row['cib']   : null;
    $loose   = isset($row['loose']) && $row['loose'] !== '' ? (float)$row['loose'] : null;
    $newP    = isset($row['new'])   && $row['new']   !== '' ? (float)$row['new']   : null;
    $hasArt  = !empty($row['hasArt']);

    $game = null;
    $matchType = 'none';

    // 1. Try match by external_id
    if ($externalId) {
        $st = db()->prepare("SELECT g.id, g.title, g.external_id, g.price_link, g.cib_price, g.loose_price, g.new_price, g.default_image, g.cib_price_updated_at, s.short_name FROM games g JOIN systems s ON s.id=g.system_id WHERE g.external_id=? LIMIT 1");
        $st->execute([$externalId]);
        $game = $st->fetch();
        if ($game) $matchType = 'external_id';
    }

    // 2. Try match by console short_name + title
    if (!$game && $console && $name) {
        $st = db()->prepare("SELECT g.id, g.title, g.external_id, g.price_link, g.cib_price, g.loose_price, g.new_price, g.default_image, g.cib_price_updated_at, s.short_name FROM games g JOIN systems s ON s.id=g.system_id WHERE s.short_name=? AND LOWER(g.title)=LOWER(?) LIMIT 1");
        $st->execute([$console, $name]);
        $game = $st->fetch();
        if ($game) $matchType = 'title';
    }

    if ($game) {
        // Same comparison as api/price_import.php: a price counts when the file has one and it differs
        $differs     = fn(?float $v, string $col) => $v !== null && (float)($game[$col] ?? -1) !== $v;
        $cibChanged  = $differs($cib, 'cib_price');
        $looseChanged = $differs($loose, 'loose_price');
        $newChanged  = $differs($newP, 'new_price');
        $artAction   = !empty($game['default_image']) ? 'skip' : ($hasArt ? 'set' : 'none');
        $items[] = [
            'status'       => 'match',
            'match_type'   => $matchType,
            'game_id'      => $game['id'],
            'game_title'   => $game['title'],
            'has_existing_art' => !empty($game['default_image']),
            'current_cib'  => $game['cib_price'],
            'cib_changed'  => $cibChanged,
            'loose_changed'=> $looseChanged,
            'new_changed'  => $newChanged,
            'price_changed'=> $cibChanged || $looseChanged || $newChanged,
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
