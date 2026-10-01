<?php
require_once __DIR__ . '/../boot.php';
$user = requireAuth();

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

switch ($action) {

    case 'save_completeness':
        $labels = array_filter(array_map('trim', $body['labels'] ?? []), fn($l)=>$l!=='');
        db()->prepare("DELETE FROM user_completeness_options WHERE user_id=?")->execute([$user['id']]);
        $ins = db()->prepare("INSERT INTO user_completeness_options (user_id,label,sort_order) VALUES(?,?,?)");
        foreach (array_values($labels) as $i => $l) $ins->execute([$user['id'],$l,$i]);
        jsonOut(['ok'=>true]);
        break;

    case 'save_played':
        $labels = array_filter(array_map('trim', $body['labels'] ?? []), fn($l)=>$l!=='');
        db()->prepare("DELETE FROM user_played_options WHERE user_id=?")->execute([$user['id']]);
        $ins = db()->prepare("INSERT INTO user_played_options (user_id,label,sort_order) VALUES(?,?,?)");
        foreach (array_values($labels) as $i => $l) $ins->execute([$user['id'],$l,$i]);
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

    case 'save_wishlist_public':
        $public = !empty($body['wishlist_public']) ? 1 : 0;
        $token  = $user['wishlist_token'] ?? '';
        if ($public && !$token) {
            $token = bin2hex(random_bytes(12));
            db()->prepare("UPDATE users SET wishlist_public=?,wishlist_token=? WHERE id=?")->execute([$public,$token,$user['id']]);
        } else {
            db()->prepare("UPDATE users SET wishlist_public=? WHERE id=?")->execute([$public,$user['id']]);
        }
        $newToken = db()->prepare("SELECT wishlist_token FROM users WHERE id=?");
        $newToken->execute([$user['id']]);
        $tok = $newToken->fetchColumn();
        jsonOut(['ok'=>true,'token'=>$tok,'base_url'=>BASE_URL]);
        break;

    case 'regenerate_token':
        $token = bin2hex(random_bytes(12));
        db()->prepare("UPDATE users SET wishlist_token=? WHERE id=?")->execute([$token,$user['id']]);
        jsonOut(['ok'=>true,'token'=>$token,'base_url'=>BASE_URL]);
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
            jsonOut(['ok'=>false,'error'=>tRaw('settings.err_current_pw')]);
        }
        clearFailures($pwKeys);
        setPassword($user['id'], $new);
        jsonOut(['ok'=>true]);
        break;

    default:
        jsonOut(['ok'=>false,'error'=>tRaw('common.err_unknown_action')], 400);
}
