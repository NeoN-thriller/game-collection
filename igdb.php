<?php
// IGDB game info: developer, publisher, genres, release date and ratings from igdb.com, shown in the game drawer.
// Site-wide and admin-managed (Settings › IGDB, assets/js/igdb-admin.js → api/igdb.php). Loaded by core.php.
// Needs IGDB_CLIENT_ID / IGDB_CLIENT_SECRET in config.php (a Twitch app) and systems.igdb_platform_id per system.
// Each game gets one game_igdb row once looked up: auto / confirmed (matched), review (an admin must pick), none.
if (!defined('DB_HOST')) { http_response_code(403); exit; }

const IGDB_API      = 'https://api.igdb.com/v4/';
const IGDB_GAP      = 0.27;   // seconds between requests: IGDB allows 4 per second
const IGDB_PAGE     = 500;    // most results IGDB returns per request
const IGDB_MULTI    = 10;     // most queries in one multiquery request (counts as one request for the rate limit)
const IGDB_FIELDS   = 'name,slug,version_parent,rating,rating_count,aggregated_rating,aggregated_rating_count,'
                    . 'alternative_names.name,game_localizations.name,game_localizations.region,game_localizations.cover.image_id,'
                    . 'cover.image_id,genres.name,'
                    . 'involved_companies.developer,involved_companies.publisher,involved_companies.company.name,'
                    . 'release_dates.platform,release_dates.release_region,release_dates.date,release_dates.human';
const IGDB_COVER_URL = 'https://images.igdb.com/igdb/image/upload/t_cover_big/%s.jpg';   // 264 × 374
const IGDB_REGION_OWN = [     // systems.region → IGDB release regions that count as the system's own, in order
    'PAL'    => ['europe', 'australia', 'new_zealand'],
    'NTSC-U' => ['north_america'],
    'NTSC-J' => ['japan'],
];

function igdbConfigured(): bool {
    return defined('IGDB_CLIENT_ID') && defined('IGDB_CLIENT_SECRET') && IGDB_CLIENT_ID !== '' && IGDB_CLIENT_SECRET !== '';
}

/** One HTTP POST; returns [status code, body]. */
function igdbPost(string $url, string $body, array $headers = []): array {
    if (!function_exists('curl_init')) throw new RuntimeException(tRaw('igdb.err_curl'));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => $headers,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($res === false) throw new RuntimeException('IGDB: ' . curl_error($ch));
    return [$code, (string)$res];
}

/** App access token from Twitch (client credentials), kept in app_settings until shortly before it expires. */
function igdbToken(bool $fresh = false): string {
    if (!$fresh && setting('igdb_token') !== '' && (int)setting('igdb_token_exp') > time() + 3600) return setting('igdb_token');
    [$code, $body] = igdbPost('https://id.twitch.tv/oauth2/token', http_build_query([
        'client_id' => IGDB_CLIENT_ID, 'client_secret' => IGDB_CLIENT_SECRET, 'grant_type' => 'client_credentials']));
    $j = json_decode($body, true);
    if ($code !== 200 || empty($j['access_token'])) throw new RuntimeException(tRaw('igdb.err_token', ['code' => $code]));
    setSetting('igdb_token', (string)$j['access_token']);
    setSetting('igdb_token_exp', (string)(time() + (int)($j['expires_in'] ?? 0)));
    return (string)$j['access_token'];
}

/** One Apicalypse query. Paced to stay under the rate limit; retries once on 429 and once with a new token on 401. */
function igdbQuery(string $endpoint, string $query): array {
    static $last = 0.0;
    $newToken = false;
    for ($try = 0; $try < 3; $try++) {
        $wait = IGDB_GAP - (microtime(true) - $last);
        if ($wait > 0) usleep((int)($wait * 1e6));
        $last = microtime(true);
        [$code, $body] = igdbPost(IGDB_API . $endpoint, $query,
            ['Client-ID: ' . IGDB_CLIENT_ID, 'Authorization: Bearer ' . igdbToken($newToken), 'Accept: application/json']);
        if ($code === 200) return json_decode($body, true) ?: [];
        if ($code === 429) { usleep(1000000); continue; }
        if ($code === 401 && !$newToken) { $newToken = true; continue; }
        throw new RuntimeException(tRaw('igdb.err_api', ['code' => $code]));
    }
    throw new RuntimeException(tRaw('igdb.err_api', ['code' => 429]));
}

/**
 * Several queries in as few requests as possible: IGDB's multiquery runs up to IGDB_MULTI of them in one request.
 * $queries: [label => [endpoint, query]]. Returns [label => results], every label present.
 */
