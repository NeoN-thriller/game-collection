<?php
/* ═══════════════════════════════════════════
   CONDITION REPORTS — the public, shareable report of one copy (grade.php?t=…),
   its snapshot, and the printable label templates / sizes. Loaded by core.php.
   The report never contains private fields: no prices paid, personal prices, buy range,
   notes, tags, upgrade flags, username or other copies.
   ═══════════════════════════════════════════ */

const LABEL_FIELDS = ['score', 'cond', 'qr', 'title', 'meta', 'date', 'id'];

/** Human-friendly report id from a token: "7F2A-91C0". Not a secret; the full token grants access. */
function shareReportId(string $token): string {
    $t = strtoupper(substr($token, 0, 8));
    return substr($t, 0, 4) . '-' . substr($t, 4, 4);
}

function shareUrl(string $token): string {
    return BASE_URL . '/grade.php?t=' . $token;
}

function shareNewToken(): string {
    return bin2hex(random_bytes(16));
}

/**
 * Per-category breakdown of one unit (mirror of gradeUnitScore): [{name, max, score, defects:[{name, count, deduction}]}].
 */
function gradeUnitBreakdown(array $tpl, array $counts): array {
    $out = [];
    foreach ($tpl['categories'] as $cat) {
        $lost = 0; $levels = []; $defects = [];
        foreach ($cat['defects'] as $d) {
            $n = (int)($counts[$d['id']] ?? 0);
            if ($n <= 0) continue;
            switch ($d['kind']) {
                case 'once':  $ded = $d['penalty']; $lost += $ded; break;
                case 'max':   $ded = $d['penalty'] * min($n, max(1, (int)$d['max_count'])); $lost += $ded; break;
                case 'level': $ded = $d['penalty']; $g = $d['level_group'] ?? ''; $levels[$g] = max($levels[$g] ?? 0, $ded); break;
                default:      $ded = $d['penalty'] * $n; $lost += $ded;
            }
            $defects[] = ['name' => $d['name'], 'count' => $n, 'deduction' => $ded];
        }
        $lost += array_sum($levels);
        $out[] = ['name' => $cat['name'], 'max' => $cat['max_points'], 'score' => max(0, $cat['max_points'] - $lost), 'defects' => $defects];
    }
    return $out;
}

/** The label scale 0–100 from grade_labels, low to high: [{name, min, max, color}]. */
function gradeLabelScale(): array {
    $labels = gradingConfig()['labels'];   // min_score high → low
    $out = []; $upper = 100;
    foreach ($labels as $l) {
        $min = max(0, min(100, (int)$l['min_score']));
        if ($min > $upper) continue;
        $out[] = ['name' => $l['name'], 'min' => $min, 'max' => $upper, 'color' => gradingColor($l['color'])];
        $upper = $min - 1;
        if ($upper < 0) break;
    }
    return array_reverse($out);
}

/**
 * Everything the public report shows for one copy, built from the current grading.
 * Returns null when the copy doesn't exist. graded_at is set by the caller.
 */
