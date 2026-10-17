<?php
require_once __DIR__ . '/../boot.php';
$admin = requireAdmin();

$body = json_decode(file_get_contents('php://input'), true);
// One import is sent in batches; they share this id so the activity log shows it as one line
$run = substr(preg_replace('/[^a-z0-9]/i', '', (string)($body['run'] ?? '')), 0, 40);

// The end of a deferred run (see $defer below): the value graph point, once for the whole import.
// New games are not looked up on IGDB here: Settings › IGDB (and the dashboard) shows they're waiting for Match.
if (!empty($body['finish'])) {
    $priceSys = array_map('intval', (array)($body['price_system_ids'] ?? []));
    if ($priceSys) valueSnapshot(null, $priceSys, 'import', $run !== '' ? $run : 'x'.bin2hex(random_bytes(6)));
    jsonOut(['ok'=>true]);
}

$rows = $body['rows'] ?? [];
if (empty($rows)) jsonOut(['ok'=>false,'error'=>tRaw('api.no_rows')], 400);
$updateOnly  = !empty($body['update_only']);                        // matched games only, nothing added
$newInactive = !$updateOnly && !empty($body['new_inactive']);       // new games start hidden (games.active = 0)
// The page sends a finish call after the last batch: the batches then skip the value snapshot
$defer = !empty($body['defer']);

$imported  = 0;
$updated   = 0;
$skippedArt= 0;
$skippedNew= 0;
$errors    = 0;
$newSystems = [];
$priceSystems = [];   // systems with changed prices (value graphs: valueSnapshot below)
$resort = [];         // systems with new games: sort orders recalculated once, after the rows

foreach ($rows as $row) {
    $externalId = trim((string)($row['external_id'] ?? ''));
    $console = strtoupper(trim($row['console']  ?? ''));
    $name    = trim($row['name']      ?? '');
    $link    = trim((string)($row['link'] ?? ''));
    // Same rules as the add / edit game form: an id that isn't one is ignored, and only http(s) links of at most
    // 255 characters are stored (anything else, e.g. a javascript: link, would become a clickable link for every user)
    if (!preg_match('/^[A-Za-z0-9-]{1,20}$/', $externalId)) $externalId = '';
    if (!priceLinkSafe($link) || mb_strlen($link) > 255) $link = '';
    if (mb_strlen($name) > 255) { $errors++; continue; }
    $cib     = isset($row['cib'])   && $row['cib']   !== '' ? (float)$row['cib']   : null;
    $loose   = isset($row['loose']) && $row['loose'] !== '' ? (float)$row['loose'] : null;
    $newP    = isset($row['new'])   && $row['new']   !== '' ? (float)$row['new']   : null;
    $artB64  = trim($row['artBase64'] ?? '');

    $game = null;

    // 1. Match by external_id
    if ($externalId) {
        $st = db()->prepare("SELECT g.*, s.short_name FROM games g JOIN systems s ON s.id=g.system_id WHERE g.external_id=? LIMIT 1");
        $st->execute([$externalId]);
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
        if ($updateOnly) { $skippedNew++; continue; }
        if (!$console || !$name) { $errors++; continue; }
        $st = db()->prepare("SELECT id FROM systems WHERE short_name=? AND active=1 LIMIT 1");
        $st->execute([$console]);
        $sys = $st->fetch();
        if (!$sys) { $errors++; continue; }

        $sortTitle = preg_replace('/^(the |a |an )/i', '', $name);
        $sortTitle = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/i', ' ', $sortTitle)));
        $st2 = db()->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM games WHERE system_id=?");
        $st2->execute([$sys['id']]); $so = $st2->fetchColumn();

        try {
            db()->prepare("INSERT INTO games (system_id,title,sort_title,sort_order,external_id,price_link,cib_price,cib_price_updated_at,loose_price,loose_price_updated_at,new_price,new_price_updated_at,active) VALUES (?,?,?,?,?,?,?,NOW(),?,NOW(),?,NOW(),?)")
                ->execute([$sys['id'],$name,$sortTitle,$so,$externalId !== '' ? $externalId : null,$link !== '' ? $link : null,$cib,$loose,$newP,$newInactive ? 0 : 1]);
        } catch (PDOException $e) { $errors++; continue; }   // one bad row (e.g. a price out of range) doesn't stop the batch
        $newId = db()->lastInsertId();

        if ($artB64 && str_contains($artB64,'base64,')) {
            $fn = saveArt($artB64,$newId);
            if ($fn) db()->prepare("UPDATE games SET default_image=? WHERE id=?")->execute([$fn,$newId]);
        }

        $resort[(int)$sys['id']] = true;
        $imported++;
        $newSystems[(int)$sys['id']] = true;   // for the "possible edition groups" notice
        continue;
    }

    // 4. Update existing
    $gameId = $game['id'];
    $fields = []; $vals = [];

    if (empty($game['external_id']) && $externalId)  { $fields[]='external_id=?';    $vals[]=$externalId; }
    if ($link && $link!==($game['price_link']??'')) { $fields[]='price_link=?'; $vals[]=$link; }

    // Prices: a change keeps the old price in *_price_prev (the ▲ / ▼ on the collection page); a price this
    // import finds unchanged clears it, so the arrow always means "changed by the last import of this game"
    $priceChanged = false;
    $clearPrev = [];
    foreach (['cib' => $cib, 'loose' => $loose, 'new' => $newP] as $k => $v) {
        if ($v === null) continue;
        if ((float)($game[$k.'_price'] ?? -1) !== $v) {
            $fields[] = $k.'_price=?';      $vals[] = $v;
            $fields[] = $k.'_price_prev=?'; $vals[] = $game[$k.'_price'];   // NULL when it had no price yet: no arrow
            $fields[] = $k.'_price_updated_at=NOW()';
            $priceChanged = true;
        } elseif (array_key_exists($k.'_price_prev', $game) && $game[$k.'_price_prev'] !== null) {
            $clearPrev[] = $k.'_price_prev=NULL';
        }
    }

    if (empty($game['default_image']) && $artB64 && str_contains($artB64,'base64,')) {
        $fn = saveArt($artB64,$gameId);
        if ($fn) { $fields[]='default_image=?'; $vals[]=$fn; }
    } elseif (!empty($game['default_image'])) {
        $skippedArt++;
    }

    if ($fields || $clearPrev) {
        $vals[] = $gameId;
        try {
            db()->prepare("UPDATE games SET ".implode(',', array_merge($fields, $clearPrev))." WHERE id=?")->execute($vals);
            if ($fields) $updated++;   // only clearing an old arrow is not an update
            if ($priceChanged) $priceSystems[(int)$game['system_id']] = true;
        }
        catch (Exception $e) { $errors++; }
    }
}

