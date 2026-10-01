<?php
// Label templates (Settings › Label templates) and sticker sizes (Admin › Label sizes).
// POST JSON {action, …}; CSRF is checked for every /api/* POST in core.php.
//   list · save · delete · set_default · import · add_size          — every user (own templates / own sizes)
//   size_save · size_delete                                          — admin (site-wide sizes)
require_once __DIR__ . '/../boot.php';
$user = requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok'=>false,'error'=>tRaw('lbl.err_action')], 405);

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = (string)($body['action'] ?? '');
$pdo     = db();
$uid     = (int)$user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';

/** Templates, sizes and the default, after a change (the editor redraws from this). */
function listOut(array $user, array $extra = []): never {
    $u = db()->prepare("SELECT * FROM users WHERE id=?");
    $u->execute([(int)$user['id']]);
    $fresh = $u->fetch() ?: $user;
    $templates = labelTemplatesFor($fresh);
    jsonOut(['ok'=>true, 'templates'=>$templates, 'sizes'=>labelSizesFor((int)$user['id']),
             'default_id'=>labelDefaultTemplateId($fresh, $templates)] + $extra);
}

function fail(string $key, array $vars = [], int $code = 400): never {
    jsonOut(['ok'=>false,'error'=>tRaw($key, $vars)], $code);
}

/** Name 1–100 chars and dimensions 15–300 mm, one decimal. */
function cleanSize(array $b): array {
    $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 100);
    $w = round((float)($b['width_mm'] ?? 0), 1);
    $h = round((float)($b['height_mm'] ?? 0), 1);
    if ($name === '') fail('lbl.err_size_name');
    if ($w < 15 || $w > 300 || $h < 15 || $h > 300) fail('lbl.err_size_range');
    return [$name, $w, $h];
}

/** A size the user may use: a site size or one of their own. */
function usableSize(int $sizeId, int $uid): bool {
    $st = db()->prepare("SELECT 1 FROM label_sizes WHERE id=? AND (user_id IS NULL OR user_id=?)");
    $st->execute([$sizeId, $uid]);
    return (bool)$st->fetchColumn();
}

/** A template row the user may change: their own, or a site template for an admin. */
function editableTemplate(int $id, int $uid, bool $isAdmin): array {
    $st = db()->prepare("SELECT * FROM label_templates WHERE id=?");
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t || !(($t['user_id'] !== null && (int)$t['user_id'] === $uid) || ($t['user_id'] === null && $isAdmin))) fail('lbl.err_template', [], 403);
    return $t;
}

/** Validated template fields from the editor or an import file. */
function cleanTemplate(array $t): array {
    $name = mb_substr(trim((string)($t['name'] ?? '')), 0, 100);
    if ($name === '') fail('lbl.err_name');
    return [
        'name'        => $name,
        'orientation' => ($t['orientation'] ?? '') === 'portrait' ? 'portrait' : 'landscape',
        'layout'      => ($t['layout'] ?? '') === 'stacked' ? 'stacked' : 'horizontal',
        'fields'      => json_encode(labelNormaliseFields($t['fields'] ?? [])),
        'colour'      => !empty($t['colour']) ? 1 : 0,
        'cut_line'    => !empty($t['cut_line']) ? 1 : 0,
    ];
}

