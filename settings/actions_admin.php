<?php
/* SETTINGS — POST handlers for the admin sections. Included by settings.php after verifyCsrf()
   when $action is in CP_ADMIN_ACTIONS; sets $msg (and $msgErr on failure). */
if (!defined('IN_SETTINGS')) exit;
$admin = requireAdmin();

// Activity log: everything here is an admin action
$logA = fn(string $cat, string $act, array $o = []) => logEvent($cat, $act, ['admin' => true, 'actor' => $admin] + $o);
/** ['id', 'name'] of a user for the log's "Target user", or null. */
$logTarget = function (int $id): ?array {
    $st = db()->prepare("SELECT id, username FROM users WHERE id=?");
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ? ['id' => (int)$u['id'], 'name' => $u['username']] : null;
};
/** Title + system of a game, for catalogue events. */
$logGame = fn(int $id) => activityGame($id);

if ($action === 'gen_invite') {
    $code = bin2hex(random_bytes(16));
    db()->prepare("INSERT INTO invite_codes (code, created_by) VALUES (?,?)")->execute([$code, $admin['id']]);
    $logA('accounts', 'invite.create', ['changes' => [activityChange('invite_code', null, activityMaskCode($code))]]);
    $msg = tRaw('admin.msg_invite', ['code' => $code]);
}

if ($action === 'user_status') {
    $uid    = (int)$_POST['user_id'];
    $status = in_array($_POST['status'], ['active','inactive','banned']) ? $_POST['status'] : 'inactive';
    if ($uid !== $admin['id']) {
        $old = db()->prepare("SELECT status FROM users WHERE id=?"); $old->execute([$uid]); $old = (string)$old->fetchColumn();
        db()->prepare("UPDATE users SET status=? WHERE id=?")->execute([$status, $uid]);
        if ($old !== $status) $logA('accounts', 'user.status', ['target' => $logTarget($uid), 'params' => ['status' => $status],
            'changes' => [activityChange('status', $old, $status, 'enum')]]);
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
        $logA('accounts', 'user.password_reset', ['target' => $logTarget($uid), 'changes' => [activityChange('password', null, '__hidden')]]);
        $msg = tRaw('admin.msg_pw_reset');
    }
}

if ($action === 'unlock') {
    $key = (string)($_POST['attempt_key'] ?? '');
    $del = db()->prepare("DELETE FROM login_attempts WHERE attempt_key=?");
    $del->execute([$key]);
    if ($del->rowCount()) {
        // u:<username> · pw:<user id> · ip:<address> (the address is masked)
        $target = null; $what = '';
        if (str_starts_with($key, 'u:')) {
            $st = db()->prepare("SELECT id FROM users WHERE LOWER(username)=?"); $st->execute([substr($key, 2)]);
            $target = ($id = (int)$st->fetchColumn()) ? $logTarget($id) : null;
            if (!$target) $what = substr($key, 2);
        } elseif (str_starts_with($key, 'pw:')) {
            $target = $logTarget((int)substr($key, 3));
        } elseif (str_starts_with($key, 'ip:')) {
            $what = activityMaskIp(substr($key, 3));
        }
        $logA('accounts', 'user.unlock', ['target' => $target, 'params' => ['v' => $target ? 'user' : 'other', 'what' => $what],
            'changes' => [activityChange('lockout', null, '__cleared')]]);
    }
    $msg = tRaw('admin.msg_unlocked');
}

if ($action === 'unlock_all') {
    $n = db()->exec("DELETE FROM login_attempts");
    if ($n) $logA('accounts', 'user.unlock_all', ['params' => ['n' => (int)$n]]);
    $msg = tRaw('admin.msg_unlocked_all');
}

