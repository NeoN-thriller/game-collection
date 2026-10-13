<?php
require_once __DIR__ . '/../boot.php';
$user = requireAuth();

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) jsonOut(['ok'=>false,'error'=>tRaw('api.no_data')], 400);

$gameId  = (int)($body['game_id'] ?? 0);
$copyNum = max(1, (int)($body['copy_number'] ?? 1));
if (!$gameId) jsonOut(['ok'=>false,'error'=>tRaw('api.invalid_game')], 400);

$chk = db()->prepare("SELECT id, group_id, system_id FROM games WHERE id=?");
$chk->execute([$gameId]);
$game = $chk->fetch();
if (!$game) jsonOut(['ok'=>false,'error'=>tRaw('api.game_not_found')], 404);

// Editions: move this copy to another edition of the same group (keeps the entry id, so grading, photos and notes stay)
$moveTo = (int)($body['move_to_game_id'] ?? 0);
if ($moveTo === $gameId) $moveTo = 0;
if ($moveTo) {
    $mv = db()->prepare("SELECT group_id FROM games WHERE id=?");
    $mv->execute([$moveTo]);
    $targetGroup = $mv->fetchColumn();
    if ($game['group_id'] === null || $targetGroup === false || (string)$targetGroup !== (string)$game['group_id']) {
        jsonOut(['ok'=>false,'error'=>tRaw('api.invalid_game')], 400);
    }
}

// Only the fields present in the request are written, so quick toggles
// (owned / wishlist / upgrade) never wipe the copy's other details or its grading.
$money = fn($v) => ($v !== null && $v !== '') ? (float)$v : null;
$text  = fn($v) => $v === null ? null : (string)$v;
$fields = [];
foreach (['owned','wishlist','upgrade'] as $f)                    if (array_key_exists($f, $body)) $fields[$f] = (int)(bool)$body[$f];
foreach (['completeness','played_status'] as $f)                   if (array_key_exists($f, $body)) $fields[$f] = (string)($body[$f] ?? '');
if (array_key_exists('variant', $body))      $fields['variant']      = mb_substr(trim((string)($body['variant'] ?? '')), 0, 100);
if (array_key_exists('wishlist_any', $body)) $fields['wishlist_any'] = (int)(bool)$body['wishlist_any'];
foreach (['price_paid','chart_price','price_min','price_max'] as $f) if (array_key_exists($f, $body)) $fields[$f] = $money($body[$f]);
foreach (['upgrade_reason','notes','tag'] as $f)                   if (array_key_exists($f, $body)) $fields[$f] = $text($body[$f]);
if (array_key_exists('value_price_type', $body)) {
    $fields['value_price_type'] = in_array($body['value_price_type'], ['loose','cib','new'], true) ? $body['value_price_type'] : 'cib';
}
if (array_key_exists('primary_photo', $body)) {
    $fields['primary_photo'] = ($body['primary_photo'] ?? '') !== '' ? (string)$body['primary_photo'] : null;
}

gradingConfig(); // loads (and on first run seeds) grading data before the transaction starts
$quickOwned = array_keys($fields) === ['owned'];   // the OWN tick in the table (for the activity log)