function igdbMulti(array $queries): array {
    $out = [];
    foreach (array_chunk($queries, IGDB_MULTI, true) as $chunk) {
        if (count($chunk) === 1) {
            $label = array_key_first($chunk);
            $out[$label] = igdbQuery($chunk[$label][0], $chunk[$label][1]);
            continue;
        }
        $body = '';
        foreach ($chunk as $label => [$ep, $q]) $body .= 'query ' . $ep . ' ' . igdbStr((string)$label) . ' { ' . $q . " };\n";
        foreach (igdbQuery('multiquery', $body) as $r) $out[(string)($r['name'] ?? '')] = $r['result'] ?? [];
    }
    foreach ($queries as $label => $_) $out[$label] ??= [];
    return $out;
}

/** A string literal for an Apicalypse query. */
function igdbStr(string $s): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
}

/** IGDB release regions, name → id (kept in app_settings; $fresh fetches them again). */
function igdbRegions(bool $fresh = false): array {
    $have = json_decode(setting('igdb_regions'), true);
    if (!$fresh && is_array($have) && $have) return $have;
    $map = [];
    foreach (igdbQuery('release_date_regions', 'fields id,region; limit 50;') as $r) $map[(string)$r['region']] = (int)$r['id'];
    if ($map) setSetting('igdb_regions', json_encode($map));
    return $map ?: (is_array($have) ? $have : []);
}

/**
 * IGDB regions of regional titles and covers (game_localizations.region), id → key like the release regions
 * ("europe", "north_america", "new_zealand", …). Kept in app_settings; $fresh fetches them again.
 */
function igdbLocRegions(bool $fresh = false): array {
    $have = json_decode(setting('igdb_loc_regions'), true);
    if (!$fresh && is_array($have) && $have) return $have;
    $map = [];
    foreach (igdbQuery('regions', 'fields id,name,identifier; limit 100;') as $r)
        $map[(int)$r['id']] = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string)($r['name'] ?? $r['identifier'] ?? ''))), '_');
    if ($map) setSetting('igdb_loc_regions', json_encode($map));
    return $map ?: (is_array($have) ? $have : []);
}

/** The cover to keep for a match: the system's regional cover (PAL: Europe → Australia → New Zealand), else the main one. */
function igdbPickCover(array $g, string $sysRegion): array {
    $locs = array_filter($g['game_localizations'] ?? [], fn($l) => !empty($l['cover']['image_id']));
    if ($locs && ($own = IGDB_REGION_OWN[$sysRegion] ?? [])) {
        $names = igdbLocRegions();
        foreach ($own as $want) foreach ($locs as $l)
            if (($names[(int)($l['region'] ?? 0)] ?? '') === $want) return [(string)$l['cover']['image_id'], $want];
    }
    return [isset($g['cover']['image_id']) ? (string)$g['cover']['image_id'] : null, null];
}

// ── Matching ─────────────────────────────

/** Loose title key: lowercase, no accents, no [edition] tags, & / 'n' → and, no punctuation, no leading "the". */
function igdbTitleKey(string $s): string {
    $s = preg_replace('/\[[^\]]*\]|\([^)]*\)/u', ' ', $s);
    if (class_exists('Normalizer')) $s = preg_replace('/\p{Mn}+/u', '', (string)Normalizer::normalize($s, Normalizer::FORM_D));
    else $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ö' => 'o',
                         'ó' => 'o', 'ô' => 'o', 'ü' => 'u', 'ú' => 'u', 'û' => 'u', 'ï' => 'i', 'í' => 'i', 'ç' => 'c', 'ñ' => 'n',
                         'ø' => 'o', 'É' => 'E']);
    $s = mb_strtolower(str_replace(['&', '/', '+'], ' and ', $s));
    $s = preg_replace("/(^|\\s)['’]?n['’]?(\\s|$)/u", ' and ', $s);   // Chip 'n Dale
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $s = preg_replace('/^the /u', '', trim($s));
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** Keys of one IGDB game per match level: name (+ alternative names), without a "Disney's" style owner prefix, before ":". */
function igdbGameKeys(array $x): array {
    $k = igdbTitleKey((string)$x['name']);
    return [
        'exact'    => array_merge([$k], array_map(fn($a) => igdbTitleKey((string)($a['name'] ?? '')),
                                              array_merge($x['alternative_names'] ?? [], $x['game_localizations'] ?? []))),
        'prefix'   => [preg_replace('/^\S+ s /u', '', $k)],
        'subtitle' => [igdbTitleKey(explode(':', (string)$x['name'])[0])],
    ];
}

/** The first level with a single hit (or a single non-edition hit) wins; several equal hits stop the search. */
function igdbPick(array $hits, string $key): ?array {
    foreach (['exact', 'prefix', 'subtitle'] as $lvl) {
        $m = array_values(array_filter($hits, fn($x) => in_array($key, igdbGameKeys($x)[$lvl], true)));
        if (!$m) continue;
        if (count($m) === 1) return $m[0];
        $main = array_values(array_filter($m, fn($x) => empty($x['version_parent'])));
        return count($main) === 1 ? $main[0] : null;
    }
    return null;
}

