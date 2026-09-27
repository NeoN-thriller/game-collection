<?php
// ─────────────────────────────────────────
//  CONFIGURATION — edit these values
// ─────────────────────────────────────────

define('BASE_URL',   'https://someurl.com');   // No trailing slash. Change to /games if needed
define('DB_HOST',    'dbhost');
define('DB_NAME',    'db_name');
define('DB_USER',    'db_user');
define('DB_PASS',    'db_pass');
define('DB_CHARSET', 'utf8mb4');

// true on the live site (has an SSL certificate): redirects http:// to https:// and sends HSTS.
// false for local/test servers that only run on plain http://
define('FORCE_HTTPS', false);

// Session name (change this to something unique for your site)
define('SESSION_NAME', 'gcollect_session');

// Upload settings
define('UPLOAD_DIR',      __DIR__ . '/uploads/users/');
define('DEFAULTS_DIR',    __DIR__ . '/uploads/defaults/');
define('UPLOAD_URL',      BASE_URL . '/uploads/users/');
define('DEFAULTS_URL',    BASE_URL . '/uploads/defaults/');
define('MAX_FILE_SIZE',   8 * 1024 * 1024); // 8MB per image
define('ALLOWED_TYPES',   ['image/jpeg','image/png','image/gif','image/webp']);

// ─────────────────────────────────────────
//  DO NOT EDIT BELOW THIS LINE
// ─────────────────────────────────────────
require_once __DIR__ . '/core.php';
