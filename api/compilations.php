<?php
// Admin: compilations (games that contain other games of the same system). See compilations.php.
// POST JSON {action, …}; CSRF is checked for every /api/* POST in core.php.
require_once __DIR__ . '/../boot.php';
$admin = requireAuth();
if ($admin['role'] !== 'admin') jsonOut(['ok'=>false,'error'=>tRaw('gapi.admins_only')], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok'=>false,'error'=>tRaw('comp.err_action')], 405);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string)($body['action'] ?? '');
$pdo    = db();

/** A game row or a 404. */
function compGame(int $id): array {
    $st = db()->prepare("SELECT id, system_id, title FROM games WHERE id=?");
    $st->execute([$id]);
    $g = $st->fetch();
    if (!$g) jsonOut(['ok'=>false,'error'=>tRaw('api.invalid_game')], 404);
    return $g;
}

switch ($action) {

// ── Suggestions for one system (system_id) or all (0 / missing) ──
case 'suggest':
    $sid = (int)($body['system_id'] ?? 0);
    jsonOut(['ok'=>true, 'suggestions'=>compilationSuggestions($sid ?: null)]);

// ── The editor: a game, what it contains, and the games of its system it can contain ──
case 'get':
    $g = compGame((int)($body['game_id'] ?? 0));
    $st = $pdo->prepare("SELECT ci.game_id FROM compilation_items ci WHERE ci.compilation_id=? ORDER BY ci.sort_order");
    $st->execute([(int)$g['id']]);
    $items = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $st = $pdo->prepare("SELECT id, title FROM games WHERE system_id=? AND active=1 AND id<>? ORDER BY sort_title, id");
    $st->execute([(int)$g['system_id'], (int)$g['id']]);
    jsonOut(['ok'=>true, 'game'=>['id'=>(int)$g['id'], 'title'=>$g['title']], 'items'=>$items,
             'games'=>array_map(fn($r) => ['id'=>(int)$r['id'], 'title'=>$r['title']], $st->fetchAll())]);

// ── Set what a compilation contains ({game_id, items:[ids]}); an empty list makes it a normal game ──
case 'save':
    $pdo->beginTransaction();
    try {
        $out = compilationSave((int)($body['game_id'] ?? 0), is_array($body['items'] ?? null) ? $body['items'] : []);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonOut(['ok'=>false,'error'=>$e->getMessage()], $e instanceof DomainException ? 400 : 500);
    }
    jsonOut(['ok'=>true, 'compilation'=>$out]);

// ── "Not a compilation": no more suggestions for this game (undismiss undoes it) ──
case 'dismiss':
case 'undismiss':
    $g = compGame((int)($body['game_id'] ?? 0));
    $pdo->prepare("UPDATE games SET comp_dismissed=? WHERE id=?")->execute([$action === 'dismiss' ? 1 : 0, (int)$g['id']]);
    jsonOut(['ok'=>true]);

default:
    jsonOut(['ok'=>false,'error'=>tRaw('comp.err_action')], 400);
}
