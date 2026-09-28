<?php
// Languages: lang/<code>.json files with flat dotted keys, English as the fallback.
// Loaded by core.php.
//
// A language file looks like:
//   { "_meta": { "name": "Nederlands", "code": "nl" },
//     "common.save": "Opslaan",
//     "collection.count": "{n} games" }
//
// Texts may contain {placeholders} and only these tags: <b> <i> <em> <strong> <code> <br>.
// Uploaded files are checked for that, so t() output is safe to put straight into HTML.
if (!defined('DB_HOST')) { http_response_code(403); exit; }

const LANG_DIR          = __DIR__ . '/lang/';
const LANG_FALLBACK     = 'en';
const LANG_CODE_PATTERN = '/^[a-z]{2}(-[A-Z]{2})?$/';
const LANG_ALLOWED_TAGS = '~</?(?:b|i|em|strong|code)>|<br\s*/?>~i';
const LANG_MAX_BYTES    = 1024 * 1024;

/** Installed languages: code => ['code','name','keys']. English first, then by name. */
function availableLanguages(): array {
    static $langs = null;
    if ($langs !== null) return $langs;
    $langs = [];
    foreach (glob(LANG_DIR . '*.json') ?: [] as $file) {
        $code = basename($file, '.json');
        if (!preg_match(LANG_CODE_PATTERN, $code)) continue;
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) continue;
        $langs[$code] = [
            'code' => $code,
            'name' => is_string($data['_meta']['name'] ?? null) ? $data['_meta']['name'] : $code,
            'keys' => count(array_filter(array_keys($data), fn($k) => $k !== '_meta')),
        ];
    }
    uasort($langs, fn($a, $b) => [$a['code'] !== LANG_FALLBACK, $a['name']] <=> [$b['code'] !== LANG_FALLBACK, $b['name']]);
    return $langs;
}

/** All texts of one language (without _meta). */
function langStrings(string $code): array {
    static $cache = [];
    if (!isset($cache[$code])) {
        $data = [];
        if (preg_match(LANG_CODE_PATTERN, $code) && is_file(LANG_DIR . $code . '.json')) {
            $data = json_decode((string)@file_get_contents(LANG_DIR . $code . '.json'), true) ?: [];
        }
        unset($data['_meta']);
        $cache[$code] = array_filter($data, 'is_string');
    }
    return $cache[$code];
}

/** Site default language. */
function siteLanguage(): string {
    $l = setting('default_language');
    return isset(availableLanguages()[$l]) ? $l : LANG_FALLBACK;
}

/**
 * Language of this request: the signed-in user's choice, else the site default.
 * Visitors who aren't signed in (sign-in page, public wishlists) always get the site default.
 */
function currentLang(): string {
    static $lang = null;
    if ($lang === null) {
        $u = auth();
        $pick = $u['language'] ?? null;
        $lang = (is_string($pick) && isset(availableLanguages()[$pick])) ? $pick : siteLanguage();
    }
    return $lang;
}

/** Merged texts for the current language, English underneath for missing keys. */
function langDict(): array {
    static $dict = null;
    if ($dict === null) $dict = langStrings(currentLang()) + langStrings(LANG_FALLBACK);
    return $dict;
}

/** Text with {placeholders} filled in as-is. For plain-text uses (JSON messages, headers, JS). */
function tRaw(string $key, array $vars = []): string {
    $s = langDict()[$key] ?? $key;
    if ($vars) $s = strtr($s, array_combine(array_map(fn($k) => '{'.$k.'}', array_keys($vars)), array_map('strval', $vars)));
    return $s;
}

/** Text for HTML (also safe inside double-quoted attributes). Placeholder values are escaped. */
function t(string $key, array $vars = []): string {
    $s = str_replace('"', '&quot;', langDict()[$key] ?? $key);
    foreach ($vars as $k => $v) $s = str_replace('{'.$k.'}', htmlspecialchars((string)$v, ENT_QUOTES), $s);
    return $s;
}

/** Plural: uses "$key_one" when $n is 1, else "$key_other". {n} is filled in (formatted). */
function tn(string $key, int|float $n, array $vars = []): string {
    return t($key.($n == 1 ? '_one' : '_other'), $vars + ['n' => fmtNum($n)]);
}

function tnRaw(string $key, int|float $n, array $vars = []): string {
    return tRaw($key.($n == 1 ? '_one' : '_other'), $vars + ['n' => fmtNum($n)]);
}

/** Keys of $code that English has but $code doesn't. */
function missingLangKeys(string $code): array {
    return array_values(array_diff(array_keys(langStrings(LANG_FALLBACK)), array_keys(langStrings($code))));
}

