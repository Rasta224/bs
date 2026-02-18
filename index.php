<?php
/**
 * Main router for BestChange clone.
 * Data comes from pre-built cache files (created by cron_update.php).
 */

// Uncomment to debug:
// ini_set('display_errors', 1); error_reporting(E_ALL);

ini_set('memory_limit', '128M');
ini_set('pcre.backtrack_limit', '5000000');

require_once __DIR__ . '/includes/exchange-data.php';
require_once __DIR__ . '/includes/html-processor.php';

// Route
$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
$route = trim(parse_url($uri, PHP_URL_PATH), '/');
$route = preg_replace('#^index\.php/?#', '', $route);

// ── Main page ──
if ($route === '') {
    header('Content-Type: text/html; charset=utf-8');
    echo buildMainPage();
    exit;
}

// ── Debug: /debug-slug/tether-trc20-to-solana ──
if (preg_match('#^debug-slug/(.+)$#', $route, $dm)) {
    header('Content-Type: text/plain; charset=utf-8');
    $slug = $dm[1];
    echo "=== Debug: $slug ===\n\n";

    $parsed = parseExchangeSlug($slug);
    echo "fromSlug: " . (isset($parsed['fromSlug']) ? $parsed['fromSlug'] : 'NULL') . "\n";
    echo "toSlug:   " . (isset($parsed['toSlug']) ? $parsed['toSlug'] : 'NULL') . "\n";
    echo "fromId:   " . (isset($parsed['fromId']) ? $parsed['fromId'] : 'NULL') . "\n";
    echo "toId:     " . (isset($parsed['toId']) ? $parsed['toId'] : 'NULL') . "\n\n";

    if ($parsed['fromId']) {
        $f = getCurrency($parsed['fromId']);
        echo "FROM: " . ($f ? $f['name'] . ' (' . $f['ticker'] . ') [ID=' . $f['id'] . ']' : 'NOT FOUND') . "\n";
    }
    if ($parsed['toId']) {
        $t = getCurrency($parsed['toId']);
        echo "TO: " . ($t ? $t['name'] . ' (' . $t['ticker'] . ') [ID=' . $t['id'] . ']' : 'NOT FOUND') . "\n";
    }

    if ($parsed['fromId'] && $parsed['toId']) {
        $rates = generateRates($parsed['fromId'], $parsed['toId']);
        echo "\nRates found: " . count($rates) . "\n";
        foreach (array_slice($rates, 0, 5) as $i => $r) {
            echo "  $i. {$r['exchangerName']}: {$r['give']} -> {$r['receive']}\n";
        }
    }

    echo "\n--- Cache files ---\n";
    foreach (['currencies.json', 'exchangers.json', 'rates.dat', 'slugs.json', 'last_update.txt'] as $f) {
        $path = __DIR__ . '/cache/' . $f;
        if (file_exists($path)) {
            echo "  $f: " . number_format(filesize($path)) . " bytes, " . date('Y-m-d H:i:s', filemtime($path)) . "\n";
        } else {
            echo "  $f: NOT FOUND\n";
        }
    }

    echo "\n--- Slug lookup (first 30) ---\n";
    $slugs = getSlugLookup();
    $i = 0;
    foreach ($slugs as $s => $id) {
        echo "  '$s' => $id\n";
        if (++$i >= 30) { echo "  ... (" . count($slugs) . " total)\n"; break; }
    }
    exit;
}

// ── Exchange direction: /xxx-to-yyy ──
if (preg_match('/^[\w-]+-to-[\w-]+$/', $route)) {
    $parsed = parseExchangeSlug($route);

    if ($parsed['fromId'] && $parsed['toId']) {
        $html = buildExchangePage($parsed['fromId'], $parsed['toId'], $parsed['fromSlug'], $parsed['toSlug']);
        if ($html) {
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        }
    }

    // Fallback: show main page
    header('Content-Type: text/html; charset=utf-8');
    echo buildMainPage();
    exit;
}

// ── Anything else -> main ──
header('Location: /');
exit;
