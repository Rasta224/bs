<?php
// CRON script: downloads BestChange data, parses it, saves to JSON files.
// Run every 5 minutes via cron: 
//   crontab -e
//   then add: 0/5 * * * * php /path/to/cron_update.php
// Or call via browser: https://yoursite.com/cron_update.php?key=YOUR_SECRET

$SECRET_KEY = 'bestchange2025update'; // change this

// Show ALL errors for this script
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Helper function - define FIRST before any calls
function msg($text) {
    $ts = date('H:i:s');
    if (php_sapi_name() === 'cli') {
        echo "[$ts] $text\n";
    } else {
        echo "[$ts] $text<br>\n";
        @ob_flush(); @flush();
    }
}

header('Content-Type: text/html; charset=utf-8');
echo '<pre style="font-family:monospace;font-size:14px;">';

// Allow cron (CLI) or browser with secret key
$isCli = (php_sapi_name() === 'cli');
$key = isset($_GET['key']) ? $_GET['key'] : '';
if (!$isCli && $key !== $SECRET_KEY) {
    die('Forbidden');
}

ini_set('memory_limit', '256M');
set_time_limit(120);

// Check required extensions
msg("PHP version: " . phpversion());
if (!extension_loaded('curl')) { die("ERROR: curl extension not loaded!\n"); }
if (!class_exists('ZipArchive')) { die("ERROR: ZipArchive class not available! Install php-zip.\n"); }
msg("Extensions OK (curl, zip)");

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    if (!@mkdir($cacheDir, 0755, true)) {
        die("ERROR: Cannot create cache dir: $cacheDir\n");
    }
}
if (!is_writable($cacheDir)) {
    die("ERROR: Cache dir is not writable: $cacheDir\n");
}
msg("Cache dir OK: $cacheDir");

$zipFile = $cacheDir . '/info.zip';

// ── 1. Download ZIP ──
msg("Downloading info.zip...");
$ch = curl_init('http://api.bestchange.ru/info.zip');
curl_setopt_array($ch, [
    CURLOPT_TIMEOUT => 30,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0',
]);
$data = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($data === false || $httpCode !== 200 || strlen($data ?: '') < 1000) {
    msg("ERROR: Download failed (HTTP $httpCode, curl error: $curlErr, size=" . strlen($data ?: '') . ")");
    // Try to use existing zip
    if (!file_exists($zipFile)) die("No cached zip available. Exiting.\n");
    msg("Using stale cached zip.");
} else {
    file_put_contents($zipFile, $data, LOCK_EX);
    msg("Downloaded " . number_format(strlen($data)) . " bytes.");
}
unset($data);

// ── 2. Open ZIP ──
$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) {
    die("ERROR: Cannot open zip\n");
}

// ── 3. Parse currencies ──
msg("Parsing currencies...");
$currencies = [];
$cyData = @iconv('CP1251', 'UTF-8//IGNORE', $zip->getFromName('bm_cy.dat'));
foreach (explode("\n", $cyData) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $p = explode(';', $line);
    if (count($p) < 3) continue;
    $id = (int)$p[0];
    $currencies[$id] = ['id' => $id, 'name' => trim($p[2])];
}
msg("Found " . count($currencies) . " currencies.");

// ── 4. Parse exchangers ──
msg("Parsing exchangers...");
$exchangers = [];
$exchData = @iconv('CP1251', 'UTF-8//IGNORE', $zip->getFromName('bm_exch.dat'));
foreach (explode("\n", $exchData) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $p = explode(';', $line);
    if (count($p) < 2) continue;
    $exchangers[(int)$p[0]] = trim($p[1]);
}
msg("Found " . count($exchangers) . " exchangers.");

// ── 5. Parse ALL rates, group by direction, keep top 50 ──
msg("Parsing rates (memory before: " . round(memory_get_usage()/1024/1024, 1) . "MB)...");
$ratesRaw = $zip->getFromName('bm_rates.dat');
$zip->close();
if ($ratesRaw === false) { die("ERROR: bm_rates.dat not found in ZIP\n"); }
msg("Raw rates size: " . number_format(strlen($ratesRaw)) . " bytes");
$ratesData = @iconv('CP1251', 'UTF-8//IGNORE', $ratesRaw);
unset($ratesRaw);

