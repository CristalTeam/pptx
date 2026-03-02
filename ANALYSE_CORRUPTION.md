# Analyse de la Corruption des Fichiers PPTX

## Résumé Exécutif

Le rapport de comparaison `pptx_compare_report.json` révèle **3 problèmes critiques** dans l'implémentation de la fusion PPTX :

1. ❌ **Ordre des rIds dans presentation.xml** : Les slides obtiennent des rIds APRÈS les ressources système au lieu d'AVANT
2. ❌ **Références NoteSlide ↔ Slide incorrectes** : Les NoteSlides pointent vers les mauvais slides après fusion
3. ⚠️ **SlideLayouts orphelins** : Des layouts sont ajoutés mais jamais référencés par les slides

---

## 🔴 Problème 1 : Ordre des rIds dans presentation.xml (CRITIQUE)

### Symptôme

Le fichier généré produit cet ordre de rIds dans `ppt/_rels/presentation.xml.rels` :

```xml
<!-- CORROMPU (code actuel) -->
<Relationship Id="rId16" Target="notesMasters/notesMaster1.xml" />
<Relationship Id="rId17" Target="presProps.xml" />
<Relationship Id="rId18" Target="viewProps.xml" />
<Relationship Id="rId19" Target="theme/theme1.xml" />
<Relationship Id="rId20" Target="tableStyles.xml" />
<Relationship Id="rId22" Target="slides/slide15.xml" />  ⚠️ SLIDE APRÈS
<Relationship Id="rId30" Target="slides/slide16.xml" />
```

Mais PowerPoint s'attend à cet ordre :

```xml
<!-- RÉPARÉ (PowerPoint) -->
<Relationship Id="rId16" Target="slides/slide15.xml" />  ✅ SLIDES AVANT
<Relationship Id="rId17" Target="slides/slide16.xml" />
<Relationship Id="rId18" Target="slides/slide17.xml" />
...
<Relationship Id="rId26" Target="notesMasters/notesMaster1.xml" />
<Relationship Id="rId27" Target="presProps.xml" />
<Relationship Id="rId28" Target="viewProps.xml" />
<Relationship Id="rId29" Target="theme/theme1.xml" />
<Relationship Id="rId30" Target="tableStyles.xml" />
```

### Cause Racine

**Fichier** : `Presentation/PPTX.php:466-475`

```php
protected function registerResourcesWithPresentation(array $clonedResources, GenericResource $originalResource): void
{
    // [...] Séparation des slides et autres ressources

    // CRITICAL: Add slides FIRST to get rIds 2-N
    foreach ($slides as $slide) {
        $this->presentation->addResource($slide);  // ❌ MAUVAIS ORDRE
        $this->slides[] = $slide;
    }

    // Then add system resources (masters, props, themes) to get rIds N+1...
    foreach ($otherResources as $resource) {
        $this->presentation->addResource($resource);
    }
}
```

Le **commentaire est correct** mais l'**implémentation est inversée** !

Le problème est dans **`Presentation::getNextSlideRId()`** (ligne 375) qui assigne aux nouvelles slides des rIds APRÈS tous les rIds existants (y compris les ressources système), au lieu de les forcer à être AVANT.

### Logique Actuelle vs Attendue

**Logique actuelle de `getNextSlideRId()` :**
1. Trouver le prochain rId disponible parmi TOUS les rIds (slides + système)
2. Si rId2-20 sont occupés (15 slides + 5 ressources système), donner rId21 à la nouvelle slide
3. ❌ Résultat : slides APRÈS ressources système

**Logique attendue :**
1. Compter les slides existantes : N slides → dernier rId = rId(N+1)
2. Insérer la nouvelle slide avec rId(N+2)
3. **Renommer** les ressources système pour qu'elles aient des rIds > rId(N+2)
4. ✅ Résultat : slides AVANT ressources système

### Impact

PowerPoint considère ce fichier comme **CORROMPU** car les rIds ne respectent pas l'ordre standard OPC. Lors de la réparation, PowerPoint :
- Réassigne tous les rIds dans l'ordre correct
- Cela casse toutes les références internes (NoteSlides, images, layouts)
- Le fichier devient instable

---

## 🔴 Problème 2 : Références NoteSlide ↔ Slide Incorrectes

### Symptôme

Le rapport montre des relations cassées entre NoteSlides et Slides :

