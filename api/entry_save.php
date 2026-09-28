<?php
require_once __DIR__ . '/../config.php';
$user = requireAuth();

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) jsonOut(['ok'=>false,'error'=>tRaw('api.no_data')], 400);

$gameId  = (int)($body['game_id'] ?? 0);
$copyNum = max(1, (int)($body['copy_number'] ?? 1));
if (!$gameId) jsonOut(['ok'=>false,'error'=>tRaw('api.invalid_game')], 400);

$chk = db()->prepare("SELECT id FROM games WHERE id=?");
$chk->execute([$gameId]);
if (!$chk->fetch()) jsonOut(['ok'=>false,'error'=>tRaw('api.game_not_found')], 404);

// Only the fields present in the request are written, so quick toggles
// (owned / wishlist / upgrade) never wipe the copy's other details or its grading.
$money = fn($v) => ($v !== null && $v !== '') ? (float)$v : null;
$text  = fn($v) => $v === null ? null : (string)$v;
$fields = [];
foreach (['owned','wishlist','upgrade'] as $f)                    if (array_key_exists($f, $body)) $fields[$f] = (int)(bool)$body[$f];
foreach (['completeness','played_status'] as $f)                   if (array_key_exists($f, $body)) $fields[$f] = (string)($body[$f] ?? '');
foreach (['price_paid','chart_price','price_min','price_max'] as $f) if (array_key_exists($f, $body)) $fields[$f] = $money($body[$f]);
foreach (['upgrade_reason','notes','tag'] as $f)                   if (array_key_exists($f, $body)) $fields[$f] = $text($body[$f]);
if (array_key_exists('value_price_type', $body)) {
    $fields['value_price_type'] = in_array($body['value_price_type'], ['loose','cib','new'], true) ? $body['value_price_type'] : 'cib';
}
if (array_key_exists('primary_photo', $body)) {
    $fields['primary_photo'] = ($body['primary_photo'] ?? '') !== '' ? (string)$body['primary_photo'] : null;
}

gradingConfig(); // loads (and on first run seeds) grading data before the transaction starts

$pdo = db();
$pdo->beginTransaction();
try {
    $find = $pdo->prepare("SELECT id FROM collection_entries WHERE user_id=? AND game_id=? AND copy_number=? FOR UPDATE");
    $find->execute([$user['id'], $gameId, $copyNum]);
    $entryId = $find->fetchColumn();

    if (!$entryId) {
        $cols = array_merge(['user_id','game_id','copy_number'], array_keys($fields));
        $vals = array_merge([$user['id'], $gameId, $copyNum], array_values($fields));
        $pdo->prepare("INSERT INTO collection_entries (".implode(',', $cols).") VALUES (".implode(',', array_fill(0, count($cols), '?')).")")
            ->execute($vals);
        $entryId = (int)$pdo->lastInsertId();
    } elseif ($fields) {
        $set = implode(', ', array_map(fn($c) => "$c=?", array_keys($fields)));
        $pdo->prepare("UPDATE collection_entries SET $set, updated_at=NOW() WHERE id=?")
            ->execute([...array_values($fields), $entryId]);
    }

    // Condition grading: {method, label_id, profile_id, parts?}
    if (isset($body['grading']) && is_array($body['grading'])) {
        saveEntryGrading((int)$entryId, (int)$user['id'], $body['grading']);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonOut(['ok'=>false,'error'=>tRaw('api.save_failed', ['error' => $e->getMessage()])], 500);
}

$st2 = db()->prepare("
    SELECT ce.*, GROUP_CONCAT(cp.filename ORDER BY cp.sort_order SEPARATOR '||') AS photos_raw
    FROM collection_entries ce
    LEFT JOIN copy_photos cp ON cp.entry_id = ce.id
    WHERE ce.id=?
    GROUP BY ce.id
");
$st2->execute([$entryId]);
$entry = $st2->fetch();
$entry['owned']    = (bool)$entry['owned'];
$entry['upgrade']  = (bool)$entry['upgrade'];
$entry['wishlist'] = (bool)$entry['wishlist'];
$entry['photos']   = $entry['photos_raw'] ? explode('||', $entry['photos_raw']) : [];
unset($entry['photos_raw']);
$entry['grading']  = loadEntryGrading([$entry['id']])[(int)$entry['id']] ?? null;

jsonOut(['ok'=>true,'entry'=>$entry]);