$allRates = [];
$lineCount = 0;
foreach (explode("\n", $ratesData) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $lineCount++;

    $p = explode(';', $line);
    if (count($p) < 6) continue;

    $giveId = (int)$p[0];
    $getId = (int)$p[1];
    $rateGive = (float)$p[3];
    $rateGet = (float)$p[4];
    if ($rateGive <= 0) continue;

    $key = $giveId . '_' . $getId;
    if (!isset($allRates[$key])) $allRates[$key] = [];

    // Only keep top 50 per direction during parsing (memory optimization)
    if (count($allRates[$key]) < 60) {
        $allRates[$key][] = [
            'eid' => (int)$p[2],     // exchanger_id
            'g' => $rateGive,         // give
            'r' => $rateGet,          // receive
            'rt' => $rateGet / $rateGive, // rate
            'rs' => (float)$p[5],     // reserve
            'rv' => isset($p[6]) ? (int)$p[6] : 0, // reviews
        ];
    }
}
unset($ratesData);
msg("Parsed $lineCount lines, " . count($allRates) . " unique directions.");

// Sort each direction by best rate and keep top 50
msg("Sorting and trimming...");
foreach ($allRates as $key => &$rows) {
    usort($rows, function($a, $b) {
        if ($b['rt'] == $a['rt']) return 0;
        return ($b['rt'] > $a['rt']) ? 1 : -1;
    });
    if (count($rows) > 50) $rows = array_slice($rows, 0, 50);
}
unset($rows);

// ── 6. Save everything to files ──
// Save currencies
file_put_contents($cacheDir . '/currencies.json', json_encode($currencies, JSON_UNESCAPED_UNICODE), LOCK_EX);
msg("Saved currencies.json");

// Save exchangers
file_put_contents($cacheDir . '/exchangers.json', json_encode($exchangers, JSON_UNESCAPED_UNICODE), LOCK_EX);
msg("Saved exchangers.json");

// Save all rates in one file (serialized is faster than JSON for PHP)
file_put_contents($cacheDir . '/rates.dat', serialize($allRates), LOCK_EX);
msg("Saved rates.dat (" . number_format(strlen(serialize($allRates))) . " bytes)");

// ── 7. Build slug lookup from ds_list + cu_list in HTML template ──
// These JS arrays contain ALL currency IDs and their exact BestChange slugs
msg("Building slug lookup from ds_list/cu_list in template...");
$slugLookup = [];

$templateFile = __DIR__ . '/template/bestchange.html';
if (!file_exists($templateFile)) {
    msg("WARNING: Template not found at $templateFile, slugs will be empty!");
} else {
    $tmpl = file_get_contents($templateFile);

    // Extract ds_list (currency IDs): var ds_list = new Array(93, 131, 43, ...);
    $dsIds = [];
    if (preg_match('/var\s+ds_list\s*=\s*new\s+Array\(([^)]+)\)/', $tmpl, $m)) {
        $dsIds = array_map('intval', explode(',', $m[1]));
    }

    // Extract cu_list (currency slugs): var cu_list = new Array('bitcoin', 'bitcoin-ln', ...);
    $cuSlugs = [];
    if (preg_match('/var\s+cu_list\s*=\s*new\s+Array\(([^)]+)\)/', $tmpl, $m)) {
        // Parse quoted strings
        if (preg_match_all("/'/", $m[1])) {
            preg_match_all("/'([^']+)'/", $m[1], $sm);
            $cuSlugs = $sm[1];
        }
    }

    unset($tmpl);

    // Map: slug -> ID (same index in both arrays)
    $count = min(count($dsIds), count($cuSlugs));
    msg("Found $count currencies in ds_list/cu_list");
    for ($i = 0; $i < $count; $i++) {
        $slug = trim($cuSlugs[$i]);
        $id = $dsIds[$i];
        if ($slug !== '' && $id > 0) {
            $slugLookup[$slug] = $id;
        }
    }
    msg("Mapped " . count($slugLookup) . " slug->ID pairs from template.");
}

// Also add ticker-based lookups from API data (e.g. "btc" -> 93, "eth" -> 149)
foreach ($currencies as $id => $cur) {
    $ticker = '';
    if (preg_match('/\(([^)]+)\)/', $cur['name'], $m)) {
        $ticker = strtolower(trim($m[1]));
    }
    if ($ticker !== '' && !isset($slugLookup[$ticker])) {
        $slugLookup[$ticker] = $id;
    }
}

file_put_contents($cacheDir . '/slugs.json', json_encode($slugLookup, JSON_UNESCAPED_UNICODE), LOCK_EX);
msg("Saved slugs.json (" . count($slugLookup) . " entries)");

// ── 8. Save timestamp ──
file_put_contents($cacheDir . '/last_update.txt', date('Y-m-d H:i:s'), LOCK_EX);

msg("DONE! All data updated.");
msg("Peak memory: " . round(memory_get_peak_usage()/1024/1024, 1) . "MB");
echo '</pre>';
