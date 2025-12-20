<?php

require __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

// ========================================
// Exemple 1: Validation basique d'images
// ========================================

echo "=== Exemple 1: Validation d'images ===\n";

$pptx = new PPTX('presentation.pptx');

// Valider toutes les images
$imageReport = $pptx->validateImages();

echo "Images totales: {$imageReport['total']}\n";
echo "Images valides: {$imageReport['valid']}\n";
echo "Images invalides: {$imageReport['invalid']}\n";

// Afficher les détails des images invalides
if ($imageReport['invalid'] > 0) {
    echo "\nImages avec problèmes:\n";
    foreach ($imageReport['details'] as $path => $details) {
        if (!$details['valid']) {
            echo "  - $path: " . implode(', ', $details['errors']) . "\n";
        }
    }
}

// ========================================
// Exemple 2: Validation complète
// ========================================

echo "\n=== Exemple 2: Validation complète ===\n";

$pptx = new PPTX('presentation.pptx', [
    'validate_images' => true,
]);

$report = $pptx->validate();

echo "Présentation valide: " . ($report['valid'] ? 'Oui' : 'Non') . "\n";
echo "Résumé: " . $report['summary'] . "\n";

// Afficher les détails par catégorie
echo "\nSlides:\n";
echo "  - Total: {$report['slides']['total_slides']}\n";
echo "  - Valides: {$report['slides']['valid_slides']}\n";
echo "  - Invalides: {$report['slides']['invalid_slides']}\n";

echo "\nRessources:\n";
echo "  - Total: {$report['resources']['total_resources']}\n";
echo "  - Valides: {$report['resources']['valid_resources']}\n";
echo "  - Invalides: {$report['resources']['invalid_resources']}\n";
echo "  - Images vérifiées: {$report['resources']['images_checked']}\n";
echo "  - Images corrompues: {$report['resources']['corrupted_images']}\n";
echo "  - Images volumineuses: {$report['resources']['oversized_images']}\n";

// ========================================
// Exemple 3: Conversion WebP
// ========================================

echo "\n=== Exemple 3: Conversion WebP ===\n";

// Vérifier si WebP est supporté
if (function_exists('imagewebp')) {
    echo "WebP est supporté ✓\n";
    
    $pptx = new PPTX('presentation.pptx', [
        'image_compression' => true,
        'convert_to_webp' => true,  // Activer conversion WebP
        'image_quality' => 85,
        'collect_stats' => true,
    ]);
    
    // Les images seront automatiquement converties en WebP lors de l'ajout
    $other = new PPTX('other.pptx');
    $pptx->addSlides($other->getSlides());
    
    $pptx->saveAs('webp_presentation.pptx');
    
    $stats = $pptx->getOptimizationStats();
    echo "Images optimisées: {$stats['images_compressed']}\n";
    echo $pptx->getOptimizationSummary() . "\n";
} else {
    echo "WebP n'est pas supporté sur ce système ✗\n";
    echo "Installez l'extension GD avec support WebP\n";
}

// ========================================
// Exemple 4: Validation avant traitement
// ========================================

echo "\n=== Exemple 4: Validation préventive ===\n";

$pptx = new PPTX('presentation.pptx', [
    'validate_images' => true,
    'max_image_size' => 5 * 1024 * 1024, // 5MB max
]);

// Valider avant toute opération
$report = $pptx->validate();

if (!$report['valid']) {
    echo "⚠ Problèmes détectés:\n";
    
    if (count($report['slides']['errors']) > 0) {
        echo "Erreurs slides:\n";
        foreach ($report['slides']['errors'] as $error) {
            echo "  - $error\n";
        }
    }
    
    if (count($report['resources']['errors']) > 0) {
        echo "Erreurs ressources:\n";
        foreach ($report['resources']['errors'] as $error) {
            echo "  - $error\n";
        }
    }
    
    // Décider si on continue ou pas
    echo "\nContinuer quand même? (y/n): ";
    // ... gestion utilisateur
} else {
    echo "✓ Aucun problème détecté, traitement sûr\n";
}

// ========================================
// Exemple 5: Rapport détaillé par image
// ========================================

echo "\n=== Exemple 5: Rapport détaillé ===\n";

$pptx = new PPTX('presentation.pptx');
$imageReport = $pptx->validateImages();

foreach ($imageReport['details'] as $path => $details) {
    echo "\n$path:\n";
    echo "  - Valide: " . ($details['valid'] ? 'Oui' : 'Non') . "\n";
    
    if (isset($details['mime_type'])) {
        echo "  - Type: {$details['mime_type']}\n";
    }
    
    if (isset($details['size'])) {
        echo "  - Taille: " . formatBytes($details['size']) . "\n";
    }
    
    if (isset($details['dimensions'])) {
        echo "  - Dimensions: {$details['dimensions']['width']}x{$details['dimensions']['height']}\n";
    }
    
    if (!$details['valid'] && isset($details['errors'])) {
        echo "  - Erreurs:\n";
        foreach ($details['errors'] as $error) {
            echo "    * $error\n";
        }
    }
}

// ========================================
// Exemple 6: Pipeline complet avec validation et optimisation
// ========================================

echo "\n=== Exemple 6: Pipeline complet ===\n";

function processPresentation($inputFile, $outputFile) {
    echo "Traitement de: $inputFile\n";
    
    // 1. Charger avec validation
    $pptx = new PPTX($inputFile, [
        'validate_images' => true,
        'image_compression' => true,
        'convert_to_webp' => function_exists('imagewebp'),
        'image_quality' => 85,
        'deduplicate_images' => true,
        'lazy_loading' => true,
        'collect_stats' => true,
    ]);
    
    // 2. Valider
    echo "  1. Validation...\n";
    $report = $pptx->validate();
    
    if (!$report['valid']) {
        echo "  ⚠ Avertissements:\n";
        foreach ($report['resources']['warnings'] as $warning) {
            echo "    - $warning\n";
        }
    } else {
        echo "  ✓ Validation OK\n";
    }
    
    // 3. Optimiser (déjà fait automatiquement)
    echo "  2. Optimisation...\n";
    
    // 4. Sauvegarder
    echo "  3. Sauvegarde...\n";
    $pptx->saveAs($outputFile);
    
    // 5. Rapport
    echo "  4. Résumé:\n";
    echo "    " . $pptx->getOptimizationSummary() . "\n";
    
    $stats = $pptx->getOptimizationStats();
    if ($stats['images_compressed'] > 0) {
        echo "    Ratio de compression: " . round($stats['compression_ratio'] * 100) . "%\n";
    }
    
    echo "  ✓ Terminé\n";
}

// Traiter une présentation
processPresentation('input.pptx', 'output_optimized.pptx');

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