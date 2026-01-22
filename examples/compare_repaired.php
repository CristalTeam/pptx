<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$corruptedPath = __DIR__ . '/../test_merge.pptx';
$repairedPath = __DIR__ . '/../test_merge_repaired.pptx';

if (!file_exists($corruptedPath) || !file_exists($repairedPath)) {
    echo "❌ Fichiers non trouvés\n";
    exit(1);
}

// Extract both files
$corruptedDir = sys_get_temp_dir() . '/pptx_corrupted_' . uniqid();
$repairedDir = sys_get_temp_dir() . '/pptx_repaired_' . uniqid();

mkdir($corruptedDir);
mkdir($repairedDir);

$zipCorrupted = new ZipArchive();
$zipRepaired = new ZipArchive();

$zipCorrupted->open($corruptedPath);
$zipRepaired->open($repairedPath);

$zipCorrupted->extractTo($corruptedDir);
$zipRepaired->extractTo($repairedDir);

$zipCorrupted->close();
$zipRepaired->close();

echo "📦 Fichiers extraits:\n";
echo "   Corrompu: $corruptedDir\n";
echo "   Réparé: $repairedDir\n\n";

// Compare files
function getFilesRecursive($dir, $base = '') {
    $files = [];
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $path = $dir . '/' . $item;
        $relativePath = $base ? $base . '/' . $item : $item;
        
        if (is_dir($path)) {
            $files = array_merge($files, getFilesRecursive($path, $relativePath));
        } else {
            $files[] = $relativePath;
        }
    }
    return $files;
}

$corruptedFiles = getFilesRecursive($corruptedDir);
$repairedFiles = getFilesRecursive($repairedDir);

sort($corruptedFiles);
sort($repairedFiles);

// Find differences in file structure
$onlyInCorrupted = array_diff($corruptedFiles, $repairedFiles);
$onlyInRepaired = array_diff($repairedFiles, $corruptedFiles);

if (!empty($onlyInCorrupted)) {
    echo "🔴 Fichiers uniquement dans le corrompu:\n";
    foreach ($onlyInCorrupted as $file) {
        echo "   - $file\n";
    }
    echo "\n";
}

if (!empty($onlyInRepaired)) {
    echo "🟢 Fichiers uniquement dans le réparé:\n";
    foreach ($onlyInRepaired as $file) {
        echo "   - $file\n";
    }
    echo "\n";
}

// Compare XML files
$xmlFiles = array_filter($corruptedFiles, function($f) {
    return pathinfo($f, PATHINFO_EXTENSION) === 'xml' || 
           pathinfo($f, PATHINFO_EXTENSION) === 'rels';
});

echo "📋 Comparaison des fichiers XML/RELS:\n\n";

