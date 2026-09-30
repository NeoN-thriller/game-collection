<?php
/* SETTINGS — POST handlers for the admin sections. Included by settings.php after verifyCsrf()
   when $action is in CP_ADMIN_ACTIONS; sets $msg (and $msgErr on failure). */
if (!defined('IN_SETTINGS')) exit;
$admin = requireAdmin();

if ($action === 'gen_invite') {
    $code = bin2hex(random_bytes(16));
    db()->prepare("INSERT INTO invite_codes (code, created_by) VALUES (?,?)")->execute([$code, $admin['id']]);
    $msg = tRaw('admin.msg_invite', ['code' => $code]);
}

if ($action === 'user_status') {
    $uid    = (int)$_POST['user_id'];
    $status = in_array($_POST['status'], ['active','inactive','banned']) ? $_POST['status'] : 'inactive';
    if ($uid !== $admin['id']) {
        db()->prepare("UPDATE users SET status=? WHERE id=?")->execute([$status, $uid]);
        $msg = tRaw('admin.msg_user');
    } else { $msg = tRaw('admin.msg_own_status'); $msgErr = true; }
}

if ($action === 'reset_password') {
    $uid  = (int)$_POST['user_id'];
    $pass = $_POST['new_password'] ?? '';
    if (strlen($pass) < MIN_PASSWORD_LENGTH) { $msg = tRaw('common.err_password_length', ['n' => MIN_PASSWORD_LENGTH]); $msgErr = true; }
    else {
        setPassword($uid, $pass);
        $st = db()->prepare("SELECT username FROM users WHERE id=?"); $st->execute([$uid]);
        clearFailures(['u:'.strtolower((string)$st->fetchColumn()), 'pw:'.$uid]);
        $msg = tRaw('admin.msg_pw_reset');
    }
}

if ($action === 'unlock') {
    $key = (string)($_POST['attempt_key'] ?? '');
    db()->prepare("DELETE FROM login_attempts WHERE attempt_key=?")->execute([$key]);
    $msg = tRaw('admin.msg_unlocked');
}

if ($action === 'unlock_all') {
    db()->exec("DELETE FROM login_attempts");
    $msg = tRaw('admin.msg_unlocked_all');
}

if ($action === 'add_game') {
    $sysId = (int)$_POST['system_id'];
    $title = trim($_POST['title'] ?? '');
    if ($title && $sysId) {
        $sort = preg_replace('/^(the |a |an )/i', '', strtolower($title));
        // Get next sort_order
        $st = db()->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM games WHERE system_id=?");
        $st->execute([$sysId]); $so = $st->fetchColumn();
        db()->prepare("INSERT INTO games (system_id, title, sort_title, sort_order) VALUES (?,?,?,?)")
            ->execute([$sysId, $title, $sort, $so]);
        $msg = tRaw('admin.msg_game_added');
    }
}

if ($action === 'toggle_game') {
    $gid = (int)$_POST['game_id'];
    db()->prepare("UPDATE games SET active = 1-active WHERE id=?")->execute([$gid]);
    $msg = tRaw('admin.msg_game_updated');
}

