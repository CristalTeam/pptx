# Configuration Globale Kilocode - Guidelines de Développement

> **Version:** 1.0  
> **Dernière mise à jour:** 2025-12-20  
> **Framework Backend:** Laravel 11  
> **Framework Frontend:** Vue.js 3 + TypeScript

---

## 📋 Table des Matières

1. [Instructions Globales](#instructions-globales)
2. [Backend (Laravel)](#backend-laravel)
3. [Frontend (Vue.js + TypeScript)](#frontend-vuejs--typescript)
4. [Commandes Artisan](#commandes-artisan-préférées)
5. [Qualité du Code](#analyse-statique--code-style)
6. [Design Patterns](#design-patterns-recommandés)
7. [Exemples de Code](#exemples-de-code)

---

## 🎯 Instructions Globales

### Principes Fondamentaux

1. **Qualité du code** : Le code doit passer PHPStan niveau 6 et Laravel Pint (PSR-12)
2. **Langue** : Tout le code, commentaires et noms de variables doivent être en **ANGLAIS**
3. **Gestion des erreurs** : Lever des exceptions métier explicites, jamais de try/catch dans les controllers
4. **Tests** : Utiliser PestPHP, tests déterministes, mocker les services externes
5. **Documentation** : Utiliser Scribe avec des attributs PHP, pas de docblocks

### Conventions de Nommage

| Élément | Convention | Exemples |
|---------|-----------|----------|
| **Classes** | PascalCase | `ContactController`, `LeadRepository` |
| **Méthodes & Variables** | camelCase | `findById`, `assignedUserId` |
| **Clés JSON** | snake_case | `first_name`, `created_at` |
| **Routes** | kebab-case | `/api/contacts`, `/leads/convert-to-contact` |
| **Constantes & ENUMS** | SCREAMING_SNAKE_CASE | `STATUS_ACTIVE`, `ROLE_ADMIN` |
| **Base de données** | snake_case | `contacts`, `assigned_user_id` |

⚠️ **Éviter les "mots magiques"** : Service, Client, Manager, Helper → Préférer des noms contextuels

✅ **Bon** : `LeadConversionService`, `ContactRepository`, `EmailNotificationSender`  
❌ **Mauvais** : `Service`, `Manager`, `Helper`, `Client`

---

## 🔧 Backend (Laravel)

### 1. Architecture & Structure

#### Controllers

**Règles :**
- Garder les controllers **légers** (orchestration uniquement)
- Une seule méthode publique par route
- **Pas de méthodes privées ou protected** dans les controllers
- **Pas de dépendances dans le constructeur** (booté trop tôt)
- Utiliser l'injection de dépendances dans les méthodes
- Noms standards CRUD : `index`, `show`, `store`, `update`, `destroy`
- Single Action Controller pour logique métier spécifique
- **Jamais de réponse JSON directe** → toujours utiliser un API Resource

**✅ Exemple Correct :**

```php
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

**❌ Exemple Incorrect :**

```php
// ❌ Logique métier dans le controller
public function store(Request $request): JsonResponse
{
    $contact = Contact::create($request->all());
    if ($contact->company) {
        $contact->company->update(['last_contact' => now()]);
    }
    Mail::to($contact->email)->send(new Welcome($contact));
    return response()->json($contact);
}
```

#### Models

**Règles :**
- Garder les modèles **fins** (représentation des données uniquement)
- Utiliser `$guarded` au lieu de `$fillable`
- Ajouter des **casts explicites** pour tous les champs non-string (y compris ENUMS)
- **Pas de `$with`** ni **`$appends`**
- Attributes documentés avec `@property` pour les champs calculés
- Éviter les scopes, préférer les **repositories**

**✅ Exemple Correct :**

```php
class Contact extends Model
{
    protected $guarded = ['id'];
    
    protected $casts = [
        'status' => ContactStatus::class,
        'is_active' => 'boolean',
        'last_contact_at' => 'datetime',
        'metadata' => 'array',
    ];
}
```

#### Requests (Form Validation)

**Règles :**
- Responsables **uniquement de la validation**
- Toujours utiliser une Request quand des paramètres sont envoyés
- **Pas de validation conditionnelle** selon les verbes HTTP
- Utiliser **`safe()`** au lieu de `validated()`
- **Jamais implémenter `authorize()`** → utiliser les policies

**✅ Exemple Correct :**

```php
class StoreContactRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:contacts,email'],
            'company_id' => ['nullable', 'exists:companies,id'],
        ];
    }
}

// Dans le controller
$data = $request->safe(); // ✅ safe() > validated()
```

#### Resources (API Responses)

**Règles :**
- Doivent être **légères**
- **Pas de chargement de relations** dans la resource
- Relations incluses via `whenLoaded`
- Utiliser **snake_case** pour les clés JSON
- Un modèle = une resource
- Les resources appellent d'autres resources si nécessaire

**✅ Exemple Correct :**

```php
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'company' => new CompanyResource($this->whenLoaded('company')),
            'created_at' => $this->created_at,
        ];
    }
}
```

### 2. Services & Repositories

#### Repositories (Persistance)

**Rôle :**
- Gèrent la **persistance** et transformation des données
- Centralisent les requêtes Eloquent
- Méthodes de filtrage, recherche, pagination

**✅ Exemple Correct :**

```php
class ContactRepository
{
    public function __construct(private readonly Contact $model) {}
    
    public function findById(int $id): ?Contact
    {
        return $this->model->find($id);
    }
    
    public function findWithFilters(FilterData $filters): LengthAwarePaginator
    {
        $query = $this->model->newQuery();
        $this->applyFilters($query, $filters);
        return $query->paginate($filters->perPage ?? 15);
    }
    
    public function create(ContactData $data): Contact
    {
        return $this->model->create($data->toArray());
    }
}
```

#### Services (Logique Métier)

**Règles :**
- Contiennent la **logique métier**, indépendants du framework
- **Pas de Facades Laravel** (auth, session, cache, etc.)
- Utiliser **l'injection de dépendances**
- Gèrent les transactions
- Coordonnent plusieurs repositories
- Appliquent les design patterns (Strategy, Factory, Repository)

**✅ Exemple Correct :**

```php
class LeadConversionService
{
    public function __construct(
        private readonly ContactRepository $contactRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly Mailer $mailer, // ✅ Contract, pas Facade
        private readonly Queue $queue
    ) {}
    
    public function convertToContact(Lead $lead, User $actor): Contact
    {
        $this->validateLeadForConversion($lead);
        
        return DB::transaction(function () use ($lead, $actor) {
            $company = $this->findOrCreateCompany($lead);
            $contact = $this->createContactFromLead($lead, $company);
            $this->transferActivities($lead, $contact);
            $lead->update(['status' => 'converted']);
            $this->sendNotification($contact, $actor);
            return $contact;
        });
    }
    
    private function validateLeadForConversion(Lead $lead): void
    {
        if (!$lead->status->canConvert()) {
            throw new LeadConversionException('Lead must be qualified');
        }
    }
}
```

**❌ Exemple Incorrect (Utilisation de Facades) :**

```php
class BadService
{
    public function doSomething(): void
    {
        Mail::to('user@example.com')->send(...); // ❌ Facade
        Cache::put('key', 'value'); // ❌ Facade
        auth()->user()->update(...); // ❌ Helper magique
    }
}
```

⚠️ **Important :**
- **Actions** : Proscrire et préférer l'utilisation de services
- **Pas de helper magique** : `auth()`, `session()`, `cache()` → injection explicite

### 3. Routage

**Règles :**
- Utiliser `Route::apiResource()` autant que possible
- Grouper par middleware et préfixe
- **Toutes les routes doivent être nommées**
- **Pas de routes basées sur des closures**
- Éviter les conflits d'URL (typer les paramètres)

**✅ Exemple Correct :**

```php
Route::prefix('api/v1')->middleware('auth:sanctum')->group(function () {
    Route::apiResource('contacts', ContactController::class);
    Route::post('leads/{lead}/convert-to-contact', [LeadController::class, 'convertToContact'])
        ->name('leads.convert-to-contact');
});
```

### 4. Middleware

**Règles :**
- **Uniquement pour les vérifications pré-controller** (authentification, état)
- Utiliser les **policies** plutôt que des middlewares
- **Pas de logique métier**

### 5. Gestion des Erreurs

**Règles :**
- **Lever des exceptions métier explicites**
- **Jamais de try/catch dans les controllers ou services**
- Centraliser dans le Handler global
- Mapping exception → HTTP status + message normalisé
- Ne pas exposer de détails internes

**✅ Exemple Correct :**

```php
// Exception métier
final class InvoiceAlreadyPaidException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invoice is already paid.');
    }
}

