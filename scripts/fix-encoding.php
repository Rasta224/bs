<?php
/**
 * Re-download static pages from GitHub raw, convert from Windows-1251 to UTF-8
 */
$pages = [
    'contacts/index.html' => 'https://raw.githubusercontent.com/Rasta224/bs/main/contacts/index.html',
    'faq/index.html'      => 'https://raw.githubusercontent.com/Rasta224/bs/main/faq/index.html',
    'list/index.html'     => 'https://raw.githubusercontent.com/Rasta224/bs/main/list/index.html',
    'partner/index.html'  => 'https://raw.githubusercontent.com/Rasta224/bs/main/partner/index.html',
    'report/index.html'   => 'https://raw.githubusercontent.com/Rasta224/bs/main/report/index.html',
    'wiki/help/index.html'=> 'https://raw.githubusercontent.com/Rasta224/bs/main/wiki/help/index.html',
    'template/bestchange.html' => 'https://raw.githubusercontent.com/Rasta224/bs/main/template/bestchange.html',
];

$root = __DIR__ . '/..';

foreach ($pages as $local => $url) {
    echo "Downloading $local...\n";
    
    $raw = @file_get_contents($url);
    if ($raw === false) {
        echo "  FAILED to download\n";
        continue;
    }
    echo "  Downloaded " . strlen($raw) . " bytes\n";
    
    // Detect encoding
    $isUtf8 = mb_check_encoding($raw, 'UTF-8');
    echo "  Valid UTF-8: " . ($isUtf8 ? 'YES' : 'NO') . "\n";
    
    // Check for charset in HTML
    $charset = 'unknown';
    if (preg_match('/charset\s*=\s*"?([\w-]+)/i', $raw, $m)) {
        $charset = strtolower($m[1]);
    }
    echo "  HTML charset: $charset\n";
    
    // If it's Windows-1251, convert to UTF-8
    if (!$isUtf8 || $charset === 'windows-1251') {
        echo "  Converting from Windows-1251 to UTF-8...\n";
        $converted = @iconv('Windows-1251', 'UTF-8//TRANSLIT', $raw);
        if ($converted !== false) {
            $raw = $converted;
            echo "  Converted: " . strlen($raw) . " bytes\n";
        } else {
            echo "  iconv FAILED, trying mb_convert_encoding...\n";
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }
    }
    
    // Replace charset declarations
    $raw = preg_replace('/charset\s*=\s*"?windows-1251"?/i', 'charset="utf-8"', $raw);
    
    // Check for remaining replacement chars
    $repCount = substr_count($raw, "\xEF\xBF\xBD");
    echo "  Replacement chars remaining: $repCount\n";
    
    // Save
    $dest = $root . '/' . $local;
    $dir = dirname($dest);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    file_put_contents($dest, $raw);
    echo "  Saved to $dest\n\n";
}

echo "Done!\n";