if ($action === 'set_system_icon') {
    $sid = (int)$_POST['system_id'];
    if (!empty($_FILES['icon_image']) && $_FILES['icon_image']['error']===UPLOAD_ERR_OK) {
        $file  = $_FILES['icon_image'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (in_array($mime, ALLOWED_TYPES) || $mime === 'image/svg+xml') {
            $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg'];
            $ext    = $extMap[$mime] ?? 'png';
            $iconDir = dirname(__DIR__) . '/uploads/icons/';
            if (!is_dir($iconDir)) mkdir($iconDir, 0755, true);
            $fn = 'sys_'.$sid.'_'.uniqid().'.'.$ext;
            move_uploaded_file($file['tmp_name'], $iconDir.$fn);
            db()->prepare("UPDATE systems SET icon_image=? WHERE id=?")->execute([$fn, $sid]);
            $msg = tRaw('admin.msg_icon');
        }
    }
}

if ($action === 'set_default_image') {
    $gid = (int)$_POST['game_id'];
    if (!empty($_FILES['default_image']) && $_FILES['default_image']['error']===UPLOAD_ERR_OK) {
        $file  = $_FILES['default_image'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (in_array($mime, ALLOWED_TYPES)) {
            $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime];
            $fn   = 'game_'.$gid.'_'.uniqid().'.'.$ext;
            move_uploaded_file($file['tmp_name'], DEFAULTS_DIR.$fn);
            db()->prepare("UPDATE games SET default_image=? WHERE id=?")->execute([$fn, $gid]);
            $msg = tRaw('admin.msg_image');
        }
    }
}

if ($action === 'add_system') {
    $name  = trim($_POST['name']       ?? '');
    $short = strtoupper(trim($_POST['short_name'] ?? ''));
    $region = in_array($_POST['region'] ?? '', REGIONS, true) ? $_POST['region'] : setting('default_region');
    if ($name && $short) {
        $st = db()->query("SELECT COALESCE(MAX(sort_order),0)+10 FROM systems"); $so = $st->fetchColumn();
        db()->prepare("INSERT INTO systems (name, short_name, region, sort_order) VALUES (?,?,?,?)")
            ->execute([$name, $short, $region, $so]);
        // Default grading profile by name (e.g. "Nintendo 64" → cartridge in box with inner tray)
        $assigned = applySystemPatterns(null, true, (int)db()->lastInsertId());
        $msg = tRaw('admin.msg_system_added').($assigned ? ' '.tRaw('admin.msg_profile', ['profile' => explode(' → ', $assigned[0])[1]]) : '');
    }
}

if ($action === 'set_system_region') {
    $region = (string)($_POST['region'] ?? '');
    if (in_array($region, REGIONS, true)) {
        db()->prepare("UPDATE systems SET region=? WHERE id=?")->execute([$region, (int)$_POST['system_id']]);
        $msg = tRaw('admin.msg_region');
    } else { $msg = tRaw('common.error'); $msgErr = true; }
}

if ($action === 'save_site_settings') {
    $in  = fn(string $k) => trim((string)($_POST[$k] ?? ''));
    $raw = fn(string $k) => (string)($_POST[$k] ?? '');   // separators may be a space
    $errs = [];
    $name = $in('site_name');
    if ($name === '' || mb_strlen($name) > 60) $errs[] = tRaw('admin.site.err_name');
    $sym = $in('currency_symbol');
    if ($sym === '' || mb_strlen($sym) > 5) $errs[] = tRaw('admin.site.err_symbol');
    $dec = $raw('decimal_sep'); $thou = $raw('thousands_sep');
    if (!in_array($dec, DECIMAL_SEPS, true) || !in_array($thou, THOUSANDS_SEPS, true) || $dec === $thou) $errs[] = tRaw('admin.site.err_seps');
    $colors = json_decode($raw('site_name_colors'), true);
    $colors = is_array($colors) ? array_values(array_map(fn($c) => in_array($c, LOGO_COLORS, true) ? $c : 'header-logo', array_slice($colors, 0, 20))) : [];
    $pick = fn(string $k, array $allowed) => in_array($in($k), $allowed, true) ? $in($k) : SITE_DEFAULTS[$k];
    // One option per line, trimmed, no duplicates, max 30 options of 100 characters
    $lines = fn(string $k) => implode("\n", array_slice(array_values(array_unique(array_filter(
                 array_map(fn($s) => mb_substr(trim($s), 0, 100), preg_split('/\R/', $raw($k))), 'strlen'))), 0, 30));
    if ($errs) { $msg = implode(' ', $errs); $msgErr = true; }
    else {
        foreach ([
            'site_name'         => $name,
            'site_name_style'   => $pick('site_name_style', ['single', 'last', 'custom']),
            'site_name_colors'  => json_encode($colors),
            'currency_symbol'   => $sym,
            'currency_position' => $pick('currency_position', ['before', 'after']),
            'currency_space'    => $in('currency_space') === '1' ? '1' : '0',
            'decimal_sep'       => $dec,
            'thousands_sep'     => $thou,
            'default_region'    => $pick('default_region', REGIONS),
            'date_format'       => $pick('date_format', DATE_FORMATS),
            'default_grading'   => $pick('default_grading', ['simple', 'points', 'both']),
            'default_value_type'=> $pick('default_value_type', VALUE_TYPES),
            'timezone'          => $pick('timezone', timezone_identifiers_list()),
            'defaults_completeness' => $lines('defaults_completeness'),
            'defaults_played'   => $lines('defaults_played'),
        ] as $k => $v) setSetting($k, $v);
        $msg = tRaw('admin.site.saved');
    }
}

if ($action === 'upload_language') {
    $f = $_FILES['lang_file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) { $msg = tRaw('admin.lang.err_upload'); $msgErr = true; }
    else {
        [$data, $err] = validateLangFile((string)file_get_contents($f['tmp_name']));
        if ($err) { $msg = $err; $msgErr = true; }
        elseif (!is_dir(LANG_DIR) || @file_put_contents(LANG_DIR.$data['_meta']['code'].'.json',
                    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n") === false) {
            $msg = tRaw('admin.lang.not_writable'); $msgErr = true;
        } else {
            $msg = tRaw('admin.lang.uploaded', ['name' => $data['_meta']['name'], 'n' => fmtNum(count($data) - 1)]);
        }
    }
}

if ($action === 'set_default_language') {
    $code = (string)($_POST['language'] ?? '');
    if (isset(availableLanguages()[$code])) {
        setSetting('default_language', $code);
        $msg = tRaw('admin.msg_language', ['name' => availableLanguages()[$code]['name']]);
    } else { $msg = tRaw('common.error'); $msgErr = true; }
}

if ($action === 'set_default_theme') {
    $theme = (string)($_POST['theme'] ?? '');
    if (isset(availableThemes()[$theme])) {
        setAppSetting('default_theme', $theme);
        $msg = tRaw('admin.msg_theme', ['name' => availableThemes()[$theme]['name']]);
    } else { $msg = tRaw('settings.err_unknown_theme'); $msgErr = true; }
}

if ($action === 'delete_invite') {
    $id = (int)$_POST['invite_id'];
    db()->prepare("DELETE FROM invite_codes WHERE id=? AND used_by IS NULL")->execute([$id]);
    $msg = tRaw('admin.msg_invite_deleted');
}