// Service
public function pay(Invoice $invoice): void
{
    if ($invoice->is_paid) {
        throw new InvoiceAlreadyPaidException();
    }
    $invoice->markAsPaid();
}

// Controller (pas de try/catch)
public function pay(Invoice $invoice, InvoiceService $service): InvoiceResource
{
    $service->pay($invoice);
    return new InvoiceResource($invoice->refresh());
}
```

**❌ Exemple Incorrect :**

```php
public function pay(Invoice $invoice): bool|JsonResponse
{
    if ($invoice->is_paid) {
        return response()->json(['error' => 'Already paid'], 409);
    }
    return true;
}
```

### 6. Tests (PestPHP)

**Règles :**
- Tests **déterministes** (pas de hasard)
- Utiliser des **factories**, pas de seeders
- Préférer `uniqid()` plutôt que `unique()` de faker
- **Mocker tous les services externes**
- Tests unitaires pour les services critiques
- Appliquer le TDD quand possible

**✅ Exemple Correct :**

```php
it('converts lead to contact', function () {
    $lead = Lead::factory()->qualified()->create();
    $service = app(LeadConversionService::class);
    
    $contact = $service->convertToContact($lead);
    
    expect($contact)->toBeInstanceOf(Contact::class)
        ->and($lead->fresh()->status)->toBe('converted');
});
```

### 7. Base de Données

**Règles :**
- Noms en **anglais** et **snake_case**
- Respecter les pluriels Laravel
- Définir la **taille des champs**
- Toujours une **clé primaire auto-incrémentée**
- Définir les **indexes** et **clés étrangères**
- `onDeleteCascade` avec parcimonie
- **Pas de données** dans les migrations (utiliser des commandes/seeders)
- **Pas de logs** ou d'exception dans les migrations

### 8. Documentation (Scribe)

**Règles :**
- Utiliser les **attributs PHP**, pas les docblocks
- Toujours régénérer après modifications
- Préférer `#[ResponseFromApiResource(TheResource::class, TheModel::class)]`
- Commentaire minimum sur chaque méthode de controller
- Utiliser `queryParameter` dans les requests

