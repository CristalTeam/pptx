<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

echo "🔍 VALIDATION STRICTE OFFICE XML\n";
echo str_repeat('=', 60) . "\n\n";

$file = $argv[1] ?? __DIR__ . '/../test_merge_powerpoint.pptx';

$zip = new ZipArchive();
$zip->open($file);

$issues = [];

// Validate XML formatting and namespaces
echo "📋 Validation du formatage XML...\n";
for ($i = 0; $i < $zip->numFiles; $i++) {
    $filename = $zip->getNameIndex($i);
    
    if (!str_ends_with($filename, '.xml') && !str_ends_with($filename, '.rels')) {
        continue;
    }
    
    $content = $zip->getFromName($filename);
    
    // Check for XML declaration
    if (!str_starts_with($content, '<?xml')) {
        $issues[] = "$filename: Pas de déclaration XML au début";
    }
    
    // Check for proper XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($content);
    if ($xml === false) {
        $errors = libxml_get_errors();
        foreach ($errors as $error) {
            $issues[] = "$filename: Erreur XML ligne {$error->line}: " . trim($error->message);
        }
        libxml_clear_errors();
        continue;
    }
    
    // Check for UTF-8 encoding
    if (!preg_match('/encoding="UTF-8"/', $content)) {
        $issues[] = "$filename: N'utilise pas UTF-8 encoding";
    }
    
    // Check for standalone attribute
    if (str_contains($filename, 'presentation.xml') || str_contains($filename, 'slide') && !str_contains($filename, '_rels')) {
        if (!preg_match('/standalone="yes"/', $content)) {
            $issues[] = "$filename: Manque standalone=\"yes\"";
        }
    }
}

echo "   Fichiers XML vérifiés: " . $i . "\n\n";

// Check for common PowerPoint corruption issues
echo "⚠️  Vérification des problèmes courants PowerPoint...\n";

// Check slide numbering consistency
$presContent = $zip->getFromName('ppt/presentation.xml');
$presXml = simplexml_load_string($presContent);
$presXml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');

$slides = $presXml->xpath('//p:sldId');
$slideIds = [];
foreach ($slides as $slide) {
    $slideIds[] = (int)(string)$slide['id'];
}

// Check if slide IDs are sequential
$expectedId = 256; // PowerPoint starts at 256
foreach ($slideIds as $i => $id) {
    $expected = $expectedId + $i;
    if ($id !== $expected) {
        $issues[] = "Slide ID non séquentiel: attendu $expected, trouvé $id";
    }
}

echo "\n";

// Display results
if (empty($issues)) {
    echo "✅ Aucun problème détecté par le validateur\n";
    echo "Le fichier semble conforme aux standards Office XML.\n";
} else {
    echo "❌ PROBLÈMES DÉTECTÉS (" . count($issues) . "):\n";
    foreach ($issues as $i => $issue) {
        echo "   " . ($i + 1) . ". $issue\n";
    }
}

$zip->close();

echo "\n💡 SUGGESTION:\n";
echo "Si PowerPoint détecte toujours un problème malgré la validation,\n";
echo "cliquez sur 'Réparer' et comparez le fichier réparé avec l'original\n";
echo "pour identifier ce que PowerPoint a modifié.\n";