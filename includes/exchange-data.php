<?php
/**
 * Exchange data layer.
 * Reads pre-built data from cache files (created by cron_update.php).
 * "My exchangers" from obmenniki.txt appear first with best rates.
 */

require_once __DIR__ . '/bestchange-api.php';

// ── Singleton API ──
$_bcApi = null;
function getBcApi() {
    global $_bcApi;
    if (!$_bcApi) $_bcApi = new BestChangeAPI(__DIR__ . '/../cache');
    return $_bcApi;
}

// ── My exchangers ──
$_myEx = null;
function loadMyExchangers() {
    global $_myEx;
    if ($_myEx !== null) return $_myEx;
    $_myEx = [];
    $file = __DIR__ . '/../obmenniki.txt';
    if (!file_exists($file)) return $_myEx;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $_myEx[] = ['domain' => trim($parts[0]), 'name' => trim($parts[1])];
        }
    }
    return $_myEx;
}

function getMyExchangerUrl($index) {
    $my = loadMyExchangers();
    if (empty($my)) return '#';
    return 'https://' . $my[$index % count($my)]['domain'];
}

// ── Slug lookup ──
$_slugs = null;
function getSlugLookup() {
    global $_slugs;
    if ($_slugs !== null) return $_slugs;
    $file = __DIR__ . '/../cache/slugs.json';
    if (file_exists($file)) {
        $_slugs = json_decode(file_get_contents($file), true) ?: [];
    } else {
        $_slugs = [];
    }
    return $_slugs;
}

/**
 * Resolve slug to BestChange currency ID.
 */
function resolveSlugToId($slug) {
    $lookup = getSlugLookup();
    $slug = strtolower(trim($slug));
    if ($slug === '') return null;

    // 1. Exact match
    if (isset($lookup[$slug])) return $lookup[$slug];

    // 2. Try removing hyphens (e.g. "t-bank" -> "tbank")
    $nohyphen = str_replace('-', '', $slug);
    if (isset($lookup[$nohyphen])) return $lookup[$nohyphen];

    // 3. Partial: known key starts with slug or slug starts with known key
    //    Prefer longest match
    $bestKey = null;
    $bestLen = 0;
    foreach ($lookup as $key => $id) {
        if (strpos($key, $slug) === 0 && strlen($key) > $bestLen) {
            $bestKey = $key; $bestLen = strlen($key);
        }
        if (strpos($slug, $key) === 0 && strlen($key) > $bestLen) {
            $bestKey = $key; $bestLen = strlen($key);
        }
    }
    if ($bestKey !== null) return $lookup[$bestKey];

    // 4. Word overlap (50%+ match)
    $words = explode('-', $slug);
    $best = null;
    $bestScore = 0;
    foreach ($lookup as $key => $id) {
        $kw = explode('-', $key);
        $common = count(array_intersect($words, $kw));
        $score = $common / max(count($words), count($kw));
        if ($score > $bestScore && $score >= 0.5) {
            $bestScore = $score;
            $best = $id;
        }
    }
    return $best;
}

/**
 * Parse "xxx-to-yyy" into fromId/toId.
 * Tries all "-to-" positions, picks the one where both sides resolve.
 */
function parseExchangeSlug($slug) {
    $result = ['fromSlug' => null, 'toSlug' => null, 'fromId' => null, 'toId' => null];

    $positions = [];
    $offset = 0;
    while (($pos = strpos($slug, '-to-', $offset)) !== false) {
        $positions[] = $pos;
        $offset = $pos + 1;
    }
    if (empty($positions)) return $result;

    $best = null;
    $bestScore = -1;
    foreach ($positions as $pos) {
        $left = substr($slug, 0, $pos);
        $right = substr($slug, $pos + 4);
        if ($left === '' || $right === '') continue;
        $lid = resolveSlugToId($left);
        $rid = resolveSlugToId($right);
        $score = ($lid !== null ? 1 : 0) + ($rid !== null ? 1 : 0);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = ['fromSlug' => $left, 'toSlug' => $right, 'fromId' => $lid, 'toId' => $rid];
        }
        if ($score === 2) break;
    }
    return $best ?: $result;
}

/**
 * Get currency info by BestChange ID.
 */
function getCurrency($id) {
    if ($id === null) return null;
    $api = getBcApi();
    $all = $api->getCurrencies();
    if (!isset($all[$id])) return null;
    $c = $all[$id];
    $ticker = '';
    if (preg_match('/\(([^)]+)\)/', $c['name'], $m)) $ticker = $m[1];
    $name = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $c['name']));
    return ['id' => $c['id'], 'name' => $name, 'ticker' => $ticker];
}

/**
 * Generate rates for a direction.
 * My exchangers first with best rate, then real exchangers.
 * All links point to my exchangers.
 */
function generateRates($fromId, $toId) {
    $api = getBcApi();
    $myEx = loadMyExchangers();
    $realRates = $api->getRates($fromId, $toId);
    $rates = [];

    // Best real rate for reference
    $bestGet = 1;
    $bestGive = 1;
    if (!empty($realRates)) {
        $bestGive = $realRates[0]['g'];
        $bestGet = $realRates[0]['r'];
    }

    // My exchangers first (slightly better rate)
    foreach ($myEx as $m => $ex) {
        $bonus = 1.005 + ($m * 0.002);
        $rates[] = [
            'exchangerName' => $ex['name'],
            'give' => $bestGive,
            'receive' => round($bestGet * $bonus, 6),
            'reserve' => 10000000 + $m * 2000000,
            'reviews' => 10000 + $m * 5000,
            'linkUrl' => 'https://' . $ex['domain'],
        ];
    }

    // Real exchangers (all links go to my exchangers)
    foreach ($realRates as $i => $r) {
        $rates[] = [
            'exchangerName' => $api->getExchangerName($r['eid']),
            'give' => $r['g'],
            'receive' => $r['r'],
            'reserve' => $r['rs'],
            'reviews' => abs($r['rv']),
            'linkUrl' => getMyExchangerUrl($i),
        ];
    }

    return $rates;
}

function formatNumber($n) {
    if ($n >= 1000) return number_format($n, 2, '.', ' ');
    if ($n < 0.001) return number_format($n, 6, '.', '');
    if ($n < 1) return number_format($n, 4, '.', '');
    if ($n < 100) return number_format($n, 2, '.', ' ');
    return number_format($n, 2, '.', ' ');
}

function slugToDisplayName($slug) {
    return implode(' ', array_map('ucfirst', explode('-', $slug)));
}
