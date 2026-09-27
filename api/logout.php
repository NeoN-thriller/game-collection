<?php
require_once __DIR__ . '/../config.php';

// Logout must be a POST with a CSRF token (checked in config.php) so other sites can't log users out.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: '.BASE_URL.'/dashboard.php');
    exit;
}

// Clear remember token
$token = $_COOKIE['remember_token'] ?? '';
if ($token && is_string($token)) {
    db()->prepare("DELETE FROM remember_tokens WHERE token=?")->execute([hash('sha256', $token)]);
}
clearRememberCookie();
$_SESSION = [];
session_destroy();
header('Location: '.BASE_URL.'/index.php');
exit;
