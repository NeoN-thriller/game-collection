<?php
// Admin: grade labels, format profiles, component templates, and export / import of the grading system.
require_once __DIR__ . '/../boot.php';
$admin = requireAuth();
if ($admin['role'] !== 'admin') jsonOut(['ok'=>false,'error'=>tRaw('gapi.admins_only')], 403);

// ── Export (file download) ───────────────
if (($_GET['action'] ?? '') === 'export') {
    $json = json_encode(exportGradingConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    session_write_close();
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="grading_system_'.date('Ymd').'.json"');
    header('Content-Length: '.strlen($json));
    header('Cache-Control: no-cache, no-store');
    echo $json;
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? ($_GET['action'] ?? 'config');
$pdo    = db();
gradingConfig(); // seeds on first run, before any transaction

function countOf(string $sql, array $args = []): int {
    $st = db()->prepare($sql); $st->execute($args); return (int)$st->fetchColumn();
}
function inList(array $ids): string { return implode(',', array_map('intval', $ids)) ?: '0'; }

/** Runs $fn in a transaction, then recalculates cached scores and returns the fresh config. */
function commitAndRespond(callable $fn, string $msg): never {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $extra = $fn() ?: [];
        recalcAllScores();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonOut(['ok'=>false,'error'=>$e->getMessage()]);
    }
    jsonOut(['ok'=>true,'msg'=>$msg,'config'=>adminConfig()] + $extra);
}

/** Everything the admin editors need. */
function adminConfig(): array {
    $cfg = gradingConfig(true);
    $systems = db()->query("SELECT id, name, short_name, grade_profile_id FROM systems ORDER BY sort_order, name")->fetchAll();
    $partUse = [];
    foreach (db()->query("SELECT profile_component_id AS id, COUNT(DISTINCT entry_id) AS n FROM entry_parts
                          WHERE profile_component_id IS NOT NULL GROUP BY profile_component_id")->fetchAll() as $r) $partUse[(int)$r['id']] = (int)$r['n'];
    $labelUse = [];
    foreach (db()->query("SELECT grade_label_id AS id, COUNT(*) AS n FROM collection_entries
                          WHERE grade_label_id IS NOT NULL GROUP BY grade_label_id")->fetchAll() as $r) $labelUse[(int)$r['id']] = (int)$r['n'];
    return [
        'labels'     => array_map(fn($l) => $l + ['used' => $labelUse[$l['id']] ?? 0], $cfg['labels']),
        'templates'  => array_values($cfg['templates']),
        'profiles'   => array_values(array_map(fn($p) => array_merge($p, [
            'components' => array_map(fn($c) => $c + ['used' => $partUse[$c['id']] ?? 0], $p['components']),
        ]), $cfg['profiles'])),
        'systems'    => array_map(fn($s) => ['id'=>(int)$s['id'], 'name'=>$s['name'], 'short'=>$s['short_name'],
                                             'profile_id'=>$s['grade_profile_id'] !== null ? (int)$s['grade_profile_id'] : null], $systems),
        'own_weight' => $cfg['own_weight'],
        'graded'     => countOf("SELECT COUNT(DISTINCT entry_id) FROM entry_parts"),
    ];
}

switch ($action) {

case 'config':
    jsonOut(['ok'=>true,'config'=>adminConfig()]);

// ── Grade labels ─────────────────────────
case 'save_labels':
    $in = array_values(array_filter((array)($body['labels'] ?? []), 'is_array'));
    $moves = (array)($body['moves'] ?? []); // deleted label id => kept label id, or "n<index>" for a new label
    $err = [];
    $mins = []; $names = [];
    foreach ($in as $i => $l) {
        $name = trim((string)($l['name'] ?? ''));
        $min  = (int)($l['min_score'] ?? -1);
        if ($name === '') $err[] = tRaw('ga.err_name');
        if ($min < 0 || $min > 100) $err[] = tRaw('gapi.label_range', ['name' => $name]);
        if (isset($names[mb_strtolower($name)])) $err[] = tRaw('gapi.label_dup', ['name' => $name]);
        if (isset($mins[$min])) $err[] = tRaw('gapi.label_same_min', ['n' => $min]);
        $names[mb_strtolower($name)] = true; $mins[$min] = true;
    }
    if (!$in) $err[] = tRaw('ga.err_keep_one');
    if ($in && !isset($mins[0])) $err[] = tRaw('ga.err_lowest');
    if ($err) jsonOut(['ok'=>false,'error'=>implode(' ', array_unique($err))]);

    $existing = array_column(gradingConfig()['labels'], null, 'id');
    $keptIds  = array_filter(array_map(fn($l) => (int)($l['id'] ?? 0), $in), fn($id) => isset($existing[$id]));
    $removed  = array_diff(array_keys($existing), $keptIds);
    $needMove = [];
    foreach ($removed as $rid) {
        $n = countOf("SELECT COUNT(*) FROM collection_entries WHERE grade_label_id=?", [$rid]);
        if ($n && !isset($moves[$rid])) $needMove[] = ['id'=>$rid, 'name'=>$existing[$rid]['name'], 'count'=>$n];
    }
    if ($needMove) jsonOut(['ok'=>false,'needs_move'=>$needMove,
        'error'=>tRaw('gapi.needs_move')]);

    commitAndRespond(function () use ($in, $existing, $removed, $moves) {
        $pdo = db();
        $newIds = [];
        foreach ($in as $i => $l) {
            $vals = [trim($l['name']), mb_substr(trim((string)($l['short'] ?? '')), 0, 6), (int)$l['min_score'], gradingColor((string)($l['color'] ?? '')), $i];
            $id = (int)($l['id'] ?? 0);
            if ($id && isset($existing[$id])) {
                $pdo->prepare("UPDATE grade_labels SET name=?, short=?, min_score=?, color=?, sort_order=? WHERE id=?")->execute([...$vals, $id]);
                $newIds[$i] = $id;
            } else {
                $pdo->prepare("INSERT INTO grade_labels (name, short, min_score, color, sort_order) VALUES (?,?,?,?,?)")->execute($vals);
                $newIds[$i] = (int)$pdo->lastInsertId();
            }
        }
        foreach ($removed as $rid) {
            $to = $moves[$rid] ?? null;
            $toId = is_string($to) && str_starts_with($to, 'n') ? ($newIds[(int)substr($to, 1)] ?? null) : (int)$to;
            if ($toId) $pdo->prepare("UPDATE collection_entries SET grade_label_id=? WHERE grade_label_id=?")->execute([$toId, $rid]);
            $pdo->prepare("DELETE FROM grade_labels WHERE id=?")->execute([$rid]);
        }
    }, tRaw('gapi.labels_saved'));

// ── Component templates ──────────────────
case 'save_template':
    $t = (array)($body['template'] ?? []);
    $name = trim((string)($t['name'] ?? ''));
    if ($name === '') jsonOut(['ok'=>false,'error'=>tRaw('gapi.template_name')]);
    $tid = (int)($t['id'] ?? 0);
    $cfg = gradingConfig();
    foreach ($cfg['templates'] as $other) {
        if ($other['id'] !== $tid && mb_strtolower($other['name']) === mb_strtolower($name)) jsonOut(['ok'=>false,'error'=>tRaw('gapi.template_dup', ['name' => $name])]);
    }
    $cats = array_values(array_filter((array)($t['categories'] ?? []), 'is_array'));
    foreach ($cats as $c) {
        if (trim((string)($c['name'] ?? '')) === '') jsonOut(['ok'=>false,'error'=>tRaw('gapi.category_name')]);
        foreach ((array)($c['defects'] ?? []) as $d) if (trim((string)($d['name'] ?? '')) === '') jsonOut(['ok'=>false,'error'=>tRaw('gapi.defect_name', ['category' => $c['name']])]);
    }

    // What disappears, and how much logged data goes with it
    $old = $tid ? ($cfg['templates'][$tid] ?? null) : null;
    if ($tid && !$old) jsonOut(['ok'=>false,'error'=>tRaw('gapi.template_missing')]);
    $keepCats = []; $keepDefs = [];
    foreach ($cats as $c) {
        if (!empty($c['id'])) $keepCats[(int)$c['id']] = true;
        foreach ((array)($c['defects'] ?? []) as $d) if (!empty($d['id'])) $keepDefs[(int)$d['id']] = true;
    }
    $goneDefs = [];
    if ($old) foreach ($old['categories'] as $c) foreach ($c['defects'] as $d) {
        if (!isset($keepDefs[$d['id']]) || !isset($keepCats[$c['id']])) $goneDefs[] = $d['id'];
    }
    $lost = $goneDefs ? countOf("SELECT COUNT(*) FROM entry_defects WHERE defect_id IN (".inList($goneDefs).")") : 0;
    if ($lost && empty($body['confirm'])) {
        jsonOut(['ok'=>false,'needs_confirm'=>true,'error'=>tRaw('gapi.confirm_defects_lost', ['n' => $lost])]);
    }

    commitAndRespond(function () use ($tid, $name, $cats, $old, $keepCats, $keepDefs) {
        $pdo = db();
        if ($tid) $pdo->prepare("UPDATE grade_templates SET name=? WHERE id=?")->execute([$name, $tid]);
        else {
            $so = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM grade_templates")->fetchColumn();
            $pdo->prepare("INSERT INTO grade_templates (name, sort_order) VALUES (?,?)")->execute([$name, $so]);
            $tid = (int)$pdo->lastInsertId();
        }
        if ($old) {
            foreach ($old['categories'] as $c) {
                if (!isset($keepCats[$c['id']])) { $pdo->prepare("DELETE FROM grade_categories WHERE id=?")->execute([$c['id']]); continue; }
                foreach ($c['defects'] as $d) if (!isset($keepDefs[$d['id']])) $pdo->prepare("DELETE FROM grade_defects WHERE id=?")->execute([$d['id']]);
            }
        }
        $oldCatIds = $old ? array_column($old['categories'], 'id') : [];
        foreach ($cats as $ci => $c) {
            $cid = (int)($c['id'] ?? 0);
            if ($cid && in_array($cid, $oldCatIds, true)) {
                $pdo->prepare("UPDATE grade_categories SET name=?, max_points=?, sort_order=? WHERE id=?")
                    ->execute([trim($c['name']), max(0, min(100, (int)($c['max_points'] ?? 0))), $ci, $cid]);
                $st = $pdo->prepare("SELECT id FROM grade_defects WHERE category_id=?"); $st->execute([$cid]);
                $oldDefIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                foreach (array_values((array)($c['defects'] ?? [])) as $di => $d) {
                    $did = (int)($d['id'] ?? 0);
                    gradingWriteDefect($cid, $d, $di, in_array($did, $oldDefIds, true) ? $did : null);
                }
            } else {
                gradingInsertCategory($tid, array_merge($c, ['defects' => array_map(fn($d) => array_diff_key($d, ['id'=>1]), (array)($c['defects'] ?? []))]), $ci);
            }
        }
        return ['saved_id' => $tid];
    }, tRaw('gapi.template_saved', ['name' => $name]));

case 'delete_template':
    $tid = (int)($body['id'] ?? 0);
    $st = $pdo->prepare("SELECT DISTINCT p.name FROM grade_profile_components c JOIN grade_profiles p ON p.id=c.profile_id WHERE c.template_id=?");
    $st->execute([$tid]);
    if ($profiles = $st->fetchAll(PDO::FETCH_COLUMN)) {
        jsonOut(['ok'=>false,'error'=>tRaw('gapi.template_in_use', ['profiles' => implode(', ', $profiles)])]);
    }
    $n = countOf("SELECT COUNT(DISTINCT entry_id) FROM entry_parts WHERE template_id=?", [$tid]);
    if ($n && empty($body['confirm'])) {
        jsonOut(['ok'=>false,'needs_confirm'=>true,'error'=>tRaw('gapi.confirm_own_items', ['n' => $n])]);
    }
    commitAndRespond(function () use ($tid) { db()->prepare("DELETE FROM grade_templates WHERE id=?")->execute([$tid]); }, tRaw('gapi.template_deleted'));

// ── Format profiles ──────────────────────
case 'save_profile':
    $p = (array)($body['profile'] ?? []);
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '') jsonOut(['ok'=>false,'error'=>tRaw('gapi.profile_name')]);
    $pid = (int)($p['id'] ?? 0);
    $cfg = gradingConfig();
    foreach ($cfg['profiles'] as $other) {
        if ($other['id'] !== $pid && mb_strtolower($other['name']) === mb_strtolower($name)) jsonOut(['ok'=>false,'error'=>tRaw('gapi.profile_dup', ['name' => $name])]);
    }
    $old = $pid ? ($cfg['profiles'][$pid] ?? null) : null;
    if ($pid && !$old) jsonOut(['ok'=>false,'error'=>tRaw('gapi.profile_missing')]);
    $comps = array_values(array_filter((array)($p['components'] ?? []), 'is_array'));
    foreach ($comps as $c) {
        if (trim((string)($c['label'] ?? '')) === '') jsonOut(['ok'=>false,'error'=>tRaw('gapi.part_name')]);
        if (!isset($cfg['templates'][(int)($c['template_id'] ?? 0)])) jsonOut(['ok'=>false,'error'=>tRaw('gapi.part_template', ['name' => $c['label']])]);
    }
    $keep = []; $tplChanged = [];
    foreach ($comps as $c) if (!empty($c['id'])) $keep[(int)$c['id']] = (int)$c['template_id'];
    $gone = [];
    if ($old) foreach ($old['components'] as $c) {
        if (!isset($keep[$c['id']])) $gone[] = $c['id'];
        elseif ($keep[$c['id']] !== $c['template_id']) $tplChanged[] = $c['id'];
    }
    $lostParts = $gone ? countOf("SELECT COUNT(DISTINCT entry_id) FROM entry_parts WHERE profile_component_id IN (".inList($gone).")") : 0;
    $lostDefs  = $tplChanged ? countOf("SELECT COUNT(*) FROM entry_defects d JOIN entry_part_units u ON u.id=d.unit_id
                                        JOIN entry_parts ep ON ep.id=u.entry_part_id WHERE ep.profile_component_id IN (".inList($tplChanged).")") : 0;
    if (($lostParts || $lostDefs) && empty($body['confirm'])) {
        $msg = [];
        if ($lostParts) $msg[] = tRaw('gapi.lost_parts', ['n' => $lostParts]);
        if ($lostDefs)  $msg[] = tRaw('gapi.lost_defects', ['n' => $lostDefs]);
        jsonOut(['ok'=>false,'needs_confirm'=>true,'error'=>tRaw('gapi.confirm_careful', ['details' => implode('; ', $msg)])]);
    }
    $systems = array_map('intval', (array)($p['systems'] ?? []));

    commitAndRespond(function () use ($pid, $name, $comps, $old, $gone, $tplChanged, $systems) {
        $pdo = db();
        if ($pid) $pdo->prepare("UPDATE grade_profiles SET name=? WHERE id=?")->execute([$name, $pid]);
        else {
            $so = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM grade_profiles")->fetchColumn();
            $pdo->prepare("INSERT INTO grade_profiles (name, sort_order) VALUES (?,?)")->execute([$name, $so]);
            $pid = (int)$pdo->lastInsertId();
        }
        if ($gone) $pdo->exec("DELETE FROM grade_profile_components WHERE id IN (".inList($gone).")");
        if ($tplChanged) $pdo->exec("DELETE d FROM entry_defects d JOIN entry_part_units u ON u.id=d.unit_id
                                     JOIN entry_parts ep ON ep.id=u.entry_part_id WHERE ep.profile_component_id IN (".inList($tplChanged).")");
        $oldIds = $old ? array_column($old['components'], 'id') : [];
        $tplNames = array_map(fn($t) => $t['name'], gradingConfig()['templates']);
        $report = ['warnings' => []];
        foreach ($comps as $ci => $c) {
            $cid = (int)($c['id'] ?? 0);
            gradingWriteComponent($pid, [
                'label' => $c['label'], 'abbr' => $c['abbr'] ?? '', 'template' => $tplNames[(int)$c['template_id']] ?? '',
                'weight' => $c['weight'] ?? 0, 'default_qty' => $c['default_qty'] ?? 1,
            ], $ci, gradingTemplateIdsByName(), in_array($cid, $oldIds, true) ? $cid : null, $report);
        }
        // Default profile for systems: exactly the ticked ones
        $pdo->prepare("UPDATE systems SET grade_profile_id=NULL WHERE grade_profile_id=?")->execute([$pid]);
        if ($systems) $pdo->prepare("UPDATE systems SET grade_profile_id=? WHERE id IN (".inList($systems).")")->execute([$pid]);
        return ['saved_id' => $pid];
    }, tRaw('gapi.profile_saved', ['name' => $name]));

case 'delete_profile':
    $pid = (int)($body['id'] ?? 0);
    $n = countOf("SELECT COUNT(DISTINCT ep.entry_id) FROM entry_parts ep JOIN grade_profile_components c ON c.id=ep.profile_component_id WHERE c.profile_id=?", [$pid]);
    if ($n && empty($body['confirm'])) {
        jsonOut(['ok'=>false,'needs_confirm'=>true,'error'=>tRaw('gapi.confirm_profile_graded', ['n' => $n])]);
    }
    commitAndRespond(function () use ($pid) { db()->prepare("DELETE FROM grade_profiles WHERE id=?")->execute([$pid]); }, tRaw('gapi.profile_deleted'));

case 'save_settings':
    $w = max(1, min(100, (int)($body['own_weight'] ?? 5)));
    setAppSetting('own_item_default_weight', (string)$w);
    jsonOut(['ok'=>true,'msg'=>tRaw('gapi.own_weight', ['n' => $w]),'config'=>adminConfig()]);

case 'auto_assign':
    $assigned = applySystemPatterns(null, true);
    jsonOut(['ok'=>true,'msg'=>$assigned ? tRaw('gapi.assigned', ['list' => implode(', ', $assigned)]) : tRaw('gapi.none_assigned'),'config'=>adminConfig()]);

case 'recalc':
    $n = recalcAllScores();
    jsonOut(['ok'=>true,'msg'=>tRaw('gapi.recalculated', ['n' => $n]),'config'=>adminConfig()]);

// ── Import / reset ───────────────────────
case 'import':
case 'reset_defaults':
    $data = $action === 'reset_defaults' ? gradingDefaults() : (array)($body['data'] ?? []);
    $mode = $action === 'reset_defaults' ? 'replace' : (($body['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge');
    $dry  = !empty($body['dry_run']);
    $report = importGradingConfig($data, $mode, $dry, !empty($body['delete_missing']));
    if (!$report['ok']) jsonOut(['ok'=>false,'error'=>implode(' ', $report['errors']),'report'=>$report]);
    jsonOut(['ok'=>true,'report'=>$report,'config'=>$dry ? null : adminConfig(),
             'msg'=>$dry ? tRaw('gapi.preview_ready') : tRaw('gapi.imported')]);

default:
    jsonOut(['ok'=>false,'error'=>tRaw('common.err_unknown_action')], 400);
}