/** Candidates for the review list: [{id, name, slug, year}]. */
function igdbCandidates(array $hits): array {
    return array_map(function ($x) {
        $years = array_filter(array_map(fn($r) => isset($r['date']) ? (int)gmdate('Y', (int)$r['date']) : 0, $x['release_dates'] ?? []));
        return ['id' => (int)$x['id'], 'name' => (string)$x['name'], 'slug' => (string)($x['slug'] ?? ''), 'year' => $years ? min($years) : null];
    }, array_slice($hits, 0, 8));
}

/**
 * Games known under another title: IGDB's alternative names ("Final Mission", working titles, …) and its regional
 * titles (game_localizations, e.g. the European name). Exact first (ignoring case), then titles containing it.
 * $platId > 0: only games on that platform. Returns full games (IGDB_FIELDS); $fields overrides that.
 */
function igdbByOtherName(string $title, int $platId, string $fields = IGDB_FIELDS): array {
    foreach (['~ ' . igdbStr($title), '~ *' . igdbStr($title) . '*'] as $cmp) {
        $ids = [];
        foreach (['alternative_names', 'game_localizations'] as $ep)
            foreach (igdbQuery($ep, 'fields game; where name ' . $cmp . '; limit 50;') as $r) $ids[] = (int)($r['game'] ?? 0);
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) continue;
        $games = igdbQuery('games', 'fields ' . $fields . '; where id = (' . implode(',', $ids) . ')'
                                  . ($platId > 0 ? ' & platforms = (' . $platId . ')' : '') . '; limit 50;');
        if ($games) return $games;
    }
    return [];
}

/**
 * igdbByOtherName() for several titles on one platform at once ([key => title] → [key => games]): every title's exact
 * and containing names in multiqueries, then the games behind them in one more. Per title the exact names still win.
 */
function igdbByOtherNames(array $titles, int $platId): array {
    if (!$titles) return [];
    $passes = ['x' => fn($t) => '~ ' . igdbStr($t), 'c' => fn($t) => '~ *' . igdbStr($t) . '*'];   // exact, containing
    $q = [];
    foreach ($titles as $k => $t)
        foreach ($passes as $p => $cmp)
            foreach (['a' => 'alternative_names', 'l' => 'game_localizations'] as $e => $ep)
                $q[$p . $e . $k] = [$ep, 'fields game; where name ' . $cmp((string)$t) . '; limit 50;'];
    $res = igdbMulti($q);

    $ids = [];   // [key][pass] => IGDB game ids
    $all = [];   // all of them
    foreach ($titles as $k => $t)
        foreach ($passes as $p => $_) {
            $l = array_map(fn($r) => (int)($r['game'] ?? 0), array_merge($res[$p.'a'.$k], $res[$p.'l'.$k]));
            $ids[$k][$p] = array_values(array_unique(array_filter($l)));
            array_push($all, ...$ids[$k][$p]);
        }

    // The games behind all those names that are on the platform
    $all = array_values(array_unique($all));
    $q = [];
    foreach (array_chunk($all, IGDB_PAGE) as $n => $chunk)
        $q['g' . $n] = ['games', 'fields ' . IGDB_FIELDS . '; where id = (' . implode(',', $chunk) . ') & platforms = (' . $platId . '); limit ' . IGDB_PAGE . ';'];
    $games = [];
    foreach (igdbMulti($q) as $list) foreach ($list as $g) $games[(int)$g['id']] = $g;

    $out = [];
    foreach ($ids as $k => $byPass) {
        $out[$k] = [];
        foreach ($byPass as $pIds) {
            $found = array_values(array_intersect_key($games, array_flip($pIds)));
            if ($found) { $out[$k] = array_slice($found, 0, 50); break; }
        }
    }
    return $out;
}

/** Games by IGDB id (any platform), id → game. */
function igdbFetch(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = [];
    foreach (array_chunk($ids, IGDB_PAGE) as $chunk)
        foreach (igdbQuery('games', 'fields ' . IGDB_FIELDS . '; where id = (' . implode(',', $chunk) . '); limit ' . IGDB_PAGE . ';') as $g)
            $out[(int)$g['id']] = $g;
    return $out;
}

/** Editions (IGDB version_parent set) with the fields IGDB left empty taken from their main game. Keeps the keys. */
function igdbFillEditions(array $games): array {
    $parents = array_filter(array_map(fn($g) => (int)($g['version_parent'] ?? 0), $games));
    if (!$parents) return $games;
    $p = igdbFetch($parents);
    foreach ($games as &$g) if (isset($p[(int)($g['version_parent'] ?? 0)])) $g = igdbFillFrom($g, $p[(int)$g['version_parent']]);
    unset($g);
    return $games;
}