switch ($action) {

case 'list':
    listOut($user);

// ── Create or update a template ──
case 'save':
    $t  = is_array($body['template'] ?? null) ? $body['template'] : [];
    $f  = cleanTemplate($t);
    $sizeId = (int)($t['size_id'] ?? 0);
    if (!usableSize($sizeId, $uid)) fail('lbl.err_size');
    $id = (int)($t['id'] ?? 0);
    if ($id) {
        $row = editableTemplate($id, $uid, $isAdmin);
        $shared = $row['user_id'] !== null && !empty($t['shared']) ? 1 : 0;   // site templates are for everyone anyway
        $pdo->prepare("UPDATE label_templates SET name=?, size_id=?, orientation=?, layout=?, fields=?, colour=?, cut_line=?, shared=? WHERE id=?")
            ->execute([$f['name'], $sizeId, $f['orientation'], $f['layout'], $f['fields'], $f['colour'], $f['cut_line'], $shared, $id]);
    } else {
        $pdo->prepare("INSERT INTO label_templates (user_id, name, size_id, orientation, layout, fields, colour, cut_line, shared) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$uid, $f['name'], $sizeId, $f['orientation'], $f['layout'], $f['fields'], $f['colour'], $f['cut_line'], !empty($t['shared']) ? 1 : 0]);
        $id = (int)$pdo->lastInsertId();
    }
    listOut($user, ['id' => $id]);

case 'delete':
    $row = editableTemplate((int)($body['id'] ?? 0), $uid, $isAdmin);
    if ($row['user_id'] === null) {
        // The site always keeps one template: it is the default for everyone without their own
        $n = (int)$pdo->query("SELECT COUNT(*) FROM label_templates WHERE user_id IS NULL")->fetchColumn();
        if ($n <= 1) fail('lbl.err_last_site');
    }
    $pdo->prepare("DELETE FROM label_templates WHERE id=?")->execute([(int)$row['id']]);
    listOut($user);

// ── The user's default (null = the site default) ──
case 'set_default':
    $id = (int)($body['id'] ?? 0);
    if ($id && !in_array($id, array_column(labelTemplatesFor($user), 'id'), true)) fail('lbl.err_template', [], 403);
    $pdo->prepare("UPDATE users SET label_template_id=? WHERE id=?")->execute([$id ?: null, $uid]);
    listOut($user);

// ── Import a template file (as the user's own template) ──
// The size is matched on its dimensions (site sizes first, then the user's); otherwise it becomes a size of their own.
case 'import':
    $d = is_array($body['data'] ?? null) ? $body['data'] : [];
    if (($d['type'] ?? '') !== 'label_template') fail('lbl.err_import');
    $f = cleanTemplate($d);
    [$sName, $w, $h] = cleanSize(is_array($d['size'] ?? null) ? $d['size'] : []);
    $st = $pdo->prepare("SELECT id FROM label_sizes WHERE (user_id IS NULL OR user_id=?)
                         AND ((width_mm=? AND height_mm=?) OR (width_mm=? AND height_mm=?))
                         ORDER BY (user_id IS NOT NULL), id LIMIT 1");
    $st->execute([$uid, $w, $h, $h, $w]);
    $sizeId = (int)$st->fetchColumn();
    if (!$sizeId) {
        $pdo->prepare("INSERT INTO label_sizes (user_id, name, width_mm, height_mm) VALUES (?,?,?,?)")->execute([$uid, $sName, $w, $h]);
        $sizeId = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO label_templates (user_id, name, size_id, orientation, layout, fields, colour, cut_line, shared) VALUES (?,?,?,?,?,?,?,?,0)")
        ->execute([$uid, $f['name'], $sizeId, $f['orientation'], $f['layout'], $f['fields'], $f['colour'], $f['cut_line']]);
    listOut($user, ['id' => (int)$pdo->lastInsertId()]);

// ── A sticker size of the user's own ──
case 'add_size':
    [$name, $w, $h] = cleanSize($body);
    $pdo->prepare("INSERT INTO label_sizes (user_id, name, width_mm, height_mm) VALUES (?,?,?,?)")->execute([$uid, $name, $w, $h]);
    listOut($user, ['size_id' => (int)$pdo->lastInsertId()]);

// ── Admin: site-wide sizes ──
case 'size_save':
    if (!$isAdmin) fail('gapi.admins_only', [], 403);
    [$name, $w, $h] = cleanSize($body);
    $id = (int)($body['id'] ?? 0);
    if ($id) {
        $st = $pdo->prepare("UPDATE label_sizes SET name=?, width_mm=?, height_mm=? WHERE id=? AND user_id IS NULL");
        $st->execute([$name, $w, $h, $id]);
    } else {
        $so = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM label_sizes WHERE user_id IS NULL")->fetchColumn();
        $pdo->prepare("INSERT INTO label_sizes (user_id, name, width_mm, height_mm, sort_order) VALUES (NULL,?,?,?,?)")->execute([$name, $w, $h, $so]);
        $id = (int)$pdo->lastInsertId();
    }
    jsonOut(['ok'=>true, 'id'=>$id]);

// Templates that use the size move to the replacement (required when any template uses it)
case 'size_delete':
    if (!$isAdmin) fail('gapi.admins_only', [], 403);
    $id = (int)($body['id'] ?? 0);
    $replace = (int)($body['replace_id'] ?? 0);
    $st = $pdo->prepare("SELECT COUNT(*) FROM label_templates WHERE size_id=?");
    $st->execute([$id]);
    $used = (int)$st->fetchColumn();
    $sites = (int)$pdo->query("SELECT COUNT(*) FROM label_sizes WHERE user_id IS NULL")->fetchColumn();
    if ($sites <= 1) fail('lbl.err_last_size');
    if ($used) {
        $chk = $pdo->prepare("SELECT 1 FROM label_sizes WHERE id=? AND user_id IS NULL AND id<>?");
        $chk->execute([$replace, $id]);
        if (!$chk->fetchColumn()) fail('lbl.err_replace');
    }
    $pdo->beginTransaction();
    try {
        if ($used) $pdo->prepare("UPDATE label_templates SET size_id=? WHERE size_id=?")->execute([$replace, $id]);
        $pdo->prepare("DELETE FROM label_sizes WHERE id=? AND user_id IS NULL")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        jsonOut(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
    jsonOut(['ok'=>true]);

default:
    fail('lbl.err_action');
}
