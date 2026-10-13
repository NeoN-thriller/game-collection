<?php
// Database export: the game catalogue (every system, game, edition and barcode, shared by all users) as CSV, JSON or XML,
// with the columns and their order chosen by an admin (Settings › Database export). Meant as an open data download,
// not a backup: nothing of a user's collection is in it. Loaded by core.php.
// Used by settings/s_data_export.php, settings/actions_admin.php (save_export_settings) and api/catalogue_export.php.
if (!defined('DB_HOST')) { http_response_code(403); exit; }

const DX_FORMATS = ['csv', 'json', 'xml'];
const DX_SEPS    = ['comma' => ',', 'semicolon' => ';', 'tab' => "\t"];   // CSV separator
const DX_ROWS    = ['barcode', 'game'];   // one row per barcode (a game without one gets a single row) | one row per game
const DX_LIST_SEP = ' | ';                 // several values in one CSV cell (one row per game, compilations)

/**
 * Every column, in the default order: id => [on by default, type]. The id is also the CSV header, the JSON key and
 * the XML element name, so never rename one (people's scripts read them). Types: text | money | int | bool | list
 * (list = several values: an array in JSON, repeated elements in XML, joined with DX_LIST_SEP in CSV).
 * Title: dx.col.<id> · help: dx.colh.<id>
 */
const DX_COLUMNS = [
    'system'               => [true,  'text'],
    'price_source_id'      => [true,  'text'],
    'ean'                  => [true,  'text'],
    'title'                => [true,  'text'],
    'edition'              => [true,  'text'],
    'variant'              => [true,  'text'],
    'system_name'          => [false, 'text'],
    'region'               => [false, 'text'],
    'base_title'           => [false, 'text'],
    'price_source_url'     => [false, 'text'],
    'loose_price'          => [false, 'money'],
    'cib_price'            => [false, 'money'],
    'new_price'            => [false, 'money'],
    'currency'             => [false, 'text'],
    'prices_updated'       => [false, 'text'],
    'barcode_note'         => [false, 'text'],
    'barcode_count'        => [false, 'int'],
    'compilation_contents' => [false, 'list'],
    'in_compilations'      => [false, 'list'],
    'image_url'            => [false, 'text'],
    'game_id'              => [false, 'int'],
    'igdb_id'              => [false, 'int'],
    'active'               => [false, 'bool'],
    'added_at'             => [false, 'text'],
];
/** Columns that hold imported prices (the page warns about their terms when one is switched on). */
const DX_PRICE_COLUMNS = ['loose_price', 'cib_price', 'new_price', 'prices_updated'];
/** Per-barcode columns: with one row per game they become lists. */
const DX_BARCODE_COLUMNS = ['ean', 'variant', 'barcode_note'];

/**
 * The export settings, cleaned: ['cols' => [['id', 'on'], …] in the chosen order, 'format', 'sep', 'rows',
 * 'systems' => [system ids] ([] = every active system), 'inactive' => bool (also games switched off in the catalogue)].
 * $raw = a decoded dx_config (or a request); null reads the saved one. Unknown columns are dropped, new ones added (off).
 */
function dxConfig(?array $raw = null): array {
    if ($raw === null) $raw = json_decode(setting('dx_config'), true) ?: [];
    $cols = [];
    foreach ((array)($raw['cols'] ?? []) as $c) {
        $id = is_array($c) ? (string)($c['id'] ?? '') : '';
        if (isset(DX_COLUMNS[$id]) && !isset($cols[$id])) $cols[$id] = ['id' => $id, 'on' => !empty($c['on'])];
    }
    foreach (DX_COLUMNS as $id => [$on]) if (!isset($cols[$id])) $cols[$id] = ['id' => $id, 'on' => $raw ? false : $on];
    if (!array_filter($cols, fn($c) => $c['on'])) foreach ($cols as $id => $c) $cols[$id]['on'] = DX_COLUMNS[$id][0];   // never an empty file
    $systems = array_values(array_unique(array_filter(array_map('intval', (array)($raw['systems'] ?? [])))));
    sort($systems);
    return [
        'cols'     => array_values($cols),
        'format'   => in_array($raw['format'] ?? '', DX_FORMATS, true) ? $raw['format'] : 'csv',
        'sep'      => is_string($raw['sep'] ?? null) && isset(DX_SEPS[$raw['sep']]) ? $raw['sep'] : 'comma',
        'rows'     => in_array($raw['rows'] ?? '', DX_ROWS, true) ? $raw['rows'] : 'barcode',
        'systems'  => $systems,
        'inactive' => !empty($raw['inactive']),
    ];
}

