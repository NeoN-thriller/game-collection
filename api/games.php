<?php
require_once __DIR__ . '/../boot.php';
requireAuth();
$sysId = (int)($_GET['system_id'] ?? 0);
$st = db()->prepare("
    SELECT id, title, sort_title, sort_order, default_image,
           pc_id, pc_link,
           cib_price,   cib_price_updated_at,
           loose_price, loose_price_updated_at,
           new_price,   new_price_updated_at
    FROM games WHERE system_id=? AND active=1 ORDER BY sort_title
");
$st->execute([$sysId]);
jsonOut(['ok'=>true,'games'=>$st->fetchAll()]);