/** Game columns the add / edit forms set besides the title: column => [log key, log type]. */
$gamePriceFields = [
    'external_id' => ['external_id', 'text'], 'price_link' => ['price_link', 'text'],
    'loose_price' => ['loose_price', 'money'], 'cib_price' => ['cib_price', 'money'], 'new_price' => ['new_price', 'money'],
];
/**
 * Price source ID, link and prices from the add / edit game form, checked. Empty fields → null.
 * @return array|string the columns, or an error message
 */
$gamePriceInput = function (int $gameId = 0): array|string {
    $externalId = trim((string)($_POST['external_id'] ?? ''));
    $link = trim((string)($_POST['price_link'] ?? ''));
    if ($externalId !== '' && !preg_match('/^[A-Za-z0-9-]{1,20}$/', $externalId)) return tRaw('admin.games.err_external_id');
    if ($link !== '' && (!priceLinkSafe($link) || mb_strlen($link) > 255)) return tRaw('admin.games.err_link');
    if ($externalId !== '') {
        // The price import finds games by this ID, so two games can't share one
        $st = db()->prepare("SELECT g.title, s.name FROM games g JOIN systems s ON s.id=g.system_id WHERE g.external_id=? AND g.id<>? LIMIT 1");
        $st->execute([$externalId, $gameId]);
        if ($dupe = $st->fetch()) return tRaw('admin.games.err_external_dupe', ['id' => $externalId, 'title' => $dupe['title'], 'system' => $dupe['name']]);
    }
    $out = ['external_id' => $externalId !== '' ? $externalId : null, 'price_link' => $link !== '' ? $link : null];
    foreach (['loose_price', 'cib_price', 'new_price'] as $col) {
        $v = str_replace([' ', ','], ['', '.'], trim((string)($_POST[$col] ?? '')));
        if ($v !== '' && !preg_match('/^\d{1,6}(\.\d{1,2})?$/', $v)) return tRaw('admin.games.err_price');
        $out[$col] = $v !== '' ? $v : null;
    }
    return $out;
};
$sortTitle = fn(string $title) => preg_replace('/^(the |a |an )/i', '', strtolower($title));

if ($action === 'add_game') {
    $sysId = (int)$_POST['system_id'];
    $title = trim($_POST['title'] ?? '');
    $pc    = $gamePriceInput();
    if (is_string($pc)) { $msg = $pc; $msgErr = true; }
    elseif ($title && $sysId) {
        // Get next sort_order
        $st = db()->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM games WHERE system_id=?");
        $st->execute([$sysId]); $so = $st->fetchColumn();
        db()->prepare("INSERT INTO games (system_id, title, sort_title, sort_order, external_id, price_link,
                           loose_price, loose_price_updated_at, cib_price, cib_price_updated_at, new_price, new_price_updated_at)
                       VALUES (?,?,?,?,?,?, ?,IF(? IS NULL,NULL,NOW()), ?,IF(? IS NULL,NULL,NOW()), ?,IF(? IS NULL,NULL,NOW()))")
            ->execute([$sysId, $title, $sortTitle($title), $so, $pc['external_id'], $pc['price_link'],
                       $pc['loose_price'], $pc['loose_price'], $pc['cib_price'], $pc['cib_price'], $pc['new_price'], $pc['new_price']]);
        $gid = (int)db()->lastInsertId();
        igdbMatchNew([$gid], 5.0);
        $logA('catalogue', 'game.add', ['subject' => ['game', $gid], 'params' => $logGame($gid),
            'changes' => activityDiff([], $pc, $gamePriceFields)]);
        $msg = tRaw('admin.msg_game_added');
    }
    elseif (!$title) { $msg = tRaw('admin.games.err_title'); $msgErr = true; }
}

