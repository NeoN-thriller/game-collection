<?php
// Activity log as CSV (admins): the filters of the log page (see activityFiltersFromRequest()),
// or everything with all=1. At most 50,000 rows, newest first. Cells that a spreadsheet would run
// as a formula (starting with = + - @) get a ' in front.
require_once __DIR__ . '/../boot.php';
$admin = requireAdmin();
session_write_close();
@set_time_limit(300);

$f = !empty($_GET['all']) ? ['period' => 'all'] : activityFiltersFromRequest($_GET);
unset($f['before']);
$f['viewer'] = (int)$admin['id'];
[$where, $args] = activityWhere($f);
$rows = activityFetch($where, $args, 50000);

$cell = function (string $v): string {
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) $v = "'".$v;
    return '"'.str_replace('"', '""', $v).'"';
};

while (ob_get_level()) ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="activity_log_'.date('Ymd').'.csv"');
header('Cache-Control: no-cache, no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 mark, so Excel reads accents right
$head = ['log.csv_id', 'log.csv_time', 'log.csv_user', 'log.csv_type', 'log.csv_admin', 'log.csv_summary', 'log.target_user', 'log.csv_details', 'log.csv_ip'];
fwrite($out, implode(',', array_map(fn($k) => $cell(tRaw($k)), $head))."\r\n");
// In chunks, so a big log doesn't all sit in memory as rendered rows
foreach (array_chunk($rows, 500) as $chunk) {
    foreach (activityRowsOut($chunk, $admin, false) as $r) {
        $details = implode('; ', array_map(fn($c) => trim($c['label'].': '.($c['from'] !== null ? $c['from'].' → ' : '').$c['to'], ': '), $r['changes']));
        if ($r['note'] !== '') $details .= ($details !== '' ? '; ' : '').$r['note'];
        fwrite($out, implode(',', array_map($cell, [
            (string)$r['id'], date('Y-m-d H:i:s', $r['ts']), $r['user'], tRaw('log.cat.'.$r['category']),
            tRaw($r['admin'] ? 'log.yes' : 'log.no'), $r['summary'], $r['target'], $details, $r['ip'],
        ]))."\r\n");
    }
}
fclose($out);
exit;
