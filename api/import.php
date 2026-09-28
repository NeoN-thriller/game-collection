<?php
require_once __DIR__ . '/../config.php';
$user = requireAuth();

$body = json_decode(file_get_contents('php://input'), true);
$data = $body['data'] ?? null;
if (!$data || empty($data['entries'])) jsonOut(['ok'=>false,'error'=>tRaw('api.invalid_import')], 400);

$cfg = gradingConfig(); // loads (and on first run seeds) grading data before the transaction starts
$labelIdByName = [];
foreach ($cfg['labels'] as $l) $labelIdByName[mb_strtolower($l['name'])] = $l['id'];
$profileIdByName = [];
foreach ($cfg['profiles'] as $p) $profileIdByName[mb_strtolower($p['name'])] = $p['id'];

$pdo = db();
$pdo->beginTransaction();

try {
    // Restore option lists if present
    foreach (['completeness_options' => 'user_completeness_options',
              'played_options'       => 'user_played_options',
              'tag_options'          => 'user_tag_options'] as $key => $table) {
        if (empty($data[$key])) continue;
        $pdo->prepare("DELETE FROM $table WHERE user_id=?")->execute([$user['id']]);
        $ins = $pdo->prepare("INSERT INTO $table (user_id, label, sort_order) VALUES (?,?,?)");
        foreach ($data[$key] as $i => $opt) {
            $label = trim((string)($opt['label'] ?? ''));
            if ($label !== '') $ins->execute([$user['id'], mb_substr($label, 0, 100), (int)($opt['sort_order'] ?? $i)]);
        }
    }
    if (!empty($data['grading']['mode']) && in_array($data['grading']['mode'], ['simple','points','both'], true)) {
        $def = in_array($data['grading']['default'] ?? '', ['simple','points'], true) ? $data['grading']['default'] : 'simple';
        $pdo->prepare("UPDATE users SET grading_mode=?, grading_default=? WHERE id=?")->execute([$data['grading']['mode'], $def, $user['id']]);
    }

    $gst = $pdo->prepare("
        SELECT g.id FROM games g
        JOIN systems s ON s.id = g.system_id
        WHERE g.title = ? AND s.short_name = ? AND g.active = 1
        LIMIT 1
    ");
    $find = $pdo->prepare("SELECT id FROM collection_entries WHERE user_id=? AND game_id=? AND copy_number=?");
    $money = fn($v) => ($v !== null && $v !== '') ? (float)$v : null;

    foreach ($data['entries'] as $entry) {
        $gameTitle = $entry['game_title'] ?? '';
        $sysShort  = $entry['system']     ?? '';
        if (!$gameTitle || !$sysShort) continue;

        $gst->execute([$gameTitle, $sysShort]);
        $gameId = $gst->fetchColumn();
        if (!$gameId) continue;
        $copyNum = max(1, (int)($entry['copy_number'] ?? 1));

        // Only fields present in the file are written (older exports lack some of them)
        $fields = [];
        foreach (['owned','wishlist','upgrade'] as $f)                      if (array_key_exists($f, $entry)) $fields[$f] = $entry[$f] ? 1 : 0;
        foreach (['completeness','played_status'] as $f)                     if (array_key_exists($f, $entry)) $fields[$f] = (string)($entry[$f] ?? '');
        foreach (['price_paid','chart_price','price_min','price_max'] as $f) if (array_key_exists($f, $entry)) $fields[$f] = $money($entry[$f]);
        foreach (['upgrade_reason','notes','tag'] as $f)                     if (array_key_exists($f, $entry)) $fields[$f] = $entry[$f] === null ? null : (string)$entry[$f];
        if (array_key_exists('value_price_type', $entry)) {
            $fields['value_price_type'] = in_array($entry['value_price_type'], ['loose','cib','new'], true) ? $entry['value_price_type'] : 'cib';
        }

        $find->execute([$user['id'], $gameId, $copyNum]);
        $entryId = $find->fetchColumn();
        if (!$entryId) {
            $cols = array_merge(['user_id','game_id','copy_number'], array_keys($fields));
            $pdo->prepare("INSERT INTO collection_entries (".implode(',', $cols).") VALUES (".implode(',', array_fill(0, count($cols), '?')).")")
                ->execute(array_merge([$user['id'], $gameId, $copyNum], array_values($fields)));
            $entryId = (int)$pdo->lastInsertId();
        } elseif ($fields) {
            $set = implode(', ', array_map(fn($c) => "$c=?", array_keys($fields)));
            $pdo->prepare("UPDATE collection_entries SET $set, updated_at=NOW() WHERE id=?")->execute([...array_values($fields), $entryId]);
        }

        // Condition grading (by name). Older exports only have 'quality' (a label name).
        $grading = [];
        if (array_key_exists('grade_label', $entry) || array_key_exists('grade_method', $entry)) {
            $grading['method']   = $entry['grade_method'] ?? null;
            $grading['label_id'] = $labelIdByName[mb_strtolower((string)($entry['grade_label'] ?? ''))] ?? null;
        } elseif (!empty($entry['quality']) && isset($labelIdByName[mb_strtolower($entry['quality'])])) {
            $grading['method']   = 'simple';
            $grading['label_id'] = $labelIdByName[mb_strtolower($entry['quality'])];
        }
        if (isset($entry['grade_parts']) && is_array($entry['grade_parts'])) {
            $pid = $profileIdByName[mb_strtolower((string)($entry['grade_profile'] ?? ''))] ?? null;
            $grading['profile_id'] = $pid;
            $grading['parts']      = importEntryGrading($entry['grade_parts'], $pid);
        }
        if ($grading) saveEntryGrading((int)$entryId, (int)$user['id'], $grading);

        // Restore photos (older exports only)
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
