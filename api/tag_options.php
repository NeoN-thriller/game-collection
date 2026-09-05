<?php
require_once __DIR__ . '/../config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $tags = $body['tags'] ?? [];
    // Delete all existing and reinsert in order
    db()->prepare("DELETE FROM user_tag_options WHERE user_id=?")->execute([$user['id']]);
    $ins = db()->prepare("INSERT INTO user_tag_options (user_id, label, sort_order) VALUES (?,?,?)");
    foreach ($tags as $i => $tag) {
        $tag = trim($tag);
        if ($tag) $ins->execute([$user['id'], $tag, $i]);
    }
    jsonOut(['ok'=>true]);
} else {
    $st = db()->prepare("SELECT label FROM user_tag_options WHERE user_id=? ORDER BY sort_order");
    $st->execute([$user['id']]);
    jsonOut(['ok'=>true, 'tags'=>$st->fetchAll(PDO::FETCH_COLUMN)]);
}