/** An edition (e.g. "… Special Edition") with its empty fields taken from the main game. Release dates per platform. */
function igdbFillFrom(array $g, array $parent): array {
    foreach (['involved_companies', 'genres', 'cover', 'game_localizations'] as $k) if (empty($g[$k]) && !empty($parent[$k])) $g[$k] = $parent[$k];
    foreach ([['rating', 'rating_count'], ['aggregated_rating', 'aggregated_rating_count']] as [$r, $n])
        if (empty($g[$n]) && !empty($parent[$n])) { $g[$r] = $parent[$r] ?? null; $g[$n] = $parent[$n]; }
    $plats = array_map(fn($d) => (int)($d['platform'] ?? 0), $g['release_dates'] ?? []);
    foreach ($parent['release_dates'] ?? [] as $d)
        if (!in_array((int)($d['platform'] ?? 0), $plats, true)) $g['release_dates'][] = $d;
    return $g;
}

/** Precision of an IGDB release date from its human text ("Mar 12, 1987", "Mar 1987", "Q2 1987", "1987", "TBD"); null = no date. */
function igdbDatePrec(array $d): ?string {
    $h = trim((string)($d['human'] ?? ''));
    if (!isset($d['date']) || $h === '' || stripos($h, 'tbd') !== false) return null;
    if (preg_match('/^Q[1-4]\b/i', $h)) return 'quarter';
    if (preg_match('/^\d{4}$/', $h)) return 'year';
    if (preg_match('/^\p{L}+\.? \d{4}$/u', $h)) return 'month';
    return 'day';
}

/**
 * The release date to show: the system's own region (PAL: Europe, Australia, New Zealand) → worldwide →
 * the earliest date of any other region (shown with its region). Only dates on this platform; TBD is skipped.
 */
function igdbReleaseDate(array $g, int $platId, string $sysRegion, array $regions): ?array {
    $byId = array_flip($regions);
    $rds = [];
    foreach ($g['release_dates'] ?? [] as $d) {
        if ((int)($d['platform'] ?? 0) !== $platId || !($prec = igdbDatePrec($d))) continue;
        $rds[] = ['ts' => (int)$d['date'], 'prec' => $prec, 'region' => $byId[(int)($d['release_region'] ?? 0)] ?? ''];
    }
    if (!$rds) return null;
    usort($rds, fn($a, $b) => $a['ts'] <=> $b['ts']);
    foreach ([[IGDB_REGION_OWN[$sysRegion] ?? [], 'own'], [['worldwide'], 'worldwide']] as [$names, $kind])
        foreach ($names as $n) foreach ($rds as $d) if ($d['region'] === $n) return $d + ['kind' => $kind];
    return $rds[0] + ['kind' => 'other'];
}

/** The game_igdb columns for one matched IGDB game. */
function igdbRow(array $g, int $platId, string $sysRegion, array $regions): array {
    $ic    = $g['involved_companies'] ?? [];
    $names = fn(string $role) => mb_substr(implode(', ', array_values(array_unique(array_filter(array_map(
        fn($c) => trim((string)($c['company']['name'] ?? '')), array_filter($ic, fn($c) => !empty($c[$role]))))))), 0, 500);
    $date  = igdbReleaseDate($g, $platId, $sysRegion, $regions);
    $num   = fn($v) => $v === null ? null : round((float)$v, 2);
    $users = (int)($g['rating_count'] ?? 0);        // a rating with no votes behind it is left out
    $crit  = (int)($g['aggregated_rating_count'] ?? 0);
    try { [$coverId, $coverRegion] = igdbPickCover($g, $sysRegion); }
    catch (Throwable) { [$coverId, $coverRegion] = [isset($g['cover']['image_id']) ? (string)$g['cover']['image_id'] : null, null]; }
    return [
        'igdb_id'        => (int)$g['id'],
        'slug'           => mb_substr((string)($g['slug'] ?? ''), 0, 255),
        'name'           => mb_substr((string)($g['name'] ?? ''), 0, 255),
        'developers'     => $names('developer') ?: null,
        'publishers'     => $names('publisher') ?: null,
        'genres'         => mb_substr(implode(', ', array_map(fn($x) => (string)$x['name'], $g['genres'] ?? [])), 0, 500) ?: null,
        'release_date'   => $date ? gmdate('Y-m-d', $date['ts']) : null,
        'release_prec'   => $date['prec'] ?? null,
        'release_region' => $date ? mb_substr($date['region'], 0, 20) : null,
        'release_kind'   => $date['kind'] ?? null,
        'rating'         => $users ? $num($g['rating'] ?? null) : null,
        'rating_count'   => $users ?: null,
        'critic_rating'  => $crit ? $num($g['aggregated_rating'] ?? null) : null,
        'critic_count'   => $crit ?: null,
        'cover_id'       => $coverId !== null ? mb_substr($coverId, 0, 64) : null,
        'cover_region'   => $coverRegion,
    ];
}

