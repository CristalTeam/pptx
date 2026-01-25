<?php

require __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

function analyzeSections($filePath, $label) {
    if (!file_exists($filePath)) {
        echo "$label: FILE NOT FOUND\n\n";
        return;
    }

    echo "\n=== $label ===\n";
    echo "File: $filePath\n\n";

    // Extract presentation.xml and check for sections
    $zip = new \ZipArchive();
    $zip->open($filePath);
    $presentationXml = $zip->getFromName('ppt/presentation.xml');
    $zip->close();

    if (!$presentationXml) {
        echo "ERROR: Could not read presentation.xml\n\n";
        return;
    }

    $dom = new \SimpleXMLElement($presentationXml);
    $dom->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
    $dom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    // Get all slides
    $slides = $dom->xpath('//p:sldIdLst/p:sldId');
    echo "Total slides: " . count($slides) . "\n";

    $slideIdMap = [];
    foreach ($slides as $slide) {
        $id = (string)$slide['id'];
        $rid = (string)$slide->attributes('r', true)['id'];
        $slideIdMap[$id] = $rid;
    }

    // Get sections
    $sections = $dom->xpath('//p:sectionLst/p:section');

    if (empty($sections)) {
        echo "No sections found\n\n";
        return;
    }

    echo "\nSections found: " . count($sections) . "\n";
    echo str_repeat("-", 70) . "\n";

    foreach ($sections as $section) {
        $name = (string)$section['name'];
        $id = (string)$section['id'];

        echo "\nSection: '$name' (id=$id)\n";

        // Get slides in this section
        $sectionSlides = $section->xpath('.//p:sldIdLst/p:sldId');

        if (empty($sectionSlides)) {
            echo "  No slides in this section\n";
            continue;
        }

        echo "  Slides: " . count($sectionSlides) . "\n";
        foreach ($sectionSlides as $sectionSlide) {
            $slideId = (string)$sectionSlide['id'];
            echo "    - slideId=$slideId\n";
        }
    }

    echo "\n";
}

// Analyze source files
analyzeSections(__DIR__ . '/../tests/mock/DEBUT.pptx', 'DEBUT.pptx (source)');
analyzeSections(__DIR__ . '/../tests/mock/FIN.pptx', 'FIN.pptx (source)');
analyzeSections(__DIR__ . '/../tests/mock/MILIEU.pptx', 'MILIEU.pptx (source)');

// Analyze merged file
analyzeSections(__DIR__ . '/../tests/tmp/merge.pptx', 'merge.pptx (MERGED - corrupt)');
analyzeSections(__DIR__ . '/../tests/tmp/merge_repaired.pptx', 'merge_repaired.pptx (REPAIRED by PowerPoint)');
