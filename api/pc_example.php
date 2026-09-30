<?php
/* Example CSV for the PriceCharting import (admins): the columns the import reads, filled with a few
   games from this site's own catalogue (their current ID, link and prices). The console column holds the
   site's system short names, which is what the import matches on, so importing the file unchanged
   matches existing games and changes nothing. */
require_once __DIR__ . '/../boot.php';
requireAdmin();

// Games that already have a PriceCharting ID first: they show every column filled in
$rows = db()->query("SELECT s.short_name, g.title, g.pc_id, g.pc_link, g.loose_price, g.cib_price, g.new_price
                     FROM games g JOIN systems s ON s.id = g.system_id
                     WHERE s.active = 1 AND g.active = 1
                     ORDER BY (g.pc_id IS NULL OR g.pc_id = ''), s.sort_order, g.sort_title
                     LIMIT 3")->fetchAll();
if (!$rows) {
    // Empty catalogue: one placeholder row for the first system (the preview shows it as a new game)
    $short = db()->query("SELECT short_name FROM systems WHERE active = 1 ORDER BY sort_order LIMIT 1")->fetchColumn();
    $rows = [['short_name' => $short ?: 'N64', 'title' => tRaw('pc.example_title'), 'pc_id' => '', 'pc_link' => '',
              'loose_price' => '', 'cib_price' => '', 'new_price' => '']];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="pricecharting-example.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fputcsv($out, ['console', 'name', 'data-product', 'link', 'loose', 'cib', 'new', 'coverArt', 'coverArtBase64'], ',', '"', '');
foreach ($rows as $r) {
    fputcsv($out, [$r['short_name'], $r['title'], $r['pc_id'] ?? '', $r['pc_link'] ?? '',
                   $r['loose_price'] ?? '', $r['cib_price'] ?? '', $r['new_price'] ?? '', '', ''], ',', '"', '');
}
fclose($out);
exit;
