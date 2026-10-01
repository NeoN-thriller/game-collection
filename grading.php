<?php
// Condition grading: admin-managed labels, component templates and format profiles,
// per-copy point grading, and the scoring engine. Loaded by core.php.
//
// The scoring formula must stay identical to GradingCore in assets/js/grading.js.
if (!defined('DB_HOST')) { http_response_code(403); exit; }

const GRADING_DEFAULTS_FILE = __DIR__ . '/assets/grading-defaults.json';
const GRADING_FORMAT        = 'game-collection-grading';
const GRADE_MAX_QTY         = 9;
const GRADE_QTY_LCM         = 2520; // divisible by every qty 1–9: keeps the weighted average in whole numbers
const GRADE_DEFECT_KINDS    = ['each', 'once', 'max', 'level'];

// ── App settings (key/value) ─────────────

function appSetting(string $name, ?string $default = null): ?string {
    $st = db()->prepare("SELECT value FROM app_settings WHERE name=?");
    $st->execute([$name]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function setAppSetting(string $name, ?string $value): void {
    db()->prepare("INSERT INTO app_settings (name, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)")
        ->execute([$name, $value]);
}

// ── Seeding ──────────────────────────────

/**
 * First run after the migration: loads assets/grading-defaults.json, assigns
 * default profiles to systems and converts the old quality values to labels.
 */
function ensureGradingSeeded(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        if (appSetting('grading_seeded') !== null) return;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') { // table doesn't exist
            http_response_code(500);
            exit('<p style="font:1rem monospace;padding:40px">'.t('settings.err_migration').'</p>');
        }
        throw $e;
    }
    $pdo = db();
    $pdo->query("SELECT GET_LOCK('gc_grading_seed', 20)")->fetchColumn();
    try {
        if (appSetting('grading_seeded') !== null) return;
        $data = gradingDefaults();
        $res  = importGradingConfig($data, 'replace', false);
        if (!$res['ok']) throw new RuntimeException('Seeding grading defaults failed: '.implode('; ', $res['errors']));
        convertLegacyQuality();
        setAppSetting('grading_seeded', date('c'));
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('gc_grading_seed')")->fetchColumn();
    }
}

function gradingDefaults(): array {
    $data = json_decode((string)@file_get_contents(GRADING_DEFAULTS_FILE), true);
    if (!is_array($data)) throw new RuntimeException('assets/grading-defaults.json is missing or invalid');
    return $data;
}

