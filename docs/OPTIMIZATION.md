# Guide d'Optimisation

Ce guide explique comment utiliser les fonctionnalités d'optimisation de la bibliothèque PPTX pour réduire la taille des fichiers et améliorer les performances.

## Table des Matières

1. [Configuration Rapide](#configuration-rapide)
2. [Options d'Optimisation](#options-doptimisation)
3. [Compression d'Images](#compression-dimages)
4. [Déduplication d'Images](#déduplication-dimages)
5. [Statistiques et Monitoring](#statistiques-et-monitoring)
6. [Meilleures Pratiques](#meilleures-pratiques)
7. [FAQ](#faq)

## Configuration Rapide

### Sans Optimisation (Comportement par défaut)

```php
$pptx = new PPTX('presentation.pptx');
```

### Avec Optimisations Activées

```php
$pptx = new PPTX('presentation.pptx', [
    'image_compression' => true,
    'deduplicate_images' => true,
    'collect_stats' => true,
]);
```

### Activer Toutes les Optimisations

```php
$pptx = new PPTX('presentation.pptx');
$pptx->getConfig()->enableOptimizations();
```

## Options d'Optimisation

### Options d'Images

| Option | Type | Défaut | Description |
|--------|------|--------|-------------|
| `image_compression` | bool | `false` | Active la compression automatique des images |
| `image_quality` | int | `85` | Qualité de compression JPEG (1-100) |
| `max_image_width` | int | `1920` | Largeur maximale des images (redimensionnement automatique) |
| `max_image_height` | int | `1080` | Hauteur maximale des images |
| `convert_to_webp` | bool | `false` | Convertir les images en WebP (non implémenté encore) |

### Options de Performance

| Option | Type | Défaut | Description |
|--------|------|--------|-------------|
| `lazy_loading` | bool | `false` | Chargement paresseux des ressources (non implémenté encore) |
| `cache_size` | int | `100` | Taille du cache LRU (non implémenté encore) |
| `deduplicate_images` | bool | `false` | Détection et réutilisation des images identiques |

### Options de Validation

| Option | Type | Défaut | Description |
|--------|------|--------|-------------|
| `validate_images` | bool | `false` | Valider les images avant ajout (non implémenté encore) |
| `max_image_size` | int | `10485760` | Taille maximale d'image (10MB) |

### Options de Debug

| Option | Type | Défaut | Description |
|--------|------|--------|-------------|
| `collect_stats` | bool | `false` | Collecter les statistiques d'optimisation |

## Compression d'Images

### Fonctionnement

La compression d'images réduit automatiquement la taille des fichiers en :
1. Redimensionnant les images trop grandes
2. Compressant les JPEG avec une qualité configurable
3. Optimisant les PNG

### Exemples

```php
// Compression avec qualité par défaut (85%)
$pptx = new PPTX('presentation.pptx', [
    'image_compression' => true,
]);

// Compression agressive (qualité 70%)
$pptx = new PPTX('presentation.pptx', [
    'image_compression' => true,
    'image_quality' => 70,
]);

// Compression + redimensionnement
$pptx = new PPTX('presentation.pptx', [
    'image_compression' => true,
    'max_image_width' => 1280,
    'max_image_height' => 720,
]);
```

### Résultats Attendus

- **JPEG** : Réduction de 30-60% selon la qualité
- **PNG** : Réduction de 10-30%
- **Images surdimensionnées** : Réduction de 50-80%

## Déduplication d'Images

### Fonctionnement

La déduplication détecte les images identiques et les réutilise automatiquement lors de la fusion de présentations.

### Exemple

```php
$pptx = new PPTX('presentation1.pptx', [
    'deduplicate_images' => true,
    'collect_stats' => true,
]);

// Fusionner une présentation qui contient des images identiques
$pptx2 = new PPTX('presentation2.pptx');
$pptx->addSlides($pptx2->getSlides());

// Vérifier les doublons détectés
$stats = $pptx->getOptimizationStats();
echo "Images dédupliquées: {$stats['images_deduplicated']}\n";
```

### Algorithme

1. Calcul d'un hash rapide basé sur les premiers et derniers 8KB de l'image
2. Comparaison avec le cache d'images existantes
3. Réutilisation de l'image si identique

**Performance** : ~100x plus rapide qu'un hash MD5 complet sur de grandes images.

## Statistiques et Monitoring

### Obtenir les Statistiques

```php
$pptx = new PPTX('presentation.pptx', [
    'image_compression' => true,
    'deduplicate_images' => true,
    'collect_stats' => true,
]);

// ... opérations ...

$stats = $pptx->getOptimizationStats();
print_r($stats);
```

### Exemple de Sortie

```php
Array
(
    [original_size] => 15728640        // 15 MB
    [optimized_size] => 8912000        // 8.5 MB
    [bytes_saved] => 6816640           // 6.5 MB
    [compression_ratio] => 0.567
    [savings_percent] => 43.33         // 43% d'économie
    [images_compressed] => 12
    [images_resized] => 5
    [images_deduplicated] => 3
    [total_optimizations] => 20
    [cache_stats] => Array
    (
        [cached_images] => 15
        [duplicates_found] => 3
        [memory_keys] => 15
    )
)
```

### Résumé Formaté

```php
echo $pptx->getOptimizationSummary();
// Output: "Optimisation: 20 images traitées, 43.33% économisés (15.00 MB -> 8.50 MB)"
```

## Meilleures Pratiques

### 1. Optimisation pour Production

```php
$pptx = new PPTX('presentation.pptx', [
    'image_compression' => true,
    'image_quality' => 85,              // Bon compromis qualité/taille
    'max_image_width' => 1920,
    'max_image_height' => 1080,
    'deduplicate_images' => true,
    'collect_stats' => false,           // Désactiver en production pour performances
]);
```

### 2. Optimisation Maximale (pour archivage)

```php
$pptx = new PPTX('presentation.pptx', [
    'image_compression' => true,
    'image_quality' => 70,              // Compression agressive
    'max_image_width' => 1280,          // Dimensions réduites
    'max_image_height' => 720,
    'deduplicate_images' => true,
]);
```

### 3. Qualité Maximale (pas d'optimisation)

```php
$pptx = new PPTX('presentation.pptx');
// Aucune optimisation, conserve la qualité originale
```

### 4. Fusion de Multiples Présentations

```php
$master = new PPTX('base.pptx', [
    'image_compression' => true,
    'deduplicate_images' => true,
    'collect_stats' => true,
]);

$presentations = glob('presentations/*.pptx');
foreach ($presentations as $file) {
    $pptx = new PPTX($file);
    $master->addSlides($pptx->getSlides());
}

$master->saveAs('merged.pptx');
echo $master->getOptimizationSummary();
```

## FAQ

### Q: L'optimisation affecte-t-elle la qualité visuelle ?

**R:** Avec les paramètres par défaut (`image_quality=85`), la perte de qualité est imperceptible. Pour des présentations professionnelles, utilisez `image_quality=90` ou plus.

### Q: Puis-je désactiver l'optimisation pour certaines images ?

**R:** Actuellement, l'optimisation s'applique à toutes les images. Pour exclure certaines images, ne les incluez pas dans la présentation optimisée.

### Q: Les optimisations ralentissent-elles le traitement ?

**R:** 
- **Compression** : Ajoute ~10-20% au temps de traitement
- **Déduplication** : Ajoute < 1% au temps de traitement
- **Redimensionnement** : Dépend de la taille originale

### Q: Puis-je utiliser les optimisations avec du code existant ?

**R:** Oui ! Les optimisations sont opt-in et ne cassent pas le code existant :

```php
// Code existant (continue de fonctionner)
$pptx = new PPTX('file.pptx');

// Avec optimisations (nouveau)
$pptx = new PPTX('file.pptx', ['image_compression' => true]);
```

### Q: Comment mesurer l'impact des optimisations ?

**R:** Utilisez `collect_stats` et comparez avant/après :

```php
$original = filesize('original.pptx');

$pptx = new PPTX('original.pptx', [
    'image_compression' => true,
    'deduplicate_images' => true,
    'collect_stats' => true,
]);

$pptx->save();
$optimized = filesize('original.pptx');

echo "Gain: " . round((1 - $optimized/$original) * 100, 1) . "%\n";
```

### Q: Les fichiers optimisés sont-ils compatibles avec PowerPoint ?

**R:** Oui, les fichiers optimisés respectent le format PPTX standard et s'ouvrent dans PowerPoint, LibreOffice, Google Slides, etc.

## Support

Pour des questions ou problèmes :
- GitHub Issues : [lien vers repo]
- Documentation API : `docs/API.md`
- Exemples : `examples/optimization.php`