```json
{
  "type": "RELATION_TARGET_CHANGED",
  "source": "ppt/notesSlides/notesSlide2.xml",
  "rid": "rId2",
  "corrupt_target": "../slides/slide15.xml",  ❌
  "repaired_target": "../slides/slide20.xml"  ✅
}
```

```json
{
  "type": "RELATION_TARGET_CHANGED",
  "source": "ppt/slides/slide20.xml",
  "rid": "rId2",
  "corrupt_target": "../media/image7.png",     ❌
  "repaired_target": "../notesSlides/notesSlide2.xml"  ✅
}
```

### Cause Racine

**Fichier** : `Presentation/PPTX.php:381-433`

La méthode `updateResourceReferences()` met à jour les références après clonage, mais elle ne gère pas correctement le cas où :

1. **Un NoteSlide est cloné** et doit pointer vers le nouveau Slide cloné
2. **Un Slide est cloné** et doit pointer vers le nouveau NoteSlide cloné

Le problème est que les **mappings de ressources ne sont pas à jour** au moment où `updateResourceReferences()` est appelé, car :

- Les NoteSlides et Slides sont clonés séparément
- Le mapping `$resourceMapping` associe ancien target → nouvelle ressource
- Mais si un Slide change de nom (slide15 → slide20), le NoteSlide ne sait pas qu'il doit pointer vers slide20

### Exemple Concret

**Avant fusion** (source) :
- slide15.xml → rId2 pointe vers notesSlide2.xml
- notesSlide2.xml → rId2 pointe vers slide15.xml

**Après fusion** (destination) :
- slide15 devient slide20 (renommé pour éviter conflit)
- notesSlide2 reste notesSlide2 (nom disponible)

**Problème** :
- slide20.xml → rId2 doit pointer vers notesSlide2.xml ✅ (OK via updateResourceReferences)
- notesSlide2.xml → rId2 doit pointer vers slide20.xml ❌ (RATE : pointe toujours vers slide15.xml car le mapping ne connaît pas le nouveau nom)

### Impact

Les NoteSlides pointent vers les mauvais slides, ce qui :
- Affiche les mauvaises notes dans PowerPoint
- Peut causer des crashs si le slide référencé n'existe pas
- Est détecté comme corruption par PowerPoint

---

## ⚠️ Problème 3 : SlideLayouts Orphelins

### Symptôme

```json
{
  "type": "FILE_REMOVED",
  "severity": "HIGH",
  "file": "ppt/slideLayouts/slideLayout13.xml"
}
{
  "type": "FILE_REMOVED",
  "severity": "HIGH",
  "file": "ppt/slideLayouts/slideLayout14.xml"
}
```

Ces layouts sont présents dans le fichier corrompu mais supprimés par PowerPoint lors de la réparation.

### Cause Racine

**Fichier** : `Presentation/PPTX.php:656-708`

Dans `getResourceTree()`, la logique s'arrête aux SlideMasters réutilisés :

```php
// For SlideMasters and NoteMasters: check if they will be reused
if ($resource instanceof SlideMaster || $resource instanceof NoteMaster) {
    $existingResource = $this->getContentType()->lookForSimilarFile($resource);
    if ($existingResource !== null) {
        // This master will be reused - don't traverse its children
        return $resourceList;  // ⚠️ STOP ICI
    }
    // ...
}
```

**Problème** : Si un SlideMaster est réutilisé :
1. Ses SlideLayouts ne sont PAS ajoutés à `$resourceList`
2. Mais le SlideMaster référence ces layouts dans ses relations
3. Ces layouts sont donc **orphelins** : présents dans l'archive mais jamais utilisés par un Slide
4. PowerPoint les détecte comme inutiles et les supprime

### Impact

Perte de layouts qui pourraient être nécessaires pour les slides, bien que dans ce cas spécifique, les slides utilisent d'autres layouts, donc pas de perte fonctionnelle.

---

## 🔧 Pistes de Correction

### 🎯 Correction 1 : Réorganiser l'ordre des rIds

**Stratégie A : Réassigner les rIds après fusion (RECOMMANDÉE)**

Après avoir ajouté toutes les ressources, réorganiser les rIds dans presentation.xml pour respecter l'ordre OPC :

**Nouveau fichier** : `Presentation/PPTX.php` - ajouter une méthode `reorderPresentationRIds()`

