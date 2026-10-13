<?php
// Photo tags of one of the user's own copies (see photo_tags.php). The drawer saves tags together with
// the copy (api/entry_save.php → photo_tags); these actions are for direct use.
// POST JSON {action, entry_id, …}; CSRF is checked for every /api/* POST in core.php.
//   set               {photo_id, part_ref, unit_no, defects:[ids]}   ('' part_ref = general / overview)
//   clear             {photo_id}
//   set_defect_photos {part_ref, unit_no, defect_id, photo_ids:[ids]}
// Every answer has the copy's full (valid) photo_tags map.
require_once __DIR__ . '/../boot.php';
$user = requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok'=>false,'error'=>tRaw('pt.err_invalid')], 405);

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = (string)($body['action'] ?? '');
$entryId = (int)($body['entry_id'] ?? 0);
$uid     = (int)$user['id'];

$st = db()->prepare("SELECT id FROM collection_entries WHERE id=? AND user_id=?");
$st->execute([$entryId, $uid]);
if (!$st->fetchColumn()) jsonOut(['ok'=>false,'error'=>tRaw('api.not_found')], 404);

$pdo = db();
$pdo->beginTransaction();
try {
    switch ($action) {
        case 'set':
            photoTagSet($entryId, $uid, (int)($body['photo_id'] ?? 0), [
                'part_ref' => (string)($body['part_ref'] ?? ''), 'unit_no' => (int)($body['unit_no'] ?? 1),
                'defects'  => is_array($body['defects'] ?? null) ? $body['defects'] : [],
            ]);
            break;
        case 'clear':
            photoTagSet($entryId, $uid, (int)($body['photo_id'] ?? 0), null);
            break;
        case 'set_defect_photos':
            photoTagsSetDefectPhotos($entryId, $uid, (string)($body['part_ref'] ?? ''), (int)($body['unit_no'] ?? 0),
                                     (int)($body['defect_id'] ?? 0), is_array($body['photo_ids'] ?? null) ? $body['photo_ids'] : []);
            break;
        default:
            throw new DomainException(tRaw('pt.err_invalid'));
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonOut(['ok'=>false,'error'=>$e->getMessage()], $e instanceof DomainException ? 400 : 500);
}
jsonOut(['ok'=>true, 'photo_tags'=>(object)(photoTagsForEntries([$entryId])[$entryId] ?? [])]);
