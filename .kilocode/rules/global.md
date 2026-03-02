# Configuration Globale Kilocode - Guide d'Installation

Ce document explique comment appliquer les guidelines Laravel 11 et Vue.js 3 + TypeScript à **tous vos projets** Kilocode.

---

## 📁 Fichiers de Configuration Créés

### 1. Configuration Globale
**Fichier :** `C:\Users\zirim\AppData\Roaming\Code\User\globalStorage\kilocode.kilo-code\settings\global_settings.yaml`

Ce fichier contient toutes les guidelines et s'applique automatiquement à tous les projets.

### 2. Configuration Projet Local (optionnel)
**Fichier :** `.kilo/config.yaml` (dans chaque projet)

Configuration spécifique au projet, surcharge les paramètres globaux si nécessaire.

### 3. Documentation Complète
**Fichier :** `KILOCODE_CONFIGURATION.md`

Documentation markdown détaillée de toutes les guidelines.

---

## ✅ Vérification de l'Installation

Pour vérifier que la configuration globale est bien appliquée :

1. **Ouvrir un nouveau projet** dans VSCode
2. **Démarrer Kilocode** en mode Code
3. **Créer un nouveau controller Laravel** et vérifier que Kilocode :
   - Utilise l'anglais pour le code
   - Crée un controller léger avec injection de dépendances
   - N'utilise pas de Facades
   - Utilise des API Resources pour les réponses

---

## 🎯 Que Contient la Configuration Globale ?

### Backend (Laravel 11)

#### ✅ Règles Appliquées Automatiquement

- **Controllers légers** : orchestration uniquement, pas de logique métier
- **Pas de Facades** : injection de dépendances (Mailer, Queue, etc.)
- **Services pour logique métier** : indépendants du framework
- **Repositories pour persistance** : centralisent les requêtes
- **Exceptions métier explicites** : pas de try/catch dans controllers
- **API Resources** : toujours pour les réponses JSON
- **Tests PestPHP** : déterministes avec mocking

#### 📋 Conventions de Nommage

| Élément | Convention | Exemple |
|---------|-----------|---------|
| Classes | PascalCase | `ContactController` |
| Méthodes | camelCase | `findById` |
| JSON Keys | snake_case | `first_name` |
| Routes | kebab-case | `/api/contacts` |
| Constantes | SCREAMING_SNAKE_CASE | `STATUS_ACTIVE` |

#### 🚫 Interdictions

- ❌ Observers
- ❌ Facades (`Mail::`, `Cache::`, etc.)
- ❌ Helpers magiques (`auth()`, `session()`, `cache()`)
- ❌ Logique métier dans controllers
- ❌ `try/catch` dans controllers/services
- ❌ Réponses JSON directes (sans Resources)

### Frontend (Vue.js 3 + TypeScript)

#### ✅ Règles Appliquées Automatiquement

- **TypeScript strict** : pas de `any`
- **Props immutables** : jamais modifier ou déstructurer
- **Typage obligatoire** : props et emits
- **Communication** : Props down, Events up
- **Composants** : PascalCase, préfixe `The` pour uniques
- **Réactivité** : `const` pour références réactives

#### 🚫 Interdictions

- ❌ Type `any`
- ❌ Mutation de props
- ❌ Déstructuration de props
- ❌ `let` pour références réactives
- ❌ Pinia comme raccourci pour éviter props
- ❌ Logique complexe dans templates

---

## 🔧 Configuration Avancée

### Surcharger les Paramètres Globaux pour un Projet Spécifique

Si vous voulez des règles différentes pour un projet particulier :

1. Créer `.kilo/config.yaml` à la racine du projet
2. Ajouter vos custom instructions spécifiques
3. Les paramètres locaux **surchargent** les globaux

**Exemple :**

```yaml
# .kilo/config.yaml (projet spécifique)
customInstructions: |
  En plus des règles globales, pour ce projet :
  - Utiliser le pattern CQRS
  - Séparer les commandes et les queries
  - Ajouter des event sourcing
```

### Désactiver les Guidelines Globales pour un Projet

