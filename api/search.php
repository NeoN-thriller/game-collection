<?php
// Search across all systems (the search box in the header).
// GET q=<text>, owned=1 for owned games only. Matches titles and edition names;
// systems the user has hidden are left out. Results follow the user's system order.
require_once __DIR__ . '/../boot.php';
$user = requireAuth();
session_write_close();   // release the session lock: quick searches don't queue behind each other

const SEARCH_LIMIT = 50;

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) jsonOut(['ok' => true, 'results' => [], 'more' => false]);

// Every word must appear in the title, the edition name or the edition group's name
$words = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [$q], 0, 6);
$where = [];
$args  = [$user['id'], $user['id']];
foreach ($words as $w) {
    $like = '%' . addcslashes($w, '\\%_') . '%';
    $where[] = "(g.title LIKE ? OR g.edition_label LIKE ? OR gg.title LIKE ?)";
    array_push($args, $like, $like, $like);
}
if (!empty($_GET['owned'])) $where[] = "ce.owned_copies > 0";

$st = db()->prepare("
    SELECT g.id, g.title, g.edition_label, gg.title AS group_title,
           s.id AS system_id, s.name AS system_name, s.short_name,
           COALESCE(ce.owned_copies, 0) AS owned_copies, COALESCE(ce.wishlist, 0) AS wishlist
    FROM games g
    JOIN systems s ON s.id = g.system_id AND s.active = 1
    LEFT JOIN user_system_prefs usp ON usp.system_id = s.id AND usp.user_id = ?
    LEFT JOIN game_groups gg ON gg.id = g.group_id
    LEFT JOIN (
        SELECT game_id, SUM(owned) AS owned_copies, MAX(wishlist) AS wishlist
        FROM collection_entries WHERE user_id = ? GROUP BY game_id
    ) ce ON ce.game_id = g.id
    WHERE g.active = 1 AND COALESCE(usp.visible, 1) = 1 AND " . implode(' AND ', $where) . "
    ORDER BY COALESCE(usp.user_sort_order, s.sort_order), s.id, g.sort_title
    LIMIT " . (SEARCH_LIMIT + 1)
);
$st->execute($args);
$rows = $st->fetchAll();

$results = array_map(fn($r) => [
    'id'           => (int)$r['id'],
    'title'        => $r['title'],
    'edition'      => (string)($r['edition_label'] ?? ''),
    'system_id'    => (int)$r['system_id'],
    'system_name'  => $r['system_name'],
    'system_short' => $r['short_name'],
    'owned'        => (int)$r['owned_copies'],
    'wishlist'     => (int)$r['wishlist'] === 1,
], array_slice($rows, 0, SEARCH_LIMIT));

jsonOut(['ok' => true, 'results' => $results, 'more' => count($rows) > SEARCH_LIMIT]);
