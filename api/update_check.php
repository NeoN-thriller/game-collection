<?php
// Background refresh of the update check (updateRefreshScript()): asks GitHub only when the cached answer is a day old.
require_once __DIR__ . '/../boot.php';
$user = auth();
if (!$user || $user['role'] !== 'admin') jsonOut(['ok' => false], 403);
session_write_close();
$cache = updateCache();
if (updateStale($cache)) $cache = updateCheckNow();
jsonOut(['ok' => (bool)($cache['ok'] ?? false), 'available' => updateAvailable($cache)['version'] ?? null]);
