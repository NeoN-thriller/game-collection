<?php
require_once __DIR__ . '/../boot.php';
requireAuth();
$sysId = (int)($_GET['system_id'] ?? 0);
$st = db()->prepare("
    SELECT id, title, sort_title, sort_order, default_image,
           pc_id, pc_link,
           cib_price,   cib_price_updated_at,
           loose_price, loose_price_updated_at,
           new_price,   new_price_updated_at,
           group_id, edition_label, edition_sort
    FROM games WHERE system_id=? AND active=1 ORDER BY sort_title
");
$st->execute([$sysId]);
$games = $st->fetchAll();

// Edition groups of this system. The client shows a group only when at least two of its games are active.
$gr = db()->prepare("SELECT id, title, sort_title, main_game_id FROM game_groups WHERE system_id=?");
$gr->execute([$sysId]);

jsonOut(['ok'=>true, 'games'=>$games, 'groups'=>$gr->fetchAll()]);
