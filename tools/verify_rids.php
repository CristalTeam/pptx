<?php

/**
 * Script to verify rId ordering in a PPTX file.
 * Checks that rIds follow PowerPoint OPC conventions:
 * 1. SlideMasters (rId1)
 * 2. Slides (rId2+)
 * 3. System resources (notesMasters, props, themes)
 */

require __DIR__ . '/../vendor/autoload.php';

if ($argc < 2) {
    echo "Usage: php verify_rids.php <path_to_pptx>\n";
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

// Read presentation.xml.rels
$relsContent = $zip->getFromName('ppt/_rels/presentation.xml.rels');
if ($relsContent === false) {
    echo "Error: Could not read ppt/_rels/presentation.xml.rels\n";
    exit(1);
}

$xml = simplexml_load_string($relsContent);
$xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

// Extract all relationships
$relationships = [];
foreach ($xml->Relationship as $rel) {
    $id = (string)$rel['Id'];
    $target = (string)$rel['Target'];
    $type = (string)$rel['Type'];

    // Extract numeric part of rId
    $numericId = (int)str_replace('rId', '', $id);

    $relationships[$numericId] = [
        'id' => $id,
        'target' => $target,
        'type' => $type,
    ];
}

// Sort by numeric rId
ksort($relationships);

// Categorize resources
$categories = [
    'slideMasters' => [],
    'slides' => [],
    'notesMasters' => [],
    'props' => [],
    'themes' => [],
    'other' => [],
];

foreach ($relationships as $numId => $rel) {
    $target = $rel['target'];

    if (str_contains($target, 'slideMasters/slideMaster')) {
        $categories['slideMasters'][] = $numId;
    } elseif (str_contains($target, 'slides/slide')) {
        $categories['slides'][] = $numId;
    } elseif (str_contains($target, 'notesMasters/notesMaster')) {
        $categories['notesMasters'][] = $numId;
    } elseif (str_contains($target, 'presProps') || str_contains($target, 'viewProps') || str_contains($target, 'tableStyles')) {
        $categories['props'][] = $numId;
    } elseif (str_contains($target, 'theme/theme')) {
        $categories['themes'][] = $numId;
    } else {
        $categories['other'][] = $numId;
    }
}

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║        rId Order Verification Report                ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

echo "File: " . basename($pptxPath) . "\n\n";

// Display ordered relationships
echo "═══ Ordered Relationships ═══\n";
foreach ($relationships as $numId => $rel) {
    $category = '';
    if (in_array($numId, $categories['slideMasters'])) $category = '[MASTER]';
    elseif (in_array($numId, $categories['slides'])) $category = '[SLIDE]';
    elseif (in_array($numId, $categories['notesMasters'])) $category = '[NOTES_MASTER]';
    elseif (in_array($numId, $categories['props'])) $category = '[PROPS]';
    elseif (in_array($numId, $categories['themes'])) $category = '[THEME]';
    else $category = '[OTHER]';

    printf("  %-6s %-15s %s\n", $rel['id'], $category, $rel['target']);
}

echo "\n═══ Summary ═══\n";
printf("  SlideMasters: %d (rIds: %s)\n", count($categories['slideMasters']), implode(', ', array_map(fn($n) => "rId$n", $categories['slideMasters'])));
printf("  Slides:       %d (rIds: %s)\n", count($categories['slides']), implode(', ', array_slice(array_map(fn($n) => "rId$n", $categories['slides']), 0, 5)) . (count($categories['slides']) > 5 ? '...' : ''));
printf("  NoteMasters:  %d (rIds: %s)\n", count($categories['notesMasters']), implode(', ', array_map(fn($n) => "rId$n", $categories['notesMasters'])));
printf("  Props:        %d (rIds: %s)\n", count($categories['props']), implode(', ', array_map(fn($n) => "rId$n", $categories['props'])));
printf("  Themes:       %d (rIds: %s)\n", count($categories['themes']), implode(', ', array_map(fn($n) => "rId$n", $categories['themes'])));
printf("  Other:        %d\n", count($categories['other']));

echo "\n═══ OPC Compliance Check ═══\n";

$issues = [];

// Check 1: SlideMasters should be rId1
if (!empty($categories['slideMasters']) && !in_array(1, $categories['slideMasters'])) {
    $issues[] = "❌ SlideMaster is not rId1 (found at rId" . $categories['slideMasters'][0] . ")";
} elseif (!empty($categories['slideMasters'])) {
    echo "  ✅ SlideMaster is correctly at rId1\n";
}

// Check 2: Slides should be consecutive starting from rId2
if (!empty($categories['slides'])) {
    $minSlideRId = min($categories['slides']);
    $maxSlideRId = max($categories['slides']);

    if ($minSlideRId == 2) {
        echo "  ✅ Slides start at rId2\n";
    } else {
        $issues[] = "❌ Slides do not start at rId2 (start at rId$minSlideRId)";
    }

    // Check if slides are consecutive
    $expectedCount = $maxSlideRId - $minSlideRId + 1;
    if (count($categories['slides']) == $expectedCount) {
        echo "  ✅ Slides have consecutive rIds\n";
    } else {
        $issues[] = "⚠️  Slides have gaps in rId sequence";
    }
}

// Check 3: System resources should come AFTER slides
if (!empty($categories['slides'])) {
    $maxSlideRId = max($categories['slides']);
    $systemRIds = array_merge($categories['notesMasters'], $categories['props'], $categories['themes']);

    $violators = array_filter($systemRIds, fn($rid) => $rid < $maxSlideRId);

    if (empty($violators)) {
        echo "  ✅ System resources come after slides\n";
    } else {
        $issues[] = "❌ Some system resources come BEFORE slides: " . implode(', ', array_map(fn($n) => "rId$n", $violators));
    }
}

if (empty($issues)) {
    echo "\n✅ ✅ ✅ OPC COMPLIANT - No issues found! ✅ ✅ ✅\n";
    exit(0);
} else {
    echo "\n❌ OPC COMPLIANCE ISSUES FOUND:\n";
    foreach ($issues as $issue) {
        echo "  $issue\n";
    }
    exit(1);
}