$pdo = db();
$pdo->beginTransaction();
try {
    // The whole row: its values before the save are what the activity log compares with
    $find = $pdo->prepare("SELECT * FROM collection_entries WHERE user_id=? AND game_id=? AND copy_number=? FOR UPDATE");
    $find->execute([$user['id'], $gameId, $copyNum]);
    $cur = $find->fetch();
    $entryId = $cur ? $cur['id'] : false;
    $stateBefore = $cur ? activityCopyState((int)$cur['id']) : null;

    // First time on the wishlist (e.g. the ♥ in the table): "any edition" follows the user's setting for games with editions
    if (($fields['wishlist'] ?? 0) === 1 && !array_key_exists('wishlist_any', $fields) && (!$cur || !$cur['wishlist'])) {
        $fields['wishlist_any'] = $game['group_id'] !== null && ($user['edition_wishlist'] ?? 'any') === 'any' ? 1 : 0;
    }

    if (!$entryId) {
        // New copies use the site's default price tier for "owned value" unless the request says otherwise
        $fields += ['value_price_type' => in_array(setting('default_value_type'), VALUE_TYPES, true) ? setting('default_value_type') : 'cib'];
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

    // Photo tags {photo_id: {part_ref, unit_no, defects} | null}, checked against the grading just saved.
    // A tag that doesn't fit (e.g. its part was removed in this save) is skipped, not an error.
    if (isset($body['photo_tags']) && is_array($body['photo_tags'])) {
        $valid = photoTagValidUnits((int)$entryId);
        foreach ($body['photo_tags'] as $pid => $tag) {
            try { photoTagSet((int)$entryId, (int)$user['id'], (int)$pid, is_array($tag) ? $tag : null, $valid); }
            catch (DomainException) { /* skipped */ }
        }
    }

    if ($moveTo) {
        $next = $pdo->prepare("SELECT COALESCE(MAX(copy_number), 0) + 1 FROM collection_entries WHERE user_id=? AND game_id=? FOR UPDATE");
        $next->execute([$user['id'], $moveTo]);
        $newCopy = (int)$next->fetchColumn();
        $pdo->prepare("UPDATE collection_entries SET game_id=?, copy_number=?, updated_at=NOW() WHERE id=?")
            ->execute([$moveTo, $newCopy, $entryId]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonOut(['ok'=>false,'error'=>tRaw('api.save_failed', ['error' => $e->getMessage()])], 500);
}

// Collection value over time (writes nothing when the save didn't change the value, e.g. notes or grading)
valueSnapshot([(int)$user['id']], [(int)$game['system_id']], 'collection');

// ── Activity log: one event per save, only what changed (nothing when nothing did) ──
try {
    $rowSt = db()->prepare("SELECT * FROM collection_entries WHERE id=?");
    $rowSt->execute([$entryId]);
    $after = $rowSt->fetch() ?: [];
    $changes = activityDiff($cur ?: [], $after, LOG_COPY_FIELDS);
    if (!$cur) {
        // A new copy: the values it was given (not the empty / default ones)
        $changes = array_values(array_filter($changes, fn($c) => $c['to'] !== null && !(($c['ty'] ?? '') === 'bool' && $c['to'] === '0')
                                                                 && $c['k'] !== 'value_type'));
    }
    $changes = array_merge($changes, activityStateDiff($stateBefore, activityCopyState((int)$entryId)));
    $keys = array_column($changes, 'k');
    $ownedNow = (int)($after['owned'] ?? 0) === 1;
    if ($moveTo) {
        $ed = db()->prepare("SELECT id, edition_label, title FROM games WHERE id IN (?, ?)");
        $ed->execute([$gameId, $moveTo]);
        $labels = [];
        foreach ($ed->fetchAll() as $r) $labels[(int)$r['id']] = $r['edition_label'] ?: $r['title'];
        array_unshift($changes, activityChange('edition', $labels[$gameId] ?? null, $labels[$moveTo] ?? null));
        $act = 'copy.move_edition';
    } elseif (!$cur) {
        $act = $ownedNow || empty($after['wishlist']) ? 'copy.add' : 'wishlist.add';
    } elseif (!$changes) {
        $act = null;
    } elseif (!array_diff($keys, ['wishlist', 'any_edition']) && in_array('wishlist', $keys, true)) {
        $act = !empty($after['wishlist']) ? 'wishlist.add' : 'wishlist.remove';
    } elseif (in_array('owned', $keys, true)) {
        $act = $ownedNow ? 'copy.add' : 'copy.unown';
    } else {
        $act = 'copy.edit';
    }
    // The sentence already says it
    $said = match ($act) { 'wishlist.add', 'wishlist.remove' => ['wishlist', 'owned'], 'copy.add', 'copy.unown' => ['owned'], default => [] };
    $changes = array_values(array_filter($changes, fn($c) => !in_array($c['k'], $said, true)));
    if ($act) {
        $g = activityGame((int)$after['game_id']);
        // Ticking OWN in the table for several games in a row: one "Added n games" row
        $quick = $quickOwned && $act === 'copy.add';
        logEvent('collection', $act, [
            'actor'   => $user,
            'subject' => ['copy', (int)$entryId],
            'params'  => ['title' => $g['title'], 'system' => $g['system'], 'sid' => $g['system_id'] ?? 0, 'copy' => (int)$after['copy_number']],
            'changes' => $quick ? [] : $changes,
            'merge'   => $quick ? ['key' => $g['system_id'] ?? 0, 'window' => 600, 'into' => 'games.add_many'] : null,
        ]);
    }
} catch (Throwable $e) {
    error_log('game-collection: activity log (entry_save): '.$e->getMessage());
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
$entry['wishlist_any'] = (bool)($entry['wishlist_any'] ?? false);
$entry['photos']   = $entry['photos_raw'] ? explode('||', $entry['photos_raw']) : [];
unset($entry['photos_raw']);
$entry['grading']  = loadEntryGrading([$entry['id']])[(int)$entry['id']] ?? null;
$photoD = entryPhotoData([(int)$entry['id']])[(int)$entry['id']] ?? [];
$entry['photos']      = $photoD['photos'] ?? [];
$entry['photo_items'] = $photoD['photo_items'] ?? [];
$entry['photo_tags']  = (object)(photoTagsForEntries([(int)$entry['id']], [(int)$entry['id'] => $entry['grading'] ?? []])[(int)$entry['id']] ?? []);

jsonOut(['ok'=>true, 'entry'=>$entry] + ($moveTo ? ['moved_from' => ['game_id' => $gameId, 'copy_number' => $copyNum]] : []));