```php
/**
 * Reorder rIds in presentation.xml to follow PowerPoint conventions:
 * - Slides must have consecutive rIds starting from rId2
 * - System resources (masters, props, themes) must come AFTER slides
 *
 * Called before saveAs() to ensure OPC compliance.
 */
protected function reorderPresentationRIds(): void
{
    $this->presentation->mapResources();

    // Step 1: Collect all resources by type
    $slides = [];
    $systemResources = [];
    $masters = [];

    foreach ($this->presentation->getResources() as $rId => $resource) {
        if ($resource instanceof Slide) {
            $slides[$rId] = $resource;
        } elseif ($resource instanceof SlideMaster) {
            $masters[$rId] = $resource;
        } elseif ($resource instanceof NoteMaster ||
                  $resource instanceof AppProperties ||
                  $resource instanceof CoreProperties ||
                  basename($resource->getTarget()) === 'presProps.xml' ||
                  basename($resource->getTarget()) === 'viewProps.xml' ||
                  basename($resource->getTarget()) === 'tableStyles.xml' ||
                  $resource instanceof Theme) {
            $systemResources[$rId] = $resource;
        }
    }

    // Step 2: Build new rId mapping
    $rIdMapping = [];  // old rId => new rId
    $nextRId = 1;

    // Masters first (rId1)
    foreach ($masters as $oldRId => $resource) {
        $rIdMapping[$oldRId] = 'rId' . $nextRId++;
    }

    // Slides second (rId2+)
    foreach ($slides as $oldRId => $resource) {
        $rIdMapping[$oldRId] = 'rId' . $nextRId++;
    }

    // System resources last (rId N+)
    foreach ($systemResources as $oldRId => $resource) {
        $rIdMapping[$oldRId] = 'rId' . $nextRId++;
    }

    // Step 3: Update presentation.xml and .rels file
    $this->presentation->remapResourceIds($rIdMapping);
}
```

**Appeler dans `saveAs()` avant `normalizeSlideIds()` :**

```php
public function saveAs(string $target): void
{
    // Reorder rIds to follow PowerPoint conventions
    $this->reorderPresentationRIds();  // ✅ NOUVEAU

    // Normalize slide IDs to be sequential starting from 256
    $this->normalizeSlideIds();

    // ... reste du code
}
```

**Méthode à ajouter dans `Presentation.php` :**

```php
/**
 * Remap all resource IDs according to the provided mapping.
 * Updates both the .rels file and the XML content references.
 *
 * @param array<string, string> $mapping Old rId => New rId
 */
public function remapResourceIds(array $mapping): void
{
    // Step 1: Remap internal resources array
    $newResources = [];
    foreach ($this->resources as $oldRId => $resource) {
        $newRId = $mapping[$oldRId] ?? $oldRId;
        $newResources[$newRId] = $resource;
    }
    $this->resources = $newResources;

    // Step 2: Update sldIdLst in presentation.xml
    $slides = $this->content->xpath('p:sldIdLst/p:sldId');
    foreach ($slides as $sldId) {
        $oldRId = (string)$sldId->attributes($this->namespaces['r'])->id;
        if (isset($mapping[$oldRId])) {
            $sldId->attributes($this->namespaces['r'])->id = $mapping[$oldRId];
        }
    }

    // Step 3: Update sldMasterIdLst
    $masters = $this->content->xpath('p:sldMasterIdLst/p:sldMasterId');
    foreach ($masters as $masterId) {
        $oldRId = (string)$masterId->attributes($this->namespaces['r'])->id;
        if (isset($mapping[$oldRId])) {
            $masterId->attributes($this->namespaces['r'])->id = $mapping[$oldRId];
        }
    }

    // Step 4: Update notesMasterIdLst
    $noteMasters = $this->content->xpath('p:notesMasterIdLst/p:notesMasterId');
    foreach ($noteMasters as $noteId) {
        $oldRId = (string)$noteId->attributes($this->namespaces['r'])->id;
        if (isset($mapping[$oldRId])) {
            $noteId->attributes($this->namespaces['r'])->id = $mapping[$oldRId];
        }
    }

    // Step 5: Force regeneration of .rels file with new IDs
    $this->isDraft = true;
    $this->save();
}
```

---

### 🎯 Correction 2 : Fixer les Références NoteSlide ↔ Slide

