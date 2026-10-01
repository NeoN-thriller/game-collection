<?php
// Condition report sharing for one of the user's own copies (drawer › "Share condition report").
// POST JSON {action, entry_id | share_id, …}; CSRF is checked for every /api/* POST in core.php.
require_once __DIR__ . '/../boot.php';
$user = requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok'=>false,'error'=>tRaw('cr.err_action')], 405);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string)($body['action'] ?? '');
$pdo    = db();
$uid    = (int)$user['id'];

/** The user's own copy, or an error. */
function ownEntry(int $entryId, int $uid): array {
    $st = db()->prepare("SELECT id, owned FROM collection_entries WHERE id=? AND user_id=?");
    $st->execute([$entryId, $uid]);
    $e = $st->fetch();
    if (!$e) jsonOut(['ok'=>false,'error'=>tRaw('cr.err_entry')], 404);
    return $e;
}

/** The user's own active share, or an error. */
function ownShare(int $shareId, int $uid): array {
    $st = db()->prepare("SELECT * FROM copy_shares WHERE id=? AND user_id=? AND active=1");
    $st->execute([$shareId, $uid]);
    $s = $st->fetch();
    if (!$s) jsonOut(['ok'=>false,'error'=>tRaw('cr.err_share')], 404);
    return $s;
}

/** What the drawer shows for a share. */
function shareOut(?array $s): ?array {
    if (!$s) return null;
    return [
        'id'        => (int)$s['id'],
        'url'       => shareUrl($s['token']),
        'report_id' => shareReportId($s['token']),
        'mode'      => $s['mode'],
        'for_sale'  => (bool)$s['for_sale'],
        'graded_at' => $s['graded_at'],
        'graded_fmt'=> $s['graded_at'] ? fmtDate($s['graded_at']) : '',
    ];
}

function reloadShare(int $id): array {
    $st = db()->prepare("SELECT * FROM copy_shares WHERE id=?");
    $st->execute([$id]);
    return $st->fetch();
}

switch ($action) {

// ── The copy's active share + the label templates for "Print label" ──
case 'get':
    ownEntry((int)($body['entry_id'] ?? 0), $uid);
    $st = $pdo->prepare("SELECT * FROM copy_shares WHERE entry_id=? AND user_id=? AND active=1 ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$body['entry_id'], $uid]);
    $templates = labelTemplatesFor($user);
    jsonOut(['ok'=>true, 'share'=>shareOut($st->fetch() ?: null),
             'templates'=>array_map(fn($t) => ['id' => $t['id'], 'name' => $t['name']], $templates),
             'default_template_id'=>labelDefaultTemplateId($user, $templates)]);

// ── New share: token + snapshot of the current grading ──
case 'create':
    $e = ownEntry((int)($body['entry_id'] ?? 0), $uid);
    if (!$e['owned']) jsonOut(['ok'=>false,'error'=>tRaw('cr.err_not_owned')], 400);
    $st = $pdo->prepare("SELECT * FROM copy_shares WHERE entry_id=? AND user_id=? AND active=1 LIMIT 1");
    $st->execute([(int)$e['id'], $uid]);
    if ($existing = $st->fetch()) jsonOut(['ok'=>true, 'share'=>shareOut($existing)]);
    $report = conditionReport((int)$e['id']);
    $pdo->prepare("INSERT INTO copy_shares (entry_id, user_id, token, mode, snapshot, for_sale, graded_at) VALUES (?,?,?,'snapshot',?,0,NOW())")
        ->execute([(int)$e['id'], $uid, shareNewToken(), json_encode($report, JSON_UNESCAPED_UNICODE)]);
    jsonOut(['ok'=>true, 'share'=>shareOut(reloadShare((int)$pdo->lastInsertId()))]);

// ── Rewrite the snapshot (same token, same QR) ──
case 'update_snapshot':
    $s = ownShare((int)($body['share_id'] ?? 0), $uid);
    $report = conditionReport((int)$s['entry_id']);
    $pdo->prepare("UPDATE copy_shares SET snapshot=?, graded_at=NOW() WHERE id=?")
        ->execute([json_encode($report, JSON_UNESCAPED_UNICODE), (int)$s['id']]);
    jsonOut(['ok'=>true, 'share'=>shareOut(reloadShare((int)$s['id']))]);

case 'set_mode':
    $s = ownShare((int)($body['share_id'] ?? 0), $uid);
    $mode = ($body['mode'] ?? '') === 'live' ? 'live' : 'snapshot';
    $pdo->prepare("UPDATE copy_shares SET mode=? WHERE id=?")->execute([$mode, (int)$s['id']]);
    jsonOut(['ok'=>true, 'share'=>shareOut(reloadShare((int)$s['id']))]);

case 'set_for_sale':
    $s = ownShare((int)($body['share_id'] ?? 0), $uid);
    $pdo->prepare("UPDATE copy_shares SET for_sale=? WHERE id=?")->execute([!empty($body['for_sale']) ? 1 : 0, (int)$s['id']]);
    jsonOut(['ok'=>true, 'share'=>shareOut(reloadShare((int)$s['id']))]);

// ── New link: a new token (and row); the old link, its photos and its QR stickers stop working ──
case 'regenerate':
    $s = ownShare((int)($body['share_id'] ?? 0), $uid);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE copy_shares SET active=0, revoked_at=NOW() WHERE id=?")->execute([(int)$s['id']]);
        $pdo->prepare("INSERT INTO copy_shares (entry_id, user_id, token, mode, snapshot, for_sale, graded_at) VALUES (?,?,?,?,?,?,?)")
            ->execute([(int)$s['entry_id'], $uid, shareNewToken(), $s['mode'], $s['snapshot'], (int)$s['for_sale'], $s['graded_at']]);
        $newId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
    jsonOut(['ok'=>true, 'share'=>shareOut(reloadShare($newId))]);

// ── Stop sharing ──
case 'revoke':
    $s = ownShare((int)($body['share_id'] ?? 0), $uid);
    $pdo->prepare("UPDATE copy_shares SET active=0, revoked_at=NOW() WHERE id=?")->execute([(int)$s['id']]);
    jsonOut(['ok'=>true, 'share'=>null]);

default:
    jsonOut(['ok'=>false,'error'=>tRaw('cr.err_action')], 400);
}
