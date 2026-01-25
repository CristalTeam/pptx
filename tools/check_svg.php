<?php
$zip = new ZipArchive();
$zip->open('tests/tmp/merge.pptx');

echo "Checking SVG files:\n";
$svgFiles = ['image102.svg', 'image103.svg'];
foreach ($svgFiles as $svg) {
    $content = $zip->getFromName('ppt/media/' . $svg);
    if ($content) {
        $isValid = str_starts_with(trim($content), '<') || str_starts_with(trim($content), '<?');
        echo "$svg: " . strlen($content) . " bytes, valid=" . ($isValid ? 'YES' : 'NO') . "\n";
        echo "First 200 chars:\n" . substr($content, 0, 200) . "\n\n";
    } else {
        echo "$svg: NOT FOUND\n";
    }
}

$zip->close();