**Problème** : Les NoteSlides pointent vers l'ancien nom du Slide avant renommage.

**Solution** : Améliorer `updateResourceReferences()` pour gérer les références bidirectionnelles.

**Fichier** : `Presentation/PPTX.php:381-433`

```php
protected function updateResourceReferences(array $clonedResources, array $resourceMapping): void
{
    // Track which resources need their .rels regenerated
    $resourcesToSave = [];

    // Update references for ALL resources in the processed tree
    foreach ($clonedResources as $resource) {
        if (!($resource instanceof XmlResource)) {
            continue;
        }

        $needsUpdate = false;
        $currentResources = $resource->getResources();

        foreach ($currentResources as $rId => $subResource) {
            $targetKey = $subResource->getTarget();

            // NOUVEAU: Special handling for NoteSlide ↔ Slide references
            if ($resource instanceof NoteSlide && $subResource instanceof Slide) {
                // NoteSlide must point to the cloned Slide, not the original
                $clonedSlide = $this->findClonedSlideForNoteSlide($resource, $clonedResources);
                if ($clonedSlide !== null && $clonedSlide !== $subResource) {
                    $resource->setResource($rId, $clonedSlide);
                    $needsUpdate = true;
                    continue;
                }
            }

            if ($resource instanceof Slide && $subResource instanceof NoteSlide) {
                // Slide must point to the cloned NoteSlide, not the original
                $clonedNote = $this->findClonedNoteSlideForSlide($resource, $clonedResources);
                if ($clonedNote !== null && $clonedNote !== $subResource) {
                    $resource->setResource($rId, $clonedNote);
                    $needsUpdate = true;
                    continue;
                }
            }

            // If we have a mapping for this target, update the reference
            if (array_key_exists($targetKey, $resourceMapping)) {
                $mappedResource = $resourceMapping[$targetKey];

                // Only update if the reference changed
                if ($subResource !== $mappedResource ||
                    ($subResource instanceof GenericResource &&
                     $mappedResource instanceof GenericResource &&
                     $subResource->getDocument() !== $mappedResource->getDocument())) {

                    $resource->setResource($rId, $mappedResource);
                    $needsUpdate = true;
                }
            }
        }

        // If references were updated, force regeneration of .rels file
        if ($needsUpdate) {
            $resourcesToSave[] = $resource;
        }
    }

    // Force save all resources that had reference updates
    foreach ($resourcesToSave as $resource) {
        if (method_exists($resource, 'performSave')) {
            $reflection = new \ReflectionMethod($resource, 'performSave');
            $reflection->setAccessible(true);
            $reflection->invoke($resource);
        }
    }
}

/**
 * Find the cloned Slide that should be referenced by a NoteSlide.
 * Uses source metadata to match the correct Slide.
 *
 * @param NoteSlide $noteSlide The NoteSlide looking for its Slide
 * @param array<string, ResourceInterface> $clonedResources All cloned resources
 * @return Slide|null The matching Slide or null
 */
protected function findClonedSlideForNoteSlide(NoteSlide $noteSlide, array $clonedResources): ?Slide
{
    // Get the original slide target from the NoteSlide's resources
    foreach ($noteSlide->getResources() as $resource) {
        if ($resource instanceof Slide) {
            $originalSlideTarget = $resource->getTarget();

            // Find the cloned slide with matching original target
            foreach ($clonedResources as $clonedResource) {
                if ($clonedResource instanceof Slide) {
                    // Match by source slide ID or target path
                    if ($this->isSameOriginalSlide($resource, $clonedResource)) {
                        return $clonedResource;
                    }
                }
            }
            break;
        }
    }

    return null;
}

/**
 * Find the cloned NoteSlide that should be referenced by a Slide.
 *
 * @param Slide $slide The Slide looking for its NoteSlide
 * @param array<string, ResourceInterface> $clonedResources All cloned resources
 * @return NoteSlide|null The matching NoteSlide or null
 */
protected function findClonedNoteSlideForSlide(Slide $slide, array $clonedResources): ?NoteSlide
{
    // Get the original note slide target from the Slide's resources
    foreach ($slide->getResources() as $resource) {
        if ($resource instanceof NoteSlide) {
            // Find the cloned NoteSlide
            foreach ($clonedResources as $clonedResource) {
                if ($clonedResource instanceof NoteSlide &&
                    basename($resource->getTarget()) === basename($clonedResource->getTarget())) {
                    return $clonedResource;
                }
            }
            break;
        }
    }

    return null;
}

/**
 * Check if two Slides represent the same original slide.
 *
 * @param Slide $slide1
 * @param Slide $slide2
 * @return bool
 */
protected function isSameOriginalSlide(Slide $slide1, Slide $slide2): bool
{
    // Compare by source slide ID if available
    $id1 = $slide1->getSourceSlideId();
    $id2 = $slide2->getSourceSlideId();

    if ($id1 !== null && $id2 !== null && $id1 === $id2) {
        return true;
    }

    // Fallback: compare by target path
    return basename($slide1->getTarget()) === basename($slide2->getTarget());
}
```

