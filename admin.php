<?php
/* The admin panel is now part of settings.php; old links and bookmarks land on the matching section. */
require_once __DIR__ . '/boot.php';

$to = isset($_GET['sys']) ? 'catalogue&sys='.(int)$_GET['sys'] : 'general';
header('Location: '.BASE_URL.'/settings.php?s='.$to);
exit;
