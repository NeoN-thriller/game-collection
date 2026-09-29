<?php
require_once __DIR__ . '/../boot.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true);
    $prefs = $body['prefs'] ?? [];

    $ins = db()->prepare("
        INSERT INTO user_system_prefs (user_id, system_id, visible, user_sort_order, count_for_totals)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            visible          = VALUES(visible),
            user_sort_order  = VALUES(user_sort_order),
            count_for_totals = VALUES(count_for_totals)
    ");
    foreach ($prefs as $p) {
        $ins->execute([
            $user['id'],
            (int)$p['system_id'],
            $p['visible']          ? 1 : 0,
            (int)($p['sort_order'] ?? 0),
            isset($p['count_for_totals']) ? ($p['count_for_totals'] ? 1 : 0) : 1,
        ]);
    }
    jsonOut(['ok'=>true]);
} else {
    $st = db()->prepare("
        SELECT s.*,
               COALESCE(usp.visible,          1) AS visible,
               COALESCE(usp.user_sort_order, s.sort_order) AS user_sort_order,
               COALESCE(usp.count_for_totals, 1) AS count_for_totals
        FROM systems s
        LEFT JOIN user_system_prefs usp ON usp.system_id = s.id AND usp.user_id = ?
        WHERE s.active = 1
        ORDER BY COALESCE(usp.user_sort_order, s.sort_order)
    ");
    $st->execute([$user['id']]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['visible']          = (bool)$r['visible'];
        $r['count_for_totals'] = (bool)$r['count_for_totals'];
    }
    jsonOut(['ok'=>true, 'systems'=>$rows]);
}