foreach ($xmlFiles as $file) {
    if (!in_array($file, $repairedFiles)) continue;
    
    $corruptedPath = $corruptedDir . '/' . $file;
    $repairedPath = $repairedDir . '/' . $file;
    
    $corruptedContent = file_get_contents($corruptedPath);
    $repairedContent = file_get_contents($repairedPath);
    
    if ($corruptedContent !== $repairedContent) {
        echo "⚠️  DIFFÉRENCE: $file\n";
        
        // Try to parse as XML and show differences
        try {
            $corruptedXml = new SimpleXMLElement($corruptedContent);
            $repairedXml = new SimpleXMLElement($repairedContent);
            
            // Compare specific elements
            if (strpos($file, '[Content_Types].xml') !== false) {
                echo "   Type: Content Types\n";
                compareContentTypes($corruptedXml, $repairedXml);
            } elseif (strpos($file, '.rels') !== false) {
                echo "   Type: Relationships\n";
                compareRelationships($corruptedXml, $repairedXml);
            } elseif (strpos($file, 'presentation.xml') !== false) {
                echo "   Type: Presentation\n";
                comparePresentation($corruptedXml, $repairedXml);
            }
            
        } catch (Exception $e) {
            echo "   Erreur de parsing XML: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }
}

function compareContentTypes($corrupted, $repaired) {
    $corruptedTypes = [];
    $repairedTypes = [];
    
    foreach ($corrupted->Override ?? [] as $override) {
        $corruptedTypes[(string)$override['PartName']] = (string)$override['ContentType'];
    }
    
    foreach ($repaired->Override ?? [] as $override) {
        $repairedTypes[(string)$override['PartName']] = (string)$override['ContentType'];
    }
    
    $onlyInCorrupted = array_diff_key($corruptedTypes, $repairedTypes);
    $onlyInRepaired = array_diff_key($repairedTypes, $corruptedTypes);
    
    if (!empty($onlyInCorrupted)) {
        echo "   🔴 ContentTypes uniquement dans corrompu:\n";
        foreach ($onlyInCorrupted as $part => $type) {
            echo "      - $part => $type\n";
        }
    }
    
    if (!empty($onlyInRepaired)) {
        echo "   🟢 ContentTypes uniquement dans réparé:\n";
        foreach ($onlyInRepaired as $part => $type) {
            echo "      - $part => $type\n";
        }
    }
}

function compareRelationships($corrupted, $repaired) {
    $corrupted->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $repaired->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
    
    $corruptedRels = [];
    $repairedRels = [];
    
    foreach ($corrupted->xpath('//r:Relationship') ?? [] as $rel) {
        $id = (string)$rel['Id'];
        $corruptedRels[$id] = [
            'Type' => (string)$rel['Type'],
            'Target' => (string)$rel['Target']
        ];
    }
    
    foreach ($repaired->xpath('//r:Relationship') ?? [] as $rel) {
        $id = (string)$rel['Id'];
        $repairedRels[$id] = [
            'Type' => (string)$rel['Type'],
            'Target' => (string)$rel['Target']
        ];
    }
    
    $onlyInCorrupted = array_diff_key($corruptedRels, $repairedRels);
    $onlyInRepaired = array_diff_key($repairedRels, $corruptedRels);
    
    if (!empty($onlyInCorrupted)) {
        echo "   🔴 Relations uniquement dans corrompu:\n";
        foreach ($onlyInCorrupted as $id => $rel) {
            echo "      - $id => {$rel['Type']} -> {$rel['Target']}\n";
        }
    }
    
    if (!empty($onlyInRepaired)) {
        echo "   🟢 Relations uniquement dans réparé:\n";
        foreach ($onlyInRepaired as $id => $rel) {
            echo "      - $id => {$rel['Type']} -> {$rel['Target']}\n";
        }
    }
    
    // Check for differences in common relationships
    $common = array_intersect_key($corruptedRels, $repairedRels);
    foreach ($common as $id => $corruptedRel) {
        $repairedRel = $repairedRels[$id];
        if ($corruptedRel !== $repairedRel) {
            echo "   ⚠️  Relation $id modifiée:\n";
            echo "      Corrompu: {$corruptedRel['Type']} -> {$corruptedRel['Target']}\n";
            echo "      Réparé:   {$repairedRel['Type']} -> {$repairedRel['Target']}\n";
        }
    }
}

function comparePresentation($corrupted, $repaired) {
    $corrupted->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
    $repaired->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
    
    $corruptedSlides = $corrupted->xpath('//p:sldIdLst/p:sldId') ?? [];
    $repairedSlides = $repaired->xpath('//p:sldIdLst/p:sldId') ?? [];
    
    echo "   Slides: " . count($corruptedSlides) . " (corrompu) vs " . count($repairedSlides) . " (réparé)\n";
    
    $corruptedMasters = $corrupted->xpath('//p:sldMasterIdLst/p:sldMasterId') ?? [];
    $repairedMasters = $repaired->xpath('//p:sldMasterIdLst/p:sldMasterId') ?? [];
    
    echo "   Masters: " . count($corruptedMasters) . " (corrompu) vs " . count($repairedMasters) . " (réparé)\n";
    
    $corruptedNotesMasters = $corrupted->xpath('//p:notesMasterIdLst/p:notesMasterId') ?? [];
    $repairedNotesMasters = $repaired->xpath('//p:notesMasterIdLst/p:notesMasterId') ?? [];
    
    echo "   Notes Masters: " . count($corruptedNotesMasters) . " (corrompu) vs " . count($repairedNotesMasters) . " (réparé)\n";
}

echo "✅ Analyse terminée\n";
echo "\n📁 Répertoires temporaires conservés pour inspection manuelle:\n";
echo "   Corrompu: $corruptedDir\n";
echo "   Réparé: $repairedDir\n";