function conditionReport(int $entryId): ?array {
    $st = db()->prepare("
        SELECT ce.id, ce.copy_number, ce.grade_method, ce.grade_label_id, ce.grade_profile_id, ce.grade_score, ce.completeness,
               g.title, g.edition_label, gg.title AS group_title,
               s.name AS system_name, s.short_name, s.region
        FROM collection_entries ce
        JOIN games g   ON g.id = ce.game_id
        JOIN systems s ON s.id = g.system_id
        LEFT JOIN game_groups gg ON gg.id = g.group_id
        WHERE ce.id = ?
    ");
    $st->execute([$entryId]);
    $e = $st->fetch();
    if (!$e) return null;

    $cfg = gradingConfig();
    $labelsById = array_column($cfg['labels'], null, 'id');
    $method = in_array($e['grade_method'], ['simple', 'points'], true) ? $e['grade_method'] : null;
    $score = null; $label = null;
    if ($method === 'points' && $e['grade_score'] !== null) {
        $score = (int)$e['grade_score'];
        $label = gradeLabelForScore($score);
    } elseif ($method === 'simple' && isset($labelsById[(int)$e['grade_label_id']])) {
        $label = $labelsById[(int)$e['grade_label_id']];
    }
    $profileId = $e['grade_profile_id'] !== null ? (int)$e['grade_profile_id'] : null;
    $profile   = $cfg['profiles'][$profileId] ?? null;

    // Parts: scored the same way as on save; missing parts (qty 0) are listed but not scored
    $parts = [];
    if ($method === 'points') {
        $raw = loadEntryGrading([$entryId])[$entryId] ?? [];
        // loadEntryGrading() gives each unit's defects as an object (for JSON); gradeScoreParts() needs an array
        $raw = array_map(function (array $p) {
            $p['units'] = array_map(fn($u) => ['s' => $u['s'], 'd' => (array)$u['d']], $p['units']);
            return $p;
        }, $raw);
        $scored = gradeScoreParts($raw, $profileId);
        $seenPc = [];
        foreach ($scored['parts'] as $p) {
            $spec = gradePartSpec($p, $cfg);
            if (!$spec) continue;
            if ($p['pc']) $seenPc[(int)$p['pc']] = true;
            $name = $spec['label'] !== '' ? $spec['label'] : tRaw('cr.part');
            $units = [];
            foreach ($p['units'] as $i => $u) {
                $units[] = [
                    'name'       => $p['qty'] > 1 ? $name . ' #' . ($i + 1) : $name,
                    'score'      => (int)$u['s'],
                    'categories' => gradeUnitBreakdown($spec['tpl'], $u['d']),
                ];
            }
            $parts[] = ['name' => $name, 'abbr' => $spec['abbr'], 'weight' => (int)$spec['weight'], 'qty' => (int)$p['qty'],
                        'included' => $p['qty'] > 0, 'units' => $units];
        }
        // Profile parts that were never added count as missing too
        foreach ($profile['components'] ?? [] as $c) {
            if (isset($seenPc[$c['id']])) continue;
            $parts[] = ['name' => $c['label'], 'abbr' => $c['abbr'], 'weight' => (int)$c['weight'], 'qty' => 0, 'included' => false, 'units' => []];
        }
        // Weight share of each unit, the part score (average of its units) and the formula
        $totalW = array_sum(array_map(fn($p) => $p['included'] ? $p['weight'] : 0, $parts));
        $terms = [];
        foreach ($parts as &$p) {
            $p['score'] = null;
            if (!$p['included']) continue;
            $p['score'] = (int)round(array_sum(array_column($p['units'], 'score')) / max(1, count($p['units'])));
            foreach ($p['units'] as &$u) $u['weight_pct'] = $totalW ? round($p['weight'] / $p['qty'] / $totalW * 100, 1) : 0;
            unset($u);
            $terms[] = [$p['weight'], $p['score']];
        }
        unset($p);
    }

    $photoSt = db()->prepare("SELECT id FROM copy_photos WHERE entry_id=? ORDER BY sort_order, id");
    $photoSt->execute([$entryId]);

    return [
        'v'            => 1,
        'title'        => $e['group_title'] ?? $e['title'],
        'edition'      => $e['group_title'] !== null ? $e['edition_label'] : null,
        'system'       => $e['system_name'],
        'short'        => $e['short_name'],
        'region'       => systemRegion(['region' => $e['region']]),
        'copy_number'  => (int)$e['copy_number'],
        'profile'      => $profile['name'] ?? null,
        'method'       => $method,
        'score'        => $score,
        'label'        => $label ? ['name' => $label['name'], 'color' => gradingColor($label['color'])] : null,
        'scale'        => gradeLabelScale(),
        'completeness' => (string)$e['completeness'],
        'parts'        => $parts,
        'formula'      => $method === 'points' && $score !== null ? ['terms' => $terms ?? [], 'total' => $totalW ?? 0, 'result' => $score] : null,
        'photos'       => array_map('intval', $photoSt->fetchAll(PDO::FETCH_COLUMN)),
    ];
}

/** An active share by token whose owner is active, or null. */
function shareFindActive(string $token): ?array {
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) return null;
    $st = db()->prepare("
        SELECT cs.*, u.theme AS owner_theme
        FROM copy_shares cs JOIN users u ON u.id = cs.user_id
        WHERE cs.token = ? AND cs.active = 1 AND u.status = 'active'
    ");
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

/** The report a share shows: the stored snapshot, or the current grading in live mode. */
function shareReport(array $share): ?array {
    if ($share['mode'] === 'live') {
        $r = conditionReport((int)$share['entry_id']);
        if (!$r) return null;
        $st = db()->prepare("SELECT updated_at FROM collection_entries WHERE id=?");
        $st->execute([(int)$share['entry_id']]);
        $r['graded_at'] = $st->fetchColumn() ?: null;
        return $r;
    }
    $r = json_decode((string)$share['snapshot'], true);
    if (!is_array($r)) return null;
    $r['graded_at'] = $share['graded_at'];
    return $r;
}

/**
 * "PS1 · PAL · Box, Cart, Manual": system, region and the included parts, for the label.
 * Copies without parts (simple grading) show their completeness instead.
 */
function reportMetaLine(array $r): string {
    $included = array_filter($r['parts'] ?? [], fn($p) => $p['included']);
    $contents = $included
        ? implode(', ', array_map(fn($p) => $p['name'] . ($p['qty'] > 1 ? ' ×' . $p['qty'] : ''), $included))
        : (string)($r['completeness'] ?? '');
    return implode(' · ', array_filter([$r['short'], $r['region'] !== 'Mixed' ? $r['region'] : '', $contents], fn($s) => $s !== ''));
}

/** Data for renderLabel() in assets/js/labels.js. */
function labelDataFromReport(array $r, string $token): array {
    return [
        'score' => $r['score'],
        'cond'  => $r['label'],
        'title' => $r['title'] . ($r['edition'] ? ' · ' . $r['edition'] : ''),
        'meta'  => reportMetaLine($r),
        'date'  => $r['graded_at'] ? fmtDate($r['graded_at']) : '',
        'id'    => shareReportId($token),
        'qr'    => shareUrl($token),
    ];
}

// ── Label templates and sizes ────────────

/** Fields in print order with on/off and scale (0.5–2.0, step 0.1); unknown ids dropped, missing ones added (off). */
function labelNormaliseFields(mixed $fields): array {
    $out = []; $seen = [];
    foreach (is_array($fields) ? $fields : [] as $f) {
        $id = (string)($f['id'] ?? '');
        if (!in_array($id, LABEL_FIELDS, true) || isset($seen[$id])) continue;
        $seen[$id] = true;
        $scale = round(max(0.5, min(2.0, (float)($f['scale'] ?? 1))), 1);
        $out[] = ['id' => $id, 'on' => !empty($f['on']), 'scale' => $scale];
    }
    foreach (LABEL_FIELDS as $id) if (!isset($seen[$id])) $out[] = ['id' => $id, 'on' => false, 'scale' => 1.0];
    return $out;
}

/** Sizes a user can pick: the site's sizes, then their own. */
function labelSizesFor(int $userId): array {
    $st = db()->prepare("SELECT id, user_id, name, width_mm, height_mm FROM label_sizes
                         WHERE user_id IS NULL OR user_id = ? ORDER BY (user_id IS NOT NULL), sort_order, id");
    $st->execute([$userId]);
    return array_map(fn($s) => [
        'id' => (int)$s['id'], 'name' => $s['name'], 'width_mm' => (float)$s['width_mm'], 'height_mm' => (float)$s['height_mm'],
        'site' => $s['user_id'] === null,
    ], $st->fetchAll());
}

/**
 * Templates a user can use: site templates, their own, and ones others shared.
 * kind: site | own | shared · editable: own, or a site template for an admin.
 */
function labelTemplatesFor(array $user): array {
    $uid = (int)$user['id'];
    $st = db()->prepare("
        SELECT t.*, u.username AS owner_name, s.name AS size_name, s.width_mm, s.height_mm
        FROM label_templates t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN label_sizes s ON s.id = t.size_id
        WHERE t.user_id IS NULL OR t.user_id = ? OR t.shared = 1
        ORDER BY (t.user_id IS NULL) DESC, (t.user_id = ?) DESC, t.name, t.id
    ");
    $st->execute([$uid, $uid]);
    $fallback = labelSizesFor($uid)[0] ?? ['id' => null, 'name' => '', 'width_mm' => 89.0, 'height_mm' => 36.0];
    $isAdmin  = ($user['role'] ?? '') === 'admin';
    $out = [];
    foreach ($st->fetchAll() as $t) {
        $kind = $t['user_id'] === null ? 'site' : ((int)$t['user_id'] === $uid ? 'own' : 'shared');
        $hasSize = $t['size_id'] !== null && $t['width_mm'] !== null;
        $out[] = [
            'id'          => (int)$t['id'],
            'name'        => $t['name'],
            'kind'        => $kind,
            'owner_name'  => $kind === 'shared' ? $t['owner_name'] : null,
            'editable'    => $kind === 'own' || ($kind === 'site' && $isAdmin),
            'size_id'     => $hasSize ? (int)$t['size_id'] : $fallback['id'],
            'size_name'   => $hasSize ? $t['size_name'] : $fallback['name'],
            'width_mm'    => $hasSize ? (float)$t['width_mm'] : $fallback['width_mm'],
            'height_mm'   => $hasSize ? (float)$t['height_mm'] : $fallback['height_mm'],
            'orientation' => $t['orientation'],
            'layout'      => $t['layout'],
            'fields'      => labelNormaliseFields(json_decode((string)$t['fields'], true)),
            'colour'      => (bool)$t['colour'],
            'cut_line'    => (bool)$t['cut_line'],
            'shared'      => (bool)$t['shared'],
        ];
    }
    return $out;
}

/** The user's default template id: their pick if they can still use it, else the first site template. */
function labelDefaultTemplateId(array $user, ?array $templates = null): ?int {
    $templates ??= labelTemplatesFor($user);
    $ids = array_column($templates, 'id');
    $pick = (int)($user['label_template_id'] ?? 0);
    if ($pick && in_array($pick, $ids, true)) return $pick;
    foreach ($templates as $t) if ($t['kind'] === 'site') return $t['id'];
    return $templates[0]['id'] ?? null;
}

/** Label data for previews and test prints: the user's last point-graded copy, else sample text. */
function labelSampleData(array $user): array {
    $st = db()->prepare("SELECT id FROM collection_entries WHERE user_id=? AND owned=1 AND grade_method='points' AND grade_score IS NOT NULL
                         ORDER BY updated_at DESC LIMIT 1");
    $st->execute([(int)$user['id']]);
    $id = (int)$st->fetchColumn();
    $r = $id ? conditionReport($id) : null;
    if ($r) {
        $r['graded_at'] = date('Y-m-d H:i:s');
        return labelDataFromReport($r, str_repeat('0', 32));
    }
    $l = gradeLabelForScore(92);
    return [
        'score' => 92,
        'cond'  => $l ? ['name' => $l['name'], 'color' => gradingColor($l['color'])] : null,
        'title' => tRaw('lbl.sample_title'),
        'meta'  => tRaw('lbl.sample_meta'),
        'date'  => fmtDate(time()),
        'id'    => '7F2A-91C0',
        'qr'    => BASE_URL . '/grade.php?t=' . str_repeat('0', 32),
    ];
}
