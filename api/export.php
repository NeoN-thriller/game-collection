<?php
require_once __DIR__ . '/../config.php';
$user = requireAuth();

// Get all entries with photos
$st = db()->prepare("
    SELECT ce.*, g.title AS game_title, g.sort_order AS game_sort_order,
           s.short_name AS system_short, s.name AS system_name,
           GROUP_CONCAT(cp.filename ORDER BY cp.sort_order SEPARATOR '||') AS photos_raw
    FROM collection_entries ce
    JOIN games g   ON g.id  = ce.game_id
    JOIN systems s ON s.id  = g.system_id
    LEFT JOIN copy_photos cp ON cp.entry_id = ce.id
    WHERE ce.user_id = ?
    GROUP BY ce.id
    ORDER BY s.sort_order, g.sort_title, ce.copy_number
");
$st->execute([$user['id']]);
$rows = $st->fetchAll();

// Get completeness options
$opts = db()->prepare("SELECT label, sort_order FROM user_completeness_options WHERE user_id=? ORDER BY sort_order");
$opts->execute([$user['id']]);

$entries = [];
foreach ($rows as $row) {
    $photos = $row['photos_raw'] ? explode('||', $row['photos_raw']) : [];
    // Encode photos as base64 for portable export
    $photosEncoded = [];
    foreach ($photos as $fn) {
        $path = UPLOAD_DIR . $fn;
        if (file_exists($path)) {
            $photosEncoded[] = [
                'filename' => basename($fn),
                'data'     => base64_encode(file_get_contents($path)),
                'mime'     => mime_content_type($path),
            ];
        }
    }
    $entries[] = [
        'system'          => $row['system_short'],
        'system_name'     => $row['system_name'],
        'game_title'      => $row['game_title'],
        'copy_number'     => (int)$row['copy_number'],
        'owned'           => (bool)$row['owned'],
        'quality'         => $row['quality'],
        'completeness'    => $row['completeness'],
        'price_paid'      => $row['price_paid'],
        'chart_price'     => $row['chart_price'],
        'price_min'       => $row['price_min'],
        'price_max'       => $row['price_max'],
        'upgrade'         => (bool)$row['upgrade'],
        'upgrade_reason'  => $row['upgrade_reason'],
        'notes'           => $row['notes'],
        'photos'          => $photosEncoded,
    ];
}

jsonOut([
    'ok'      => true,
    'data'    => [
        'exported_at'          => date('c'),
        'username'             => $user['username'],
        'completeness_options' => $opts->fetchAll(),
        'entries'              => $entries,
    ]
]);
