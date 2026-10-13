<?php
require_once __DIR__ . '/../boot.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true);
    $sites = $body['sites'] ?? [];
    // Validate each site has label and url_template
    $clean = [];
    foreach ($sites as $s) {
        $label = trim($s['label'] ?? '');
        $url   = trim($s['url_template'] ?? '');
        if ($label && $url) $clean[] = ['label'=>$label,'url_template'=>$url];
    }
    db()->prepare("UPDATE users SET auction_sites=? WHERE id=?")
        ->execute([json_encode($clean), $user['id']]);
    jsonOut(['ok'=>true]);
} else {
    $raw   = $user['auction_sites'] ?? null;
    $sites = $raw ? json_decode($raw, true) : [];
    jsonOut(['ok'=>true, 'sites'=>$sites ?: []]);
}
