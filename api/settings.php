<?php
require_once __DIR__ . '/../boot.php';
$user = requireAuth();

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

/** One of the user's own wishlist share links, or an error. */
function ownWishlistShare(int $id, int $uid): array {
    $st = db()->prepare("SELECT * FROM wishlist_shares WHERE id=? AND user_id=?");
    $st->execute([$id, $uid]);
    $share = $st->fetch();
    if (!$share) jsonOut(['ok'=>false, 'error'=>tRaw('settings.share_not_found')], 404);
    return $share;
}

switch ($action) {

    case 'save_completeness':
        $labels = array_filter(array_map('trim', $body['labels'] ?? []), fn($l)=>$l!=='');
        db()->prepare("DELETE FROM user_completeness_options WHERE user_id=?")->execute([$user['id']]);
        $ins = db()->prepare("INSERT INTO user_completeness_options (user_id,label,sort_order) VALUES(?,?,?)");
        foreach (array_values($labels) as $i => $l) $ins->execute([$user['id'],$l,$i]);
        jsonOut(['ok'=>true]);
        break;

    case 'save_played':
        // labels[] with groups[] in the same order (finished | started | none)
        $labels = array_map(fn($l) => trim((string)$l), (array)($body['labels'] ?? []));
        $groups = array_values((array)($body['groups'] ?? []));
        db()->prepare("DELETE FROM user_played_options WHERE user_id=?")->execute([$user['id']]);
        $ins = db()->prepare("INSERT INTO user_played_options (user_id,label,play_group,sort_order) VALUES(?,?,?,?)");
        $n = 0; $seen = [];
        foreach (array_values($labels) as $i => $l) {
            if ($l === '' || isset($seen[mb_strtolower($l)])) continue;
            $seen[mb_strtolower($l)] = true;
            $g = in_array($groups[$i] ?? null, PLAY_GROUPS, true) ? $groups[$i] : playGroupOf($l);
            $ins->execute([$user['id'], mb_substr($l, 0, 100), $g, $n++]);
        }
        if (isset($body['pct'])) {   // Finished / Started counters: of all games or of owned games, and shown or not
            db()->prepare("UPDATE users SET played_pct=?, show_played=? WHERE id=?")
                ->execute([$body['pct'] === 'owned' ? 'owned' : 'all', !empty($body['show']) ? 1 : 0, $user['id']]);
        }
        jsonOut(['ok'=>true]);
        break;

    case 'save_editions':
        $mode = ($body['mode'] ?? '') === 'every' ? 'every' : 'one';
        $wish = ($body['wishlist'] ?? '') === 'exact' ? 'exact' : 'any';
        try {
            db()->prepare("UPDATE users SET edition_mode=?, edition_wishlist=? WHERE id=?")->execute([$mode, $wish, $user['id']]);
        } catch (PDOException) {
            jsonOut(['ok'=>false,'error'=>tRaw('settings.err_migration')]);
        }
        jsonOut(['ok'=>true]);
        break;

    case 'save_compilation_mode':
        $mode = ($body['mode'] ?? '') === 'contents' ? 'contents' : 'own';
        db()->prepare("UPDATE users SET compilation_mode=? WHERE id=?")->execute([$mode, $user['id']]);
        jsonOut(['ok'=>true]);
        break;

    case 'save_variants':
        // Turning tracking off only hides the variant field; stored values on copies are kept
        $labels = array_values(array_unique(array_filter(array_map(fn($l) => mb_substr(trim((string)$l), 0, 100), $body['labels'] ?? []), fn($l)=>$l!=='')));
        try {
            db()->prepare("UPDATE users SET track_variants=? WHERE id=?")->execute([!empty($body['track']) ? 1 : 0, $user['id']]);
            db()->prepare("DELETE FROM user_variant_options WHERE user_id=?")->execute([$user['id']]);
            $ins = db()->prepare("INSERT INTO user_variant_options (user_id,label,sort_order) VALUES(?,?,?)");
            foreach ($labels as $i => $l) $ins->execute([$user['id'],$l,$i]);
        } catch (PDOException) {
            jsonOut(['ok'=>false,'error'=>tRaw('settings.err_migration')]);
        }
        jsonOut(['ok'=>true]);
        break;

    case 'save_grading':
        $mode = in_array($body['mode'] ?? '', ['simple','points','both'], true) ? $body['mode'] : 'simple';
        $def  = in_array($body['default'] ?? '', ['simple','points'], true) ? $body['default'] : 'simple';
        if ($mode !== 'both') $def = $mode; // the only enabled method is also the default
        db()->prepare("UPDATE users SET grading_mode=?, grading_default=? WHERE id=?")->execute([$mode, $def, $user['id']]);
        jsonOut(['ok'=>true]);
        break;

    case 'save_language':
        $lang = (string)($body['language'] ?? '');
        if (!isset(availableLanguages()[$lang])) jsonOut(['ok'=>false,'error'=>tRaw('settings.err_unknown_lang')]);
        try {
            // Choosing the site default stores "no choice", so the user follows it if the admin changes it
            db()->prepare("UPDATE users SET language=? WHERE id=?")->execute([$lang === siteLanguage() ? null : $lang, $user['id']]);
        } catch (PDOException) {
            jsonOut(['ok'=>false,'error'=>tRaw('settings.err_migration')]);
        }
        jsonOut(['ok'=>true]);
        break;

    case 'save_theme':
        $theme = (string)($body['theme'] ?? '');
        if ($theme !== '' && !isset(availableThemes()[$theme])) jsonOut(['ok'=>false,'error'=>tRaw('settings.err_unknown_theme')]);
        try {
            db()->prepare("UPDATE users SET theme=? WHERE id=?")->execute([$theme === '' ? null : $theme, $user['id']]);
        } catch (PDOException) {
            jsonOut(['ok'=>false,'error'=>tRaw('settings.err_migration')]);
        }
        jsonOut(['ok'=>true]);
        break;

    // ── Wishlist share links (Settings › Wishlist sharing) ──
    case 'wishlist_share_create':
        $token = bin2hex(random_bytes(16));
        db()->prepare("INSERT INTO wishlist_shares (user_id, token, name, `columns`, listed) VALUES (?,?,'',?,0)")
            ->execute([$user['id'], $token, json_encode(WISHLIST_SHARE_COLS)]);
        logEvent('sharing', 'wishlist.share_create', ['actor' => $user]);
        jsonOut(['ok'=>true]);
        break;

    case 'wishlist_share_save':
        $share = ownWishlistShare((int)($body['id'] ?? 0), (int)$user['id']);
        $name  = mb_substr(trim((string)($body['name'] ?? '')), 0, 100);
        $cols  = wishlistShareCols((array)($body['columns'] ?? []));
        db()->prepare("UPDATE wishlist_shares SET name=?, `columns`=?, listed=? WHERE id=?")
            ->execute([$name, json_encode($cols), !empty($body['listed']) ? 1 : 0, $share['id']]);
        logEvent('sharing', 'wishlist.share_edit', ['actor' => $user, 'params' => ['name' => $name !== '' ? $name : tRaw('settings.share_unnamed')]]);
        jsonOut(['ok'=>true, 'columns'=>$cols]);
        break;

    // New link: a new token, the old link stops working
    case 'wishlist_share_token':
        $share = ownWishlistShare((int)($body['id'] ?? 0), (int)$user['id']);
        $token = bin2hex(random_bytes(16));
        db()->prepare("UPDATE wishlist_shares SET token=? WHERE id=?")->execute([$token, $share['id']]);
        logEvent('sharing', 'wishlist.share_token', ['actor' => $user, 'params' => ['name' => $share['name'] !== '' ? $share['name'] : tRaw('settings.share_unnamed')]]);
        jsonOut(['ok'=>true, 'url'=>wishlistShareUrl($token)]);
        break;

    case 'wishlist_share_delete':
        $share = ownWishlistShare((int)($body['id'] ?? 0), (int)$user['id']);
        db()->prepare("DELETE FROM wishlist_shares WHERE id=?")->execute([$share['id']]);
        logEvent('sharing', 'wishlist.share_delete', ['actor' => $user, 'params' => ['name' => $share['name'] !== '' ? $share['name'] : tRaw('settings.share_unnamed')]]);
        jsonOut(['ok'=>true]);
        break;

    case 'change_password':
        $cur  = $body['current_password'] ?? '';
        $new  = $body['new_password']     ?? '';
        $conf = $body['confirm_password'] ?? '';
        if (!$cur || !$new || !$conf) jsonOut(['ok'=>false,'error'=>tRaw('settings.err_fields')]);
        if ($new !== $conf) jsonOut(['ok'=>false,'error'=>tRaw('common.err_password_match')]);
        if (strlen($new) < MIN_PASSWORD_LENGTH) jsonOut(['ok'=>false,'error'=>tRaw('common.err_password_length', ['n' => MIN_PASSWORD_LENGTH])]);
        $pwKeys = ['pw:'.$user['id']];
        if ($locked = lockRemaining($pwKeys)) jsonOut(['ok'=>false,'error'=>lockMessage($locked)]);
        if (!password_verify($cur, $user['password'])) {
            recordFailure($pwKeys);
            if ($locked = lockRemaining($pwKeys)) activityLogLockout('password.lockout', $pwKeys, $locked, $user);
            jsonOut(['ok'=>false,'error'=>tRaw('settings.err_current_pw')]);
        }
        clearFailures($pwKeys);
        setPassword($user['id'], $new);
        logEvent('accounts', 'password.change', ['actor' => $user]);
        jsonOut(['ok'=>true]);
        break;

    default:
        jsonOut(['ok'=>false,'error'=>tRaw('common.err_unknown_action')], 400);
}