---

### 🎯 Correction 3 : Éviter les SlideLayouts Orphelins

**Option A : Ne pas ajouter les layouts non utilisés**

Dans `getResourceTree()`, vérifier si un SlideLayout est réellement utilisé par au moins un Slide avant de l'ajouter.

**Option B : Accepter les orphelins** (Solution actuelle recommandée)

Les layouts orphelins ne causent pas de corruption, juste un peu de bloat. PowerPoint les supprime automatiquement lors de la réparation. Pas critique.

---

## 🎯 Correction 4 : Améliorer la Robustesse Générale

### 4.1 Validation OPC Avant Sauvegarde

Ajouter une validation OPC stricte avant `saveAs()` :

```php
public function saveAs(string $target): void
{
    // Validate OPC structure before saving
    if ($this->config->isEnabled('validate_opc')) {
        $validator = new OPCValidator($this);
        $report = $validator->validate();

        if (!$report['valid']) {
            throw new OPCValidationException(
                'OPC validation failed: ' . json_encode($report['errors'])
            );
        }
    }

    // ... reste du code
}
```

### 4.2 Tests de Non-Régression

Créer un test qui vérifie que le fichier généré peut être ouvert par PowerPoint sans réparation :

```php
/**
 * @test
 */
public function it_generates_opc_compliant_pptx(): void
{
    $pptx = new PPTX(__DIR__ . '/mock/DEBUT.pptx');
    $pptxToAppend = new PPTX(__DIR__ . '/mock/FIN.pptx');

    $pptx->addSlides($pptxToAppend->getSlides());
    $pptx->saveAs(self::TMP_PATH . '/opc_valid_merge.pptx');

    // Validate that PowerPoint can open without repair
    $validator = new PowerPointValidator();
    $this->assertTrue(
        $validator->canOpenWithoutRepair(self::TMP_PATH . '/opc_valid_merge.pptx'),
        'PowerPoint should be able to open the file without repair'
    );
}
```

---

## 📊 Priorités de Correction

| Problème | Sévérité | Priorité | Effort |
|----------|----------|----------|--------|
| 1. Ordre des rIds | 🔴 CRITIQUE | P0 | Moyen |
| 2. Références NoteSlide | 🔴 CRITIQUE | P0 | Élevé |
| 3. SlideLayouts orphelins | 🟡 MINEUR | P2 | Faible |
| 4. Validation OPC | 🟢 AMÉLIORATION | P1 | Moyen |

**Recommandation** : Commencer par la **Correction 1** (réorganisation des rIds), car elle est la plus simple à implémenter et résout le problème principal de corruption. Ensuite, traiter la **Correction 2** pour les NoteSlides.

---

## 🧪 Plan de Test

1. **Test unitaire** : Vérifier que `reorderPresentationRIds()` produit le bon ordre
2. **Test d'intégration** : Merger 3 PPTX et vérifier la structure interne
3. **Test de validation** : Ouvrir le fichier dans PowerPoint et vérifier qu'aucune réparation n'est demandée
4. **Test de régression** : Comparer avec `merge_repaired.pptx` pour vérifier que la structure est identique

---

## 📝 Conclusion

Les problèmes de corruption sont causés par :
1. Une **mauvaise assignation des rIds** qui ne respecte pas les conventions OPC
2. Des **références non mises à jour** entre NoteSlides et Slides après fusion

Les corrections proposées sont **non-invasives** et peuvent être implémentées sans refonte majeure du code existant. La priorité absolue est la **réorganisation des rIds** qui résoudra la majorité des problèmes de corruption.
