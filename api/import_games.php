<?php
require_once __DIR__ . '/../boot.php';
$admin = requireAdmin();

$body     = json_decode(file_get_contents('php://input'), true);
$systemId = (int)($body['system_id'] ?? 0);
$titles   = $body['titles'] ?? [];

if (!$systemId || empty($titles)) jsonOut(['ok'=>false,'error'=>tRaw('api.missing_titles')], 400);

$chk = db()->prepare("SELECT id FROM systems WHERE id=? AND active=1");
$chk->execute([$systemId]);
if (!$chk->fetch()) jsonOut(['ok'=>false,'error'=>tRaw('api.system_not_found')], 404);

// Fetch all existing titles (lowercase) for duplicate check
$st = db()->prepare("SELECT LOWER(title) AS t FROM games WHERE system_id=?");
$st->execute([$systemId]);
$existing = array_flip($st->fetchAll(PDO::FETCH_COLUMN));

$ins = db()->prepare("INSERT INTO games (system_id, title, sort_title, sort_order) VALUES (?,?,?,0)");

$imported = 0;
$skipped  = 0;

foreach ($titles as $title) {
    $title = trim($title);
    if (!$title) continue;
    if (isset($existing[strtolower($title)])) { $skipped++; continue; }

    $sortTitle = preg_replace('/^(the |a |an )/i', '', $title);
    $sortTitle = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/i', ' ', $sortTitle)));

    $ins->execute([$systemId, $title, $sortTitle]);
    $existing[strtolower($title)] = true;
    $imported++;
}

// After all inserts, recalculate sort_order for all games in this system alphabetically
if ($imported > 0) {
    $allGames = db()->prepare("SELECT id, sort_title FROM games WHERE system_id=? ORDER BY sort_title ASC");
    $allGames->execute([$systemId]);
    $rows = $allGames->fetchAll();
    $upd  = db()->prepare("UPDATE games SET sort_order=? WHERE id=?");
    foreach ($rows as $i => $row) {
        $upd->execute([$i + 1, $row['id']]);
    }
}

if ($imported) {
    $sn = db()->prepare("SELECT short_name FROM systems WHERE id=?");
    $sn->execute([$systemId]);
    logEvent('catalogue', 'games.import', ['admin' => true, 'actor' => $admin, 'params' => ['n' => $imported, 'system' => (string)$sn->fetchColumn()],
        'changes' => [activityChange('new_games', null, (string)$imported), activityChange('skipped', null, (string)$skipped)]]);
}

// New games are not looked up on IGDB here: Settings › IGDB (and the dashboard) shows they're waiting for Match
jsonOut(['ok'=>true, 'imported'=>$imported, 'skipped'=>$skipped]);
