<?php
// Every linked barcode as CSV (admins, Settings › Barcodes › Export CSV). The columns are the ones the import reads
// (barcode, system, title, edition, variant, note), so the file can be imported again; added_by and added_at are extra.
// Cells that a spreadsheet would run as a formula (starting with = + - @) get a ' in front.
require_once __DIR__ . '/../boot.php';
$admin = requireAdmin();
session_write_close();
@set_time_limit(300);

$cell = function (string $v): string {
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) $v = "'".$v;
    return '"'.str_replace('"', '""', $v).'"';
};

while (ob_get_level()) ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="barcodes_'.date('Ymd').'.csv"');
header('Cache-Control: no-cache, no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 mark, so Excel reads accents right
fwrite($out, "barcode,system,title,edition,variant,note,added_by,added_at\r\n");
$st = db()->query("
    SELECT b.barcode, s.short_name, g.title, g.edition_label, b.variant, b.note, b.added_username, b.created_at
    FROM game_barcodes b JOIN games g ON g.id = b.game_id JOIN systems s ON s.id = g.system_id
    ORDER BY s.short_name, g.sort_title, b.barcode
");
while ($r = $st->fetch()) {
    fwrite($out, implode(',', array_map(fn($v) => $cell((string)($v ?? '')), [
        $r['barcode'], $r['short_name'], $r['title'], $r['edition_label'], $r['variant'], $r['note'], $r['added_username'], $r['created_at'],
    ]))."\r\n");
}
fclose($out);
exit;
