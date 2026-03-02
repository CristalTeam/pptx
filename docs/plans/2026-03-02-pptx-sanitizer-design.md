# PPTX Sanitizer & Comparator — Design Document

**Date**: 2026-03-02
**Auteur**: Claude + Jeremy
**Status**: Approuvé

## Problème

La librairie Cristal PPTX produit des fichiers corrompus dans certains cas de fusion. Les corruptions varient : parfois PowerPoint peut réparer, parfois le fichier est illisible. Chaque cas remonté par les utilisateurs révèle un nouveau type d'incohérence non couvert.

Les validations existantes (OPCValidator, PPTXValidationTest) ne sont jamais exécutées automatiquement pendant `saveAs()`. Le debugging est manuel et ad-hoc.

## Objectifs

1. **Prévenir** la corruption via un sanitizer intégré dans `saveAs()` (auto-repair + warning)
2. **Diagnostiquer** les nouveaux cas via un comparateur corrompu/réparé (outil dev)
3. **Empêcher les régressions** via des tests de non-régression pour chaque cas connu

## Solution : PPTXSanitizer + PPTXComparator

### Architecture

```
saveAs():
  1. reorderPresentationRIds()
  2. normalizeSlideIds()
  3. cleanOrphanedResources()
  4. updateAppProperties()
  5. ► PPTXSanitizer::sanitize() ◄  ← dernier filet de sécurité
  6. contentType->save()
  7. close() + copy
```

Le sanitizer est le dernier check avant la sauvegarde physique. Il peut être désactivé via la config.

### Composant 1 : PPTXSanitizer

**Emplacement** : `src/Presentation/Sanitizer/`

```
Sanitizer/
├── PPTXSanitizer.php          # Orchestrateur
├── SanitizeRule.php            # Interface pour chaque règle
├── SanitizeReport.php          # Rapport structuré (issues détectées/réparées)
├── SanitizeIssue.php           # Un problème individuel
└── Rules/
    ├── UniqueRIdRule.php
    ├── OrphanedSlideMasterRule.php
    ├── SldLayoutIdLstConsistencyRule.php
    ├── BidirectionalMasterLayoutRule.php
    ├── NoteSlideReferenceRule.php
    ├── SectionCompletenessRule.php
    ├── ImageExtensionRule.php
    ├── RIdOrderingRule.php
    ├── ContentTypeCompletenessRule.php
    └── AllRIdsResolveRule.php
```

**Interface SanitizeRule** :
```php
interface SanitizeRule
{
    public function name(): string;
    public function severity(): Severity; // CRITICAL, HIGH, MEDIUM, WARNING
    public function detect(PPTX $pptx): array; // Returns SanitizeIssue[]
    public function repair(PPTX $pptx, array $issues): void;
}
```

**PPTXSanitizer** :
```php
class PPTXSanitizer
{
    public function __construct(array $rules) {}

    public function sanitize(PPTX $pptx): SanitizeReport
    {
        $report = new SanitizeReport();
        foreach ($this->rules as $rule) {
            $issues = $rule->detect($pptx);
            if (!empty($issues)) {
                $rule->repair($pptx, $issues);
                $report->addRepairedIssues($rule->name(), $issues);
            }
        }
        return $report;
    }
}
```

### Composant 2 : PPTXComparator (outil dev)

**Emplacement** : `src/Presentation/Comparator/PPTXComparator.php`

Compare deux fichiers PPTX (corrompu vs réparé) et produit un rapport structuré :
- Fichiers ajoutés/supprimés
- Différences dans les .rels (rIds modifiés)
- Différences dans presentation.xml
- Suggestion de règles de sanitization manquantes

**CLI** : `tools/pptx-doctor.php`
```bash
php tools/pptx-doctor.php diagnose output.pptx        # Diagnostic sans référence
php tools/pptx-doctor.php compare failed.pptx repaired.pptx  # Comparaison
```

### Les 10 règles initiales