/** Writes one game's row. $data: igdbRow() for a match; candidates for review; nothing for none. */
function igdbSave(int $gameId, string $status, array $data = [], array $cands = []): void {
    $cols = ['igdb_id', 'slug', 'name', 'developers', 'publishers', 'genres', 'release_date', 'release_prec', 'release_region',
             'release_kind', 'rating', 'rating_count', 'critic_rating', 'critic_count', 'cover_id', 'cover_region'];
    $vals = array_map(fn($c) => $data[$c] ?? null, $cols);
    $set  = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols));
    db()->prepare("INSERT INTO game_igdb (game_id, status, " . implode(', ', $cols) . ", candidates, fetched_at)
                   VALUES (?, ?, " . implode(', ', array_fill(0, count($cols), '?')) . ", ?, NOW())
                   ON DUPLICATE KEY UPDATE status = VALUES(status), $set, candidates = VALUES(candidates), fetched_at = NOW()")
        ->execute(array_merge([$gameId, $status], $vals, [$cands ? json_encode($cands, JSON_UNESCAPED_UNICODE) : null]));
}

/** Systems an admin set to "Don't match" (e.g. hardware: consoles and peripherals), kept in app_settings: [system id, …]. */
function igdbSkipped(): array {
    $ids = json_decode(setting('igdb_skip_systems'), true);
    return is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];
}

/** The systems row bits matching needs, or null when the system has no IGDB platform or is set to "Don't match". */
function igdbSystem(int $sysId): ?array {
    if (in_array($sysId, igdbSkipped(), true)) return null;
    $st = db()->prepare("SELECT id, region, igdb_platform_id FROM systems WHERE id = ?");
    $st->execute([$sysId]);
    $s = $st->fetch();
    return $s && (int)$s['igdb_platform_id'] > 0 ? $s : null;
}

/**
 * Looks up a few games (at most IGDB_MULTI) of one system and saves each result: auto (a sure match) or review.
 * In a handful of requests: all title searches in one multiquery, then other titles (PAL names) for the games
 * without a sure match, then the main games of editions. Returns ['auto' => n, 'review' => n].
 */
function igdbMatchBatch(array $games, array $sys, array $regions): array {
    $plat = (int)$sys['igdb_platform_id'];
    $games = array_values($games);
    $q = [];
    foreach ($games as $i => $g)
        $q['s' . $i] = ['games', 'search ' . igdbStr((string)$g['title']) . '; fields ' . IGDB_FIELDS . '; where platforms = (' . $plat . '); limit 10;'];
    $res = igdbMulti($q);

    $keys = $hits = $picks = $other = [];
    foreach ($games as $i => $g) {
        $keys[$i]  = igdbTitleKey((string)$g['title']);
        $hits[$i]  = $res['s' . $i];
        $picks[$i] = igdbPick($hits[$i], $keys[$i]);
        if (!$picks[$i]) $other[$i] = (string)$g['title'];
    }
    foreach (igdbByOtherNames($other, $plat) as $i => $alt) {
        $picks[$i] = igdbPick($alt, $keys[$i]);   // only when one of its titles really is this title; else it's a candidate
        $known = array_column($hits[$i], 'id');
        foreach ($alt as $a) if (!in_array($a['id'], $known)) array_unshift($hits[$i], $a);   // best guesses first
    }

    $sure = igdbFillEditions(array_filter($picks));   // keeps the keys
    $out = ['auto' => 0, 'review' => 0];
    foreach ($games as $i => $g) {
        if (isset($sure[$i])) {
            igdbSave((int)$g['id'], 'auto', igdbRow($sure[$i], $plat, (string)$sys['region'], $regions));
            $out['auto']++;
        } else {
            igdbSave((int)$g['id'], 'review', [], igdbCandidates($hits[$i]));
            $out['review']++;
        }
    }
    return $out;
}

/**
 * Matches games that were never looked up, until $budget seconds have passed.
 * $gameIds: only these (new games of an import); otherwise every game of $sysId without a row.
 * Returns ['auto' => n, 'review' => n, 'left' => games still without a row].
 */
function igdbMatchPending(int $sysId, float $budget, ?array $gameIds = null): array {
    $out = ['auto' => 0, 'review' => 0, 'left' => 0];
    $sys = igdbSystem($sysId);
    if (!$sys || !igdbConfigured()) return $out;
    $sql = "SELECT g.id, g.title FROM games g LEFT JOIN game_igdb i ON i.game_id = g.id WHERE g.system_id = ? AND i.game_id IS NULL";
    $args = [$sysId];
    if ($gameIds !== null) {
        $gameIds = array_values(array_filter(array_map('intval', $gameIds)));
        if (!$gameIds) return $out;
        $sql .= ' AND g.id IN (' . implode(',', array_fill(0, count($gameIds), '?')) . ')';
        $args = array_merge($args, $gameIds);
    }
    $st = db()->prepare($sql . ' ORDER BY g.active DESC, g.sort_title');
    $st->execute($args);
    $todo  = $st->fetchAll();
    $start = microtime(true);
    $regions = igdbRegions();
    foreach (array_chunk($todo, IGDB_MULTI) as $n => $batch) {
        if (microtime(true) - $start > $budget) { $out['left'] = count($todo) - $n * IGDB_MULTI; break; }
        foreach (igdbMatchBatch($batch, $sys, $regions) as $k => $v) $out[$k] += $v;
    }
    return $out;
}

