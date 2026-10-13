<?php
// Deletes one of the user's own copies for good (edit drawer › "Delete copy"): the copy, its grading,
// photo tags and condition report links (database cascades) and its photo files. The copies after it
// move up one number, so a game's copies stay numbered 1, 2, 3 …
// POST JSON {entry_id}; CSRF is checked for every /api/* POST in core.php.
require_once __DIR__ . '/../boot.php';
$user = requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok'=>false,'error'=>tRaw('common.err_unknown_action')], 405);

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$entryId = (int)($body['entry_id'] ?? 0);
$uid     = (int)$user['id'];

$pdo = db();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare("SELECT * FROM collection_entries WHERE id=? AND user_id=? FOR UPDATE");
    $st->execute([$entryId, $uid]);
    $copy = $st->fetch();
    if (!$copy) { $pdo->rollBack(); jsonOut(['ok'=>false,'error'=>tRaw('api.not_found')], 404); }
    $state = activityCopyState($entryId);   // for the activity log: what it was
    $ph = $pdo->prepare("SELECT filename FROM copy_photos WHERE entry_id=? AND user_id=?");
    $ph->execute([$entryId, $uid]);
    $photos = $ph->fetchAll(PDO::FETCH_COLUMN);
    $sh = $pdo->prepare("SELECT COUNT(*) FROM copy_shares WHERE entry_id=? AND active=1");
    $sh->execute([$entryId]);
    $shares = (int)$sh->fetchColumn();

    $pdo->prepare("DELETE FROM collection_entries WHERE id=?")->execute([$entryId]);
    // Close the gap: copy 3 becomes copy 2 (in number order, so the unique key never clashes)
    $pdo->prepare("UPDATE collection_entries SET copy_number = copy_number - 1
                   WHERE user_id=? AND game_id=? AND copy_number > ? ORDER BY copy_number")
        ->execute([$uid, (int)$copy['game_id'], (int)$copy['copy_number']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonOut(['ok'=>false,'error'=>tRaw('api.save_failed', ['error' => $e->getMessage()])], 500);
}

// Collection value over time
if ((int)$copy['owned'] === 1) valueSnapshot([$uid], valueSystemsOfGames([(int)$copy['game_id']]), 'collection');

// Photo files: only inside the user's own upload folder (as in photo_delete.php)
$base = realpath(UPLOAD_DIR . $uid);
foreach ($photos as $f) {
    $full = realpath(UPLOAD_DIR . $f);
    if ($base && $full && str_starts_with($full, $base . DIRECTORY_SEPARATOR)) @unlink($full);
}

// Activity log: the values it had, each "→ Removed"
$changes = [];
foreach (LOG_COPY_FIELDS as $col => [$key, $type]) {
    $v = $copy[$col] ?? null;
    if ($v === null || $v === '' || ($type === 'bool' && (string)$v === '0') || $key === 'value_type') continue;
    $changes[] = activityChange($key, $v, '__removed', $type);
}
if ($state['condition'] !== '') $changes[] = activityChange('condition', $state['condition'], '__removed');
if ($photos) $changes[] = activityChange('photos', (string)count($photos), '__removed');
if ($shares) $changes[] = activityChange('report_link', '1', '__removed', 'bool');
$g = activityGame((int)$copy['game_id']);
logEvent('collection', 'copy.delete', [
    'actor'   => $user,
    'subject' => ['copy', $entryId],
    'params'  => ['title' => $g['title'], 'system' => $g['system'], 'sid' => $g['system_id'] ?? 0, 'copy' => (int)$copy['copy_number']],
    'changes' => $changes,
]);

jsonOut(['ok'=>true, 'game_id'=>(int)$copy['game_id'], 'copy_number'=>(int)$copy['copy_number']]);