/**
 * Checks an uploaded language file. Returns [data, error]: data is the cleaned array
 * (with _meta) to write, or null with an error message.
 */
function validateLangFile(string $json): array {
    if (strlen($json) > LANG_MAX_BYTES) return [null, tRaw('admin.lang.err_size')];
    $data = json_decode($json, true);
    if (!is_array($data) || array_is_list($data)) return [null, tRaw('admin.lang.err_json')];
    $code = $data['_meta']['code'] ?? null;
    $name = $data['_meta']['name'] ?? null;
    if (!is_string($code) || !preg_match(LANG_CODE_PATTERN, $code)) return [null, tRaw('admin.lang.err_code')];
    if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 50) return [null, tRaw('admin.lang.err_name')];
    $clean = ['_meta' => ['name' => trim($name), 'code' => $code]];
    foreach ($data as $k => $v) {
        if ($k === '_meta') continue;
        if (!is_string($v) || !preg_match('/^[a-z0-9_.]+$/', (string)$k)) return [null, tRaw('admin.lang.err_entry', ['key' => (string)$k])];
        // Only the whitelisted tags, without attributes
        if (str_contains(preg_replace(LANG_ALLOWED_TAGS, '', $v), '<')) return [null, tRaw('admin.lang.err_html', ['key' => $k])];
        $clean[$k] = $v;
    }
    return [$clean, null];
}

// ── Client side ──────────────────────────

/**
 * Put in every page's <head> (after csrfScript()). Gives the page's JS:
 *   t(key, vars)    — text, placeholder values HTML-escaped
 *   tRaw(key, vars) — text, placeholders as-is (for alert/confirm/textContent)
 *   tn(key, n, vars) / tnRaw(...) — plural: key_one / key_other, {n} filled in
 *   money(v, dec)   — amount with the site currency, e.g. "€1,234.56"
 *   fmtNum(v, dec)  — number with the site separators
 *   fmtDate(value)  — date in the site format
 * $prefixes limits which texts are sent, e.g. ['common', 'collection'].
 */
function appScript(array $prefixes = ['common']): string {
    $prefixes = array_unique(array_merge(['common', 'js', 'date'], $prefixes));
    $texts = [];
    foreach (langDict() as $k => $v) {
        foreach ($prefixes as $p) {
            if (str_starts_with($k, $p.'.')) { $texts[$k] = $v; break; }
        }
    }
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
    $lang = json_encode($texts, $flags);
    $fmt  = json_encode(formatClientConfig(), $flags);
    return <<<HTML
<script>
window.LANG = $lang;
window.FMT  = $fmt;
function tRaw(key, vars) {
  let s = (key in LANG) ? LANG[key] : key;
  if (vars) for (const k in vars) s = s.split('{' + k + '}').join(String(vars[k]));
  return s;
}
function t(key, vars) {
  const e = v => String(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let s = ((key in LANG) ? LANG[key] : key).replace(/"/g, '&quot;');
  if (vars) for (const k in vars) s = s.split('{' + k + '}').join(e(vars[k]));
  return s;
}
function tn(key, n, vars) { return t(key + (n == 1 ? '_one' : '_other'), Object.assign({ n: fmtNum(n) }, vars)); }
function tnRaw(key, n, vars) { return tRaw(key + (n == 1 ? '_one' : '_other'), Object.assign({ n: fmtNum(n) }, vars)); }
function fmtNum(v, dec = 0) {
  const n = Number(v);
  if (!isFinite(n)) return '';
  const [i, f] = Math.abs(n).toFixed(dec).split('.');
  return (n < 0 && Number(n.toFixed(dec)) !== 0 ? '-' : '') + i.replace(/\B(?=(\d{3})+(?!\d))/g, FMT.thou) + (f ? FMT.dec + f : '');
}
function money(v, dec = 2) {
  const n = fmtNum(v, dec), sp = FMT.space ? ' ' : '';
  return FMT.after ? n + sp + FMT.sym : FMT.sym + sp + n;
}
function fmtDate(v) {
  if (v === null || v === undefined || v === '') return '';
  const d = v instanceof Date ? v : new Date(String(v).replace(' ', 'T'));
  if (isNaN(d)) return String(v);
  const p = n => String(n).padStart(2, '0');
  return FMT.date.replace(/YYYY|MMM|MM|DD|D/g, m => ({
    YYYY: d.getFullYear(), MMM: FMT.months[d.getMonth()], MM: p(d.getMonth() + 1), DD: p(d.getDate()), D: d.getDate()
  }[m]));
}
</script>
HTML;
}