| # | Règle | Détecte | Répare | Sévérité |
|---|-------|---------|--------|----------|
| 1 | UniqueRIdRule | rIds dupliqués dans presentation.xml (sldIdLst, custDataLst, etc.) | Réassigne des rIds uniques | CRITICAL |
| 2 | OrphanedSlideMasterRule | SlideMasters sans aucun slide qui les utilise | Supprime master + theme dédié | CRITICAL |
| 3 | SldLayoutIdLstConsistencyRule | sldLayoutIdLst ne correspond pas aux .rels | Reconstruit depuis les .rels | CRITICAL |
| 4 | BidirectionalMasterLayoutRule | Layout→Master et Master→Layout incohérents | Corrige la référence layout | HIGH |
| 5 | NoteSlideReferenceRule | NoteSlide pointe vers slide inexistant | Corrige via mapping source→dest | HIGH |
| 6 | SectionCompletenessRule | Slides orphelins quand sectionLst existe | Crée "Section par défaut" | MEDIUM |
| 7 | ImageExtensionRule | Extension fichier ≠ type MIME réel | Renomme + met à jour Content_Types | MEDIUM |
| 8 | RIdOrderingRule | rIds ne suivent pas l'ordre OPC | Log warning (reorder devrait gérer) | WARNING |
| 9 | ContentTypeCompletenessRule | Fichiers sans entrée Content_Types | Ajoute l'entrée manquante | MEDIUM |
| 10 | AllRIdsResolveRule | rId pointe vers target inexistant | Supprime la référence orpheline | HIGH |

### Configuration

```php
$pptx = new PPTX('file.pptx', [
    'sanitize' => true,         // défaut: true. false pour désactiver
    'sanitize_rules' => null,   // null = toutes, ou ['UniqueRIdRule', 'OrphanedSlideMasterRule']
]);
```

### API

```php
$pptx->saveAs('output.pptx');

$report = $pptx->getSanitizeReport();
if ($report && $report->hasIssues()) {
    foreach ($report->getIssues() as $issue) {
        error_log("[PPTX Sanitizer] {$issue->severity}: {$issue->message}");
    }
}
```

## Stratégie de tests

### Tests unitaires (par règle)
Chaque règle a son propre test vérifiant detect() et repair() sur des cas construits.

### Test d'intégration principal
```php
public function it_sanitizes_propale_failed(): void
{
    // Charger PropaleFailed.pptx, appliquer sanitizer
    // Vérifier que les 5 problèmes connus sont corrigés
    // Comparer structurellement avec PropaleRepaired.pptx
}
```

### Tests de non-régression
Chaque nouveau fichier corrompu remonté par un utilisateur devient un test :
1. Ajouter le fichier dans `tests/mock/`
2. Ajouter un test qui vérifie la correction
3. Le test empêche la régression pour toujours

## Analyse de PropaleFailed.pptx (cas de référence)

5 problèmes identifiés par comparaison avec PropaleRepaired.pptx :

1. **rId14 dupliqué** — utilisé pour slide11 ET custDataLst/tags. `reorderPresentationRIds()` ne met pas à jour custDataLst.
2. **3 SlideMasters quasi-identiques** — déduplication a échoué (différence mineure de font-size)
3. **Mauvais ordre rIds** dans presentation.xml.rels
4. **Theme en rId1** dans les SlideMasters clonés (layouts devraient être premiers)
5. **Extensions images incorrectes** (.png/.jpeg inversés)

## Workflow pour un nouveau cas de corruption

```
Utilisateur remonte fichier corrompu
    ↓
1. php tools/pptx-doctor.php diagnose fichier.pptx
    → Identifie les problèmes
    ↓
2. Utilisateur répare dans PowerPoint, fournit version réparée
    ↓
3. php tools/pptx-doctor.php compare failed.pptx repaired.pptx
    → Rapport des différences + suggestion de règle manquante
    ↓
4. Implémenter la nouvelle SanitizeRule
    ↓
5. Ajouter le fichier en test de non-régression
    ↓
6. Le problème ne se reproduira plus jamais
```
