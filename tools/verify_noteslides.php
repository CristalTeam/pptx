<?php

/**
 * Script to verify NoteSlide ↔ Slide references in a PPTX file.
 * Checks that:
 * 1. Each NoteSlide points to the correct Slide
 * 2. Each Slide with notes points to the correct NoteSlide
 * 3. The references are bidirectional and consistent
 */

require __DIR__ . '/../vendor/autoload.php';

if ($argc < 2) {
    echo "Usage: php verify_noteslides.php <path_to_pptx>\n";
    exit(1);
}

$pptxPath = $argv[1];

if (!file_exists($pptxPath)) {
    echo "Error: File not found: $pptxPath\n";
    exit(1);
}

$zip = new ZipArchive();
$res = $zip->open($pptxPath);

if ($res !== true) {
    echo "Error: Could not open PPTX file\n";
    exit(1);
}

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║     NoteSlide References Verification Report        ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

echo "File: " . basename($pptxPath) . "\n\n";

// Step 1: Parse presentation.xml to get all slides and their rIds
$presentationXml = $zip->getFromName('ppt/presentation.xml');
if ($presentationXml === false) {
    echo "Error: Could not read ppt/presentation.xml\n";
    exit(1);
}

$xml = simplexml_load_string($presentationXml);
$xml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
$xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

$slides = [];
foreach ($xml->xpath('//p:sldIdLst/p:sldId') as $sldId) {
    $rId = (string)$sldId->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
    $slideId = (int)$sldId['id'];
    $slides[$rId] = ['id' => $slideId];
}

// Step 2: Read presentation.xml.rels to map rIds to actual slide files
$presentationRels = $zip->getFromName('ppt/_rels/presentation.xml.rels');
if ($presentationRels === false) {
    echo "Error: Could not read ppt/_rels/presentation.xml.rels\n";
    exit(1);
}

$relsXml = simplexml_load_string($presentationRels);
foreach ($relsXml->Relationship as $rel) {
    $rId = (string)$rel['Id'];
    $target = (string)$rel['Target'];

    if (isset($slides[$rId])) {
        $slides[$rId]['file'] = $target;
    }
}

// Step 3: For each slide, check if it has a NoteSlide reference
$slideToNote = [];
$noteToSlide = [];
$issues = [];

foreach ($slides as $rId => $slideInfo) {
    if (!isset($slideInfo['file'])) {
        continue;
    }

    $slideFile = 'ppt/' . $slideInfo['file'];
    $slideRelsFile = str_replace('.xml', '.xml.rels', str_replace('ppt/slides/', 'ppt/slides/_rels/', $slideFile));

    // Read slide's .rels file
    $slideRelsContent = @$zip->getFromName($slideRelsFile);
    if ($slideRelsContent === false) {
        continue; // No .rels file, skip
    }

    $slideRelsXml = simplexml_load_string($slideRelsContent);
    foreach ($slideRelsXml->Relationship as $rel) {
        $relTarget = (string)$rel['Target'];
        $relType = (string)$rel['Type'];

        // Check if this is a NoteSlide relationship
        if (str_contains($relType, 'notesSlide')) {
            $noteSlideFile = basename($relTarget);
            $slideToNote[$slideInfo['file']] = $noteSlideFile;
        }
    }
}

// Step 4: For each NoteSlide, check which Slide it references
$noteSlideFiles = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $filename = $zip->getNameIndex($i);
    if (str_starts_with($filename, 'ppt/notesSlides/notesSlide') && str_ends_with($filename, '.xml')) {
        $noteSlideFiles[] = $filename;
    }
}

foreach ($noteSlideFiles as $noteSlideFile) {
    $noteRelsFile = str_replace('.xml', '.xml.rels', str_replace('ppt/notesSlides/', 'ppt/notesSlides/_rels/', $noteSlideFile));

    $noteRelsContent = @$zip->getFromName($noteRelsFile);
    if ($noteRelsContent === false) {
        continue; // No .rels file
    }

    $noteRelsXml = simplexml_load_string($noteRelsContent);
    foreach ($noteRelsXml->Relationship as $rel) {
        $relTarget = (string)$rel['Target'];
        $relType = (string)$rel['Type'];

        // Check if this is a Slide relationship
        if (str_contains($relType, 'slide') && !str_contains($relType, 'slideLayout')) {
            $slideFile = basename($relTarget);
            $noteBasename = basename($noteSlideFile);
            $noteToSlide[$noteBasename] = $slideFile;
        }
    }
}

// Step 5: Display results
echo "═══ Slide → NoteSlide References ═══\n";
if (empty($slideToNote)) {
    echo "  (No slides with notes found)\n";
} else {
    foreach ($slideToNote as $slide => $note) {
        printf("  %-20s → %s\n", $slide, $note);
    }
}

echo "\n═══ NoteSlide → Slide References ═══\n";
if (empty($noteToSlide)) {
    echo "  (No note slides found)\n";
} else {
    foreach ($noteToSlide as $note => $slide) {
        printf("  %-20s → %s\n", $note, $slide);
    }
}

// Step 6: Verify bidirectional consistency
echo "\n═══ Bidirectional Consistency Check ═══\n";

$allConsistent = true;

foreach ($slideToNote as $slide => $note) {
    // Check if the NoteSlide points back to this Slide
    if (!isset($noteToSlide[$note])) {
        $issues[] = "❌ $slide → $note, but $note has no Slide reference";
        $allConsistent = false;
    } elseif (basename($noteToSlide[$note]) !== basename($slide)) {
        $issues[] = "❌ $slide → $note, but $note → {$noteToSlide[$note]} (mismatch!)";
        $allConsistent = false;
    } else {
        echo "  ✅ $slide ↔ $note (consistent)\n";
    }
}

// Check orphaned NoteSlides (point to a Slide that doesn't point back)
foreach ($noteToSlide as $note => $slide) {
    $slideBasename = basename($slide);
    $foundMatch = false;

    foreach ($slideToNote as $slideKey => $noteValue) {
        if (basename($slideKey) === $slideBasename && basename($noteValue) === $note) {
            $foundMatch = true;
            break;
        }
    }

    if (!$foundMatch) {
        $issues[] = "⚠️  $note → $slide, but $slide has no NoteSlide reference (orphan NoteSlide)";
        $allConsistent = false;
    }
}

if (empty($slideToNote) && empty($noteToSlide)) {
    echo "  ℹ️  No NoteSlides in this presentation\n";
    exit(0);
}

echo "\n═══ Summary ═══\n";
printf("  Slides with notes: %d\n", count($slideToNote));
printf("  Total NoteSlides:  %d\n", count($noteToSlide));

if ($allConsistent && !empty($slideToNote)) {
    echo "\n✅ ✅ ✅ ALL REFERENCES CONSISTENT ✅ ✅ ✅\n";
    exit(0);
} elseif (empty($slideToNote) && empty($noteToSlide)) {
    echo "\nℹ️  No NoteSlides to verify\n";
    exit(0);
} else {
    echo "\n❌ CONSISTENCY ISSUES FOUND:\n";
    foreach ($issues as $issue) {
        echo "  $issue\n";
    }
    exit(1);
}
