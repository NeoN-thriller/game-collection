<?php
// Barcodes: EAN-13 / EAN-8 / UPC-A codes linked to games (game_barcodes, shared by all users),
// valid codes nobody has linked yet (barcode_unknown) and their daily clean-up. Loaded by core.php.
// Used by scan.php, api/barcode.php, api/barcode_admin.php and Settings › Barcodes.
if (!defined('DB_HOST')) { http_response_code(403); exit; }

const BARCODE_WHO          = ['all', 'admins'];
const BARCODE_UNKNOWN_DAYS = ['7' => 7, '30' => 30, '90' => 90, '0' => 0];   // Settings › Barcodes: forget unknown scans after
const BARCODE_UNDO_HOURS   = 12;    // Add barcodes page: users may take back their own codes this long
const BARCODE_UNKNOWN_CAP  = 500;   // at most this many unknown scans are kept
const BARCODE_DISMISSED_DAYS    = 30;    // dismissed unknown scans go after this many days

/** Digits only. 12 digits (UPC-A) become 13 with a leading 0. Returns null unless EAN-13 / EAN-8 with a correct check digit. */
function barcodeNormalize(string $raw): ?string {
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 12) $d = '0' . $d;
    $n = strlen($d);
    if ($n !== 8 && $n !== 13) return null;
    $sum = 0;
    for ($i = 0; $i < $n - 1; $i++) $sum += (int)$d[$i] * ((($n - 1 - $i) % 2 === 1) ? 3 : 1);
    return ((10 - $sum % 10) % 10) === (int)$d[$n - 1] ? $d : null;
}

/**
 * For the barcode APIs: an uncaught error answers with JSON that says what went wrong, instead of an error page the
 * scanner can't read. Missing tables (the database update hasn't run) get their own message.
 */
function barcodeApiErrors(): void {
    set_exception_handler(function (Throwable $e): void {
        error_log('game-collection: barcode API: ' . $e->getMessage());
        $missing = $e instanceof PDOException && in_array((string)$e->getCode(), ['42S02', '42S22'], true);
        jsonOut(['ok' => false, 'error' => $missing ? tRaw('bc.no_tables') : tRaw('bc.err_server', ['error' => $e->getMessage()])], 500);
    });
}

/** "5 045001 183012" (EAN-13) or "1234 5670" (EAN-8). */
function barcodeFormat(string $code): string {
    if (strlen($code) === 13) return $code[0] . ' ' . substr($code, 1, 6) . ' ' . substr($code, 7);
    if (strlen($code) === 8)  return substr($code, 0, 4) . ' ' . substr($code, 4);
    return $code;
}

/** 'EAN-13' or 'EAN-8'. */
function barcodeFormatName(string $code): string {
    return strlen($code) === 8 ? 'EAN-8' : 'EAN-13';
}

/** Is barcode scanning switched on (Settings › Barcodes)? When off, the scanner, the header's Scan link, barcode search,
    the drawer section and the column are gone for everyone; admins keep Settings › Barcodes to switch it back on. */
function barcodeEnabled(): bool {
    return setting('barcode_enabled') !== '0';
}

/** May this user link barcodes to games? Admins always; others when Settings › Barcodes allows all users. */
function barcodeCanEdit(array $user): bool {
    return ($user['role'] ?? '') === 'admin' || setting('barcode_who') !== 'admins';
}

/** "Title" plus the edition label when the title doesn't carry it already. */
function barcodeGameLabel(array $g): string {
    $title = (string)($g['title'] ?? '');
    $ed    = trim((string)($g['edition_label'] ?? ''));
    return $ed !== '' && mb_stripos($title, $ed) === false ? $title . ' · ' . $ed : $title;
}

/**
 * Every game a barcode is linked to, with this user's state of each. Hidden and switched-off games are included
 * (flags say which), so the scanner can tell the user why a game can't be opened.
 */
