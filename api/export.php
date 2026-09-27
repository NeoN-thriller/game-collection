<?php
require_once __DIR__ . '/../config.php';
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

// Get completeness options
$opts = db()->prepare("SELECT label, sort_order FROM user_completeness_options WHERE user_id=? ORDER BY sort_order");
$opts->execute([$user['id']]);

$entries = [];
foreach ($rows as $row) {
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
    ];
}

$data = [
    'exported_at'          => date('c'),
    'username'             => $user['username'],
    'completeness_options' => $opts->fetchAll(),
    'entries'              => $entries,
];

// Sent as a file download (no fetch/blob in the browser, so nothing can fail silently)
$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) { http_response_code(500); exit('Export failed: '.json_last_error_msg()); }

session_write_close();
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="game_collection_backup_'.date('Ymd').'.json"');
header('Content-Length: '.strlen($json));
header('Cache-Control: no-cache, no-store');
echo $json;
exit;