/** The ids of the columns that are switched on, in order. */
function dxColumnIds(array $cfg): array {
    return array_values(array_map(fn($c) => $c['id'], array_filter($cfg['cols'], fn($c) => $c['on'])));
}

/** The export settings from an admin's download link (?cols=a,b&format=…&sep=…&rows=…&sys=1,2&inactive=1). */
function dxConfigFromRequest(array $q): array {
    $ids = array_filter(explode(',', (string)($q['cols'] ?? '')));
    return dxConfig([
        'cols'     => array_map(fn($id) => ['id' => $id, 'on' => true], $ids),
        'format'   => $q['format'] ?? '',
        'sep'      => $q['sep'] ?? '',
        'rows'     => $q['rows'] ?? '',
        'systems'  => explode(',', (string)($q['sys'] ?? '')),
        'inactive' => ($q['inactive'] ?? '') === '1',
    ]);
}

/** This site's address (scheme, host and BASE_URL), for image and price links in the file. */
function dxSiteUrl(): string {
    return (isHttps() ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').BASE_URL;
}

/**
 * The export rows, one at a time: column id => value (null = empty; arrays for list columns).
 * $limit > 0 stops after that many games (the preview). Flagged barcodes (reported as wrong) are left out.
 */
function dxRows(array $cfg, int $limit = 0): Generator {
    $ids  = dxColumnIds($cfg);
    $need = array_flip($ids);
    $where = ['s.active = 1'];
    $args  = [];
    if ($cfg['systems']) {
        $where = ['s.id IN ('.implode(',', array_fill(0, count($cfg['systems']), '?')).')'];   // a chosen hidden system is fine
        $args  = $cfg['systems'];
    }
    if (!$cfg['inactive']) $where[] = 'g.active = 1';
    $sql = "SELECT g.id, g.title, g.edition_label, g.external_id, g.price_link, g.loose_price, g.cib_price, g.new_price,
                   g.loose_price_updated_at, g.cib_price_updated_at, g.new_price_updated_at, g.default_image, g.active, g.created_at,
                   s.short_name, s.name AS system_name, s.region, gg.title AS group_title
            FROM games g
            JOIN systems s ON s.id = g.system_id
            LEFT JOIN game_groups gg ON gg.id = g.group_id
            WHERE ".implode(' AND ', $where)."
            ORDER BY s.sort_order, s.id, g.sort_title, g.edition_sort, g.id".($limit > 0 ? ' LIMIT '.(int)$limit : '');
    $st = db()->prepare($sql);
    $st->execute($args);
    $games = $st->fetchAll();
    if (!$games) return;
    $gameIds = array_map(fn($g) => (int)$g['id'], $games);
    $in      = implode(',', $gameIds);   // ints from the database

    // Barcodes per game (only read when a barcode column is on, or for one row per barcode)
    $codes = [];
    if ($cfg['rows'] === 'barcode' || array_intersect_key($need, array_flip([...DX_BARCODE_COLUMNS, 'barcode_count']))) {
        try {
            foreach (db()->query("SELECT game_id, barcode, variant, note FROM game_barcodes
                                  WHERE game_id IN ($in) AND flagged_at IS NULL ORDER BY game_id, variant, barcode") as $b) {
                $codes[(int)$b['game_id']][] = $b;
            }
        } catch (PDOException) { /* barcode tables not created yet (database update pending) */ }
    }
    // Compilations: the games a compilation holds, and the compilations a game is in (titles with their edition)
    $contents = $partOf = [];
    if (isset($need['compilation_contents']) || isset($need['in_compilations'])) {
        $label = fn(array $r, string $p) => $r[$p.'title'].($r[$p.'edition'] !== null && $r[$p.'edition'] !== '' ? ' ('.$r[$p.'edition'].')' : '');
        foreach (db()->query("SELECT ci.compilation_id, ci.game_id, c.title AS c_title, c.edition_label AS c_edition, g.title AS g_title, g.edition_label AS g_edition
                              FROM compilation_items ci JOIN games c ON c.id = ci.compilation_id JOIN games g ON g.id = ci.game_id
                              WHERE ci.compilation_id IN ($in) OR ci.game_id IN ($in)
                              ORDER BY ci.sort_order, g.sort_title, c.sort_title") as $r) {
            $contents[(int)$r['compilation_id']][] = $label($r, 'g_');
            $partOf[(int)$r['game_id']][]          = $label($r, 'c_');
        }
    }
    // IGDB: the id of each game's own match (Settings › IGDB)
    $igdbIds = [];
    if (isset($need['igdb_id'])) {
        try {
            $igdbIds = array_map('intval', db()->query("SELECT game_id, igdb_id FROM game_igdb
                WHERE game_id IN ($in) AND status IN ('auto', 'confirmed') AND igdb_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (PDOException) { /* IGDB table not created yet (database update pending) */ }
    }
    $site     = isset($need['image_url']) ? dxSiteUrl() : '';
    $currency = setting('currency_symbol');
    $money    = fn($v) => $v === null || $v === '' ? null : (float)$v;

    foreach ($games as $g) {
        $gid = (int)$g['id'];
        $upd = array_filter([$g['loose_price_updated_at'], $g['cib_price_updated_at'], $g['new_price_updated_at']]);
        $all = [
            'system'               => $g['short_name'],
            'price_source_id'      => $g['external_id'],
            'title'                => $g['title'],
            'edition'              => $g['edition_label'],
            'system_name'          => $g['system_name'],
            'region'               => $g['region'],
            'base_title'           => $g['group_title'] ?? $g['title'],
            'price_source_url'     => priceLinkSafe($g['price_link']),
            'loose_price'          => $money($g['loose_price']),
            'cib_price'            => $money($g['cib_price']),
            'new_price'            => $money($g['new_price']),
            'currency'             => $currency,
            'prices_updated'       => $upd ? substr(max($upd), 0, 10) : null,
            'barcode_count'        => count($codes[$gid] ?? []),
            'compilation_contents' => $contents[$gid] ?? [],
            'in_compilations'      => $partOf[$gid] ?? [],
            'image_url'            => $g['default_image'] ? $site.'/uploads/defaults/'.rawurlencode($g['default_image']) : null,
            'game_id'              => $gid,
            'igdb_id'              => $igdbIds[$gid] ?? null,
            'active'               => (bool)$g['active'],
            'added_at'             => $g['created_at'] ? substr($g['created_at'], 0, 10) : null,
        ];
        $pick = function (array $codeVals) use ($all, $ids): array {
            $row = [];
            foreach ($ids as $id) $row[$id] = array_key_exists($id, $codeVals) ? $codeVals[$id] : ($all[$id] ?? null);
            return $row;
        };
        $gc = $codes[$gid] ?? [];
        if ($cfg['rows'] === 'game') {
            yield $pick([
                'ean'          => array_column($gc, 'barcode'),
                'variant'      => array_map(fn($b) => (string)$b['variant'], $gc),
                'barcode_note' => array_map(fn($b) => (string)$b['note'], $gc),
            ]);
        } elseif (!$gc) {
            yield $pick(['ean' => null, 'variant' => null, 'barcode_note' => null]);
        } else {
            foreach ($gc as $b) yield $pick(['ean' => $b['barcode'], 'variant' => $b['variant'] !== '' ? $b['variant'] : null, 'barcode_note' => $b['note']]);
        }
    }
}

/** One value as CSV/XML text: prices with a dot and two decimals, booleans as 1/0. */
function dxText(mixed $v): string {
    if ($v === null) return '';
    if (is_bool($v))  return $v ? '1' : '0';
    if (is_float($v)) return number_format($v, 2, '.', '');
    return (string)$v;
}

/** Sends the export as a file download and stops. $public: the anonymous link (cacheable for an hour). */
function dxSend(array $cfg, bool $public = false): never {
    session_write_close();
    @set_time_limit(300);
    while (ob_get_level()) ob_end_clean();
    $ids  = dxColumnIds($cfg);
    $ext  = $cfg['format'];
    $mime = ['csv' => 'text/csv', 'json' => 'application/json', 'xml' => 'application/xml'][$ext];
    header('Content-Type: '.$mime.'; charset=utf-8');
    header('Content-Disposition: attachment; filename="game_catalogue_'.date('Ymd').'.'.$ext.'"');
    header($public ? 'Cache-Control: public, max-age=3600' : 'Cache-Control: no-cache, no-store');
    header('X-Content-Type-Options: nosniff');
    if ($public) header('Access-Control-Allow-Origin: *');   // open data: other sites may read it
    $out = fopen('php://output', 'wb');

    if ($ext === 'csv') {
        $sep  = DX_SEPS[$cfg['sep']];
        // Cells that a spreadsheet would run as a formula (starting with = + - @) get a ' in front
        $cell = function (mixed $v) use ($sep): string {
            $v = is_array($v) ? implode(DX_LIST_SEP, array_map('dxText', $v)) : dxText($v);
            if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) $v = "'".$v;
            return '"'.str_replace('"', '""', $v).'"';
        };
        fwrite($out, "\xEF\xBB\xBF");   // UTF-8 mark, so Excel reads accents right
        fwrite($out, implode($sep, $ids)."\r\n");
        foreach (dxRows($cfg) as $row) fwrite($out, implode($sep, array_map($cell, $row))."\r\n");
    } elseif ($ext === 'json') {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;
        $meta = [
            'source'      => setting('site_name'),
            'url'         => dxSiteUrl(),
            'exported_at' => date('c'),
            'rows'        => $cfg['rows'],
            'currency'    => setting('currency_symbol'),
            'columns'     => $ids,
        ];
        fwrite($out, rtrim(substr(json_encode($meta, $flags | JSON_PRETTY_PRINT), 0, -1)).",\n    \"items\": [");
        $first = true;
        foreach (dxRows($cfg) as $row) {
            fwrite($out, ($first ? "\n" : ",\n").'        '.json_encode($row, $flags));
            $first = false;
        }
        fwrite($out, ($first ? '' : "\n    ")."]\n}\n");
    } else {
        // Characters XML 1.0 can't hold are dropped
        $x = fn(string $s) => htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '', ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        fwrite($out, '<?xml version="1.0" encoding="UTF-8"?>'."\n");
        fwrite($out, '<catalogue source="'.$x(setting('site_name')).'" url="'.$x(dxSiteUrl()).'" exported_at="'.date('c')
            .'" rows="'.$cfg['rows'].'" currency="'.$x(setting('currency_symbol')).'">'."\n");
        foreach (dxRows($cfg) as $row) {
            $xml = '  <game>';
            foreach ($row as $id => $v) {
                if (is_array($v)) {
                    // A list: one <id> per value (an empty list leaves the element out)
                    foreach ($v as $one) $xml .= '<'.$id.'>'.$x(dxText($one)).'</'.$id.'>';
                } elseif ($v === null) {
                    $xml .= '<'.$id.'/>';
                } else {
                    $xml .= '<'.$id.'>'.$x(dxText($v)).'</'.$id.'>';
                }
            }
            fwrite($out, $xml."</game>\n");
        }
        fwrite($out, "</catalogue>\n");
    }
    fclose($out);
    exit;
}
