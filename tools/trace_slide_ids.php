<?php

require __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

echo "=== SOURCE FILES ===\n\n";

$files = ['DEBUT.pptx', 'FIN.pptx', 'MILIEU.pptx'];
$sourceMapping = [];

foreach ($files as $file) {
    $path = __DIR__ . "/../tests/mock/$file";
    if (!file_exists($path)) {
        continue;
    }

    echo "File: $file\n";
    $pptx = new PPTX($path);
    $slides = $pptx->getSlides();

    foreach ($slides as $slide) {
        $slideTarget = basename($slide->getTarget());
        $slideId = $slide->getSourceSlideId();

        $hasNotes = false;
        foreach ($slide->getResources() as $resource) {
            if ($resource instanceof \Cristal\Presentation\Resource\NoteSlide) {
                $hasNotes = true;
                break;
            }
        }

        if ($slideId !== null) {
            $sourceMapping[$slideId] = "$file/$slideTarget" . ($hasNotes ? " (HAS NOTES)" : "");
        }

        echo "  $slideTarget: id=$slideId" . ($hasNotes ? " (HAS NOTES)" : "") . "\n";
    }
    echo "\n";
}

echo "\n=== MERGED FILE (merge.pptx) ===\n\n";

$merged = new PPTX(__DIR__ . '/../tests/tmp/merge.pptx');
$slides = $merged->getSlides();

foreach ($slides as $slide) {
    $slideTarget = basename($slide->getTarget());
    $slideId = $slide->getSourceSlideId();

    $hasNotes = false;
    $noteTarget = '';
    foreach ($slide->getResources() as $resource) {
        if ($resource instanceof \Cristal\Presentation\Resource\NoteSlide) {
            $hasNotes = true;
            $noteTarget = basename($resource->getTarget());
            break;
        }
    }

    $source = isset($sourceMapping[$slideId]) ? $sourceMapping[$slideId] : "UNKNOWN";

    if ($hasNotes || $slideId === null || isset($sourceMapping[$slideId])) {
        echo sprintf(
            "%s: sourceSlideId=%s%s%s\n",
            $slideTarget,
            $slideId ?? 'null',
            $hasNotes ? " → $noteTarget" : "",
            $slideId !== null ? " [from $source]" : ""
        );
    }
}
