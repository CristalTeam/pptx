<?php

require __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

echo "Testing single slide merge from MILIEU.pptx...\n\n";

// Open destination (DEBUT)
$dest = new PPTX(__DIR__ . '/../tests/mock/DEBUT.pptx');
echo "DEBUT slides: " . count($dest->getSlides()) . "\n";

// Open source (MILIEU) and get slides with notes
$source = new PPTX(__DIR__ . '/../tests/mock/MILIEU.pptx');
$sourceSlides = $source->getSlides();

echo "MILIEU slides: " . count($sourceSlides) . "\n";

// Find slides with notes
$slidesWithNotes = [];
foreach ($sourceSlides as $slide) {
    $hasNotes = false;
    foreach ($slide->getResources() as $res) {
        if ($res instanceof \Cristal\Presentation\Resource\NoteSlide) {
            $hasNotes = true;
            break;
        }
    }

    if ($hasNotes) {
        $slidesWithNotes[] = $slide;
        echo "  " . basename($slide->getTarget()) . " has notes (sourceSlideId=" . $slide->getSourceSlideId() . ")\n";
    }
}

echo "\nMerging " . count($slidesWithNotes) . " slides with notes...\n";

// Add only slides with notes
foreach ($slidesWithNotes as $slide) {
    $dest->addSlide($slide);
}

$dest->saveAs(__DIR__ . '/../tests/tmp/test_single_merge.pptx');

echo "\nMerge complete. Checking result...\n\n";

$result = new PPTX(__DIR__ . '/../tests/tmp/test_single_merge.pptx');
$resultSlides = $result->getSlides();

echo "Result has " . count($resultSlides) . " slides\n\n";

// Find slides with notes in result
echo "Slides with notes in result:\n";
foreach ($resultSlides as $slide) {
    $slideId = $slide->getSourceSlideId();
    $slideTarget = basename($slide->getTarget());

    $hasNotes = false;
    $noteTarget = '';

    foreach ($slide->getResources() as $res) {
        if ($res instanceof \Cristal\Presentation\Resource\NoteSlide) {
            $hasNotes = true;
            $noteTarget = basename($res->getTarget());

            // Check NoteSlide's Slide reference
            foreach ($res->getResources() as $subRes) {
                if ($subRes instanceof \Cristal\Presentation\Resource\Slide) {
                    $refSlide = basename($subRes->getTarget());
                    echo "  $slideTarget (id=$slideId): has $noteTarget which points to $refSlide ";
                    if ($refSlide === $slideTarget) {
                        echo "✓ CORRECT\n";
                    } else {
                        echo "✗ WRONG (should point to $slideTarget)\n";
                    }
                }
            }
            break;
        }
    }

    // Check if a slide that should have notes is missing them
    if (!$hasNotes && ($slideId === 754 || $slideId === 275)) {
        echo "  $slideTarget (id=$slideId): ✗ NO NOTESLIDE (should have one!)\n";
    }
}