if ($action === 'edit_game') {
    $gid   = (int)($_POST['game_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $st = db()->prepare("SELECT * FROM games WHERE id=?");
    $st->execute([$gid]);
    $old = $st->fetch();
    $pc  = $old ? $gamePriceInput($gid) : null;
    if (!$old)               { $msg = tRaw('admin.games.err_game'); $msgErr = true; }
    elseif (is_string($pc))  { $msg = $pc; $msgErr = true; }
    elseif ($title === '' || mb_strlen($title) > 255) { $msg = tRaw('admin.games.err_title'); $msgErr = true; }
    else {
        $new = ['title' => $title] + $pc;
        $changes = activityDiff($old, $new, ['title' => ['title', 'text']] + $gamePriceFields);
        if ($changes) {
            $sets = ['external_id=?', 'price_link=?', 'loose_price=?', 'cib_price=?', 'new_price=?'];
            $vals = [$pc['external_id'], $pc['price_link'], $pc['loose_price'], $pc['cib_price'], $pc['new_price']];
            // Sort title only with a new title (imported games have their own sort title)
            if ($title !== $old['title']) { array_push($sets, 'title=?', 'sort_title=?'); array_push($vals, $title, $sortTitle($title)); }
            // A changed price gets today's date (shown as "last updated" in the collection)
            $priceChanged = false;
            foreach (['loose_price', 'cib_price', 'new_price'] as $col) {
                $a = $old[$col] === null ? null : (float)$old[$col];
                $b = $pc[$col]  === null ? null : (float)$pc[$col];
                if ($a !== $b) { $sets[] = $col . '_updated_at=' . ($b === null ? 'NULL' : 'NOW()'); $sets[] = $col . '_prev=NULL'; $priceChanged = true; }
            }
            $vals[] = $gid;
            db()->prepare("UPDATE games SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
            if ($title !== $old['title']) igdbTitleChanged($gid);   // look it up again under the new name
            // Collection value over time: a 'price' point for everyone who owns it (nothing when their value is unchanged)
            if ($priceChanged) valueSnapshot(null, [(int)$old['system_id']], 'price');
            $logA('catalogue', 'game.edit', ['subject' => ['game', $gid], 'params' => $logGame($gid), 'changes' => $changes]);
        }
        $msg = tRaw('admin.msg_game_saved');
    }
}

if ($action === 'toggle_game') {
    $gid = (int)$_POST['game_id'];
    db()->prepare("UPDATE games SET active = 1-active WHERE id=?")->execute([$gid]);
    valueSnapshot(null, valueSystemsOfGames([$gid]), 'collection');   // hiding or showing a game changes its owners' value
    $st = db()->prepare("SELECT active FROM games WHERE id=?"); $st->execute([$gid]);
    $logA('catalogue', 'game.toggle', ['subject' => ['game', $gid], 'params' => $logGame($gid) + ['v' => $st->fetchColumn() ? 'show' : 'hide']]);
    $msg = tRaw('admin.msg_game_updated');
}

// System icons: icon_image is the one for dark themes, icon_image_light the one for light themes (systemIcon() picks)
$sysIconCol = fn() => ($_POST['variant'] ?? '') === 'light' ? 'icon_image_light' : 'icon_image';
$sysIconUnlink = function (?string $fn): void {   // an icon file that is no longer used (only sys_… files in uploads/icons/)
    if ($fn && preg_match('/^sys_\d+_[a-z0-9]+\.(jpg|png|gif|webp|svg)$/i', $fn)) @unlink(dirname(__DIR__) . '/uploads/icons/' . $fn);
};

if ($action === 'set_system_icon') {
    $sid = (int)$_POST['system_id'];
    $col = $sysIconCol();
    if (!empty($_FILES['icon_image']) && $_FILES['icon_image']['error']===UPLOAD_ERR_OK) {
        $file  = $_FILES['icon_image'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (in_array($mime, ALLOWED_TYPES) || $mime === 'image/svg+xml') {
            $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg'];
            $ext    = $extMap[$mime] ?? 'png';
            $iconDir = dirname(__DIR__) . '/uploads/icons/';
            if (!is_dir($iconDir)) mkdir($iconDir, 0755, true);
            $fn = 'sys_'.$sid.'_'.bin2hex(random_bytes(8)).'.'.$ext;
            if (!storeUploadedFile($file['tmp_name'], $iconDir.$fn)) { $msg = tRaw('api.upload_error'); $msgErr = true; return; }
            $st = db()->prepare("SELECT name, $col AS old FROM systems WHERE id=?"); $st->execute([$sid]); $sys = $st->fetch();
            db()->prepare("UPDATE systems SET $col=? WHERE id=?")->execute([$fn, $sid]);
            if ($sys) $sysIconUnlink($sys['old']);
            $logA('catalogue', 'system.icon', ['subject' => ['system', $sid], 'params' => ['name' => (string)($sys['name'] ?? '')]]);
            $msg = tRaw('admin.msg_icon');
        }
    }
}

if ($action === 'remove_system_icon') {
    $sid = (int)($_POST['system_id'] ?? 0);
    $col = $sysIconCol();
    $st = db()->prepare("SELECT name, $col AS old FROM systems WHERE id=?"); $st->execute([$sid]);
    if ($sys = $st->fetch()) {
        db()->prepare("UPDATE systems SET $col=NULL WHERE id=?")->execute([$sid]);
        $sysIconUnlink($sys['old']);
        $logA('catalogue', 'system.icon', ['subject' => ['system', $sid], 'params' => ['name' => (string)$sys['name']]]);
        $msg = tRaw('admin.msg_icon_removed');
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
            $fn   = 'game_'.$gid.'_'.bin2hex(random_bytes(8)).'.'.$ext;
            if (!storeUploadedFile($file['tmp_name'], DEFAULTS_DIR.$fn)) { $msg = tRaw('api.upload_error'); $msgErr = true; return; }
            db()->prepare("UPDATE games SET default_image=? WHERE id=?")->execute([$fn, $gid]);
            $logA('catalogue', 'game.image', ['subject' => ['game', $gid], 'params' => $logGame($gid)]);
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
        $newSys = (int)db()->lastInsertId();
        $logA('catalogue', 'system.add', ['subject' => ['system', $newSys], 'params' => ['name' => $name],
            'changes' => [activityChange('short_name', null, $short), activityChange('region', null, $region)]]);
        $assigned = applySystemPatterns(null, true, $newSys);
        $msg = tRaw('admin.msg_system_added').($assigned ? ' '.tRaw('admin.msg_profile', ['profile' => explode(' → ', $assigned[0])[1]]) : '');
    }
}

if ($action === 'set_system_region') {
    $region = (string)($_POST['region'] ?? '');
    if (in_array($region, REGIONS, true)) {
        $st = db()->prepare("SELECT name, region FROM systems WHERE id=?"); $st->execute([(int)$_POST['system_id']]); $sys = $st->fetch();
        db()->prepare("UPDATE systems SET region=? WHERE id=?")->execute([$region, (int)$_POST['system_id']]);
        if ($sys && $sys['region'] !== $region) $logA('catalogue', 'system.region', ['subject' => ['system', (int)$_POST['system_id']],
            'params' => ['name' => $sys['name']], 'changes' => [activityChange('region', $sys['region'], $region)]]);
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
        $before = siteSettings();
        $logChanges = [];
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
        ] as $k => $v) {
            if ((string)($before[$k] ?? '') !== (string)$v) {
                // Long values (colours, option lists) only as "changed"
                $long = in_array($k, ['site_name_colors', 'defaults_completeness', 'defaults_played'], true);
                $logChanges[] = activityChange('s_'.$k, $long ? null : $before[$k], $long ? '__changed' : $v);
            }
            setSetting($k, $v);
        }
        if ($logChanges) $logA('system', 'settings.save', ['changes' => $logChanges]);
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
            $logA('system', 'language.upload', ['params' => ['code' => $data['_meta']['code'], 'name' => $data['_meta']['name']]]);
            $msg = tRaw('admin.lang.uploaded', ['name' => $data['_meta']['name'], 'n' => fmtNum(count($data) - 1)]);
        }
    }
}

if ($action === 'set_default_language') {
    $code = (string)($_POST['language'] ?? '');
    if (isset(availableLanguages()[$code])) {
        $old = setting('default_language');
        setSetting('default_language', $code);
        if ($old !== $code) $logA('system', 'language.default', ['changes' => [activityChange('language',
            availableLanguages()[$old]['name'] ?? $old, availableLanguages()[$code]['name'])]]);
        $msg = tRaw('admin.msg_language', ['name' => availableLanguages()[$code]['name']]);
    } else { $msg = tRaw('common.error'); $msgErr = true; }
}

if ($action === 'set_default_theme') {
    $theme = (string)($_POST['theme'] ?? '');
    if (isset(availableThemes()[$theme])) {
        $old = siteTheme();
        setAppSetting('default_theme', $theme);
        if ($old !== $theme) $logA('system', 'theme.default', ['changes' => [activityChange('theme',
            availableThemes()[$old]['name'] ?? $old, availableThemes()[$theme]['name'])]]);
        $msg = tRaw('admin.msg_theme', ['name' => availableThemes()[$theme]['name']]);
    } else { $msg = tRaw('settings.err_unknown_theme'); $msgErr = true; }
}

// Settings › General › Covers: which cover games show site-wide (see igdb.php)
if ($action === 'save_cover_default') {
    $src = ($_POST['cover_default'] ?? '') === 'igdb' ? 'igdb' : 'uploaded';
    if ($src !== coverDefaultSource()) {
        setSetting('igdb_cover_default', $src);
        $logA('catalogue', 'igdb.cover_default', ['params' => ['v' => $src]]);
    }
    $msg = tRaw('igdb.covers_saved');
}

// Settings › Catalogue › game list: one game's cover ('' = the site default, uploaded, igdb)
if ($action === 'set_cover_source') {
    $gid = (int)($_POST['game_id'] ?? 0);
    $src = in_array($_POST['cover_source'] ?? '', ['uploaded', 'igdb'], true) ? $_POST['cover_source'] : null;
    $st = db()->prepare("SELECT cover_source FROM games WHERE id = ?");
    $st->execute([$gid]);
    $old = $st->fetchColumn();
    if ($old === false) { $msg = tRaw('admin.games.err_game'); $msgErr = true; }
    else {
        if ($old !== $src) {
            db()->prepare("UPDATE games SET cover_source = ? WHERE id = ?")->execute([$src, $gid]);
            $logA('catalogue', 'igdb.cover', ['subject' => ['game', $gid], 'params' => $logGame($gid) + ['v' => $src ?? 'default']]);
        }
        $msg = tRaw('igdb.cover_saved');
    }
}

if ($action === 'clear_cover_overrides') {
    $n = (int)db()->exec("UPDATE games SET cover_source = NULL WHERE cover_source IS NOT NULL");
    if ($n) $logA('catalogue', 'igdb.cover_default', ['params' => ['v' => 'clear', 'n' => $n]]);
    $msg = tnRaw('igdb.covers_cleared', $n);
}

if ($action === 'delete_invite') {
    $id = (int)$_POST['invite_id'];
    $st = db()->prepare("SELECT code FROM invite_codes WHERE id=? AND used_by IS NULL"); $st->execute([$id]);
    $code = $st->fetchColumn();
    db()->prepare("DELETE FROM invite_codes WHERE id=? AND used_by IS NULL")->execute([$id]);
    if ($code !== false) $logA('accounts', 'invite.delete', ['changes' => [activityChange('invite_code', activityMaskCode((string)$code), '__removed')]]);
    $msg = tRaw('admin.msg_invite_deleted');
}

// ── Updates (forms on settings/s_updates.php are plain POSTs: the page shows $msg and $migrationResult) ──

if ($action === 'check_updates') {
    $c = updateCheckNow();
    if (!$c['ok']) { $msg = tRaw('updates.err_check', ['error' => $c['error']]); $msgErr = true; }
    else $msg = ($r = updateAvailable($c)) ? tRaw('updates.status_new', ['version' => $r['version']]) : tRaw('updates.checked_ok');
}

if ($action === 'fetch_update') {
    $release = updateCache()['release'] ?? null;
    if (!$release) { $msg = tRaw('updates.err_no_release'); $msgErr = true; }
    else {
        [$ok, $msg] = updateDownload($release); $msgErr = !$ok;
        if ($ok) $logA('system', 'update.fetch', ['params' => ['version' => $release['version']]]);
    }
}

if ($action === 'delete_update') {
    if ($path = updateZipPath((string)($_POST['file'] ?? ''))) {
        @unlink($path);
        $logA('system', 'update.delete', ['params' => ['file' => basename($path)]]);
    }
    $msg = tRaw('updates.dl_deleted');
}

if ($action === 'run_migrations') {
    // Ticked by default: a database backup first (Settings › Site Backup). No backup, no update.
    $backupFirst = !empty($_POST['backup_first']) && pendingMigrations();
    [$backupOk, $backupRes] = $backupFirst ? siteBackupNow(['db'], 'update', (int)$admin['id']) : [true, null];
    if (!$backupOk) {
        $msg = tRaw('sbackup.err_pre_update', ['error' => $backupRes]); $msgErr = true;
    } else {
        $migrationResult = runPendingMigrations();
        $ran = array_merge($migrationResult['done'], $migrationResult['failed'] ? [$migrationResult['failed']] : []);
        if ($ran) $logA('system', 'migration.run', ['warn' => !$migrationResult['ok'],
            'params' => ['files' => implode(', ', $ran), 'version' => APP_VERSION, 'v' => $migrationResult['ok'] ? 'ok' : 'failed'],
            'note' => $migrationResult['ok'] ? '' : mb_substr($migrationResult['error'], 0, 500)]);
        if (!$migrationResult['ok'])    { $msg = $migrationResult['error']; $msgErr = true; }
        elseif ($migrationResult['done']) $msg = tRaw('updates.db_done', ['n' => count($migrationResult['done'])]);
        else                              $msg = tRaw('updates.db_nothing');
        if ($backupFirst) $msg .= ' '.tRaw('sbackup.pre_update_done');
    }
}

if ($action === 'save_backup_settings') {
    $sched = (string)($_POST['backup_schedule'] ?? 'off');
    if (!isset(SITE_BACKUP_SCHEDULES[$sched])) $sched = 'off';
    $parts = siteBackupParts((array)($_POST['backup_parts'] ?? []));
    if ($sched !== 'off' && !$parts) { $msg = tRaw('sbackup.err_no_parts'); $msgErr = true; }
    else {
        $keep = (string)max(1, min(50, (int)($_POST['backup_keep'] ?? 3)));
        $logChanges = activityDiff(siteSettings(), ['backup_schedule' => $sched, 'backup_parts' => implode(',', $parts), 'backup_keep' => $keep],
            ['backup_schedule' => ['backup_schedule', 'enum'], 'backup_parts' => ['backup_parts', 'parts'], 'backup_keep' => ['backup_keep', 'text']]);
        setSetting('backup_schedule', $sched);
        setSetting('backup_parts', implode(',', $parts));
        setSetting('backup_keep', $keep);
        if ($logChanges) $logA('system', 'sitebackup.settings', ['changes' => $logChanges]);
        siteBackupTable();
        siteBackupPrune();
        $msg = tRaw('sbackup.msg_saved');
    }
}

// ── Activity log (settings/s_activity.php) ──

if ($action === 'save_log_settings') {
    $new = ['log_enabled' => !empty($_POST['log_enabled']) ? '1' : '0'];
    foreach (LOG_CATEGORIES as $c) $new['log_on_'.$c] = !empty($_POST['log_on_'.$c]) ? '1' : '0';
    $new['log_logins']         = !empty($_POST['log_logins']) ? '1' : '0';
    $new['log_retention_days'] = (string)(LOG_RETENTIONS[(string)($_POST['log_retention_days'] ?? '90')] ?? 90);
    $new['log_ip_mode']        = in_array($_POST['log_ip_mode'] ?? '', LOG_IP_MODES, true) ? $_POST['log_ip_mode'] : 'security';
    $new['log_user_history']   = !empty($_POST['log_user_history']) ? '1' : '0';
    $fields = [];
    foreach (array_keys($new) as $k) $fields[$k] = [$k, in_array($k, ['log_retention_days', 'log_ip_mode'], true) ? 'enum' : 'bool'];
    $logChanges = activityDiff(siteSettings(), $new, $fields);
    // Switching all logging off is recorded first (afterwards nothing can be); everything else after saving,
    // so turning a type on is recorded and turning System off isn't
    $turningOff = setting('log_enabled') === '1' && $new['log_enabled'] === '0';
    if ($logChanges && $turningOff) $logA('system', 'log.settings', ['changes' => $logChanges]);
    foreach ($new as $k => $v) setSetting($k, $v);
    if ($logChanges && !$turningOff) $logA('system', 'log.settings', ['changes' => $logChanges]);
    $msg = tRaw('log.saved');
}

if ($action === 'save_barcode_settings') {
    $new = [
        'barcode_enabled'      => !empty($_POST['barcode_enabled']) ? '1' : '0',
        'barcode_who'          => in_array($_POST['barcode_who'] ?? '', BARCODE_WHO, true) ? $_POST['barcode_who'] : 'all',
        'barcode_unknown_days' => (string)(BARCODE_UNKNOWN_DAYS[(string)($_POST['barcode_unknown_days'] ?? '30')] ?? 30),
    ];
    // "all" / "admins" are logged as bc_all / bc_admins (log.v.all already means "All events")
    $changes = activityDiff(siteSettings(), $new, ['barcode_enabled' => ['barcode_enabled', 'bool'], 'barcode_unknown_days' => ['barcode_unknown_days', 'enum']]);
    if (setting('barcode_who') !== $new['barcode_who']) array_unshift($changes, activityChange('barcode_who', 'bc_'.setting('barcode_who'), 'bc_'.$new['barcode_who'], 'enum'));
    foreach ($new as $k => $v) setSetting($k, $v);
    if ($changes) $logA('system', 'barcode.settings', ['changes' => $changes]);
    $msg = tRaw('bc.saved');
}

if ($action === 'save_export_settings') {
    $cfg    = dxConfig(json_decode((string)($_POST['dx_config'] ?? ''), true) ?: []);
    $json   = json_encode($cfg, JSON_UNESCAPED_UNICODE);
    $public = !empty($_POST['dx_public']) ? '1' : '0';
    $changes = activityDiff(siteSettings(), ['dx_public' => $public], ['dx_public' => ['dx_public', 'bool']]);
    $changed = $changes || $json !== json_encode(dxConfig(), JSON_UNESCAPED_UNICODE);
    setSetting('dx_config', $json);
    setSetting('dx_public', $public);
    if ($changed) $logA('system', 'dataexport.settings', ['changes' => $changes]);
    $msg = tRaw('dx.saved');
}

if ($action === 'purge_log') {
    $days = (int)setting('log_retention_days');
    if ($days > 0) {
        $n = activityPurgeOlder($days, 1000);
        // Written after the delete, so it is never removed by it
        $logA('system', 'log.purge', ['params' => ['n' => $n, 'period' => $days]]);
        $msg = tRaw('log.purged', ['n' => fmtNum($n)]);
    } else { $msg = tRaw('log.purge_none'); $msgErr = true; }
}
