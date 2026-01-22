<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

echo "🔍 Test de réutilisation des themes\n\n";

// Open source file
$source = new PPTX(__DIR__ . '/../tests/mock/FIN.pptx');
$sourceSlide = $source->getSlides()[0];

// Get theme from slideMaster
$slideMaster = null;
foreach ($sourceSlide->getResources() as $resource) {
    if ($resource instanceof \Cristal\Presentation\Resource\SlideLayout) {
        foreach ($resource->getResources() as $res) {
            if ($res instanceof \Cristal\Presentation\Resource\SlideMaster) {
                $slideMaster = $res;
                break 2;
            }
        }
    }
}

if ($slideMaster) {
    echo "✓ SlideMaster found\n";
    $theme = null;
    foreach ($slideMaster->getResources() as $res) {
        if ($res instanceof \Cristal\Presentation\Resource\Theme) {
            $theme = $res;
            break;
        }
    }
    
    if ($theme) {
        echo "✓ Theme found: " . $theme->getTarget() . "\n";
        echo "  Hash: " . substr($theme->getHashFile(), 0, 16) . "...\n";
        echo "  Content length: " . strlen($theme->getContent()) . " bytes\n\n";
    }
}

echo "📦 Creating merged file...\n";
$dest = new PPTX(__DIR__ . '/../tests/mock/FIN.pptx');

// Check existing themes in dest before merge
echo "Themes in destination BEFORE merge:\n";
$contentType = $dest->getContentType();
for ($i = 0; $i < $dest->getArchive()->numFiles; $i++) {
    $name = $dest->getArchive()->getNameIndex($i);
    if (strpos($name, 'ppt/theme/theme') !== false && strpos($name, '.xml') !== false && strpos($name, '.rels') === false) {
        $resource = $contentType->getResource($name);
        if ($resource instanceof \Cristal\Presentation\Resource\Theme) {
            echo "  - " . $resource->getTarget() . " (hash: " . substr($resource->getHashFile(), 0, 16) . "...)\n";
        }
    }
}

echo "\n🔄 Adding slides from source...\n";
$dest->addSlides($source->getSlides());

$outputPath = sys_get_temp_dir() . '/debug_theme_reuse.pptx';
$dest->saveAs($outputPath);

echo "✅ Saved to: $outputPath\n\n";

// Check themes after merge
$zip = new ZipArchive();
$zip->open($outputPath);
echo "Themes in merged file:\n";
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (strpos($name, 'ppt/theme/theme') !== false && strpos($name, '.xml') !== false && strpos($name, '.rels') === false) {
        echo "  - $name\n";
    }
}
$zip->close();