/** Old installs: quality (Mint/Good/Fair/Poor) → grade_label_id, matched by name. */
function convertLegacyQuality(): void {
    $has = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='collection_entries' AND COLUMN_NAME='quality'")->fetchColumn();
    if (!$has) return;
    $up = db()->prepare("UPDATE collection_entries SET grade_label_id=?, grade_method=COALESCE(grade_method,'simple')
                         WHERE quality=? AND grade_label_id IS NULL");
    foreach (db()->query("SELECT id, name FROM grade_labels")->fetchAll() as $l) {
        $up->execute([$l['id'], $l['name']]);
    }
}

/**
 * Sets a default profile on systems by matching their name (patterns as in grading-defaults.json).
 * Without $patterns the ones from grading-defaults.json are used.
 */
function applySystemPatterns(?array $patterns = null, bool $onlyUnassigned = true, ?int $systemId = null): array {
    $patterns ??= gradingDefaults()['system_patterns'] ?? [];
    $profiles = [];
    foreach (db()->query("SELECT id, name FROM grade_profiles")->fetchAll() as $p) $profiles[mb_strtolower($p['name'])] = (int)$p['id'];
    $sql = "SELECT id, name, short_name, grade_profile_id FROM systems".($systemId ? " WHERE id=".(int)$systemId : "");
    $up  = db()->prepare("UPDATE systems SET grade_profile_id=? WHERE id=?");
    $assigned = [];
    foreach (db()->query($sql)->fetchAll() as $s) {
        if ($onlyUnassigned && $s['grade_profile_id']) continue;
        $hay = ' '.$s['name'].' '.$s['short_name'].' ';
        foreach ($patterns as $p) {
            $pid = $profiles[mb_strtolower($p['profile'] ?? '')] ?? null;
            if (!$pid || !preg_match('~'.str_replace('~', '\~', $p['match']).'~iu', $hay)) continue;
            if (!empty($p['exclude']) && preg_match('~'.str_replace('~', '\~', $p['exclude']).'~iu', $hay)) continue;
            $up->execute([$pid, $s['id']]);
            $assigned[] = $s['short_name'].' → '.$p['profile'];
            break;
        }
    }
    return $assigned;
}

// ── Config (labels, templates, profiles) ─

function gradingConfig(bool $fresh = false): array {
    static $cfg = null;
    if ($cfg !== null && !$fresh) return $cfg;
    ensureGradingSeeded();
    $pdo = db();

    $labels = array_map(fn($l) => [
        'id' => (int)$l['id'], 'name' => $l['name'], 'short' => $l['short'],
        'min_score' => (int)$l['min_score'], 'color' => $l['color'],
    ], $pdo->query("SELECT * FROM grade_labels ORDER BY min_score DESC, id")->fetchAll());

    $templates = [];
    foreach ($pdo->query("SELECT * FROM grade_templates ORDER BY sort_order, id")->fetchAll() as $t) {
        $templates[(int)$t['id']] = ['id' => (int)$t['id'], 'name' => $t['name'], 'categories' => []];
    }
    $cats = [];
    foreach ($pdo->query("SELECT * FROM grade_categories ORDER BY sort_order, id")->fetchAll() as $c) {
        if (!isset($templates[(int)$c['template_id']])) continue;
        $cats[(int)$c['id']] = ['id' => (int)$c['id'], 'template_id' => (int)$c['template_id'], 'name' => $c['name'],
                                'max_points' => (int)$c['max_points'], 'defects' => []];
    }
    foreach ($pdo->query("SELECT * FROM grade_defects ORDER BY sort_order, id")->fetchAll() as $d) {
        if (!isset($cats[(int)$d['category_id']])) continue;
        $cats[(int)$d['category_id']]['defects'][] = [
            'id' => (int)$d['id'], 'name' => $d['name'], 'penalty' => (int)$d['penalty'], 'kind' => $d['kind'],
            'max_count' => $d['max_count'] !== null ? (int)$d['max_count'] : null,
            'level_group' => $d['level_group'] !== null && $d['level_group'] !== '' ? $d['level_group'] : null,
        ];
    }
    foreach ($cats as $c) {
        $tid = $c['template_id']; unset($c['template_id']);
        $templates[$tid]['categories'][] = $c;
    }

    $profiles = [];
    foreach ($pdo->query("SELECT * FROM grade_profiles ORDER BY sort_order, id")->fetchAll() as $p) {
        $profiles[(int)$p['id']] = ['id' => (int)$p['id'], 'name' => $p['name'], 'components' => [], 'systems' => []];
    }
    $components = [];
    foreach ($pdo->query("SELECT * FROM grade_profile_components ORDER BY sort_order, id")->fetchAll() as $c) {
        if (!isset($profiles[(int)$c['profile_id']])) continue;
        $comp = ['id' => (int)$c['id'], 'profile_id' => (int)$c['profile_id'], 'template_id' => (int)$c['template_id'],
                 'label' => $c['label'], 'abbr' => $c['abbr'], 'weight' => (int)$c['weight'], 'default_qty' => (int)$c['default_qty']];
        $components[$comp['id']] = $comp;
        $profiles[$comp['profile_id']]['components'][] = $comp;
    }
    $systemProfiles = [];
    foreach ($pdo->query("SELECT id, grade_profile_id FROM systems")->fetchAll() as $s) {
        if ($s['grade_profile_id'] && isset($profiles[(int)$s['grade_profile_id']])) {
            $systemProfiles[(int)$s['id']] = (int)$s['grade_profile_id'];
            $profiles[(int)$s['grade_profile_id']]['systems'][] = (int)$s['id'];
        }
    }

    return $cfg = [
        'labels'         => $labels,
        'templates'      => $templates,
        'profiles'       => $profiles,
        'components'     => $components,
        'system_profiles'=> $systemProfiles,
        'own_weight'     => max(1, min(100, (int)appSetting('own_item_default_weight', '5'))),
    ];
}

/** What the browser needs: config plus the user's grading preferences. */
function gradingClientConfig(?array $user): array {
    $c = gradingConfig();
    return [
        'labels'          => $c['labels'],
        'templates'       => (object)$c['templates'],
        'profiles'        => array_values($c['profiles']),
        'system_profiles' => (object)$c['system_profiles'],
        'own_weight'      => $c['own_weight'],
        'max_qty'         => GRADE_MAX_QTY,
        'base'            => BASE_URL,
        'mode'            => $user['grading_mode']    ?? 'simple',
        'default'         => $user['grading_default'] ?? 'simple',
    ];
}

function gradingClientJson(?array $user): string {
    return json_encode(gradingClientConfig($user), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function gradeLabelForScore(int $score): ?array {
    foreach (gradingConfig()['labels'] as $l) if ($score >= $l['min_score']) return $l;
    return null;
}

/** A label badge in the label's own colour (same look as GradingUI.badge in grading.js). */
function gradeBadgeHtml(?array $l): string {
    if (!$l) return '<span class="qbadge q-na">—</span>';
    $hex = gradingColor($l['color']);
    [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    return '<span class="qbadge" style="color:'.$hex.';background:rgba('."$r,$g,$b".',.18);border:1px solid rgba('."$r,$g,$b".',.4)">'
         .htmlspecialchars($l['name']).'</span>';
}

/** SQL for a copy's effective label id: derived from the score for point-graded copies, else the simple label. */
function gradeLabelSql(string $a = 'ce'): string {
    return "(CASE WHEN $a.grade_method='points' AND $a.grade_score IS NOT NULL
                THEN (SELECT gl.id FROM grade_labels gl WHERE gl.min_score <= $a.grade_score ORDER BY gl.min_score DESC LIMIT 1)
                ELSE $a.grade_label_id END)";
}

// ── Scoring (mirror of GradingCore in grading.js) ──

/** Score of one unit (0–100) from its defect counts {defect_id: count}. */
function gradeUnitScore(array $tpl, array $counts): int {
    $sumCat = 0; $sumMax = 0;
    foreach ($tpl['categories'] as $cat) {
        $lost = 0; $levels = [];
        foreach ($cat['defects'] as $d) {
            $n = (int)($counts[$d['id']] ?? 0);
            if ($n <= 0) continue;
            switch ($d['kind']) {
                case 'once':  $lost += $d['penalty']; break;
                case 'max':   $lost += $d['penalty'] * min($n, max(1, (int)$d['max_count'])); break;
                case 'level': $g = $d['level_group'] ?? ''; $levels[$g] = max($levels[$g] ?? 0, $d['penalty']); break;
                default:      $lost += $d['penalty'] * $n;
            }
        }
        $lost   += array_sum($levels);
        $sumCat += max(0, $cat['max_points'] - $lost);
        $sumMax += $cat['max_points'];
    }
    if ($sumMax <= 0)   return 100;
    if ($sumMax === 100) return $sumCat;
    return intdiv(2 * $sumCat * 100 + $sumMax, 2 * $sumMax); // rescale to 100, rounded half up
}

/** Weighted average over units: [[weight, qty, score], …]. Each unit weighs weight/qty. Null when nothing counts. */
function gradeOverallScore(array $units): ?int {
    $num = 0; $den = 0;
    foreach ($units as [$w, $qty, $s]) {
        $f = $w * intdiv(GRADE_QTY_LCM, max(1, $qty));
        $num += $f * $s;
        $den += $f;
    }
    return $den > 0 ? intdiv(2 * $num + $den, 2 * $den) : null;
}

/** Weight and template of a part, or null if it no longer resolves (deleted component/template). */
function gradePartSpec(array $p, array $cfg): ?array {
    if (!empty($p['pc'])) {
        $c = $cfg['components'][(int)$p['pc']] ?? null;
        if (!$c || !isset($cfg['templates'][$c['template_id']])) return null;
        return ['weight' => $c['weight'], 'tpl' => $cfg['templates'][$c['template_id']], 'label' => $c['label'], 'abbr' => $c['abbr']];
    }
    $t = $cfg['templates'][(int)($p['tpl'] ?? 0)] ?? null;
    if (!$t) return null;
    return ['weight' => max(0, min(100, (int)($p['w'] ?? $cfg['own_weight']))), 'tpl' => $t,
            'label' => (string)($p['name'] ?? ''), 'abbr' => ''];
}

/**
 * Validates and normalises a copy's parts (from the browser or the DB), and scores them.
 * Returns ['parts' => [...], 'score' => ?int]. Every unit gets 's' (score) and clean 'd' counts.
 */
function gradeScoreParts(array $parts, ?int $profileId = null): array {
    $cfg = gradingConfig();
    $out = []; $units = []; $seenPc = [];
    foreach (array_values($parts) as $p) {
        if (!is_array($p)) continue;
        $pc = !empty($p['pc']) ? (int)$p['pc'] : null;
        if ($pc) {
            if (isset($seenPc[$pc])) continue;
            $comp = $cfg['components'][$pc] ?? null;
            if (!$comp || ($profileId && $comp['profile_id'] !== $profileId)) continue;
            $seenPc[$pc] = true;
            $norm = ['pc' => $pc, 'name' => null, 'tpl' => null, 'w' => null];
        } else {
            $name = trim(mb_substr((string)($p['name'] ?? ''), 0, 100));
            $tpl  = (int)($p['tpl'] ?? 0);
            if ($name === '' || !isset($cfg['templates'][$tpl])) continue;
            $norm = ['pc' => null, 'name' => $name, 'tpl' => $tpl, 'w' => max(1, min(100, (int)($p['w'] ?? $cfg['own_weight'])))];
        }
        $spec = gradePartSpec($norm, $cfg);
        if (!$spec) continue;
        $qty = max(0, min(GRADE_MAX_QTY, (int)($p['qty'] ?? 0)));
        $norm['qty'] = $qty;

        // Defects allowed on this template
        $defs = [];
        foreach ($spec['tpl']['categories'] as $cat) foreach ($cat['defects'] as $d) $defs[$d['id']] = $d;

        $norm['units'] = [];
        $srcUnits = array_values(is_array($p['units'] ?? null) ? $p['units'] : []);
        for ($i = 0; $i < $qty; $i++) {
            $raw = $srcUnits[$i]['d'] ?? [];
            $d = []; $levelPick = [];
            if (is_array($raw)) foreach ($raw as $did => $n) {
                $did = (int)$did; $n = (int)$n;
                if ($n <= 0 || !isset($defs[$did])) continue;
                $def = $defs[$did];
                if ($def['kind'] === 'level') {
                    $g = $def['level_group'] ?? '';
                    if (!isset($levelPick[$g]) || $def['penalty'] > $defs[$levelPick[$g]]['penalty']) $levelPick[$g] = $did;
                    continue;
                }
                $cap = match ($def['kind']) { 'once' => 1, 'max' => max(1, (int)$def['max_count']), default => 99 };
                $d[$did] = min($n, $cap);
            }
            foreach ($levelPick as $did) $d[$did] = 1;
            $s = gradeUnitScore($spec['tpl'], $d);
            $norm['units'][] = ['s' => $s, 'd' => $d];
            $units[] = [$spec['weight'], $qty, $s];
        }
        $out[] = $norm;
    }
    return ['parts' => $out, 'score' => gradeOverallScore($units)];
}

// ── Per-copy load / save ─────────────────

/** Grading parts for many copies: [entry_id => [part, …]] in the same shape the browser sends. */
function loadEntryGrading(array $entryIds): array {
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds))));
    $result = [];
    foreach (array_chunk($entryIds, 500) as $chunk) {
        $in = implode(',', $chunk);
        $parts = [];
        foreach (db()->query("SELECT * FROM entry_parts WHERE entry_id IN ($in) ORDER BY entry_id, sort_order, id")->fetchAll() as $p) {
            $parts[(int)$p['id']] = [
                'id'       => (int)$p['id'],
                'entry_id' => (int)$p['entry_id'],
                'pc'   => $p['profile_component_id'] !== null ? (int)$p['profile_component_id'] : null,
                'name' => $p['custom_name'],
                'tpl'  => $p['template_id'] !== null ? (int)$p['template_id'] : null,
                'w'    => $p['custom_weight'] !== null ? (int)$p['custom_weight'] : null,
                'qty'  => (int)$p['qty'],
                'units'=> [],
            ];
        }
        if (!$parts) continue;
        $units = [];
        foreach (db()->query("SELECT u.* FROM entry_part_units u JOIN entry_parts p ON p.id=u.entry_part_id
                              WHERE p.entry_id IN ($in) ORDER BY u.entry_part_id, u.unit_no")->fetchAll() as $u) {
            $units[(int)$u['id']] = ['part' => (int)$u['entry_part_id'], 'no' => (int)$u['unit_no'], 's' => (int)$u['score'], 'd' => []];
        }
        foreach (db()->query("SELECT d.* FROM entry_defects d JOIN entry_part_units u ON u.id=d.unit_id
                              JOIN entry_parts p ON p.id=u.entry_part_id WHERE p.entry_id IN ($in)")->fetchAll() as $d) {
            if (isset($units[(int)$d['unit_id']])) $units[(int)$d['unit_id']]['d'][(int)$d['defect_id']] = (int)$d['count'];
        }
        foreach ($units as $u) {
            if (!isset($parts[$u['part']])) continue;
            $parts[$u['part']]['units'][$u['no'] - 1] = ['s' => $u['s'], 'd' => $u['d']];
        }
        foreach ($parts as $p) {
            $eid = $p['entry_id']; unset($p['entry_id']);
            // Fill gaps (a unit without a row) and cast defect maps to objects for JSON
            $list = [];
            for ($i = 0; $i < $p['qty']; $i++) $list[] = $p['units'][$i] ?? ['s' => 100, 'd' => []];
            $p['units'] = array_map(fn($u) => ['s' => $u['s'], 'd' => (object)$u['d']], $list);
            $result[$eid][] = $p;
        }
    }
    return $result;
}

/** Adds 'grading' (parts) to each entry row that has point-grading data. */
function attachEntryGrading(array &$rows): void {
    $map = loadEntryGrading(array_column($rows, 'id'));
    foreach ($rows as &$r) $r['grading'] = $map[(int)$r['id']] ?? null;
}

/** Writes normalised parts for a copy (replacing what was there) and returns the overall score. */
function writeEntryParts(int $entryId, array $parts): void {
    $pdo = db();
    $pdo->prepare("DELETE FROM entry_parts WHERE entry_id=?")->execute([$entryId]);
    $insP = $pdo->prepare("INSERT INTO entry_parts (entry_id, profile_component_id, custom_name, template_id, custom_weight, qty, sort_order)
                           VALUES (?,?,?,?,?,?,?)");
    $insU = $pdo->prepare("INSERT INTO entry_part_units (entry_part_id, unit_no, score) VALUES (?,?,?)");
    $insD = $pdo->prepare("INSERT INTO entry_defects (unit_id, defect_id, count) VALUES (?,?,?)");
    foreach ($parts as $i => $p) {
        $insP->execute([$entryId, $p['pc'], $p['name'], $p['tpl'], $p['w'], $p['qty'], $i]);
        $pid = (int)$pdo->lastInsertId();
        foreach ($p['units'] as $u => $unit) {
            $insU->execute([$pid, $u + 1, $unit['s']]);
            $uid = (int)$pdo->lastInsertId();
            foreach ($unit['d'] as $did => $n) $insD->execute([$uid, $did, $n]);
        }
    }
}

/**
 * Saves a copy's grading. $g may hold: method ('simple'|'points'|null), label_id, profile_id, parts.
 * Keys that are absent are left unchanged, so quick toggles never touch grading.
 */
function saveEntryGrading(int $entryId, int $userId, array $g): void {
    $cfg = gradingConfig();
    $own = db()->prepare("SELECT id, grade_profile_id FROM collection_entries WHERE id=? AND user_id=?");
    $own->execute([$entryId, $userId]);
    $entry = $own->fetch();
    if (!$entry) return;

    $set = []; $vals = [];
    if (array_key_exists('method', $g)) {
        $set[] = 'grade_method=?'; $vals[] = in_array($g['method'], ['simple', 'points'], true) ? $g['method'] : null;
    }
    if (array_key_exists('label_id', $g)) {
        $lid = (int)$g['label_id'];
        $set[] = 'grade_label_id=?'; $vals[] = in_array($lid, array_column($cfg['labels'], 'id'), true) ? $lid : null;
    }
    $profileId = $entry['grade_profile_id'] !== null ? (int)$entry['grade_profile_id'] : null;
    if (array_key_exists('profile_id', $g)) {
        $profileId = isset($cfg['profiles'][(int)$g['profile_id']]) ? (int)$g['profile_id'] : null;
        $set[] = 'grade_profile_id=?'; $vals[] = $profileId;
    }
    if (array_key_exists('parts', $g) && is_array($g['parts'])) {
        $scored = gradeScoreParts($g['parts'], $profileId);
        writeEntryParts($entryId, $scored['parts']);
        $set[] = 'grade_score=?'; $vals[] = $scored['score'];
    }
    if (!$set) return;
    $vals[] = $entryId;
    db()->prepare("UPDATE collection_entries SET ".implode(', ', $set)." WHERE id=?")->execute($vals);
}

/** Recomputes cached unit and copy scores (after admin changes). Returns how many copies were checked. */
function recalcAllScores(): int {
    gradingConfig(true);
    $ids = db()->query("SELECT DISTINCT entry_id FROM entry_parts")->fetchAll(PDO::FETCH_COLUMN);
    $upU = db()->prepare("UPDATE entry_part_units SET score=? WHERE entry_part_id=? AND unit_no=?");
    $upE = db()->prepare("UPDATE collection_entries SET grade_score=? WHERE id=?");
    $cfg = gradingConfig();
    $n = 0;
    foreach (array_chunk($ids, 300) as $chunk) {
        foreach (loadEntryGrading($chunk) as $eid => $parts) {
            $units = [];
            foreach ($parts as $p) {
                $spec = gradePartSpec($p, $cfg);
                if (!$spec) continue;
                foreach ($p['units'] as $ui => $u) {
                    $s = gradeUnitScore($spec['tpl'], (array)$u['d']);
                    if ($s !== $u['s']) $upU->execute([$s, $p['id'], $ui + 1]);
                    $units[] = [$spec['weight'], $p['qty'], $s];
                }
            }
            $upE->execute([gradeOverallScore($units), $eid]);
            $n++;
        }
    }
    db()->exec("UPDATE collection_entries ce SET grade_score=NULL
                WHERE grade_score IS NOT NULL AND NOT EXISTS (SELECT 1 FROM entry_parts p WHERE p.entry_id=ce.id AND p.qty>0)");
    return $n;
}

// ── Per-copy grading in the user's collection export / import (portable, by name) ──

function exportEntryGrading(array $parts): array {
    $cfg = gradingConfig();
    $out = [];
    foreach ($parts as $p) {
        $spec = gradePartSpec($p, $cfg);
        if (!$spec) continue;
        $defs = [];
        foreach ($spec['tpl']['categories'] as $cat) foreach ($cat['defects'] as $d) $defs[$d['id']] = [$cat['name'], $d];
        $units = [];
        foreach ($p['units'] as $u) {
            $list = [];
            foreach ((array)$u['d'] as $did => $n) {
                if (!isset($defs[$did])) continue;
                [$catName, $d] = $defs[$did];
                $list[] = array_filter(['category' => $catName, 'group' => $d['level_group'], 'defect' => $d['name'], 'count' => (int)$n],
                                       fn($v) => $v !== null);
            }
            $units[] = $list;
        }
        $out[] = $p['pc']
            ? ['component' => $spec['label'], 'qty' => $p['qty'], 'units' => $units]
            : ['name' => $p['name'], 'template' => $spec['tpl']['name'], 'weight' => $p['w'], 'qty' => $p['qty'], 'units' => $units];
    }
    return $out;
}

/** Turns exported parts back into ids for the given profile. Unknown names are skipped. */
function importEntryGrading(array $parts, ?int $profileId): array {
    $cfg = gradingConfig();
    $tplByName = [];
    foreach ($cfg['templates'] as $t) $tplByName[mb_strtolower($t['name'])] = $t;
    $compByLabel = [];
    if ($profileId && isset($cfg['profiles'][$profileId])) {
        foreach ($cfg['profiles'][$profileId]['components'] as $c) $compByLabel[mb_strtolower($c['label'])] = $c;
    }
    $out = [];
    foreach ($parts as $p) {
        if (!is_array($p)) continue;
        if (isset($p['component'])) {
            $c = $compByLabel[mb_strtolower((string)$p['component'])] ?? null;
            if (!$c) continue;
            $tpl = $cfg['templates'][$c['template_id']] ?? null;
            $part = ['pc' => $c['id']];
        } else {
            $tpl = $tplByName[mb_strtolower((string)($p['template'] ?? ''))] ?? null;
            if (!$tpl) continue;
            $part = ['name' => (string)($p['name'] ?? ''), 'tpl' => $tpl['id'], 'w' => (int)($p['weight'] ?? $cfg['own_weight'])];
        }
        if (!$tpl) continue;
        $lookup = [];
        foreach ($tpl['categories'] as $cat) foreach ($cat['defects'] as $d) {
            $lookup[mb_strtolower($cat['name'].'|'.($d['level_group'] ?? '').'|'.$d['name'])] = $d['id'];
        }
        $part['qty'] = (int)($p['qty'] ?? 1);
        $part['units'] = [];
        foreach ((array)($p['units'] ?? []) as $u) {
            $d = [];
            foreach ((array)$u as $x) {
                $key = mb_strtolower(($x['category'] ?? '').'|'.($x['group'] ?? '').'|'.($x['defect'] ?? ''));
                if (isset($lookup[$key])) $d[$lookup[$key]] = (int)($x['count'] ?? 1);
            }
            $part['units'][] = ['d' => $d];
        }
        $out[] = $part;
    }
    return $out;
}

// ── Admin: export / import of the whole grading system ──

function exportGradingConfig(): array {
    $cfg = gradingConfig(true);
    $sysShort = [];
    foreach (db()->query("SELECT id, short_name FROM systems")->fetchAll() as $s) $sysShort[(int)$s['id']] = $s['short_name'];
    return [
        'format'      => GRADING_FORMAT,
        'version'     => 1,
        'exported_at' => date('c'),
        'own_item_default_weight' => $cfg['own_weight'],
        'labels'      => array_map(fn($l) => ['name' => $l['name'], 'short' => $l['short'], 'min_score' => $l['min_score'], 'color' => $l['color']], $cfg['labels']),
        'templates'   => array_values(array_map(fn($t) => [
            'name' => $t['name'],
            'categories' => array_map(fn($c) => [
                'name' => $c['name'], 'max_points' => $c['max_points'],
                'defects' => array_map(fn($d) => array_filter([
                    'name' => $d['name'], 'penalty' => $d['penalty'], 'kind' => $d['kind'],
                    'max_count' => $d['kind'] === 'max' ? $d['max_count'] : null,
                    'level_group' => $d['kind'] === 'level' ? $d['level_group'] : null,
                ], fn($v) => $v !== null), $c['defects']),
            ], $t['categories']),
        ], $cfg['templates'])),
        'profiles'    => array_values(array_map(fn($p) => [
            'name'       => $p['name'],
            'systems'    => array_values(array_filter(array_map(fn($sid) => $sysShort[$sid] ?? null, $p['systems']))),
            'components' => array_map(fn($c) => [
                'label' => $c['label'], 'abbr' => $c['abbr'], 'template' => $cfg['templates'][$c['template_id']]['name'] ?? '',
                'weight' => $c['weight'], 'default_qty' => $c['default_qty'],
            ], $p['components']),
        ], $cfg['profiles'])),
    ];
}

/** Checks a grading file. Returns a list of problems (empty = fine). */
function validateGradingData(array $data): array {
    $err = [];
    if (($data['format'] ?? '') !== GRADING_FORMAT) $err[] = tRaw('gapi.v_format');
    $labels = $data['labels'] ?? [];
    if (!is_array($labels) || !$labels) $err[] = tRaw('gapi.v_no_labels');
    else {
        $mins = array_map(fn($l) => (int)($l['min_score'] ?? -1), $labels);
        if (!in_array(0, $mins, true)) $err[] = tRaw('ga.err_lowest');
        if (count($mins) !== count(array_unique($mins))) $err[] = tRaw('ga.err_same');
        foreach ($labels as $l) if (trim((string)($l['name'] ?? '')) === '') $err[] = 'A grade label has no name.';
    }
    $tplNames = [];
    foreach ((array)($data['templates'] ?? []) as $t) {
        $n = trim((string)($t['name'] ?? ''));
        if ($n === '') { $err[] = 'A template has no name.'; continue; }
        if (isset($tplNames[mb_strtolower($n)])) $err[] = tRaw('gapi.v_tpl_twice', ['name' => $n]);
        $tplNames[mb_strtolower($n)] = true;
        foreach ((array)($t['categories'] ?? []) as $c) {
            foreach ((array)($c['defects'] ?? []) as $d) {
                if (!in_array($d['kind'] ?? 'each', GRADE_DEFECT_KINDS, true)) $err[] = tRaw('gapi.v_kind', ['name' => $n]);
            }
        }
    }
    foreach ((array)($data['profiles'] ?? []) as $p) {
        if (trim((string)($p['name'] ?? '')) === '') $err[] = 'A profile has no name.';
        foreach ((array)($p['components'] ?? []) as $c) {
            if (trim((string)($c['label'] ?? '')) === '') $err[] = 'A profile part has no name.';
        }
    }
    return array_values(array_unique($err));
}

/**
 * Imports a grading system.
 *  mode 'merge':   match by name, update matches, add new items. Items missing from the file are
 *                  kept, unless $deleteMissing (then they are removed, with their users' data).
 *  mode 'replace': remove every label/template/profile and ALL users' point grades, then load the file.
 * With $dryRun nothing is kept: the report shows what would happen.
 */
function importGradingConfig(array $data, string $mode, bool $dryRun, bool $deleteMissing = false): array {
    $errors = validateGradingData($data);
    $report = ['ok' => false, 'mode' => $mode, 'dry_run' => $dryRun, 'errors' => $errors,
               'added' => [], 'updated' => [], 'removed' => [], 'kept' => [], 'systems' => [], 'warnings' => []];
    if ($errors) return $report;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($mode === 'replace') gradingImportReplace($data, $report);
        else                     gradingImportMerge($data, $deleteMissing, $report);

        if (isset($data['own_item_default_weight'])) {
            setAppSetting('own_item_default_weight', (string)max(1, min(100, (int)$data['own_item_default_weight'])));
        }
        gradingConfig(true);
        if ($mode === 'replace' && !empty($data['system_patterns'])) {
            foreach (applySystemPatterns((array)$data['system_patterns']) as $a) $report['systems'][] = $a;
        }
        // Final label set must still be valid (merge can combine old and new labels)
        $mins = array_column(gradingConfig(true)['labels'], 'min_score');
        if (!in_array(0, $mins, true) || count($mins) !== count(array_unique($mins))) {
            throw new RuntimeException(tRaw('gapi.v_overlap'));
        }
        if ($dryRun) {
            $pdo->rollBack();
            gradingConfig(true);
        } else {
            recalcAllScores();
            $pdo->commit();
        }
        $report['ok'] = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        gradingConfig(true);
        $report['errors'][] = $e->getMessage();
    }
    return $report;
}

function gradingInsertTemplate(array $t, int $sort): int {
    $pdo = db();
    $pdo->prepare("INSERT INTO grade_templates (name, sort_order) VALUES (?,?)")->execute([trim($t['name']), $sort]);
    $tid = (int)$pdo->lastInsertId();
    foreach (array_values((array)($t['categories'] ?? [])) as $ci => $c) gradingInsertCategory($tid, $c, $ci);
    return $tid;
}

function gradingInsertCategory(int $tid, array $c, int $sort): int {
    $pdo = db();
    $pdo->prepare("INSERT INTO grade_categories (template_id, name, max_points, sort_order) VALUES (?,?,?,?)")
        ->execute([$tid, trim((string)$c['name']), max(0, min(100, (int)($c['max_points'] ?? 0))), $sort]);
    $cid = (int)$pdo->lastInsertId();
    foreach (array_values((array)($c['defects'] ?? [])) as $di => $d) gradingWriteDefect($cid, $d, $di, null);
    return $cid;
}

/** Inserts ($id null) or updates a defect row. */
function gradingWriteDefect(int $cid, array $d, int $sort, ?int $id): void {
    $kind  = in_array($d['kind'] ?? 'each', GRADE_DEFECT_KINDS, true) ? $d['kind'] : 'each';
    $vals  = [trim((string)$d['name']), max(0, min(100, (int)($d['penalty'] ?? 0))), $kind,
              $kind === 'max' ? max(1, min(99, (int)($d['max_count'] ?? 1))) : null,
              $kind === 'level' ? (trim((string)($d['level_group'] ?? '')) ?: 'Level') : null, $sort];
    if ($id) {
        $vals[] = $id;
        db()->prepare("UPDATE grade_defects SET name=?, penalty=?, kind=?, max_count=?, level_group=?, sort_order=? WHERE id=?")->execute($vals);
    } else {
        array_unshift($vals, $cid);
        db()->prepare("INSERT INTO grade_defects (category_id, name, penalty, kind, max_count, level_group, sort_order) VALUES (?,?,?,?,?,?,?)")->execute($vals);
    }
}

function gradingTemplateIdsByName(): array {
    $m = [];
    foreach (db()->query("SELECT id, name FROM grade_templates")->fetchAll() as $t) $m[mb_strtolower($t['name'])] = (int)$t['id'];
    return $m;
}

function gradingSystemIdsByKey(): array {
    $m = [];
    foreach (db()->query("SELECT id, name, short_name FROM systems")->fetchAll() as $s) {
        $m[mb_strtolower($s['name'])] = (int)$s['id'];
        $m[mb_strtolower($s['short_name'])] = (int)$s['id'];
    }
    return $m;
}

function gradingWriteComponent(int $pid, array $c, int $sort, array $tplIds, ?int $id, array &$report): void {
    $tid = $tplIds[mb_strtolower((string)($c['template'] ?? ''))] ?? null;
    if (!$tid) { $report['warnings'][] = tRaw('gapi.w_part_skipped', ['part' => $c['label'], 'template' => $c['template'] ?? '']); return; }
    $vals = [trim((string)$c['label']), mb_substr(trim((string)($c['abbr'] ?? '')), 0, 8), $tid,
             max(0, min(100, (int)($c['weight'] ?? 0))), max(0, min(GRADE_MAX_QTY, (int)($c['default_qty'] ?? 1))), $sort];
    if ($id) {
        $vals[] = $id;
        db()->prepare("UPDATE grade_profile_components SET label=?, abbr=?, template_id=?, weight=?, default_qty=?, sort_order=? WHERE id=?")->execute($vals);
    } else {
        array_unshift($vals, $pid);
        db()->prepare("INSERT INTO grade_profile_components (profile_id, label, abbr, template_id, weight, default_qty, sort_order) VALUES (?,?,?,?,?,?,?)")->execute($vals);
    }
}

function gradingAssignSystems(int $pid, string $profileName, array $systems, array &$report): void {
    $sysIds = gradingSystemIdsByKey();
    $up = db()->prepare("UPDATE systems SET grade_profile_id=? WHERE id=?");
    foreach ($systems as $key) {
        $sid = $sysIds[mb_strtolower((string)$key)] ?? null;
        if ($sid) { $up->execute([$pid, $sid]); $report['systems'][] = "$key → $profileName"; }
        else $report['warnings'][] = tRaw('gapi.w_system', ['name' => $key]);
    }
}

function gradingImportReplace(array $data, array &$report): void {
    $pdo = db();
    $n = (int)$pdo->query("SELECT COUNT(DISTINCT entry_id) FROM entry_parts")->fetchColumn();
    if ($n) $report['warnings'][] = "$n point-graded copies lose their point grades (their simple labels are kept).";

    // New labels first, then move simple grades over (same name, else the label covering the old start score)
    $old = $pdo->query("SELECT id, name, min_score FROM grade_labels")->fetchAll();
    $new = [];
    $ins = $pdo->prepare("INSERT INTO grade_labels (name, short, min_score, color, sort_order) VALUES (?,?,?,?,?)");
    foreach (array_values($data['labels']) as $i => $l) {
        $ins->execute([trim($l['name']), mb_substr((string)($l['short'] ?? ''), 0, 6), (int)$l['min_score'], gradingColor($l['color'] ?? ''), $i]);
        $new[] = ['id' => (int)$pdo->lastInsertId(), 'name' => trim($l['name']), 'min_score' => (int)$l['min_score']];
        $report['added'][] = 'Label: '.trim($l['name']);
    }
    usort($new, fn($a, $b) => $b['min_score'] <=> $a['min_score']);
    $move = $pdo->prepare("UPDATE collection_entries SET grade_label_id=? WHERE grade_label_id=?");
    foreach ($old as $o) {
        $target = null;
        foreach ($new as $nl) if (mb_strtolower($nl['name']) === mb_strtolower($o['name'])) { $target = $nl; break; }
        if (!$target) foreach ($new as $nl) if ($nl['min_score'] <= (int)$o['min_score']) { $target = $nl; break; }
        if ($target) $move->execute([$target['id'], $o['id']]);
    }
    if ($old) $pdo->exec("DELETE FROM grade_labels WHERE id IN (".implode(',', array_map(fn($o) => (int)$o['id'], $old)).")");

    $pdo->exec("DELETE FROM entry_parts");
    $pdo->exec("UPDATE collection_entries SET grade_score=NULL, grade_profile_id=NULL WHERE grade_score IS NOT NULL OR grade_profile_id IS NOT NULL");
    $pdo->exec("DELETE FROM grade_profiles");   // components cascade, systems → NULL
    $pdo->exec("DELETE FROM grade_templates");  // categories and defects cascade

    foreach (array_values((array)($data['templates'] ?? [])) as $i => $t) {
        gradingInsertTemplate($t, $i);
        $report['added'][] = tRaw('gapi.r_template', ['name' => trim($t['name'])]);
    }
    $tplIds = gradingTemplateIdsByName();
    foreach (array_values((array)($data['profiles'] ?? [])) as $i => $p) {
        $pdo->prepare("INSERT INTO grade_profiles (name, sort_order) VALUES (?,?)")->execute([trim($p['name']), $i]);
        $pid = (int)$pdo->lastInsertId();
        foreach (array_values((array)($p['components'] ?? [])) as $ci => $c) gradingWriteComponent($pid, $c, $ci, $tplIds, null, $report);
        gradingAssignSystems($pid, trim($p['name']), (array)($p['systems'] ?? []), $report);
        $report['added'][] = tRaw('gapi.r_profile', ['name' => trim($p['name'])]);
    }
}

function gradingImportMerge(array $data, bool $deleteMissing, array &$report): void {
    $pdo = db();
    $usage = function (string $sql, array $args) use ($pdo): int {
        $st = $pdo->prepare($sql); $st->execute($args); return (int)$st->fetchColumn();
    };

    // Labels
    $dbLabels = [];
    foreach ($pdo->query("SELECT * FROM grade_labels")->fetchAll() as $l) $dbLabels[mb_strtolower($l['name'])] = $l;
    $seen = [];
    foreach (array_values($data['labels']) as $i => $l) {
        $key = mb_strtolower(trim($l['name']));
        $vals = [mb_substr((string)($l['short'] ?? ''), 0, 6), (int)$l['min_score'], gradingColor($l['color'] ?? ''), $i];
        if (isset($dbLabels[$key])) {
            $pdo->prepare("UPDATE grade_labels SET name=?, short=?, min_score=?, color=?, sort_order=? WHERE id=?")->execute([trim($l['name']), ...$vals, $dbLabels[$key]['id']]);
            $report['updated'][] = 'Label: '.$l['name'];
        } else {
            $pdo->prepare("INSERT INTO grade_labels (name, short, min_score, color, sort_order) VALUES (?,?,?,?,?)")->execute([trim($l['name']), ...$vals]);
            $report['added'][] = 'Label: '.$l['name'];
        }
        $seen[$key] = true;
    }
    foreach ($dbLabels as $key => $l) {
        if (isset($seen[$key])) continue;
        $n = $usage("SELECT COUNT(*) FROM collection_entries WHERE grade_label_id=?", [$l['id']]);
        if (!$deleteMissing) { $report['kept'][] = tRaw('gapi.r_label', ['name' => $l['name']]); continue; }
        // Copies move to the label that covers this label's start score
        $st = $pdo->prepare("SELECT id FROM grade_labels WHERE id<>? AND min_score<=? ORDER BY min_score DESC LIMIT 1");
        $st->execute([$l['id'], $l['min_score']]);
        $to = $st->fetchColumn();
        if ($to) $pdo->prepare("UPDATE collection_entries SET grade_label_id=? WHERE grade_label_id=?")->execute([$to, $l['id']]);
        $pdo->prepare("DELETE FROM grade_labels WHERE id=?")->execute([$l['id']]);
        $report['removed'][] = tRaw('gapi.r_label', ['name' => $l['name']]).($n ? ' '.tRaw('gapi.s_moved', ['n' => $n]) : '');
    }

    // Templates → categories → defects
    $dbT = [];
    foreach ($pdo->query("SELECT * FROM grade_templates")->fetchAll() as $t) $dbT[mb_strtolower($t['name'])] = $t;
    $seenT = [];
    foreach (array_values((array)($data['templates'] ?? [])) as $ti => $t) {
        $tkey = mb_strtolower(trim($t['name']));
        $seenT[$tkey] = true;
        if (!isset($dbT[$tkey])) { gradingInsertTemplate($t, $ti); $report['added'][] = tRaw('gapi.r_template', ['name' => $t['name']]); continue; }
        $tid = (int)$dbT[$tkey]['id'];
        $pdo->prepare("UPDATE grade_templates SET sort_order=? WHERE id=?")->execute([$ti, $tid]);
        $report['updated'][] = tRaw('gapi.r_template', ['name' => $t['name']]);

        $st = $pdo->prepare("SELECT * FROM grade_categories WHERE template_id=?"); $st->execute([$tid]);
        $dbC = [];
        foreach ($st->fetchAll() as $c) $dbC[mb_strtolower($c['name'])] = $c;
        $seenC = [];
        foreach (array_values((array)($t['categories'] ?? [])) as $ci => $c) {
            $ckey = mb_strtolower(trim($c['name']));
            $seenC[$ckey] = true;
            if (!isset($dbC[$ckey])) { gradingInsertCategory($tid, $c, $ci); $report['added'][] = tRaw('gapi.r_category', ['name' => "{$t['name']} › {$c['name']}"]); continue; }
            $cid = (int)$dbC[$ckey]['id'];
            $pdo->prepare("UPDATE grade_categories SET max_points=?, sort_order=? WHERE id=?")
                ->execute([max(0, min(100, (int)($c['max_points'] ?? 0))), $ci, $cid]);
            $st = $pdo->prepare("SELECT * FROM grade_defects WHERE category_id=?"); $st->execute([$cid]);
            $dbD = [];
            foreach ($st->fetchAll() as $d) $dbD[mb_strtolower(($d['level_group'] ?? '').'|'.$d['name'])] = $d;
            $seenD = [];
            foreach (array_values((array)($c['defects'] ?? [])) as $di => $d) {
                $dkey = mb_strtolower((($d['kind'] ?? '') === 'level' ? trim((string)($d['level_group'] ?? '')) : '').'|'.trim($d['name']));
                $seenD[$dkey] = true;
                gradingWriteDefect($cid, $d, $di, isset($dbD[$dkey]) ? (int)$dbD[$dkey]['id'] : null);
                if (!isset($dbD[$dkey])) $report['added'][] = tRaw('gapi.r_defect', ['name' => "{$t['name']} › {$c['name']} › {$d['name']}"]);
            }
            foreach ($dbD as $dkey => $d) {
                if (isset($seenD[$dkey])) continue;
                $n = $usage("SELECT COUNT(*) FROM entry_defects WHERE defect_id=?", [$d['id']]);
                if ($deleteMissing) {
                    $pdo->prepare("DELETE FROM grade_defects WHERE id=?")->execute([$d['id']]);
                    $report['removed'][] = tRaw('gapi.r_defect', ['name' => "{$t['name']} › {$c['name']} › {$d['name']}"]).($n ? ' '.tRaw('gapi.s_logged', ['n' => $n]) : '');
                } else $report['kept'][] = tRaw('gapi.r_defect', ['name' => "{$t['name']} › {$c['name']} › {$d['name']}"]);
            }
        }
        foreach ($dbC as $ckey => $c) {
            if (isset($seenC[$ckey])) continue;
            $n = $usage("SELECT COUNT(*) FROM entry_defects ed JOIN grade_defects d ON d.id=ed.defect_id WHERE d.category_id=?", [$c['id']]);
            if ($deleteMissing) {
                $pdo->prepare("DELETE FROM grade_categories WHERE id=?")->execute([$c['id']]);
                $report['removed'][] = tRaw('gapi.r_category', ['name' => "{$t['name']} › {$c['name']}"]).($n ? ' '.tRaw('gapi.s_cat_logged', ['n' => $n]) : '');
            } else $report['kept'][] = tRaw('gapi.r_category', ['name' => "{$t['name']} › {$c['name']}"]);
        }
    }

    // Profiles → components
    $tplIds = gradingTemplateIdsByName();
    $dbP = [];
    foreach ($pdo->query("SELECT * FROM grade_profiles")->fetchAll() as $p) $dbP[mb_strtolower($p['name'])] = $p;
    $seenP = [];
    foreach (array_values((array)($data['profiles'] ?? [])) as $pi => $p) {
        $pkey = mb_strtolower(trim($p['name']));
        $seenP[$pkey] = true;
        if (isset($dbP[$pkey])) {
            $pid = (int)$dbP[$pkey]['id'];
            $pdo->prepare("UPDATE grade_profiles SET sort_order=? WHERE id=?")->execute([$pi, $pid]);
            $report['updated'][] = tRaw('gapi.r_profile', ['name' => $p['name']]);
        } else {
            $pdo->prepare("INSERT INTO grade_profiles (name, sort_order) VALUES (?,?)")->execute([trim($p['name']), $pi]);
            $pid = (int)$pdo->lastInsertId();
            $report['added'][] = tRaw('gapi.r_profile', ['name' => $p['name']]);
        }
        $st = $pdo->prepare("SELECT * FROM grade_profile_components WHERE profile_id=?"); $st->execute([$pid]);
        $dbComp = [];
        foreach ($st->fetchAll() as $c) $dbComp[mb_strtolower($c['label'])] = $c;
        $seenComp = [];
        foreach (array_values((array)($p['components'] ?? [])) as $ci => $c) {
            $ckey = mb_strtolower(trim($c['label']));
            $seenComp[$ckey] = true;
            gradingWriteComponent($pid, $c, $ci, $tplIds, isset($dbComp[$ckey]) ? (int)$dbComp[$ckey]['id'] : null, $report);
        }
        foreach ($dbComp as $ckey => $c) {
            if (isset($seenComp[$ckey])) continue;
            $n = $usage("SELECT COUNT(DISTINCT entry_id) FROM entry_parts WHERE profile_component_id=?", [$c['id']]);
            if ($deleteMissing) {
                $pdo->prepare("DELETE FROM grade_profile_components WHERE id=?")->execute([$c['id']]);
                $report['removed'][] = tRaw('gapi.r_part', ['name' => "{$p['name']} › {$c['label']}"]).($n ? ' '.tRaw('gapi.s_graded', ['n' => $n]) : '');
            } else $report['kept'][] = tRaw('gapi.r_part', ['name' => "{$p['name']} › {$c['label']}"]);
        }
        gradingAssignSystems($pid, trim($p['name']), (array)($p['systems'] ?? []), $report);
    }
    foreach ($dbP as $pkey => $p) {
        if (isset($seenP[$pkey])) continue;
        $n = $usage("SELECT COUNT(DISTINCT ep.entry_id) FROM entry_parts ep JOIN grade_profile_components c ON c.id=ep.profile_component_id WHERE c.profile_id=?", [$p['id']]);
        if ($deleteMissing) {
            $pdo->prepare("DELETE FROM grade_profiles WHERE id=?")->execute([$p['id']]);
            $report['removed'][] = tRaw('gapi.r_profile', ['name' => $p['name']]).($n ? ' '.tRaw('gapi.s_point_grades', ['n' => $n]) : '');
        } else $report['kept'][] = tRaw('gapi.r_profile', ['name' => $p['name']]);
    }

    // Templates last: only those no remaining profile uses
    foreach ($dbT as $tkey => $t) {
        if (isset($seenT[$tkey])) continue;
        if (!$deleteMissing) { $report['kept'][] = tRaw('gapi.r_template', ['name' => $t['name']]); continue; }
        $used = $usage("SELECT COUNT(*) FROM grade_profile_components WHERE template_id=?", [$t['id']]);
        if ($used) { $report['kept'][] = tRaw('gapi.r_template', ['name' => $t['name']]).' '.tRaw('gapi.s_still_used'); continue; }
        $n = $usage("SELECT COUNT(DISTINCT entry_id) FROM entry_parts WHERE template_id=?", [$t['id']]);
        $pdo->prepare("DELETE FROM grade_templates WHERE id=?")->execute([$t['id']]);
        $report['removed'][] = tRaw('gapi.r_template', ['name' => $t['name']]).($n ? ' '.tRaw('gapi.s_own_items', ['n' => $n]) : '');
    }
}

function gradingColor(string $c): string {
    return preg_match('/^#[0-9a-f]{6}$/i', $c) ? strtolower($c) : '#b0a898';
}
