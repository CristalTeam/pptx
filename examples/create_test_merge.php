<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

echo "🔧 Création d'un fichier fusionné pour test PowerPoint...\n\n";

$source = new PPTX(__DIR__ . '/../tests/mock/FIN.pptx');
$toMerge = new PPTX(__DIR__ . '/../tests/mock/FIN.pptx');

$source->addSlides($toMerge->getSlides());

// Sauvegarder dans le répertoire courant pour test facile
$outputPath = __DIR__ . '/../test_merge_powerpoint.pptx';
$source->saveAs($outputPath);

echo "✅ Fichier créé : $outputPath\n\n";
echo "📋 Veuillez :\n";
echo "   1. Ouvrir ce fichier dans PowerPoint\n";
echo "   2. Noter le message d'erreur EXACT affiché par PowerPoint\n";
echo "   3. Me communiquer ce message\n\n";

// Vérification rapide
$zip = new ZipArchive();
$zip->open($outputPath);

echo "📊 Informations sur le fichier créé :\n";
echo "   - Nombre de fichiers dans le ZIP : " . $zip->numFiles . "\n";

// Compter les themes
$themeCount = 0;
$themesList = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (preg_match('#ppt/theme/(theme\d+\.xml)$#', $name, $match)) {
        $themeCount++;
        $themesList[] = $match[1];
    }
}
echo "   - Nombre de themes : $themeCount (" . implode(', ', $themesList) . ")\n";

// Vérifier les themes référencés
$referenced = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (strpos($name, '.rels') !== false) {
        $content = $zip->getFromName($name);
        if (preg_match_all('/Target="\.\.\/theme\/(theme\d+\.xml)"/', $content, $matches)) {
            $referenced = array_merge($referenced, $matches[1]);
        }
    }
}
$referenced = array_unique($referenced);
echo "   - Themes référencés : " . implode(', ', $referenced) . "\n";

$orphans = array_diff($themesList, $referenced);
echo "   - Themes orphelins : " . (empty($orphans) ? 'AUCUN ✅' : implode(', ', $orphans) . ' ❌') . "\n";

$zip->close();

echo "\n🎯 Le fichier est prêt pour test dans PowerPoint !\n";