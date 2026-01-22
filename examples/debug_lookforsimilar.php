<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;
use Cristal\Presentation\Resource\Theme;

echo "🔍 Debug lookForSimilarFile pour les themes\n\n";

// Open destination
$dest = new PPTX(__DIR__ . '/../tests/mock/FIN.pptx');
$contentType = $dest->getContentType();

echo "📦 Fichiers dans cachedFilename qui sont des themes:\n";
$reflection = new ReflectionClass($contentType);
$property = $reflection->getProperty('cachedFilename');
$property->setAccessible(true);
$cachedFilename = $property->getValue($contentType);

foreach ($cachedFilename as $path) {
    if (strpos($path, 'ppt/theme/theme') !== false && strpos($path, '.xml') !== false && strpos($path, '.rels') === false) {
        echo "  - $path\n";
        
        // Load this resource and check its hash
        $resource = $contentType->getResource($path);
        if ($resource instanceof Theme) {
            echo "    Hash: " . substr($resource->getHashFile(), 0, 16) . "...\n";
        }
    }
}

echo "\n🔍 Opening source and getting theme1...\n";
$source = new PPTX(__DIR__ . '/../tests/mock/FIN.pptx');
$sourceSlide = $source->getSlides()[0];

$theme1 = null;
foreach ($sourceSlide->getResources() as $resource) {
    if ($resource instanceof \Cristal\Presentation\Resource\SlideLayout) {
        foreach ($resource->getResources() as $res) {
            if ($res instanceof \Cristal\Presentation\Resource\SlideMaster) {
                foreach ($res->getResources() as $r) {
                    if ($r instanceof Theme) {
                        $theme1 = $r;
                        break 3;
                    }
                }
            }
        }
    }
}

if ($theme1) {
    echo "✓ Theme from source found: " . $theme1->getTarget() . "\n";
    echo "  Hash: " . substr($theme1->getHashFile(), 0, 16) . "...\n";
    echo "  Pattern: " . $theme1->getPatternPath() . "\n";
    
    echo "\n🔎 Calling lookForSimilarFile...\n";
    $similar = $contentType->lookForSimilarFile($theme1);
    
    if ($similar) {
        echo "✓ Found similar file: " . $similar->getTarget() . "\n";
        echo "  Hash: " . substr($similar->getHashFile(), 0, 16) . "...\n";
    } else {
        echo "✗ No similar file found!\n";
        
        // Manual search
        echo "\n🔧 Manual search in destination:\n";
        $startBy = dirname($theme1->getTarget()) . '/';
        echo "  Looking in directory: $startBy\n";
        
        foreach ($cachedFilename as $path) {
            if (str_starts_with($path, $startBy) && dirname($path) . '/' === $startBy) {
                echo "  Found file: $path\n";
                $existingFile = $contentType->getResource($path, $theme1->getRelType(), false, true);
                echo "    Class: " . get_class($existingFile) . "\n";
                echo "    Hash: " . substr($existingFile->getHashFile(), 0, 16) . "...\n";
                echo "    Match: " . ($existingFile->getHashFile() === $theme1->getHashFile() ? 'YES ✓' : 'NO ✗') . "\n";
            }
        }
    }
}