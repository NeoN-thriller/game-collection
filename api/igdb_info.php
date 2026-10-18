<?php
// The drawer's "Game info" (assets/js/igdb-drawer.js): GET game_id → {ok, info|null, manage|null, cover|null}.
// info: see igdbInfo() in igdb.php · manage: admins only, the game in Settings › IGDB ("Change match")
// cover: admins only, {cover_url, cover_uploaded, cover_igdb, cover_source, default} for the cover switch.
require_once __DIR__ . '/../boot.php';
requireAuth();
session_write_close();
$gameId = (int)($_GET['game_id'] ?? 0);
try {
    $info = igdbInfo($gameId);
} catch (PDOException $e) {
    $info = null;   // the IGDB table doesn't exist yet (migration not run): no game info
}
$manage = $cover = null;
if (isAdmin()) {
    $st = db()->prepare("SELECT id, system_id, title, default_image FROM games WHERE id = ?");
    $st->execute([$gameId]);
    if (($g = $st->fetch()) && !in_array((int)$g['system_id'], igdbSkipped(), true)) {   // "Don't match" systems: no IGDB tools
        $manage = BASE_URL . '/settings.php?' . http_build_query(['s' => 'igdb', 'sys' => (int)$g['system_id'], 'q' => $g['title'], 'tab' => 'all']);
        // The cover switch (admins): both covers, this game's choice and the site default
        $c = withCovers([$g])[0];
        if ($c['cover_uploaded'] || $c['cover_igdb'])
            $cover = array_intersect_key($c, array_flip(['cover_url', 'cover_uploaded', 'cover_igdb', 'cover_source'])) + ['default' => coverDefaultSource()];
    }
}
jsonOut(['ok' => true, 'info' => $info, 'manage' => $manage, 'cover' => $cover]);
