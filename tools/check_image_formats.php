<?php
$zip = new ZipArchive();
$zip->open('tests/tmp/merge.pptx');

$problematicImages = ['image112.jpeg', 'image115.png', 'image118.png', 'image106.png',
                      'image114.jpeg', 'image103.svg', 'image121.jpeg', 'image104.png',
                      'image113.png', 'image102.svg'];

echo "Checking image format consistency:\n\n";

foreach ($problematicImages as $img) {
    $path = 'ppt/media/' . $img;
    $content = $zip->getFromName($path);

    if (!$content) {
        echo "$img: NOT FOUND\n";
        continue;
    }

    // Detect actual format from content
    $actualFormat = 'unknown';
    if (str_starts_with($content, "\x89PNG")) {
        $actualFormat = 'PNG';
    } elseif (str_starts_with($content, "\xFF\xD8\xFF")) {
        $actualFormat = 'JPEG';
    } elseif (str_starts_with(trim($content), '<svg') || str_starts_with(trim($content), '<?xml')) {
        $actualFormat = 'SVG';
    } elseif (str_starts_with($content, 'GIF')) {
        $actualFormat = 'GIF';
    }

    // Get extension
    $ext = strtoupper(pathinfo($img, PATHINFO_EXTENSION));

    // Check match
    $matches = ($ext === $actualFormat ||
                ($ext === 'JPEG' && $actualFormat === 'JPEG') ||
                ($ext === 'JPG' && $actualFormat === 'JPEG'));

    $status = $matches ? '✅ OK' : '❌ MISMATCH';
    echo "$img: extension=$ext, actual=$actualFormat $status\n";
}

$zip->close();
