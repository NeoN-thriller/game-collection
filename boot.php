<?php
// Loaded first by every page and API endpoint.
// No config.php yet → the site isn't installed: send the visitor to install.php.
if (!is_file(__DIR__ . '/config.php')) {
    $dir = dirname(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/'));
    if (basename($dir) === 'api') {
        http_response_code(503);
        header('Content-Type: application/json');
        exit('{"ok":false,"error":"Not installed yet: open install.php."}');
    }
    header('Location: ' . rtrim($dir, '/') . '/install.php');
    exit;
}
require_once __DIR__ . '/config.php';