**✅ Exemple Correct :**

```php
/**
 * Create a new contact
 *
 * Store a new contact in the database with the provided information.
 */
#[ResponseFromApiResource(ContactResource::class, Contact::class, 201)]
public function store(StoreContactRequest $request): JsonResponse
{
    // ...
}
```

### 9. Performance

**Règles :**
- Afficher des loaders pour les tâches > 0.5s
- Utiliser des **queues** pour les tâches > 2s
- Utiliser du **caching** pour les opérations coûteuses
- Préférer les **actions en masse** (bulk)
- Éviter les requêtes N+1 (eager loading)

### 🚫 INTERDICTIONS Backend

- ❌ **Observers** : interdits
- ❌ **Facades** : interdites (utiliser l'injection de dépendances)
- ❌ **Events/Listeners** : à utiliser avec parcimonie
- ❌ **Helpers magiques** : `auth()`, `session()`, `cache()`
- ❌ **Logique métier dans les controllers**
- ❌ **try/catch dans les controllers ou services**
- ❌ **Réponses JSON directes** (toujours via Resources)

---

## 🎨 Frontend (Vue.js + TypeScript)

### 1. Principes Fondamentaux

- **Séparation des responsabilités** : Frontend = logique d'affichage + interaction
- **Composition** : Assemblage de composants simples et réutilisables
- **Performance & Ergonomie** : Chargements asynchrones non bloquants
- **Typage strict** : Pas de `any`, typage obligatoire

### 2. Vue.js - Structure & Conventions

#### Nommage des Composants

- **PascalCase** pour tous les composants (`MonComposant.vue`)
- Préfixe `The` pour composants à instance unique (`TheHeader.vue`)

#### Props & Events

- **Props** : déclarées en camelCase dans le script, utilisées en kebab-case dans le template
- **Events** : toujours en kebab-case (`@update-value`)
- Préfixer par la ressource si plusieurs types : `product:update`, `user:save`

**✅ Exemple Correct :**

```vue
<script setup lang="ts">
interface Props {
  isVisible: boolean
  userName: string
}

const props = defineProps<Props>()
const emit = defineEmits<{
  'close': []
  'update-value': [value: string]
}>()
</script>

<template>
  <div v-if="isVisible">
    <p>{{ userName }}</p>
    <button @click="emit('close')">Close</button>
  </div>
</template>
```

#### Règles Critiques

⚠️ **Ne JAMAIS modifier une prop**

```vue
<!-- ❌ MAUVAIS -->
<script setup>
const props = defineProps({ isVisible: Boolean })
props.isVisible = false // ❌ Mutation directe
</script>

<!-- ✅ BON -->
<script setup>
defineProps({ isVisible: Boolean })
const emit = defineEmits(['close'])

function close() {
  emit('close')
}
</script>
```

⚠️ **Ne JAMAIS déstructurer les props** (brise la réactivité)

```typescript
// ❌ MAUVAIS
const { user } = props

// ✅ BON
props.user
```

#### Réactivité

- Utiliser **`const`** pour toutes les références réactives (`ref`, `reactive`)
- **Ne jamais utiliser `let`** pour les références réactives
- Toujours utiliser une **`:key` unique** dans les `v-for`
- Éviter la logique complexe dans les templates → **Computed Properties**

#### Communication entre Composants

- **Props down, Events up** (flux unidirectionnel)
- Ne pas utiliser Pinia comme raccourci pour éviter les props
- Composables pour la logique réutilisable

#### Modales

**✅ Exemple Correct :**

```vue
<template>
  <button @click="showModal = true">Open</button>
  <UserModal v-if="showModal" @close="showModal = false" />
</template>
```

### 3. TypeScript

#### Règles de Base

- **Pas de `any`** : utiliser `unknown` ou des types génériques
- **Typage obligatoire** pour props et emits
- Utiliser les génériques de `defineProps` et `defineEmits`

**✅ Exemple Correct :**

```typescript
interface Props {
  user: User
  isActive: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update': [user: User]
  'delete': [id: number]
}>()
```

**❌ Exemple Incorrect :**

```typescript
const props = defineProps({
  user: Object, // Pas de typage précis
  isActive: Boolean
})
```

### 4. Gestion de l'État

#### Pinia

**Utiliser uniquement pour :**
1. Communication entre composants sans lien hiérarchique
2. État global à l'application

**Règles :**
- Le store n'a pas accès au contexte du composant
- ❌ **Pas d'appels serveur** directement dans un store
- ❌ **Ne pas utiliser** comme raccourci pour éviter les props

**❌ Exemple Incorrect :**

```vue
<!-- Mauvais - Utiliser Pinia pour éviter les props -->
<script setup>
import { useProductStore } from '@/stores/product'

const store = useProductStore()
</script>
<template>
  <div>{{ store.currentProduct.name }}</div>
</template>
```

**✅ Exemple Correct :**

```vue
<!-- Bon - Passer les données via props -->
<script setup>
defineProps<{ product: Product }>()
</script>
<template>
  <div>{{ product.name }}</div>
</template>
```

#### TanStack Query

- Pour le **cache et synchronisation serveur**
- Gestion automatique du loading, error, retry
- Invalidation intelligente du cache

#### Validation des Formulaires

- **Double validation** : client (UX) + serveur (sécurité)
- Utiliser une librairie de validation côté client

### 5. Routage (Vue Router)

- **Lazy loading** des routes pour la performance
- **Validation** des paramètres de route
- Routes nommées pour la navigation
- Navigation guards pour les vérifications d'accès

### 6. Style & CSS/SCSS

- **CSS Scoped** pour éviter les conflits
- **Variables CSS** pour les valeurs réutilisables
- Bonnes pratiques SCSS : nesting limité (max 3 niveaux)

**✅ Exemple Correct :**

```vue
<style scoped lang="scss">
.component {
  color: var(--primary-color);
  
  &__title {
    font-size: 1.5rem;
  }
}
</style>
```

### 7. Tests

- **Tests unitaires** pour la logique métier (composables, utils)
- **Tests de composants** pour l'UI
- **Tests E2E** pour les parcours utilisateurs critiques
- Utiliser les testing libraries Vue (Vue Test Utils, Vitest)

---

## 🔧 Commandes Artisan Préférées

Toujours utiliser les commandes artisan pour générer les fichiers :

```bash
# Model complet
php artisan make:model ModelName -mfsc

# Controller API
php artisan make:controller ControllerName --api

# Form Request
php artisan make:request RequestName

# API Resource
php artisan make:resource ResourceName

# Custom Exception
php artisan make:exception ExceptionName

# Pest Test
php artisan make:test TestName --pest
```

**Ne pas créer manuellement :**
- Models, Controllers, Requests, Resources, Migrations, Factories

---

## ✅ Analyse Statique & Code Style

Avant chaque commit :

### 1. Laravel Pint (PSR-12)

```bash
vendor/bin/pint --dirty
```

### 2. PHPStan/Larastan (niveau 6 requis)

```bash
vendor/bin/phpstan analyse --level=6
```

### 3. Tests

```bash
php artisan test
```

---

## 🎯 Early Return

Préférer le early return pour un code plus plat et maintenable :

**✅ Exemple Correct (Early Return) :**

```php
public function process(User $user): void
{
    if (!$user->isActive()) {
        return;
    }
    
    if (!$user->hasPermission('process')) {
        throw new UnauthorizedException();
    }
    
    // Logique principale visible immédiatement
    $this->doMainLogic($user);
}
```

**❌ Exemple Incorrect (Nested Conditions) :**

```php
public function process(User $user): void
{
    if ($user->isActive()) {
        if ($user->hasPermission('process')) {
            // Logique principale cachée dans des niveaux d'imbrication
            $this->doMainLogic($user);
        }
    }
}
```

---

## 🏗️ Design Patterns Recommandés

- **Repository Pattern** : Pour la persistance et les requêtes
- **Strategy Pattern** : Pour les algorithmes interchangeables
- **Factory Pattern** : Pour la création d'objets complexes
- **Service Pattern** : Pour la logique métier
- **DTO Pattern** : Pour le transfert de données (Spatie Laravel Data)
- **Builder Pattern** : Pour la construction d'objets complexes

---

## 📝 Exemples de Code

### Backend

#### Controller Léger

```php
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

#### Service avec Injection de Dépendances

```php
class LeadConversionService
{
    public function __construct(
        private readonly ContactRepository $contactRepository,
        private readonly Mailer $mailer
    ) {}
    
    public function convert(Lead $lead, User $actor): Contact
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

#### Repository pour la Persistance

```php
class ContactRepository
{
    public function __construct(private readonly Contact $model) {}
    
    public function findById(int $id): ?Contact
    {
        return $this->model->find($id);
    }
    
    public function create(ContactData $data): Contact
    {
        return $this->model->create($data->toArray());
    }
}
```

### Frontend

#### Composant Vue Bien Structuré

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

## 📚 Références

- [Guidelines Backend Français](./back/guidelines-en-français.md)
- [Guidelines Backend English](./back/guidelines-en-anglais.md)
- [Guidelines Frontend](./front/exemples-guidelines-front.md)
- [Exemples Backend](./back/exemples-guidelines-back.md)
- [Vue.js Style Guide](https://vuejs.org/style-guide/)
- [Laravel Documentation](https://laravel.com/docs)
- [TypeScript Handbook](https://www.typescriptlang.org/docs/)

---

## 🚀 Mise en Application

Pour créer le fichier de configuration YAML réel pour Kilocode :

1. Basculer en mode **Code**
2. Créer le fichier `.kilo/config.yaml` avec cette configuration
3. Adapter selon les besoins spécifiques du projet

Cette documentation sert de référence complète pour tous les développeurs du projet.