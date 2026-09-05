<?php
require_once __DIR__ . '/../config.php';
$user = requireAuth();

$body = json_decode(file_get_contents('php://input'), true);
$data = $body['data'] ?? null;
if (!$data || empty($data['entries'])) jsonOut(['ok'=>false,'error'=>'Invalid import data'], 400);

$pdo = db();
$pdo->beginTransaction();

try {
    // Restore completeness options if present
    if (!empty($data['completeness_options'])) {
        $pdo->prepare("DELETE FROM user_completeness_options WHERE user_id=?")->execute([$user['id']]);
        $ins = $pdo->prepare("INSERT INTO user_completeness_options (user_id, label, sort_order) VALUES (?,?,?)");
        foreach ($data['completeness_options'] as $opt) {
            $ins->execute([$user['id'], $opt['label'], $opt['sort_order']]);
        }
    }

    foreach ($data['entries'] as $entry) {
        $gameTitle = $entry['game_title'] ?? '';
        $sysShort  = $entry['system']     ?? '';
        if (!$gameTitle || !$sysShort) continue;

        // Find game
        $gst = $pdo->prepare("
            SELECT g.id FROM games g
            JOIN systems s ON s.id = g.system_id
            WHERE g.title = ? AND s.short_name = ? AND g.active = 1
            LIMIT 1
        ");
        $gst->execute([$gameTitle, $sysShort]);
        $game = $gst->fetch();
        if (!$game) continue;

        $gameId  = $game['id'];
        $copyNum = max(1, (int)($entry['copy_number'] ?? 1));

        // Upsert entry
        $pdo->prepare("
            INSERT INTO collection_entries
                (user_id, game_id, copy_number, owned, quality, completeness,
                 price_paid, chart_price, price_min, price_max,
                 upgrade, upgrade_reason, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                owned=VALUES(owned), quality=VALUES(quality),
                completeness=VALUES(completeness), price_paid=VALUES(price_paid),
                chart_price=VALUES(chart_price), price_min=VALUES(price_min),
                price_max=VALUES(price_max), upgrade=VALUES(upgrade),
                upgrade_reason=VALUES(upgrade_reason), notes=VALUES(notes),
                updated_at=NOW()
        ")->execute([
            $user['id'], $gameId, $copyNum,
            $entry['owned'] ? 1 : 0,
            $entry['quality']      ?? '',
            $entry['completeness'] ?? '',
            $entry['price_paid']   ?? null,
            $entry['chart_price']  ?? null,
            $entry['price_min']    ?? null,
            $entry['price_max']    ?? null,
            $entry['upgrade'] ? 1 : 0,
            $entry['upgrade_reason'] ?? null,
            $entry['notes']          ?? null,
        ]);

        // Get entry id
        $eid = $pdo->prepare("SELECT id FROM collection_entries WHERE user_id=? AND game_id=? AND copy_number=?");
        $eid->execute([$user['id'], $gameId, $copyNum]);
        $entryId = $eid->fetchColumn();

        // Restore photos
        if (!empty($entry['photos']) && $entryId) {
            $userDir = UPLOAD_DIR . $user['id'] . '/';
            if (!is_dir($userDir)) mkdir($userDir, 0755, true);

            foreach ($entry['photos'] as $i => $photo) {
                if (empty($photo['data'])) continue;
                $ext      = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$photo['mime']] ?? 'jpg';
                $filename = $user['id'].'_'.$entryId.'_import_'.uniqid().'.'.$ext;
                $fullPath = $userDir.$filename;
                file_put_contents($fullPath, base64_decode($photo['data']));

                $chk = $pdo->prepare("SELECT id FROM copy_photos WHERE entry_id=? AND filename=?");
                $chk->execute([$entryId, $user['id'].'/'.$filename]);
                if (!$chk->fetch()) {
                    $pdo->prepare("INSERT INTO copy_photos (entry_id, user_id, filename, sort_order) VALUES (?,?,?,?)")
                        ->execute([$entryId, $user['id'], $user['id'].'/'.$filename, $i]);
                }
            }
        }
    }

    $pdo->commit();
    jsonOut(['ok'=>true]);

} catch (Exception $e) {
    $pdo->rollBack();
    jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500);
}