function barcodeLookup(string $code, array $user): array {
    $uid = (int)$user['id'];
    $st = db()->prepare("
        SELECT b.id AS barcode_id, b.variant, b.note, b.flagged_at,
               g.id AS game_id, g.title, g.edition_label, g.group_id, g.system_id, g.default_image, g.active AS game_active,
               s.name AS system_name, s.short_name AS `system`, s.active AS system_active,
               COALESCE(usp.visible, 1) AS system_visible
        FROM game_barcodes b
        JOIN games g   ON g.id = b.game_id
        JOIN systems s ON s.id = g.system_id
        LEFT JOIN user_system_prefs usp ON usp.system_id = s.id AND usp.user_id = ?
        WHERE b.barcode = ?
        ORDER BY s.short_name, g.edition_sort, g.title
    ");
    $st->execute([$uid, $code]);
    $rows = $st->fetchAll();
    if (!$rows) return [];

    $gameIds  = array_map(fn($r) => (int)$r['game_id'], $rows);
    $covers = [];   // the cover to show per game (uploaded or IGDB, Settings › IGDB › Covers)
    foreach (withCovers(array_map(fn($r) => ['id' => (int)$r['game_id'], 'default_image' => $r['default_image']], $rows)) as $c) $covers[$c['id']] = $c['cover_url'];
    $groupIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['group_id'], $rows))));
    $copies = barcodeUserCopies($uid, $gameIds);

    // Editions per group, and what the user owns of the other editions
    $edCount = []; $ownedIn = [];
    if ($groupIds) {
        $in = implode(',', array_fill(0, count($groupIds), '?'));
        $q = db()->prepare("SELECT group_id, COUNT(*) FROM games WHERE active = 1 AND group_id IN ($in) GROUP BY group_id");
        $q->execute($groupIds);
        $edCount = array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
        $q = db()->prepare("SELECT ce.game_id, g.group_id, SUM(ce.owned) AS n FROM collection_entries ce JOIN games g ON g.id = ce.game_id
                            WHERE ce.user_id = ? AND g.group_id IN ($in) GROUP BY ce.game_id, g.group_id");
        $q->execute(array_merge([$uid], $groupIds));
        foreach ($q->fetchAll() as $r) $ownedIn[(int)$r['group_id']][(int)$r['game_id']] = (int)$r['n'];
    }

    $out = [];
    foreach ($rows as $r) {
        $gid  = (int)$r['game_id'];
        $grp  = (int)$r['group_id'];
        $mine = $copies[$gid] ?? [];
        $owned = array_values(array_filter($mine, fn($c) => (int)$c['owned'] === 1));
        $other = 0;
        foreach ($ownedIn[$grp] ?? [] as $g => $n) if ($g !== $gid) $other += $n;
        $out[] = [
            'barcode_id'     => (int)$r['barcode_id'],
            'game_id'        => $gid,
            'title'          => $r['title'],
            'edition_label'  => (string)($r['edition_label'] ?? ''),
            'label'          => barcodeGameLabel($r),
            'group_id'       => $grp ?: null,
            'editions'       => $grp ? ($edCount[$grp] ?? 1) : 1,
            'system_id'      => (int)$r['system_id'],
            'system'         => $r['system'],
            'system_name'    => $r['system_name'],
            'image'          => $covers[$gid] ?? null,   // a full URL
            'variant'        => (string)$r['variant'],
            'note'           => (string)($r['note'] ?? ''),
            'flagged'        => $r['flagged_at'] !== null,
            'game_active'    => (int)$r['game_active'] === 1 && (int)$r['system_active'] === 1,
            'system_visible' => (int)$r['system_visible'] === 1,
            'copies'         => count($mine),
            'owned'          => count($owned),
            'owned_other'    => $other,
            'wishlist'       => (bool)array_filter($mine, fn($c) => (int)$c['wishlist'] === 1),
            'first_entry_id' => $owned ? (int)$owned[0]['id'] : null,
            'condition'      => $owned ? activityCopyState((int)$owned[0]['id'])['condition'] : '',
            'completeness'   => $owned ? (string)$owned[0]['completeness'] : '',
        ];
    }
    return $out;
}

/**
 * The user's copies of these games, oldest number first: game id => [{id, copy_number, owned, wishlist, variant,
 * completeness, has_data}]. has_data: photos or a grade, so a scan never reuses that copy.
 */
function barcodeUserCopies(int $uid, array $gameIds): array {
    if (!$gameIds) return [];
    $in = implode(',', array_fill(0, count($gameIds), '?'));
    $st = db()->prepare("
        SELECT ce.id, ce.game_id, ce.copy_number, ce.owned, ce.wishlist, ce.variant, ce.completeness,
               (ce.grade_method IS NOT NULL OR ce.grade_label_id IS NOT NULL
                OR EXISTS (SELECT 1 FROM copy_photos cp WHERE cp.entry_id = ce.id)) AS has_data
        FROM collection_entries ce
        WHERE ce.user_id = ? AND ce.game_id IN ($in)
        ORDER BY ce.game_id, ce.copy_number
    ");
    $st->execute(array_merge([$uid], $gameIds));
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['game_id']][] = $r;
    return $out;
}

/**
 * Every barcode of a game and of the other editions in its group, one entry per code:
 * [{barcode, display, format, rows:[{id, game_id, edition_label, variant, note, added_username, created_at, flagged, flag_reason}]}].
 */
