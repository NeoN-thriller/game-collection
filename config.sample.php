<?php
// ─────────────────────────────────────────
//  CONFIGURATION
//  The easiest way: open install.php in the browser, it writes config.php for you.
//  By hand: copy this file to config.php and fill in the values.
// ─────────────────────────────────────────

define('BASE_URL',   'https://games.example.com');   // No trailing slash. Change to /games if needed
define('DB_HOST',    'localhost');
define('DB_NAME',    'game_collection');
define('DB_USER',    'game_user');
define('DB_PASS',    'a-strong-password');
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

// Test sites only: also run the development migrations in migrations/dev/ (Settings › Updates).
// Leave this out on the live site; it gets one combined migration per release.
// define('DEV_MIGRATIONS', true);

// ─────────────────────────────────────────
//  DO NOT EDIT BELOW THIS LINE
// ─────────────────────────────────────────
require_once __DIR__ . '/core.php';
