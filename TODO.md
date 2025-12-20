# TODO - Implémentation des Améliorations

## 🚀 Sprint 1: Quick Wins (Priorité Haute)

### 1. Système de Configuration
- [ ] Créer `Presentation/Config/OptimizationConfig.php`
  - [ ] Définir les options par défaut
  - [ ] Validation des options
  - [ ] Getters/setters pour les options

- [ ] Modifier `Presentation/PPTX.php`
  - [ ] Ajouter paramètre `$options` au constructeur
  - [ ] Initialiser `OptimizationConfig`
  - [ ] Conserver la rétrocompatibilité

### 2. Optimisation de Base des Images
- [ ] Améliorer `Presentation/Resource/Image.php`
  - [ ] Ajouter méthode `compressJpeg(string $content, int $quality): string`
  - [ ] Ajouter méthode `compressPng(string $content, int $level): string`
  - [ ] Ajouter méthode `detectImageType(string $content): string`
  - [ ] Surcharger `setContent()` pour appliquer la compression
  - [ ] Ajouter propriété `$originalSize` et `$compressedSize`

### 3. Hash Rapide pour Images
- [ ] Créer `Presentation/Cache/ImageCache.php`
  - [ ] Méthode `fastHash(string $content): string` (hash partiel)
  - [ ] Méthode `findDuplicate(string $content): ?Image`
  - [ ] Méthode `register(string $hash, Image $image): void`
  - [ ] Propriété `$cache` pour stocker les hashes

- [ ] Modifier `Presentation/PPTX.php`
  - [ ] Intégrer `ImageCache` dans `addResource()`
  - [ ] Vérifier les doublons avant ajout
  - [ ] Réutiliser les images existantes

### 4. Lazy Loading Simple
- [ ] Modifier `Presentation/Resource/GenericResource.php`
  - [ ] Ajouter propriété `$contentLoaded = false`
  - [ ] Ajouter propriété `$lazyContent = null`
  - [ ] Modifier `getContent()` pour charger à la demande
  - [ ] Ajouter méthode `unloadContent()` pour libérer la mémoire

---

## 🔧 Sprint 2: Optimisations Majeures (Priorité Moyenne)

### 5. Redimensionnement Automatique
- [ ] Améliorer `Presentation/Resource/Image.php`
  - [ ] Ajouter méthode `getDimensions(string $content): array`
  - [ ] Ajouter méthode `needsResize(int $width, int $height): bool`
  - [ ] Ajouter méthode `resize(string $content, int $maxWidth, int $maxHeight): string`
  - [ ] Intégrer dans `setContent()`
  - [ ] Préserver le ratio d'aspect

### 6. Cache LRU
- [ ] Créer `Presentation/Cache/LRUCache.php`
  - [ ] Propriété `$maxSize` (défaut: 100)
  - [ ] Propriété `$cache` (tableau associatif)
  - [ ] Propriété `$order` (tableau pour ordre d'accès)
  - [ ] Méthode `get(string $key): mixed`
  - [ ] Méthode `set(string $key, mixed $value): void`
  - [ ] Méthode `evict(): void` (supprimer le plus ancien)

- [ ] Modifier `Presentation/Resource/ContentType.php`
  - [ ] Remplacer `$cachedResources` par `LRUCache`
  - [ ] Adapter les méthodes existantes

### 7. Traitement par Batch
- [ ] Modifier `Presentation/PPTX.php`
  - [ ] Créer méthode `addSlidesBatch(array $slides, array $options = []): PPTX`
  - [ ] Optimiser les écritures ZIP groupées
  - [ ] Mise à jour unique du presentation.xml
  - [ ] Gestion des transactions (rollback en cas d'erreur)

---

## 🎨 Sprint 3: Fonctionnalités Avancées (Priorité Basse)

### 8. Support WebP
- [ ] Améliorer `Presentation/Resource/Image.php`
  - [ ] Ajouter méthode `convertToWebP(string $content): string`
  - [ ] Détection automatique du support WebP
  - [ ] Option de conversion dans la config

- [ ] Modifier `Presentation/Resource/ContentType.php`
  - [ ] Ajouter type MIME WebP dans `CLASSES`

### 9. Validation des Images
- [ ] Créer `Presentation/Validator/ImageValidator.php`
  - [ ] Méthode `validateDimensions(int $width, int $height): bool`
  - [ ] Méthode `validateSize(int $filesize): bool`
  - [ ] Méthode `validateMimeType(string $content): bool`
  - [ ] Méthode `isCorrupted(string $content): bool`

- [ ] Créer `Presentation/Validator/PresentationValidator.php`
  - [ ] Méthode `validateSlides(array $slides): array`
  - [ ] Méthode `validateResources(array $resources): array`
  - [ ] Retourner rapport de validation

### 10. Système de Statistiques
- [ ] Créer `Presentation/Stats/OptimizationStats.php`
  - [ ] Propriétés pour les métriques
  - [ ] Méthode `recordCompression(int $before, int $after): void`
  - [ ] Méthode `recordDeduplication(): void`
  - [ ] Méthode `recordResize(): void`
  - [ ] Méthode `getReport(): array`

- [ ] Modifier `Presentation/PPTX.php`
  - [ ] Intégrer `OptimizationStats`
  - [ ] Créer méthode `getOptimizationStats(): array`
  - [ ] Enregistrer toutes les opérations

---

## 📝 Tests à Créer

### Tests Unitaires
- [ ] `tests/Resource/ImageTest.php`
  - [ ] Test compression JPEG
  - [ ] Test compression PNG
  - [ ] Test redimensionnement
  - [ ] Test détection de type

- [ ] `tests/Cache/ImageCacheTest.php`
  - [ ] Test détection de doublons
  - [ ] Test hash rapide

- [ ] `tests/Cache/LRUCacheTest.php`
  - [ ] Test éviction LRU
  - [ ] Test limite de taille

### Tests d'Intégration
- [ ] `tests/Integration/OptimizationTest.php`
  - [ ] Test compression end-to-end
  - [ ] Test déduplication end-to-end
  - [ ] Test statistiques

### Tests de Performance
- [ ] `tests/Performance/BenchmarkTest.php`
  - [ ] Benchmark fusion de présentations
  - [ ] Benchmark compression
  - [ ] Benchmark mémoire

---

## 📚 Documentation à Créer

- [ ] `docs/OPTIMIZATION.md` - Guide d'optimisation
- [ ] `docs/MIGRATION.md` - Guide de migration
- [ ] `docs/API.md` - Documentation API des nouvelles classes
- [ ] `examples/optimization.php` - Exemple d'utilisation
- [ ] `examples/batch_processing.php` - Exemple de traitement par batch
- [ ] `BENCHMARKS.md` - Résultats de benchmarks

---

## 🔍 Code Review & Qualité

- [ ] PSR-12 compliance pour tous les nouveaux fichiers
- [ ] PHPDoc complet pour toutes les méthodes publiques
- [ ] Gestion d'erreurs cohérente
- [ ] Tests de couverture > 80%
- [ ] Revue de code par pairs

---

## 📦 Release

- [ ] Mettre à jour `CHANGELOG.md`
- [ ] Créer tag de version (v2.0.0)
- [ ] Publier sur Packagist
- [ ] Annonce sur README