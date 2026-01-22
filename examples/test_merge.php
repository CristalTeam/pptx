<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

// Create a merged file
echo "🔧 Création d'un fichier fusionné...\n";

$source = new PPTX(__DIR__ . '/../tests/mock/FIN.pptx');
$toMerge = new PPTX(__DIR__ . '/../tests/mock/FIN.pptx');

$source->addSlides($toMerge->getSlides());
$outputPath = sys_get_temp_dir() . '/test_merge_diagnostic.pptx';
$source->saveAs($outputPath);

echo "✅ Fichier créé: $outputPath\n\n";

// Now diagnose it
echo "🔍 Lancement du diagnostic...\n\n";

passthru("php " . __DIR__ . "/diagnose_corruption.php " . escapeshellarg($outputPath), $returnCode);

if ($returnCode === 0) {
    echo "\n✅ Le fichier semble valide.\n";
} else {
    echo "\n❌ Des problèmes ont été détectés.\n";
}

echo "\n📍 Fichier disponible pour test manuel: $outputPath\n";
echo "   Essayez de l'ouvrir dans PowerPoint pour voir si une réparation est nécessaire.\n";