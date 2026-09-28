<?php
require_once __DIR__ . '/../config.php';
requireAdmin();

// Downloads one language file as-is (the code is whitelisted, never used as a raw path)
$code = (string)($_GET['code'] ?? '');
if (!isset(availableLanguages()[$code])) { http_response_code(404); exit(tRaw('settings.err_unknown_lang')); }

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$code.'.json"');
readfile(LANG_DIR . $code . '.json');
