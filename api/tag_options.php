<?php
require_once __DIR__ . '/../boot.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $tags = $body['tags'] ?? [];
    // Delete all existing and reinsert in order
    db()->prepare("DELETE FROM user_tag_options WHERE user_id=?")->execute([$user['id']]);
    $ins = db()->prepare("INSERT INTO user_tag_options (user_id, label, sort_order) VALUES (?,?,?)");
    $n = 0; $seen = [];
    foreach ((array)$tags as $tag) {
        $tag = mb_substr(trim((string)$tag), 0, 100);
        if ($tag === '' || isset($seen[mb_strtolower($tag)])) continue;   // a tag can only be in the list once
        $seen[mb_strtolower($tag)] = true;
        $ins->execute([$user['id'], $tag, $n++]);
    }
    jsonOut(['ok'=>true]);
} else {
    $st = db()->prepare("SELECT label FROM user_tag_options WHERE user_id=? ORDER BY sort_order");
    $st->execute([$user['id']]);
    jsonOut(['ok'=>true, 'tags'=>$st->fetchAll(PDO::FETCH_COLUMN)]);
}
