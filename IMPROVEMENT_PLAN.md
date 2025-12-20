# Plan d'Amélioration de la Bibliothèque PPTX

## 📊 Analyse des Goulots d'Étranglement Actuels

### Problèmes de Performance Identifiés

1. **Gestion des images inefficace**
   - Classe `Image.php` quasi vide sans optimisation
   - Aucune compression ou redimensionnement automatique
   - Images chargées entièrement en mémoire

2. **Calcul de hash coûteux**
   - `GenericResource::getHashFile()` utilise md5() sur le contenu complet
   - Problématique pour les images volumineuses (plusieurs Mo)

3. **Absence de lazy loading**
   - Toutes les ressources sont chargées immédiatement
   - Consommation mémoire importante pour les grandes présentations

4. **Cache sous-optimal**
   - Cache simple par chemin dans `ContentType`
   - Pas de limite de taille ou d'éviction LRU

---

## 🎯 Plan d'Amélioration Structuré

### Phase 1: Optimisation des Images (Priorité Haute)

#### 1.1 Compression Automatique

**Fichiers à modifier:**
- `Presentation/Resource/Image.php`
- `Presentation/PPTX.php` (nouvelle option dans constructeur)

**Fonctionnalités:**
- Compression JPEG avec qualité configurable (défaut: 85%)
- Compression PNG avec niveau configurable (défaut: 9)
- Support WebP pour réduction de taille (~30% plus léger)
- Option pour désactiver la compression

**Bénéfices attendus:**
- Réduction de 30-60% de la taille des fichiers PPTX
- Temps de chargement/sauvegarde réduits
- Moins d'utilisation mémoire

#### 1.2 Détection et Déduplication d'Images

**Fichiers à créer/modifier:**
- `Presentation/Cache/ImageCache.php` (nouveau)
- `Presentation/Resource/ContentType.php`

**Fonctionnalités:**
- Cache de hash d'images pour éviter les doublons
- Réutilisation automatique d'images identiques
- Statistiques de déduplication

**Bénéfices attendus:**
- Réduction de 20-40% pour les présentations avec images répétées
- Fusion de présentations plus rapide
- Moins de stockage

#### 1.3 Redimensionnement Intelligent

**Fichiers à modifier:**
- `Presentation/Resource/Image.php`

**Fonctionnalités:**
- Détection automatique des images surdimensionnées
- Redimensionnement selon résolution max (ex: 1920x1080)
- Préservation du ratio d'aspect
- Option pour conserver les originaux

**Bénéfices attendus:**
- Réduction de 50-80% pour images très grandes
- Performances améliorées dans PowerPoint

---

### Phase 2: Optimisation des Performances (Priorité Moyenne)

#### 2.1 Lazy Loading des Ressources

**Fichiers à modifier:**
- `Presentation/Resource/XmlResource.php`
- `Presentation/Resource/GenericResource.php`

**Bénéfices:**
- Réduction de 60-80% de la mémoire au chargement
- Démarrage plus rapide

#### 2.2 Traitement par Batch

**Fichiers à modifier:**
- `Presentation/PPTX.php`

**Fonctionnalités:**
- Traitement groupé avec transaction
- Optimisation des écritures ZIP
- Mise à jour unique du XML

**Bénéfices:**
- 40-60% plus rapide pour fusion de multiples slides
- Moins de réouvertures du fichier ZIP

#### 2.3 Cache Amélioré avec LRU

**Fichiers à créer:**
- `Presentation/Cache/LRUCache.php` (nouveau)

**Bénéfices:**
- Utilisation mémoire contrôlée
- Performance stable pour grandes présentations

---

### Phase 3: Nouvelles Fonctionnalités (Priorité Basse)

#### 3.1 Support de Formats d'Image Modernes
- Support WebP natif
- Support AVIF (future-proof)
- Conversion automatique depuis BMP/TIFF

#### 3.2 Validation et Sanitization

**Fichiers à créer:**
- `Presentation/Validator/PresentationValidator.php`
- `Presentation/Validator/ImageValidator.php`

**Fonctionnalités:**
- Validation des dimensions d'images
- Vérification des types MIME
- Détection des fichiers corrompus
- Limites de taille configurables

#### 3.3 Reporting et Statistiques

**Fonctionnalités:**
- Statistiques d'optimisation
- Rapport de compression
- Métriques de performance

---

## 📝 Ordre d'Implémentation Recommandé

### Sprint 1 (2-3 jours) - Quick Wins
1. ✅ Compression JPEG/PNG basique
2. ✅ Hash rapide pour déduplication
3. ✅ Lazy loading simple

### Sprint 2 (3-4 jours) - Optimisations Majeures
4. ✅ Redimensionnement automatique
5. ✅ Cache LRU
6. ✅ Traitement par batch

### Sprint 3 (2-3 jours) - Fonctionnalités Avancées
7. ✅ Support WebP
8. ✅ Validation complète
9. ✅ Système de reporting

---

## 🔧 Configuration Suggérée

```php
$pptx = new PPTX('presentation.pptx', [
    // Optimisation des images
    'image_compression' => true,
    'image_quality' => 85,
    'max_image_width' => 1920,
    'max_image_height' => 1080,
    'convert_to_webp' => false,
    
    // Performance
    'lazy_loading' => true,
    'cache_size' => 100,
    'deduplicate_images' => true,
    
    // Validation
    'validate_images' => true,
    'max_image_size' => 10 * 1024 * 1024, // 10MB
]);
```

---

## 📊 Métriques de Succès

### Objectifs de Performance
- **Taille des fichiers:** Réduction de 40-60%
- **Temps de fusion:** Amélioration de 50-70%
- **Utilisation mémoire:** Réduction de 60-80%
- **Temps de chargement:** Amélioration de 40-50%

---

## 🚀 Migration et Rétrocompatibilité

Toutes les nouvelles fonctionnalités seront **opt-in par défaut** pour garantir la compatibilité:

```php
// Comportement actuel (par défaut)
$pptx = new PPTX('file.pptx');

// Avec optimisations
$pptx = new PPTX('file.pptx', [
    'enable_optimizations' => true
]);
```

---

## 📚 Documentation à Créer

1. **Guide d'optimisation des images** (README-optimization.md)
2. **Guide de migration** (MIGRATION.md)
3. **Benchmarks de performance** (BENCHMARKS.md)
4. **Exemples d'utilisation** (examples/optimization.php)