function barcodeGroupCodes(int $gameId): array {
    $st = db()->prepare("SELECT id, group_id FROM games WHERE id = ?");
    $st->execute([$gameId]);
    $g = $st->fetch();
    if (!$g) return [];
    if ($g['group_id'] !== null) {
        $st = db()->prepare("SELECT id FROM games WHERE group_id = ?");
        $st->execute([$g['group_id']]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $ids = [$gameId];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("
        SELECT b.id, b.barcode, b.game_id, b.variant, b.note, b.added_username, UNIX_TIMESTAMP(b.created_at) AS created,
               b.flagged_at IS NOT NULL AS flagged, b.flag_reason, b.flagged_username, g.title, g.edition_label
        FROM game_barcodes b JOIN games g ON g.id = b.game_id
        WHERE b.game_id IN ($in)
        ORDER BY b.created_at, b.id
    ");
    $st->execute($ids);
    $codes = [];
    foreach ($st->fetchAll() as $r) {
        $c = $r['barcode'];
        $codes[$c] ??= ['barcode' => $c, 'display' => barcodeFormat($c), 'format' => barcodeFormatName($c), 'rows' => []];
        $codes[$c]['rows'][] = [
            'id'             => (int)$r['id'],
            'game_id'        => (int)$r['game_id'],
            'label'          => (string)($r['edition_label'] ?: $r['title']),
            'variant'        => (string)$r['variant'],
            'note'           => (string)($r['note'] ?? ''),
            'added_username' => (string)$r['added_username'],
            'created'        => (int)$r['created'],
            'flagged'        => (bool)$r['flagged'],
            'flag_reason'    => (string)($r['flag_reason'] ?? ''),
            'flagged_username' => (string)$r['flagged_username'],
        ];
    }
    return array_values($codes);
}

/** How many barcode rows each game has: game id => count. */
function barcodeCounts(array $gameIds): array {
    if (!$gameIds) return [];
    $in = implode(',', array_fill(0, count($gameIds), '?'));
    $st = db()->prepare("SELECT game_id, COUNT(*) FROM game_barcodes WHERE game_id IN ($in) GROUP BY game_id");
    $st->execute(array_values($gameIds));
    return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** A valid code nobody has linked: counted in barcode_unknown (kept to BARCODE_UNKNOWN_CAP rows). */
function barcodeRecordUnknown(string $code, array $user): void {
    try {
        $name = mb_substr((string)($user['username'] ?? ''), 0, 50);
        db()->prepare("INSERT INTO barcode_unknown (barcode, first_user_id, first_username, last_username) VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE times = times + 1, last_seen = NOW(), last_username = VALUES(last_username), dismissed = 0")
            ->execute([$code, (int)$user['id'] ?: null, $name, $name]);
        $n = (int)db()->query("SELECT COUNT(*) FROM barcode_unknown")->fetchColumn();
        if ($n > BARCODE_UNKNOWN_CAP) {
            // Full: the oldest dismissed scans go first, then the oldest others (never the one just recorded)
            $over = $n - BARCODE_UNKNOWN_CAP;
            $del = db()->prepare("DELETE FROM barcode_unknown WHERE barcode <> ? ORDER BY dismissed DESC, last_seen ASC LIMIT $over");
            $del->execute([$code]);
        }
    } catch (Throwable $e) {
        error_log('game-collection: barcode_unknown: ' . $e->getMessage());
    }
}

/** A code was linked: it is no longer unknown. */
function barcodeForgetUnknown(string $code): void {
    db()->prepare("DELETE FROM barcode_unknown WHERE barcode = ?")->execute([$code]);
}

/**
 * Once a day (the first signed-in request of the day, like activityMaybePurge()): unknown scans older than the
 * setting, and dismissed ones older than BARCODE_DISMISSED_DAYS days. The day is claimed with one UPDATE.
 */
function barcodeMaybePurge(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $today = date('Y-m-d');
        if (setting('barcode_last_purge') === $today) return;
        $st = db()->prepare("INSERT INTO app_settings (name, value) VALUES ('barcode_last_purge', ?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
        $st->execute([$today]);
        if ($st->rowCount() === 0) return;   // another request claimed today already
        $days = (int)setting('barcode_unknown_days');
        if ($days > 0) db()->prepare("DELETE FROM barcode_unknown WHERE last_seen < NOW() - INTERVAL ? DAY")->execute([$days]);
        db()->prepare("DELETE FROM barcode_unknown WHERE dismissed = 1 AND last_seen < NOW() - INTERVAL ? DAY")->execute([BARCODE_DISMISSED_DAYS]);
    } catch (Throwable $e) {
        error_log('game-collection: barcode clean-up: ' . $e->getMessage());
    }
}

/** Title + system of a game for barcode log events. */
function barcodeLogGame(int $gameId): array {
    $st = db()->prepare("SELECT g.title, g.edition_label, s.short_name FROM games g JOIN systems s ON s.id = g.system_id WHERE g.id = ?");
    $st->execute([$gameId]);
    $g = $st->fetch();
    return $g ? ['title' => barcodeGameLabel($g), 'system' => $g['short_name']] : ['title' => '#' . $gameId, 'system' => ''];
}

/** <script> with window.BARCODE_CONFIG for assets/js/barcode.js (load it before that file). */
function barcodeConfigScript(array $user): string {
    $variants = [];
    if (!empty($user['track_variants'])) {
        $vq = db()->prepare("SELECT label FROM user_variant_options WHERE user_id=? ORDER BY sort_order");
        $vq->execute([$user['id']]);
        $variants = $vq->fetchAll(PDO::FETCH_COLUMN);
    }
    $cfg = [
        'base'          => BASE_URL,
        'isAdmin'       => ($user['role'] ?? '') === 'admin',
        'canEdit'       => barcodeCanEdit($user),
        'trackVariants' => !empty($user['track_variants']),
        'variants'      => $variants,
    ];
    return '<script>window.BARCODE_CONFIG = ' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
}
