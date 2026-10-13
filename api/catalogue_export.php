<?php
// The game catalogue as CSV, JSON or XML (data_export.php).
//   Admins (Settings › Database export): the columns and options in the link (?cols=…&format=…&sep=…&rows=…&sys=…&inactive=1);
//     &preview=1 answers with JSON: the first rows and the row count, for the preview table.
//   Everyone else, only while "Public download" is on: the saved settings, in ?format=csv|json|xml (default the saved one).
require_once __DIR__ . '/../boot.php';

if (isAdmin()) {
    $cfg = dxConfigFromRequest($_GET);
    if (!empty($_GET['preview'])) {
        $rows = [];
        foreach (dxRows($cfg, 10) as $r) { $rows[] = $r; if (count($rows) >= 10) break; }
        // Row count without building every row: games, plus the extra rows for games with several barcodes
        $count = 0;
        foreach (dxRows(['cols' => [['id' => 'game_id', 'on' => true]]] + $cfg) as $r) $count++;
        jsonOut(['ok' => true, 'columns' => dxColumnIds($cfg), 'rows' => $rows, 'count' => $count]);
    }
    dxSend($cfg);
}

if (setting('dx_public') !== '1') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}
$saved = dxConfig();
if (in_array($_GET['format'] ?? '', DX_FORMATS, true)) $saved['format'] = $_GET['format'];
dxSend($saved, true);
