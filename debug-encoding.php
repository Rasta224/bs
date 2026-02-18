<?php
// Quick debug page - visit /debug-encoding.php to see results
header('Content-Type: text/plain; charset=utf-8');

$files = [
    'template/bestchange.html',
    'contacts/index.html',
    'report/index.html',
];

foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    if (!file_exists($path)) {
        echo "$f: FILE NOT FOUND\n\n";
        continue;
    }
    $raw = file_get_contents($path);
    $size = strlen($raw);
    
    // Check detected encoding
    $detected = mb_detect_encoding($raw, ['UTF-8', 'Windows-1251', 'ISO-8859-1', 'ASCII'], true);
    echo "$f: size=$size, detected encoding=$detected\n";
    
    // Check if it's valid UTF-8
    $isUtf8 = mb_check_encoding($raw, 'UTF-8');
    echo "  Valid UTF-8: " . ($isUtf8 ? 'YES' : 'NO') . "\n";
    
    // Count replacement chars
    $repCount = substr_count($raw, "\xEF\xBF\xBD"); // UTF-8 bytes for U+FFFD
    echo "  U+FFFD replacement chars: $repCount\n";
    
    // Find charset in HTML
    if (preg_match('/<meta[^>]*charset="?([^"\s>]+)/i', $raw, $m)) {
        echo "  HTML charset: {$m[1]}\n";
    }
    
    // Try to find windows-1251 sequences
    // Russian chars in windows-1251 are 0xC0-0xFF
    $win1251count = 0;
    for ($i = 0; $i < min($size, 50000); $i++) {
        $byte = ord($raw[$i]);
        if ($byte >= 0xC0 && $byte <= 0xFF) {
            // Could be win-1251 Cyrillic OR UTF-8 lead byte
            // In UTF-8, 0xC0-0xDF are 2-byte lead, 0xE0-0xEF are 3-byte lead
            // In win-1251, 0xC0-0xFF are Cyrillic А-я
        }
    }
    
    // Show first 200 chars to see if text is readable
    $sample = substr($raw, 0, 200);
    echo "  First 200 chars: " . $sample . "\n";
    
    // Find some visible text to check
    if (preg_match('/<title>([^<]+)<\/title>/i', $raw, $m)) {
        echo "  Title: {$m[1]}\n";
    }
    
    echo "\n---\n\n";
}