/** New games of an import: match what fits in a few seconds, quietly (the rest waits for Settings › IGDB › Match). */
function igdbMatchNew(array $gameIds, float $budget = 8.0): void {
    if (!$gameIds || !igdbConfigured()) return;
    try {
        $in = implode(',', array_map('intval', $gameIds));
        $bySys = [];
        foreach (db()->query("SELECT id, system_id FROM games WHERE id IN ($in)")->fetchAll() as $r) $bySys[(int)$r['system_id']][] = (int)$r['id'];
        $start = microtime(true);
        foreach ($bySys as $sysId => $ids) {
            $left = $budget - (microtime(true) - $start);
            if ($left <= 0) break;
            igdbMatchPending($sysId, $left, $ids);
        }
    } catch (Throwable $e) {
        error_log('game-collection: IGDB match on import: ' . $e->getMessage());
    }
}

/** Games never looked up, in active systems that have an IGDB platform and aren't set to "Don't match"
    (imports leave them for Settings › IGDB › Match). */
function igdbPendingCount(): int {
    static $n = null;
    if ($n !== null) return $n;
    if (!igdbConfigured()) return $n = 0;
    try {
        $skip = igdbSkipped();
        $n = (int)db()->query("SELECT COUNT(*) FROM games g JOIN systems s ON s.id = g.system_id
                               LEFT JOIN game_igdb i ON i.game_id = g.id
                               WHERE s.active = 1 AND s.igdb_platform_id > 0 AND i.game_id IS NULL"
                               . ($skip ? " AND s.id NOT IN (" . implode(',', $skip) . ")" : ""))->fetchColumn();
    } catch (Throwable $e) {
        $n = 0;   // e.g. the IGDB tables aren't there yet
    }
    return $n;
}

/** For admins: a notice linking to Settings › IGDB while games are waiting to be matched (dashboard). */
function igdbNoticeHtml(array $user): string {
    if ($user['role'] !== 'admin' || !($n = igdbPendingCount())) return '';
    return '<a class="cp-msg" href="'.BASE_URL.'/settings.php?s=igdb" style="display:block;margin-bottom:18px;text-decoration:none">'
         . tn('igdb.dash_notice', $n).'</a>';
}

/** A renamed game: an automatic match or open review is looked up again under the new title. */
function igdbTitleChanged(int $gameId): void {
    try {
        db()->prepare("DELETE FROM game_igdb WHERE game_id = ? AND status IN ('auto', 'review')")->execute([$gameId]);
        igdbMatchNew([$gameId], 5.0);
    } catch (Throwable $e) {
        error_log('game-collection: IGDB rematch: ' . $e->getMessage());
    }
}

