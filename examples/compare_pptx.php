<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

echo "🔍 COMPARAISON DÉTAILLÉE : Source vs Fusionné\n";
echo str_repeat('=', 60) . "\n\n";

$source = new ZipArchive();
$source->open(__DIR__ . '/../tests/mock/FIN.pptx');

$merged = new ZipArchive();
$merged->open(__DIR__ . '/../test_merge_powerpoint.pptx');

echo "📊 Statistiques générales:\n";
echo "   Source: " . $source->numFiles . " fichiers\n";
echo "   Fusionné: " . $merged->numFiles . " fichiers\n\n";

// Compare [Content_Types].xml
echo "📋 Comparaison de [Content_Types].xml:\n";
$sourceContentTypes = $source->getFromName('[Content_Types].xml');
$mergedContentTypes = $merged->getFromName('[Content_Types].xml');

$sourceXml = simplexml_load_string($sourceContentTypes);
$mergedXml = simplexml_load_string($mergedContentTypes);

$sourceOverrides = [];
foreach ($sourceXml->Override as $override) {
    $sourceOverrides[] = (string)$override['PartName'];
}

$mergedOverrides = [];
foreach ($mergedXml->Override as $override) {
    $mergedOverrides[] = (string)$override['PartName'];
}

echo "   Overrides dans source: " . count($sourceOverrides) . "\n";
echo "   Overrides dans fusionné: " . count($mergedOverrides) . "\n";

$missingInMerged = array_diff($sourceOverrides, $mergedOverrides);
$extraInMerged = array_diff($mergedOverrides, $sourceOverrides);

if (!empty($extraInMerged)) {
    echo "   ✓ Overrides supplémentaires dans fusionné: " . count($extraInMerged) . "\n";
    foreach (array_slice($extraInMerged, 0, 5) as $extra) {
        echo "     - $extra\n";
    }
}
echo "\n";

// Compare presentation.xml
echo "📄 Comparaison de presentation.xml:\n";
$sourcePres = $source->getFromName('ppt/presentation.xml');
$mergedPres = $merged->getFromName('ppt/presentation.xml');

$sourcePresXml = simplexml_load_string($sourcePres);
$mergedPresXml = simplexml_load_string($mergedPres);

$sourcePresXml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
$mergedPresXml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');

$sourceSlides = $sourcePresXml->xpath('//p:sldId');
$mergedSlides = $mergedPresXml->xpath('//p:sldId');

echo "   Slides dans source: " . count($sourceSlides) . "\n";
echo "   Slides dans fusionné: " . count($mergedSlides) . "\n";

$sourceMasters = $sourcePresXml->xpath('//p:sldMasterId');
$mergedMasters = $mergedPresXml->xpath('//p:sldMasterId');

echo "   Masters dans source: " . count($sourceMasters) . "\n";
echo "   Masters dans fusionné: " . count($mergedMasters) . "\n\n";

// Compare presentation.xml.rels
echo "🔗 Comparaison de presentation.xml.rels:\n";
$sourcePresRels = $source->getFromName('ppt/_rels/presentation.xml.rels');
$mergedPresRels = $merged->getFromName('ppt/_rels/presentation.xml.rels');

$sourcePresRelsXml = simplexml_load_string($sourcePresRels);
$mergedPresRelsXml = simplexml_load_string($mergedPresRels);

$sourcePresRelsXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
$mergedPresRelsXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

$sourceRels = $sourcePresRelsXml->xpath('//r:Relationship');
$mergedRels = $mergedPresRelsXml->xpath('//r:Relationship');

echo "   Relationships dans source: " . count($sourceRels) . "\n";
echo "   Relationships dans fusionné: " . count($mergedRels) . "\n\n";

// Check for duplicate relationship IDs in merged
echo "🔍 Vérification des IDs de relationship dupliqués:\n";
$mergedRelsIds = [];
$duplicates = [];
foreach ($mergedRels as $rel) {
    $id = (string)$rel['Id'];
    if (in_array($id, $mergedRelsIds)) {
        $duplicates[] = $id;
    }
    $mergedRelsIds[] = $id;
}

if (empty($duplicates)) {
    echo "   ✓ Aucun ID dupliqué\n";
} else {
    echo "   ❌ IDs dupliqués trouvés: " . implode(', ', $duplicates) . "\n";
}
echo "\n";

// List all files in merged
echo "📁 Fichiers dans le PPTX fusionné:\n";
$mergedFiles = [];
for ($i = 0; $i < $merged->numFiles; $i++) {
    $mergedFiles[] = $merged->getNameIndex($i);
}
sort($mergedFiles);

// Group by directory
$byDir = [];
foreach ($mergedFiles as $file) {
    $dir = dirname($file);
    if (!isset($byDir[$dir])) {
        $byDir[$dir] = [];
    }
    $byDir[$dir][] = basename($file);
}

foreach ($byDir as $dir => $files) {
    echo "   $dir/ (" . count($files) . " fichiers)\n";
    if (count($files) <= 10) {
        foreach ($files as $file) {
            echo "     - $file\n";
        }
    } else {
        foreach (array_slice($files, 0, 5) as $file) {
            echo "     - $file\n";
        }
        echo "     ... et " . (count($files) - 5) . " autres\n";
    }
}

$source->close();
$merged->close();

echo "\n" . str_repeat('=', 60) . "\n";
echo "FIN DE LA COMPARAISON\n";