<?php

require __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

$pptx = new PPTX(__DIR__ . '/../tests/tmp/merge.pptx');
$slides = $pptx->getSlides();

echo "Checking sourceSlideId for all slides:\n";
echo "========================================\n";

foreach ($slides as $slide) {
    $id = $slide->getSourceSlideId();
    $target = basename($slide->getTarget());
    $resources = $slide->getResources();

    $hasNoteSlide = false;
    $noteSlideTarget = '';
    foreach ($resources as $resource) {
        if ($resource instanceof \Cristal\Presentation\Resource\NoteSlide) {
            $hasNoteSlide = true;
            $noteSlideTarget = basename($resource->getTarget());
            break;
        }
    }

    echo sprintf(
        "%s: sourceSlideId=%s, hasNotes=%s%s\n",
        $target,
        $id ?? 'null',
        $hasNoteSlide ? 'YES' : 'NO',
        $hasNoteSlide ? " ($noteSlideTarget)" : ''
    );
}

echo "\nChecking NoteSlide references:\n";
echo "========================================\n";

$presentation = $pptx->getPresentation();
$allResources = $presentation->getResources();

foreach ($allResources as $resource) {
    if ($resource instanceof \Cristal\Presentation\Resource\NoteSlide) {
        $noteTarget = basename($resource->getTarget());
        $resources = $resource->getResources();

        foreach ($resources as $subResource) {
            if ($subResource instanceof \Cristal\Presentation\Resource\Slide) {
                $slideTarget = basename($subResource->getTarget());
                echo "$noteTarget -> $slideTarget\n";
                break;
            }
        }
    }
}
