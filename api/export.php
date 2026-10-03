<?php
require_once __DIR__ . '/../boot.php';
$user = requireAuth();

// Data-only export: photos are backed up separately as per-system zips (Settings → Image Backups)
$st = db()->prepare("
    SELECT ce.*, g.title AS game_title, g.sort_order AS game_sort_order,
           s.short_name AS system_short, s.name AS system_name
    FROM collection_entries ce
    JOIN games g   ON g.id  = ce.game_id
    JOIN systems s ON s.id  = g.system_id
    WHERE ce.user_id = ?
    ORDER BY s.sort_order, g.sort_title, ce.copy_number
");
$st->execute([$user['id']]);
$rows = $st->fetchAll();

// User option lists
$optionList = function (string $table) use ($user): array {
    $cols = $table === 'user_played_options' ? 'label, play_group, sort_order' : 'label, sort_order';   // played: also its counter group
    $st = db()->prepare("SELECT $cols FROM $table WHERE user_id=? ORDER BY sort_order");
    $st->execute([$user['id']]);
    return $st->fetchAll();
};

// Condition grading is exported by name (labels, profiles, parts, defects), so it survives
// an import on a site where the ids differ
$cfg      = gradingConfig();
$labels   = array_column($cfg['labels'], null, 'id');
$gradings = loadEntryGrading(array_column($rows, 'id'));

$entries = [];
foreach ($rows as $row) {
    $simple = $labels[(int)$row['grade_label_id']] ?? null;
    $eff    = $row['grade_method'] === 'points' && $row['grade_score'] !== null ? gradeLabelForScore((int)$row['grade_score']) : $simple;
    $entry = [
        'system'          => $row['system_short'],
        'system_name'     => $row['system_name'],
        'game_title'      => $row['game_title'],
        'copy_number'     => (int)$row['copy_number'],
        'owned'           => (bool)$row['owned'],
        'quality'         => $eff['name'] ?? '', // shown label; kept for older versions of the app
        'completeness'    => $row['completeness'],
        'played_status'   => $row['played_status'],
        'variant'         => $row['variant'] ?? '',
        'wishlist'        => (bool)$row['wishlist'],
        'wishlist_any'    => (bool)($row['wishlist_any'] ?? false),
        'price_paid'      => $row['price_paid'],
        'chart_price'     => $row['chart_price'],
        'price_min'       => $row['price_min'],
        'price_max'       => $row['price_max'],
        'upgrade'         => (bool)$row['upgrade'],
        'upgrade_reason'  => $row['upgrade_reason'],
        'notes'           => $row['notes'],
        'tag'             => $row['tag'],
        'value_price_type'=> $row['value_price_type'],
        'grade_method'    => $row['grade_method'],
        'grade_label'     => $simple['name'] ?? null,
    ];
    if (!empty($gradings[(int)$row['id']])) {
        $entry['grade_profile'] = $cfg['profiles'][(int)$row['grade_profile_id']]['name'] ?? null;
        $entry['grade_score']   = $row['grade_score'] !== null ? (int)$row['grade_score'] : null;
        $entry['grade_parts']   = exportEntryGrading($gradings[(int)$row['id']]);
    }
    $entries[] = $entry;
}

$data = [
    'exported_at'          => date('c'),
    'username'             => $user['username'],
    'completeness_options' => $optionList('user_completeness_options'),
    'played_options'       => $optionList('user_played_options'),
    'tag_options'          => $optionList('user_tag_options'),
    'variant_options'      => $optionList('user_variant_options'),
    'editions'             => [
        'mode'           => $user['edition_mode'] ?? 'one',
        'wishlist'       => $user['edition_wishlist'] ?? 'any',
        'track_variants' => !empty($user['track_variants']),
    ],
    'compilations'         => ['mode' => compilationMode($user)],
    'played_pct'           => ($user['played_pct'] ?? 'all') === 'owned' ? 'owned' : 'all',
    'show_played'          => showPlayedCounters($user),
    'grading'              => ['mode' => $user['grading_mode'] ?? 'simple', 'default' => $user['grading_default'] ?? 'simple'],
    'entries'              => $entries,
];

// Sent as a file download (no fetch/blob in the browser, so nothing can fail silently)
$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) { http_response_code(500); exit(tRaw('api.export_failed', ['error' => json_last_error_msg()])); }

session_write_close();
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="game_collection_backup_'.date('Ymd').'.json"');
header('Content-Length: '.strlen($json));
header('Cache-Control: no-cache, no-store');
echo $json;
exit;
