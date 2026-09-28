<?php
require_once __DIR__ . '/../config.php';
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

    case 'save_grading':
        $mode = in_array($body['mode'] ?? '', ['simple','points','both'], true) ? $body['mode'] : 'simple';
        $def  = in_array($body['default'] ?? '', ['simple','points'], true) ? $body['default'] : 'simple';
        if ($mode !== 'both') $def = $mode; // the only enabled method is also the default
        db()->prepare("UPDATE users SET grading_mode=?, grading_default=? WHERE id=?")->execute([$mode, $def, $user['id']]);
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
        if (!$cur || !$new || !$conf) jsonOut(['ok'=>false,'error'=>'All fields required']);
        if ($new !== $conf) jsonOut(['ok'=>false,'error'=>'Passwords do not match']);
        if (strlen($new) < MIN_PASSWORD_LENGTH) jsonOut(['ok'=>false,'error'=>'Password must be at least '.MIN_PASSWORD_LENGTH.' characters']);
        $pwKeys = ['pw:'.$user['id']];
        if ($locked = lockRemaining($pwKeys)) jsonOut(['ok'=>false,'error'=>lockMessage($locked)]);
        if (!password_verify($cur, $user['password'])) {
            recordFailure($pwKeys);
            jsonOut(['ok'=>false,'error'=>'Current password is incorrect']);
        }
        clearFailures($pwKeys);
        setPassword($user['id'], $new);
        jsonOut(['ok'=>true]);
        break;

    default:
        jsonOut(['ok'=>false,'error'=>'Unknown action'], 400);
}
