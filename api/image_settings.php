<?php
require_once __DIR__ . '/../boot.php';
requireAdmin();

// Stored in app_settings (img_max_width / img_max_height / img_quality)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    setSetting('img_max_width',  (string)max(200, min(4000, (int)($body['max_width']  ?? 1200))));
    setSetting('img_max_height', (string)max(200, min(4000, (int)($body['max_height'] ?? 1200))));
    setSetting('img_quality',    (string)max(10,  min(100,  (int)($body['quality']    ?? 80))));
    jsonOut(['ok'=>true]);
}
jsonOut(['ok'=>true, 'settings'=>imageSettings()]);
