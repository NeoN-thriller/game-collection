<?php
require_once __DIR__ . '/../boot.php';
requireAdmin();

$body = json_decode(file_get_contents('php://input'), true);
$rows = $body['rows'] ?? [];
if (empty($rows)) jsonOut(['ok'=>false,'error'=>tRaw('api.no_rows')], 400);

$imported  = 0;
$updated   = 0;
$skippedArt= 0;
$errors    = 0;
$newSystems = [];

foreach ($rows as $row) {
    $pcId    = trim($row['pc_id']     ?? '');
    $console = strtoupper(trim($row['console']  ?? ''));
    $name    = trim($row['name']      ?? '');
    $link    = trim($row['link']      ?? '');
    $cib     = isset($row['cib'])   && $row['cib']   !== '' ? (float)$row['cib']   : null;
    $loose   = isset($row['loose']) && $row['loose'] !== '' ? (float)$row['loose'] : null;
    $newP    = isset($row['new'])   && $row['new']   !== '' ? (float)$row['new']   : null;
    $artB64  = trim($row['artBase64'] ?? '');

    $game = null;

    // 1. Match by pc_id
    if ($pcId) {
        $st = db()->prepare("SELECT g.*, s.short_name FROM games g JOIN systems s ON s.id=g.system_id WHERE g.pc_id=? LIMIT 1");
        $st->execute([$pcId]);
        $game = $st->fetch() ?: null;
    }

    // 2. Match by console + title
    if (!$game && $console && $name) {
        $st = db()->prepare("SELECT g.*, s.short_name FROM games g JOIN systems s ON s.id=g.system_id WHERE s.short_name=? AND LOWER(g.title)=LOWER(?) LIMIT 1");
        $st->execute([$console, $name]);
        $game = $st->fetch() ?: null;
    }

    // 3. New game
    if (!$game) {
        if (!$console || !$name) { $errors++; continue; }
        $st = db()->prepare("SELECT id FROM systems WHERE short_name=? AND active=1 LIMIT 1");
        $st->execute([$console]);
        $sys = $st->fetch();
        if (!$sys) { $errors++; continue; }

        $sortTitle = preg_replace('/^(the |a |an )/i', '', $name);
        $sortTitle = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/i', ' ', $sortTitle)));
        $st2 = db()->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM games WHERE system_id=?");
        $st2->execute([$sys['id']]); $so = $st2->fetchColumn();

        db()->prepare("INSERT INTO games (system_id,title,sort_title,sort_order,pc_id,pc_link,cib_price,cib_price_updated_at,loose_price,loose_price_updated_at,new_price,new_price_updated_at) VALUES (?,?,?,?,?,?,?,NOW(),?,NOW(),?,NOW())")
            ->execute([$sys['id'],$name,$sortTitle,$so,$pcId,$link,$cib,$loose,$newP]);
        $newId = db()->lastInsertId();

        if ($artB64 && str_contains($artB64,'base64,')) {
            $fn = saveArt($artB64,$newId);
            if ($fn) db()->prepare("UPDATE games SET default_image=? WHERE id=?")->execute([$fn,$newId]);
        }

        // Recalculate sort orders
        $allG = db()->prepare("SELECT id,sort_title FROM games WHERE system_id=? ORDER BY sort_title ASC");
        $allG->execute([$sys['id']]);
        $upd = db()->prepare("UPDATE games SET sort_order=? WHERE id=?");
        foreach ($allG->fetchAll() as $idx => $r) $upd->execute([$idx+1,$r['id']]);
        $imported++;
        $newSystems[(int)$sys['id']] = true;   // for the "possible edition groups" notice
        continue;
    }

    // 4. Update existing
    $gameId = $game['id'];
    $fields = []; $vals = [];

    if (empty($game['pc_id']) && $pcId)  { $fields[]='pc_id=?';    $vals[]=$pcId; }
    if ($link && $link!==($game['pc_link']??'')) { $fields[]='pc_link=?'; $vals[]=$link; }

    if ($cib   !== null && (float)($game['cib_price']   ??-1) !== $cib)   { $fields[]='cib_price=?';   $vals[]=$cib;   $fields[]='cib_price_updated_at=NOW()'; }
    if ($loose !== null && (float)($game['loose_price'] ??-1) !== $loose) { $fields[]='loose_price=?'; $vals[]=$loose; $fields[]='loose_price_updated_at=NOW()'; }
    if ($newP  !== null && (float)($game['new_price']   ??-1) !== $newP)  { $fields[]='new_price=?';   $vals[]=$newP;  $fields[]='new_price_updated_at=NOW()'; }

    if (empty($game['default_image']) && $artB64 && str_contains($artB64,'base64,')) {
        $fn = saveArt($artB64,$gameId);
        if ($fn) { $fields[]='default_image=?'; $vals[]=$fn; }
    } elseif (!empty($game['default_image'])) {
        $skippedArt++;
    }

    if ($fields) {
        $vals[] = $gameId;
        try { db()->prepare("UPDATE games SET ".implode(',',$fields)." WHERE id=?")->execute($vals); $updated++; }
        catch (Exception $e) { $errors++; }
    }
}

jsonOut(['ok'=>true,'imported'=>$imported,'updated'=>$updated,'skipped_art'=>$skippedArt,'errors'=>$errors,'system_ids'=>array_keys($newSystems)]);

function saveArt(string $b64, int $gameId): ?string {
    try {
        $parts=$b64; if (!str_contains($b64,'base64,')) return null;
        $parts   = explode('base64,',$b64,2);
        $mime    = str_replace('data:','',explode(';',$parts[0])[0]);
        $extMap  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        $ext     = $extMap[$mime]??'jpg';
        $decoded = base64_decode($parts[1]);
        if (!$decoded||strlen($decoded)<100) return null;
        if (!is_dir(DEFAULTS_DIR)) mkdir(DEFAULTS_DIR,0755,true);
        $fn = 'pc_'.$gameId.'_'.uniqid().'.'.$ext;
        file_put_contents(DEFAULTS_DIR.$fn,$decoded);
        return $fn;
    } catch (Exception $e) { return null; }
}