// Recalculate sort orders: once per system for the whole batch, in one transaction, only the rows that moved
if ($resort) {
    $allG = db()->prepare("SELECT id,sort_title,sort_order FROM games WHERE system_id=? ORDER BY sort_title ASC");
    $upd  = db()->prepare("UPDATE games SET sort_order=? WHERE id=?");
    db()->beginTransaction();
    try {
        foreach (array_keys($resort) as $sysId) {
            $allG->execute([$sysId]);
            foreach ($allG->fetchAll() as $idx => $r) if ((int)$r['sort_order'] !== $idx+1) $upd->execute([$idx+1,$r['id']]);
        }
        db()->commit();
    } catch (Exception $e) { db()->rollBack(); error_log('price import: sort order: '.$e->getMessage()); }
}

// Collection value over time: every batch of one import shares the run, so the import ends as one point
if (!$defer && $priceSystems) valueSnapshot(null, array_keys($priceSystems), 'import', $run !== '' ? $run : 'x'.bin2hex(random_bytes(6)));

$changes = [activityChange('rows', null, (string)count($rows)), activityChange('prices_updated', null, (string)$updated),
            activityChange('new_games', null, (string)$imported), activityChange('errors', null, (string)$errors)];
if ($updateOnly)  $changes[] = activityChange('import_mode', null, 'update_only', 'enum');
if ($newInactive) $changes[] = activityChange('import_mode', null, 'new_hidden', 'enum');
if ($skippedNew)  $changes[] = activityChange('not_added', null, (string)$skippedNew);
logEvent('catalogue', 'prices.import', ['admin' => true, 'actor' => $admin,
    'params'  => ['prices' => $updated, 'new' => $imported],
    'changes' => $changes,
    'merge'   => $run !== '' ? ['key' => 'prices'.$run, 'window' => 3600, 'sum' => true] : null]);

jsonOut(['ok'=>true,'imported'=>$imported,'updated'=>$updated,'skipped_art'=>$skippedArt,'skipped_new'=>$skippedNew,'errors'=>$errors,'system_ids'=>array_keys($newSystems),
         'price_system_ids'=>array_keys($priceSystems)]);

function saveArt(string $b64, int $gameId): ?string {
    try {
        $parts=$b64; if (!str_contains($b64,'base64,')) return null;
        $parts   = explode('base64,',$b64,2);
        $decoded = base64_decode($parts[1], true);
        if (!$decoded||strlen($decoded)<100||strlen($decoded)>MAX_FILE_SIZE) return null;
        // The file must really be one of the allowed image types (the data: header itself is not trusted)
        $mime    = (new finfo(FILEINFO_MIME_TYPE))->buffer($decoded);
        $extMap  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        if (!isset($extMap[$mime]) || !@getimagesizefromstring($decoded)) return null;
        $ext     = $extMap[$mime];
        if (!is_dir(DEFAULTS_DIR)) mkdir(DEFAULTS_DIR,0755,true);
        $fn = 'cover_'.$gameId.'_'.bin2hex(random_bytes(8)).'.'.$ext;
        file_put_contents(DEFAULTS_DIR.$fn,$decoded);
        return $fn;
    } catch (Exception $e) { return null; }
}