/** Fetches the info of matched games again (ratings change), IGDB_PAGE IGDB games per call. Returns [updated rows, next offset|null]. */
function igdbRefresh(int $sysId, int $offset): array {
    $sys = igdbSystem($sysId);
    if (!$sys) return [0, null];
    $st = db()->prepare("SELECT DISTINCT i.igdb_id FROM game_igdb i JOIN games g ON g.id = i.game_id
                         WHERE g.system_id = ? AND i.status IN ('auto', 'confirmed') AND i.igdb_id IS NOT NULL
                         ORDER BY i.igdb_id LIMIT " . IGDB_PAGE . " OFFSET " . max(0, $offset));
    $st->execute([$sysId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) return [0, null];
    $regions = igdbRegions($offset === 0);   // the first call of a run also refreshes the region list
    $games = igdbFillEditions(igdbFetch($ids));
    $rows = db()->prepare("SELECT i.game_id, i.igdb_id, i.status FROM game_igdb i JOIN games g ON g.id = i.game_id
                           WHERE g.system_id = ? AND i.status IN ('auto', 'confirmed') AND i.igdb_id IN (" . implode(',', $ids) . ")");
    $rows->execute([$sysId]);
    $n = 0;
    foreach ($rows->fetchAll() as $r) {
        if (!isset($games[(int)$r['igdb_id']])) continue;   // gone from IGDB: keep what we had
        igdbSave((int)$r['game_id'], $r['status'], igdbRow($games[(int)$r['igdb_id']], (int)$sys['igdb_platform_id'], (string)$sys['region'], $regions));
        $n++;
    }
    return [$n, count($ids) < IGDB_PAGE ? null : $offset + IGDB_PAGE];
}

/** Links a game to an IGDB game picked by an admin (status confirmed). Returns the saved row. */
function igdbConfirm(int $gameId, int $igdbId): array {
    $st = db()->prepare("SELECT g.system_id FROM games g WHERE g.id = ?");
    $st->execute([$gameId]);
    $sys = igdbSystem((int)$st->fetchColumn());
    if (!$sys) throw new RuntimeException(tRaw('igdb.err_no_platform'));
    $g = igdbFillEditions(igdbFetch([$igdbId]))[$igdbId] ?? null;
    if (!$g) throw new RuntimeException(tRaw('igdb.err_not_found'));
    $row = igdbRow($g, (int)$sys['igdb_platform_id'], (string)$sys['region'], igdbRegions());
    igdbSave($gameId, 'confirmed', $row);
    return $row;
}

// ── Reading (the drawer and the collection columns) ──

/** One matched game_igdb row as the drawer and the collection columns use it. */
function igdbFormat(array $r): array {
    $f = fn($v) => $v === null ? null : (float)$v;
    $i = fn($v) => $v === null ? null : (int)$v;
    return [
        'igdb_id' => $i($r['igdb_id']), 'name' => $r['name'],
        'url' => $r['slug'] ? 'https://www.igdb.com/games/' . rawurlencode((string)$r['slug']) : null,
        'developers' => $r['developers'], 'publishers' => $r['publishers'], 'genres' => $r['genres'],
        'release_date' => $r['release_date'], 'release_prec' => $r['release_prec'],
        'release_region' => $r['release_region'], 'release_kind' => $r['release_kind'],
        'rating' => $f($r['rating']), 'rating_count' => $i($r['rating_count']),
        'critic_rating' => $f($r['critic_rating']), 'critic_count' => $i($r['critic_count']),
    ];
}

/**
 * A compilation without a match of its own: the developers, publishers and genres of the games inside it together,
 * and the earliest release date among them. No scores (those belong to one game).
 */
function igdbCombine(array $parts): array {
    $union = fn(string $k) => implode(', ', array_values(array_unique(array_merge(...array_map(
        fn($p) => $p[$k] ? explode(', ', (string)$p[$k]) : [], $parts))))) ?: null;
    $dated = array_values(array_filter($parts, fn($p) => !empty($p['release_date'])));
    usort($dated, fn($a, $b) => strcmp($a['release_date'], $b['release_date']));
    $d = $dated[0] ?? [];
    return [
        'igdb_id' => null, 'name' => null, 'url' => null,
        'developers' => $union('developers'), 'publishers' => $union('publishers'), 'genres' => $union('genres'),
        'release_date' => $d['release_date'] ?? null, 'release_prec' => $d['release_prec'] ?? null,
        'release_region' => $d['release_region'] ?? null, 'release_kind' => $d['release_kind'] ?? null,
        'rating' => null, 'rating_count' => null, 'critic_rating' => null, 'critic_count' => null,
        'from' => 'contents',
    ];
}

/**
 * IGDB info of every game of a system: game id → igdbFormat() + 'from':
 *   null                    — the game's own match
 *   ['game_id', 'label']    — another edition of its group (the group's main game first, then edition order)
 *   'contents'              — a compilation without a match of its own: combined from the games inside it
 * Games without any info are left out.
 */
function igdbResolveSystem(int $sysId): array {
    $st = db()->prepare("SELECT g.id, g.title, g.edition_label, g.group_id, gg.main_game_id, i.*
                         FROM games g
                         LEFT JOIN game_groups gg ON gg.id = g.group_id
                         LEFT JOIN game_igdb i ON i.game_id = g.id AND i.status IN ('auto', 'confirmed')
                         WHERE g.system_id = ?
                         ORDER BY g.edition_sort, g.id");
    $st->execute([$sysId]);
    $rows = $st->fetchAll();
    $own = $group = $out = [];
    foreach ($rows as $r) if ($r['igdb_id'] !== null) $own[(int)$r['id']] = igdbFormat($r) + ['from' => null];
    foreach ($rows as $r) {
        $gid = (int)$r['group_id'];
        if (!$gid || !isset($own[(int)$r['id']])) continue;
        $main = (int)$r['id'] === (int)$r['main_game_id'];
        if (!isset($group[$gid]) || ($main && !$group[$gid]['main']))
            $group[$gid] = ['main' => $main, 'id' => (int)$r['id'], 'label' => (string)($r['edition_label'] ?: $r['title'])];
    }
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        if (isset($own[$id])) $out[$id] = $own[$id];
        elseif ($g = $group[(int)$r['group_id']] ?? null) $out[$id] = ['from' => ['game_id' => $g['id'], 'label' => $g['label']]] + $own[$g['id']];
    }
    foreach (compilationsOfSystem($sysId) as $c) {
        if (isset($out[$c['id']])) continue;
        $parts = array_values(array_filter(array_map(fn($gid) => $out[$gid] ?? null, $c['items'])));
        if ($parts) $out[$c['id']] = igdbCombine($parts);
    }
    return $out;
}

/**
 * What the drawer shows for one game (see igdbResolveSystem()), plus for a compilation 'contents':
 * each game inside it with its own info. Null when there is nothing at all.
 */
function igdbInfo(int $gameId): ?array {
    $st = db()->prepare("SELECT system_id FROM games WHERE id = ?");
    $st->execute([$gameId]);
    $sysId = (int)$st->fetchColumn();
    if (!$sysId) return null;
    $all  = igdbResolveSystem($sysId);
    $info = $all[$gameId] ?? null;
    $items = [];
    foreach (compilationsOfSystem($sysId) as $c) if ($c['id'] === $gameId) $items = $c['items'];
    if ($items) {
        $tq = db()->query("SELECT id, title, edition_label FROM games WHERE id IN (" . implode(',', array_map('intval', $items)) . ")");
        $titles = [];
        foreach ($tq->fetchAll() as $t) $titles[(int)$t['id']] = $t['title'] . ($t['edition_label'] ? ' [' . $t['edition_label'] . ']' : '');
        $info = ($info ?? igdbCombine([])) + ['contents' => array_map(fn($gid) => ['game_id' => $gid, 'title' => $titles[$gid] ?? ''] + (isset($all[$gid]) ? [
            'url' => $all[$gid]['url'], 'developers' => $all[$gid]['developers'],
            'year' => $all[$gid]['release_date'] ? (int)substr($all[$gid]['release_date'], 0, 4) : null,
            'rating' => $all[$gid]['rating'], 'rating_count' => $all[$gid]['rating_count'],
            'critic_rating' => $all[$gid]['critic_rating'], 'critic_count' => $all[$gid]['critic_count'],
        ] : []), $items)];
    }
    return $info;
}

/** The collection columns: game id → the fields shown there (api/games.php). Empty while the table doesn't exist. */
function igdbColumns(int $sysId): array {
    try { $all = igdbResolveSystem($sysId); } catch (PDOException) { return []; }
    $keep = array_flip(['developers', 'publishers', 'genres', 'release_date', 'release_prec', 'release_region', 'release_kind',
                        'rating', 'rating_count', 'critic_rating', 'critic_count']);
    return array_map(fn($i) => array_intersect_key($i, $keep), $all);
}

// ── Covers ───────────────────────────────
// A game's cover: its uploaded cover (games.default_image) or its IGDB cover (game_igdb.cover_id, linked from
// images.igdb.com). games.cover_source picks one per game; NULL follows the site default (Settings › IGDB › Covers).
// The other one is shown when the chosen one doesn't exist.

function igdbCoverUrl(?string $id): ?string {
    return $id !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) ? sprintf(IGDB_COVER_URL, $id) : null;
}

/** The site default: 'uploaded' or 'igdb'. */
function coverDefaultSource(): string {
    return setting('igdb_cover_default') === 'igdb' ? 'igdb' : 'uploaded';
}

/**
 * Adds the cover fields to game rows (each with id and default_image):
 *   cover_url (the one to show, or null) · cover_uploaded · cover_igdb (URLs or null) · cover_source (null | uploaded | igdb)
 * The IGDB cover is the game's own match's, else another edition's of its group (main game first).
 */
function withCovers(array $games): array {
    $ids = array_values(array_unique(array_filter(array_map(fn($g) => (int)($g['id'] ?? 0), $games))));
    $extra = [];
    if ($ids) {
        try {
            $st = db()->query("
                SELECT g.id, g.cover_source,
                       COALESCE(i.cover_id, (
                           SELECT i2.cover_id FROM games g2
                           JOIN game_igdb i2 ON i2.game_id = g2.id AND i2.status IN ('auto', 'confirmed') AND i2.cover_id IS NOT NULL
                           LEFT JOIN game_groups gg ON gg.id = g2.group_id
                           WHERE g.group_id IS NOT NULL AND g2.group_id = g.group_id
                           ORDER BY g2.id = gg.main_game_id DESC, g2.edition_sort, g2.id LIMIT 1)) AS cover_id
                FROM games g
                LEFT JOIN game_igdb i ON i.game_id = g.id AND i.status IN ('auto', 'confirmed')
                WHERE g.id IN (" . implode(',', $ids) . ")");
            foreach ($st->fetchAll() as $r) $extra[(int)$r['id']] = $r;
        } catch (PDOException) { /* IGDB tables / columns not there yet: uploaded covers only */ }
    }
    $default = coverDefaultSource();
    foreach ($games as &$g) {
        $x   = $extra[(int)($g['id'] ?? 0)] ?? [];
        $up  = !empty($g['default_image']) ? BASE_URL . '/uploads/defaults/' . rawurlencode((string)$g['default_image']) : null;
        $ig  = igdbCoverUrl($x['cover_id'] ?? null);
        $src = in_array($x['cover_source'] ?? null, ['uploaded', 'igdb'], true) ? $x['cover_source'] : null;
        $g['cover_uploaded'] = $up;
        $g['cover_igdb']     = $ig;
        $g['cover_source']   = $src;
        $g['cover_url']      = ($src ?? $default) === 'igdb' ? ($ig ?? $up) : ($up ?? $ig);
    }
    unset($g);
    return $games;
}
