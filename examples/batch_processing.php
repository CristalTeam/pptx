<?php

require __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

// ========================================
// Exemple 1: Traitement par batch basique
// ========================================

echo "=== Exemple 1: Traitement par batch ===\n";

$master = new PPTX('base.pptx', [
    'image_compression' => true,
    'deduplicate_images' => true,
    'lazy_loading' => true,
    'collect_stats' => true,
]);

// Charger plusieurs présentations
$presentations = [
    new PPTX('presentation1.pptx'),
    new PPTX('presentation2.pptx'),
    new PPTX('presentation3.pptx'),
];

// Collecter toutes les slides
$allSlides = [];
foreach ($presentations as $pptx) {
    $allSlides = array_merge($allSlides, $pptx->getSlides());
}

// Traitement par batch (plus rapide que addSlides)
$start = microtime(true);
$master->addSlidesBatch($allSlides);
$batchTime = microtime(true) - $start;

$master->saveAs('merged_batch.pptx');

echo "Temps de traitement batch: " . round($batchTime, 3) . "s\n";
echo "Slides ajoutées: " . count($allSlides) . "\n";
echo $master->getOptimizationSummary() . "\n";

// ========================================
// Exemple 2: Comparaison batch vs normal
// ========================================

echo "\n=== Exemple 2: Comparaison performance ===\n";

// Méthode normale
$pptxNormal = new PPTX('base.pptx');
$start = microtime(true);
foreach ($presentations as $pptx) {
    $pptxNormal->addSlides($pptx->getSlides());
}
$normalTime = microtime(true) - $start;

// Méthode batch
$pptxBatch = new PPTX('base.pptx');
$start = microtime(true);
$pptxBatch->addSlidesBatch($allSlides);
$batchTime = microtime(true) - $start;

echo "Temps méthode normale: " . round($normalTime, 3) . "s\n";
echo "Temps méthode batch: " . round($batchTime, 3) . "s\n";
echo "Gain de performance: " . round((1 - $batchTime/$normalTime) * 100, 1) . "%\n";

// ========================================
// Exemple 3: Batch avec options avancées
// ========================================

echo "\n=== Exemple 3: Options avancées ===\n";

$master = new PPTX('base.pptx', [
    'image_compression' => true,
    'lazy_loading' => true,
    'cache_size' => 200,            // Cache plus grand
    'deduplicate_images' => true,
]);

// Options de batch processing
$batchOptions = [
    'refresh_at_end' => true,       // Rafraîchir une seule fois à la fin
    'save_incrementally' => false,  // Ne pas sauvegarder après chaque slide
    'continue_on_error' => true,    // Continuer même en cas d'erreur
    'collect_stats' => true,
];

try {
    $master->addSlidesBatch($allSlides, $batchOptions);
    echo "Batch complété avec succès\n";
} catch (Exception $e) {
    echo "Erreur durant le batch: " . $e->getMessage() . "\n";
}

// ========================================
// Exemple 4: Batch avec gestion mémoire
// ========================================

echo "\n=== Exemple 4: Gestion mémoire optimisée ===\n";

$master = new PPTX('base.pptx', [
    'lazy_loading' => true,         // Charger à la demande
    'cache_size' => 50,             // Cache plus petit pour économiser mémoire
    'image_compression' => true,
    'deduplicate_images' => true,
]);

// Traiter par petits lots
$batchSize = 10;
$slideBatches = array_chunk($allSlides, $batchSize);

echo "Traitement de " . count($slideBatches) . " batches de $batchSize slides\n";

foreach ($slideBatches as $index => $batch) {
    echo "Batch " . ($index + 1) . "/" . count($slideBatches) . "...";
    
    $master->addSlidesBatch($batch, [
        'refresh_at_end' => false,  // Ne rafraîchir qu'à la toute fin
    ]);
    
    echo " ✓\n";
    
    // Afficher utilisation mémoire
    $memUsage = round(memory_get_usage(true) / 1024 / 1024, 2);
    echo "Mémoire utilisée: {$memUsage} MB\n";
}

// Rafraîchir une seule fois à la fin
$master->refreshSource();
$master->saveAs('merged_memory_optimized.pptx');

echo "\nTraitement terminé\n";
echo $master->getOptimizationSummary() . "\n";

// ========================================
// Exemple 5: Statistiques détaillées du cache
// ========================================

echo "\n=== Exemple 5: Statistiques de cache ===\n";

$master = new PPTX('base.pptx', [
    'lazy_loading' => true,
    'cache_size' => 100,
    'deduplicate_images' => true,
    'collect_stats' => true,
]);

$master->addSlidesBatch($allSlides);

$stats = $master->getOptimizationStats();

echo "Statistiques du cache:\n";
if (isset($stats['cache_stats'])) {
    $cache = $stats['cache_stats'];
    echo "  - Images en cache: {$cache['cached_images']}\n";
    echo "  - Doublons trouvés: {$cache['duplicates_found']}\n";
}

// Statistiques de ContentType cache
$cacheStats = $master->getContentType()->getCacheStats();
if ($cacheStats) {
    echo "\nStatistiques du cache LRU:\n";
    echo "  - Taille: {$cacheStats['size']}/{$cacheStats['max_size']}\n";
    echo "  - Taux de réussite: {$cacheStats['hit_rate']}%\n";
    echo "  - Hits: {$cacheStats['hits']}\n";
    echo "  - Misses: {$cacheStats['misses']}\n";
    echo "  - Évictions: {$cacheStats['evictions']}\n";
    echo "  - Utilisation: {$cacheStats['usage_percent']}%\n";
}

// ========================================
// Exemple 6: Fusion massive avec progression
// ========================================

echo "\n=== Exemple 6: Fusion massive ===\n";

$master = new PPTX('base.pptx', [
    'image_compression' => true,
    'lazy_loading' => true,
    'deduplicate_images' => true,
    'collect_stats' => true,
]);

// Simuler une fusion massive
$files = glob('presentations/*.pptx');
$totalSlides = 0;

echo "Fusion de " . count($files) . " fichiers...\n";

foreach ($files as $i => $file) {
    $pptx = new PPTX($file);
    $slides = $pptx->getSlides();
    $totalSlides += count($slides);
    
    $master->addSlidesBatch($slides, [
        'refresh_at_end' => false,
    ]);
    
    // Afficher progression
    $progress = round(($i + 1) / count($files) * 100);
    echo "[$progress%] Fichier " . ($i + 1) . "/" . count($files) . " - " . count($slides) . " slides\n";
}

// Rafraîchir et sauvegarder
$master->refreshSource();
$master->saveAs('massive_merge.pptx');

echo "\nFusion terminée:\n";
echo "  - Fichiers fusionnés: " . count($files) . "\n";
echo "  - Slides totales: $totalSlides\n";
echo "  - " . $master->getOptimizationSummary() . "\n";