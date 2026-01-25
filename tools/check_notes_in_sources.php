<?php

require __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

$files = ['DEBUT.pptx', 'FIN.pptx', 'MILIEU.pptx'];

foreach ($files as $file) {
    $path = __DIR__ . "/../tests/mock/$file";
    if (!file_exists($path)) {
        continue;
    }

    echo "\n=== $file ===\n";
    $pptx = new PPTX($path);
    $slides = $pptx->getSlides();

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

        if ($hasNotes) {
            echo "$slideTarget (id=$slideId): HAS NOTES ($noteTarget)\n";
        }
    }
}
