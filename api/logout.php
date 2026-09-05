<?php
require_once __DIR__ . '/../config.php';

// Clear remember token
$token = $_COOKIE['remember_token'] ?? '';
if ($token) {
    db()->prepare("DELETE FROM remember_tokens WHERE token=?")->execute([$token]);
    clearRememberCookie();
}
session_destroy();
header('Location: '.BASE_URL.'/index.php');
exit;