Si vous voulez désactiver complètement les guidelines globales :

```yaml
# .kilo/config.yaml
ignoreGlobalSettings: true

customInstructions: |
  Instructions personnalisées pour ce projet uniquement
```

---

## 📚 Commandes Artisan à Utiliser

Kilocode utilisera automatiquement ces commandes :

```bash
# Model complet
php artisan make:model ModelName -mfsc

# Controller API
php artisan make:controller ControllerName --api

# Form Request
php artisan make:request RequestName

# API Resource
php artisan make:resource ResourceName

# Exception personnalisée
php artisan make:exception ExceptionName

# Test Pest
php artisan make:test TestName --pest
```

---

## ✨ Exemples de Code Généré

### Backend - Controller

```php
// Kilocode générera automatiquement ce style de code
class ContactController extends Controller
{
    public function store(
        StoreContactRequest $request,
        ContactRepository $repository
    ): JsonResponse {
        $contact = $repository->create($request->safe());
        return response()->json(new ContactResource($contact), 201);
    }
}
```

### Backend - Service avec Injection de Dépendances

```php
class LeadConversionService
{
    public function __construct(
        private readonly ContactRepository $contactRepository,
        private readonly Mailer $mailer, // ✅ Pas de Facade
        private readonly Queue $queue
    ) {}
    
    public function convertToContact(Lead $lead, User $actor): Contact
    {
        $this->validateForConversion($lead);
        
        return DB::transaction(function () use ($lead, $actor) {
            $contact = $this->createContact($lead);
            $this->notify($contact, $actor);
            return $contact;
        });
    }
}
```

### Frontend - Composant Vue

```vue
<script setup lang="ts">
interface Props {
  user: User
  isActive: boolean
}

const props = defineProps<Props>()
const emit = defineEmits<{
  'update': [user: User]
  'close': []
}>()

const fullName = computed(() => 
  `${props.user.firstName} ${props.user.lastName}`
)
</script>

<template>
  <div v-if="isActive" class="user-card">
    <h2>{{ fullName }}</h2>
    <button @click="emit('close')">Close</button>
  </div>
</template>

<style scoped>
.user-card {
  padding: 1rem;
  border: 1px solid var(--border-color);
}
</style>
```

---

## 🔄 Mise à Jour des Guidelines

Pour mettre à jour les guidelines globales :

1. Modifier le fichier `global_settings.yaml`
2. Redémarrer VSCode
3. Les nouvelles règles s'appliquent automatiquement

---

## 🆘 Dépannage

### Les Guidelines ne s'appliquent pas

1. **Vérifier l'emplacement du fichier** :
   ```
   C:\Users\zirim\AppData\Roaming\Code\User\globalStorage\kilocode.kilo-code\settings\global_settings.yaml
   ```

2. **Redémarrer VSCode**

3. **Vérifier les logs Kilocode** dans la console de sortie

### Les Guidelines locales ne surchargent pas les globales

- Vérifier que `.kilo/config.yaml` existe dans le projet
- S'assurer que le fichier est correctement formaté (YAML valide)

### Kilocode ne respecte pas une règle spécifique

- Vérifier que la règle est bien dans `globalCustomInstructions`
- Essayer d'être plus explicite dans la formulation
- Ajouter des exemples de code dans les instructions

---

## 📖 Références

- **Documentation complète** : `KILOCODE_CONFIGURATION.md`
- **Guidelines Backend** : `back/guidelines-en-français.md`
- **Guidelines Frontend** : `front/exemples-guidelines-front.md`
- **Exemples Backend** : `back/exemples-guidelines-back.md`

---

## 🎉 Résultat

Avec cette configuration :

✅ Tous vos projets Laravel 11 et Vue.js 3 suivent automatiquement les mêmes guidelines  
✅ Code cohérent et maintenable sur tous les projets  
✅ Pas besoin de répéter les instructions à chaque nouveau projet  
✅ Possibilité de surcharger pour des besoins spécifiques  
✅ Documentation centralisée et à jour

---

**Version :** 1.0  
**Dernière mise à jour :** 2025-12-20