<?php
require_once __DIR__ . '/../config.php';
requireAdmin();

$body     = json_decode(file_get_contents('php://input'), true);
$systemId = (int)($body['system_id'] ?? 0);
$titles   = $body['titles'] ?? [];

if (!$systemId || empty($titles)) jsonOut(['ok'=>false,'error'=>'Missing system or titles'], 400);

// Fetch existing titles for this system (lowercase for comparison)
$st = db()->prepare("SELECT LOWER(title) AS t FROM games WHERE system_id=?");
$st->execute([$systemId]);
$existing = array_flip($st->fetchAll(PDO::FETCH_COLUMN));

$items = [];
foreach ($titles as $title) {
    $title = trim($title);
    if (!$title) continue;
    $items[] = [
        'title'  => $title,
        'status' => isset($existing[strtolower($title)]) ? 'duplicate' : 'new',
    ];
}

jsonOut(['ok'=>true, 'items'=>$items]);
