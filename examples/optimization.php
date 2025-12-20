<?php

require __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

// ========================================
// Exemple 1: Utilisation basique (sans optimisation)
// ========================================

echo "=== Exemple 1: Sans optimisation ===\n";

$pptx = new PPTX('presentation.pptx');
echo "Nombre de slides: " . count($pptx->getSlides()) . "\n";

// ========================================
// Exemple 2: Avec optimisation d'images activée
// ========================================

echo "\n=== Exemple 2: Avec optimisation d'images ===\n";

$pptx = new PPTX('presentation.pptx', [
    'image_compression' => true,
    'image_quality' => 85,
    'deduplicate_images' => true,
    'collect_stats' => true,
]);

// Fusionner une autre présentation
$pptxToMerge = new PPTX('other_presentation.pptx');
$pptx->addSlides($pptxToMerge->getSlides());

// Sauvegarder
$pptx->saveAs('optimized_presentation.pptx');

// Afficher les statistiques
$stats = $pptx->getOptimizationStats();
echo "Statistiques d'optimisation:\n";
echo "- Taille originale: " . formatBytes($stats['original_size']) . "\n";
echo "- Taille optimisée: " . formatBytes($stats['optimized_size']) . "\n";
echo "- Économisé: " . formatBytes($stats['bytes_saved']) . " ({$stats['savings_percent']}%)\n";
echo "- Images compressées: {$stats['images_compressed']}\n";
echo "- Images dédupliquées: {$stats['images_deduplicated']}\n";

echo "\nRésumé: " . $pptx->getOptimizationSummary() . "\n";

// ========================================
// Exemple 3: Configuration personnalisée
// ========================================

echo "\n=== Exemple 3: Configuration personnalisée ===\n";

$pptx = new PPTX('presentation.pptx', [
    // Optimisation des images
    'image_compression' => true,
    'image_quality' => 75,  // Qualité plus basse = plus de compression
    'max_image_width' => 1920,
    'max_image_height' => 1080,
    
    // Performance
    'deduplicate_images' => true,
    'collect_stats' => true,
]);

// ========================================
// Exemple 4: Activer toutes les optimisations
// ========================================

echo "\n=== Exemple 4: Toutes les optimisations ===\n";

$pptx = new PPTX('presentation.pptx');
$pptx->getConfig()->enableOptimizations();

// Fusionner plusieurs présentations
$presentations = ['pres1.pptx', 'pres2.pptx', 'pres3.pptx'];
foreach ($presentations as $file) {
    if (file_exists($file)) {
        $toMerge = new PPTX($file);
        $pptx->addSlides($toMerge->getSlides());
    }
}

$pptx->saveAs('merged_optimized.pptx');
echo $pptx->getOptimizationSummary() . "\n";

// ========================================
// Exemple 5: Comparaison avant/après
// ========================================

echo "\n=== Exemple 5: Comparaison avant/après ===\n";

// Sans optimisation
$start = microtime(true);
$pptxNormal = new PPTX('large_presentation.pptx');
$pptxToAdd = new PPTX('presentation.pptx');
$pptxNormal->addSlides($pptxToAdd->getSlides());
$pptxNormal->saveAs('merged_normal.pptx');
$timeNormal = microtime(true) - $start;
$sizeNormal = filesize('merged_normal.pptx');

// Avec optimisation
$start = microtime(true);
$pptxOpt = new PPTX('large_presentation.pptx', [
    'image_compression' => true,
    'deduplicate_images' => true,
    'collect_stats' => true,
]);
$pptxToAddOpt = new PPTX('presentation.pptx');
$pptxOpt->addSlides($pptxToAddOpt->getSlides());
$pptxOpt->saveAs('merged_optimized.pptx');
$timeOpt = microtime(true) - $start;
$sizeOpt = filesize('merged_optimized.pptx');

echo "Résultats de la comparaison:\n";
echo "Sans optimisation:\n";
echo "  - Temps: " . round($timeNormal, 2) . "s\n";
echo "  - Taille: " . formatBytes($sizeNormal) . "\n";
echo "\nAvec optimisation:\n";
echo "  - Temps: " . round($timeOpt, 2) . "s\n";
echo "  - Taille: " . formatBytes($sizeOpt) . "\n";
echo "\nGain:\n";
echo "  - Taille: " . round((1 - $sizeOpt/$sizeNormal) * 100, 1) . "%\n";
echo "  - Temps: " . ($timeOpt < $timeNormal ? "+" : "-") . round(abs($timeNormal - $timeOpt), 2) . "s\n";

// ========================================
// Fonction utilitaire
// ========================================

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $index = 0;
    $size = $bytes;
    
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }
    
    return round($size, 2) . ' ' . $units[$index];
}