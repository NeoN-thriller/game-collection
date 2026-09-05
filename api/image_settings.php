<?php
require_once __DIR__ . '/../config.php';
requireAdmin();

$cfgFile = __DIR__.'/../uploads/img_settings.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $cfg  = [
        'max_width'  => max(200, min(4000, (int)($body['max_width']  ?? 1200))),
        'max_height' => max(200, min(4000, (int)($body['max_height'] ?? 1200))),
        'quality'    => max(10,  min(100,  (int)($body['quality']    ?? 80))),
    ];
    if (!is_dir(dirname($cfgFile))) mkdir(dirname($cfgFile), 0755, true);
    file_put_contents($cfgFile, json_encode($cfg));
    jsonOut(['ok'=>true]);
} else {
    $cfg = file_exists($cfgFile) ? json_decode(file_get_contents($cfgFile), true) : [];
    jsonOut(['ok'=>true, 'settings'=>[
        'max_width'  => (int)($cfg['max_width']  ?? 1200),
        'max_height' => (int)($cfg['max_height'] ?? 1200),
        'quality'    => (int)($cfg['quality']    ?? 80),
    ]]);
}
