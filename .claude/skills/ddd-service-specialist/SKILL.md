---
name: ddd-service-specialist
description: Create and design DDD services, implement business logic, QueryBuilder patterns, rights protection, and entity access control in the mgamadeus/ddd framework. Use when creating services, writing custom queries, or implementing rights restrictions.
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
---

# DDD Service Specialist

Services, business logic, QueryBuilder patterns, and rights protection within the DDD Core framework (`mgamadeus/ddd`).

## When to Use

- Creating entity-bound services (`EntitiesService`) or custom services (`Service`)
- Writing custom QueryBuilder queries in services
- Implementing rights protection (`applyReadRightsQuery`, etc.) in DB repositories
- Understanding service instantiation and DDD architecture conventions

## Namespace

All code uses the `DDD\` root namespace. Services live under `DDD\Domain\{Domain}\Services\` or `DDD\Infrastructure\Services\`.

---

## Service Template

**Path:** `src/Domain/{DomainName}/Services/{EntityName}sService.php`

```php
<?php
declare(strict_types=1);
namespace DDD\Domain\{DomainName}\Services;

use DDD\Domain\{DomainName}\Entities\{Group}\{EntityName};
use DDD\Domain\{DomainName}\Entities\{Group}\{EntityName}s;
use DDD\Domain\{DomainName}\Repo\DB\{Group}\DB{EntityName};
use DDD\Domain\{DomainName}\Repo\DB\{Group}\DB{EntityName}s;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * @method {EntityName} find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method {EntityName}s findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DB{EntityName} getEntityRepoClassInstance()
 * @method DB{EntityName}s getEntitySetRepoClassInstance()
 */
class {EntityName}sService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = {EntityName}::class;
}
```

## Service Base Classes

| Base Class | Use For |
|------------|---------|
| `DDD\Domain\Base\Services\EntitiesService` | Entity management services (`find()`, `findAll()`, repo access) |
| `DDD\Infrastructure\Services\Service` | Custom services without entity management (orchestrators, API wrappers) |

`EntitiesBaseService` does **NOT** exist -- always use `EntitiesService`.

**Rule:** If a service manages a specific entity type with DB repo, use `EntitiesService`. For orchestration, API clients, or cross-cutting concerns, use `Service`.

---

## Critical Rules

- **NEVER** use `private` -- always `protected`. The `private` keyword destroys extensibility. This is a DDD framework -- every class may be extended. No exceptions.
- **NEVER** use `new ServiceClass()` -- always resolve from the container
- **NEVER** cache services in class properties -- always resolve inline
- `EntitiesBaseService` does **NOT** exist -- always use `EntitiesService`

## Service Instantiation

**ALWAYS** use container-based resolution. **NEVER** use `new ServiceClass()`. **NEVER** cache services in class properties.

```php
// Via Entity shorthand (entity-bound services)
$service = {EntityName}s::getService();

// Via AppService (any service)
/** @var {EntityName}sService ${entityName}sService */
${entityName}sService = AppService::instance()->getService({EntityName}sService::class);
```

Service variables must be named after their class: `EntityModelGeneratorService` -> `$entityModelGeneratorService`.

---

## DDD Architecture Conventions

### Entity Logic Ownership

**Entities handle ALL logic that is NOT repo-dependent.** Domain logic, calculations, validations, parsing, formatting -- all live on the entity.

**Services handle repo-dependent logic for their OWN entity type.** A service must NOT implement query/finder methods for entities it doesn't own.

```php
// CORRECT -- SupportMessagesService has finder for SupportMessage
class SupportMessagesService extends EntitiesService {
    public function findByGmailMessageId(string $gmailMessageId): ?SupportMessage { /* ... */ }
}

// WRONG -- SupportEmailsService implementing finders for SupportTicket
class SupportEmailsService extends Service {
    public function findTicketByGmailThreadId(string $threadId): ?SupportTicket { /* belongs in SupportTicketsService */ }
}
```

### Pass Objects, Not IDs

**Always pass entity objects** to service methods instead of raw integer IDs. The caller loads the entity first.

```php
// CORRECT
public function sendReply(SupportTicket $ticket, string $replyBody): SupportMessage

// WRONG
public function sendReply(int $ticketId, string $replyBody): SupportMessage
```

---

## When to Write Service Methods

**ONLY write service methods for scenarios lazy loading can't handle.**

**AVOID finder methods** -- use lazy loading or QueryOptions instead:
- `findByParent()` -> `$parent->children` (lazy load)
- `findByStatus()` -> QueryOptions filtering in API layer

## QueryBuilder Pattern -- ALWAYS Use Base Model Alias

All custom queries MUST use the **base model alias** as a column prefix. Queries without the alias fail at runtime.

### Pattern: Single Entity

```php
$repoClass = $this->getEntityRepoClassInstance();
$queryBuilder = $repoClass::createQueryBuilder();
$baseModelAlias = $repoClass::getBaseModelAlias();
$queryBuilder->andWhere("{$baseModelAlias}.columnName = :param");
$queryBuilder->setParameter('param', $value);
return $repoClass->find($queryBuilder);
```

### Pattern: Collection or Scalar

```php
$repoClass = $this->getEntitySetRepoClassInstance();
$queryBuilder = $repoClass::createQueryBuilder(true);
$baseModelAlias = $repoClass::getBaseModelAlias();
$queryBuilder->select("COUNT({$baseModelAlias}.id)")
    ->where("{$baseModelAlias}.name = :name")
    ->setParameter('name', $name);
```

### Key Rules

- `createQueryBuilder()` and `getBaseModelAlias()` are called **statically** on the repo class (`$repoClass::`)
- Column references MUST be `{$baseModelAlias}.columnName` -- never bare `columnName`
- Use `getEntityRepoClassInstance()` (single entity repo) for `->find()` returning one entity
- Use `getEntitySetRepoClassInstance()` (entity set repo) for `->find()` returning a collection, or for scalar queries

---

## Service Method Examples

### Find Single Entity by Unique Field

```php
public function findByLanguageCode(string $languageCode): ?Language
{
    $repoClass = $this->getEntityRepoClassInstance();
    $queryBuilder = $repoClass::createQueryBuilder();
    $baseModelAlias = $repoClass::getBaseModelAlias();
    $queryBuilder->andWhere("{$baseModelAlias}.languageCode = :languageCode");
    $queryBuilder->setParameter('languageCode', $languageCode);
    return $repoClass->find($queryBuilder);
}
```

### Uniqueness Checks

```php
public function isNameUnique(string $name, ?int $excludeId = null): bool
{
    $repoClass = $this->getEntitySetRepoClassInstance();
    $qb = $repoClass::createQueryBuilder(true);
    $a = $repoClass::getBaseModelAlias();
    $qb->select("COUNT({$a}.id)")->where("{$a}.name = :name")->setParameter('name', $name);
    if ($excludeId !== null) {
        $qb->andWhere("{$a}.id != :excludeId")->setParameter('excludeId', $excludeId);
    }
    return (int) $qb->getQuery()->getSingleScalarResult() === 0;
}
```

### Computed Values

```php
public function getNextDisplayOrder(int $parentId): int
{
    $repoClass = $this->getEntitySetRepoClassInstance();
    $qb = $repoClass::createQueryBuilder(true);
    $a = $repoClass::getBaseModelAlias();
    $qb->select("MAX({$a}.displayOrder)")->where("{$a}.parentId = :pid")->setParameter('pid', $parentId);
    return ($qb->getQuery()->getSingleScalarResult() ?? -1) + 1;
}
```

### ID Generation

```php
public function generateUniqueAccessCode(): string
{
    do { $code = Entity::generateAccessCode(); } while (!$this->isAccessCodeUnique($code));
    return $code;
}
```

### Display Order Sequencing

```php
public function getNextDisplayOrder(int $parentId): int
{
    $repoClass = $this->getEntitySetRepoClassInstance();
    $qb = $repoClass::createQueryBuilder(true);
    $a = $repoClass::getBaseModelAlias();
    $qb->select("MAX({$a}.displayOrder)")->where("{$a}.parentId = :pid")->setParameter('pid', $parentId);
    return ($qb->getQuery()->getSingleScalarResult() ?? -1) + 1;
}
```

Used by entities with `displayOrder` property (menus, sections, zones).

### Scope-Aware Queries (Multi-Tenant)

For entities with Global/Business/Location scoping:

```php
public function findAllAvailableForLocation(int $locationId, ?int $businessId): ?Ingredients
{
    $repoClass = $this->getEntitySetRepoClassInstance();
    $qb = $repoClass::createQueryBuilder(true);
    $a = $repoClass::getBaseModelAlias();

    $conditions = [
        "({$a}.businessId IS NULL AND {$a}.locationId IS NULL)",          // GLOBAL
    ];
    if ($businessId) {
        $conditions[] = "({$a}.businessId = :businessId AND {$a}.locationId IS NULL)";  // BUSINESS
        $qb->setParameter('businessId', $businessId);
    }
    $conditions[] = "({$a}.locationId = :locationId)";                    // LOCATION
    $qb->setParameter('locationId', $locationId);

    $qb->andWhere(implode(' OR ', $conditions));
    return $repoClass->find($qb);
}
```

### Uniqueness Validation Within Scope

```php
public function isCodeUniqueWithinScope(
    string $code,
    ?int $businessId,
    ?int $locationId,
    ?int $excludeId = null
): bool {
    $repoClass = $this->getEntitySetRepoClassInstance();
    $qb = $repoClass::createQueryBuilder(true);
    $a = $repoClass::getBaseModelAlias();
    $qb->select("COUNT({$a}.id)")->where("{$a}.code = :code")->setParameter('code', $code);

    if ($businessId) {
        $qb->andWhere("{$a}.businessId = :businessId")->setParameter('businessId', $businessId);
    } else {
        $qb->andWhere("{$a}.businessId IS NULL");
    }
    if ($locationId) {
        $qb->andWhere("{$a}.locationId = :locationId")->setParameter('locationId', $locationId);
    } else {
        $qb->andWhere("{$a}.locationId IS NULL");
    }
    if ($excludeId !== null) {
        $qb->andWhere("{$a}.id != :excludeId")->setParameter('excludeId', $excludeId);
    }
    return (int) $qb->getQuery()->getSingleScalarResult() === 0;
}
```

---

## Advanced Service Patterns

### Update Override with Side Effects

Override `update()` to handle related operations before/after persisting:

```php
public function update(DefaultObject &$entity, int $depth = 1): ?Entity
{
    // Pre-processing: clean up related data
    if (isset($entity->id)) {
        // e.g., delete roles not in the new set
        $repoClass = $this->getEntitySetRepoClassInstance();
        $qb = $repoClass::createQueryBuilder(true);
        // ... raw SQL for batch cleanup if ORM is too slow
    }

    // Delegate to parent
    return parent::update($entity, $depth);
}
```

### Delete Override with Cleanup

Override to cascade-delete related entities or clean up external resources:

```php
public function delete(DefaultObject &$entity): void
{
    // Clean up related MediaItems before deletion
    if ($entity->mediaItem) {
        $entity->mediaItem->delete();
    }
    parent::delete($entity);
}
```

### Junction Entity Management (M:N Links)

Creating many-to-many links via a junction entity service:

```php
protected function linkAllergens(Ingredient $ingredient, array $allergenCodes, array $allergenIdByCode): void
{
    /** @var IngredientAllergensService $ingredientAllergensService */
    $ingredientAllergensService = IngredientAllergens::getService();

    foreach ($allergenCodes as $code) {
        if (!isset($allergenIdByCode[$code])) {
            continue;
        }
        $link = new IngredientAllergen();
        $link->ingredientId = $ingredient->id;
        $link->allergenId = $allergenIdByCode[$code];
        $link->update();  // Idempotent via unique index
    }
}
```

### Config-Driven Import/Seed

Loading seed data from configuration files (UPSERT pattern):

```php
public function importFromConfig(): Entities
{
    $configData = Config::get('Catalog.Allergens');
    $entities = new Entities();

    foreach ($configData as $item) {
        // Try to find existing by unique key
        $entity = $this->findByCode($item['code']);
        if (!$entity) {
            $entity = new Entity();
        }
        $entity->code = $item['code'];
        $entity->name = $item['name'];
        $entity->scope = Entity::SCOPE_GLOBAL;
        $entity = $entity->update();
        $entities->add($entity);
    }
    return $entities;
}
```

### Cross-Service Orchestration

When one import depends on another, pass the service as a parameter:

```php
public function importIngredientsFromConfig(AllergensService $allergensService): Ingredients
{
    // Build lookup from allergens service
    $allergens = $allergensService->findAll();
    $allergenIdByCode = [];
    foreach ($allergens->getElements() as $allergen) {
        $allergenIdByCode[$allergen->code] = $allergen->id;
    }

    // Import ingredients and link allergens
    $configData = Config::get('Catalog.Ingredients');
    foreach ($configData as $item) {
        $ingredient = $this->findByName($item['name']) ?? new Ingredient();
        // ... set properties, update
        $this->linkAllergens($ingredient, $item['allergenCodes'] ?? [], $allergenIdByCode);
    }
}
```

### Async Message Dispatching

Dispatch background tasks for expensive operations:

```php
use Symfony\Component\Messenger\MessageBusInterface;

public function processGeoHashesForTracks(Tracks $tracks): void
{
    /** @var MessageBusInterface $messageBus */
    $messageBus = AppService::instance()->getService(MessageBusInterface::class);

    foreach ($tracks->getElements() as $track) {
        $messageBus->dispatch(new TrackGeoHashesMessage($track->id));
    }
}
```

Message handlers live in `Domain/{Domain}/MessageHandlers/` and process dispatched messages asynchronously.

---

## Infrastructure Utilities in Services

### Config Access

```php
use DDD\Infrastructure\Libs\Config;

$dbHost = Config::get('database.host');              // Dot-notation path through config files
$apiKey = Config::getEnv('API_KEY');                  // Env var with type coercion
$seedData = Config::get('Catalog.Allergens');         // Load seed data arrays from config
```

### Caching

```php
use DDD\Infrastructure\Cache\Cache;

$cache = Cache::instance();
// Use for expensive computations, external API results, or rankings
// TTL constants: CACHE_TTL_ONE_HOUR (3600), CACHE_TTL_ONE_DAY (86400), CACHE_TTL_TEN_MINUTES (600)
```

### Encryption

```php
use DDD\Infrastructure\Libs\Encrypt;

$encrypted = Encrypt::encrypt($sensitiveData, $password);
$decrypted = Encrypt::decrypt($encrypted, $password);
$hash = Encrypt::hashWithSalt($password);            // SHA256 with env salt
```

### JWT for Secure Tokens

```php
use DDD\Infrastructure\Libs\JWTPayload;

$token = JWTPayload::createJWTFromParameters(['userId' => $id], validityInSeconds: 3600);
$params = JWTPayload::getParametersFromJWT($token);  // false if expired
```

### Structured Error Logging (IssuesLogService)

For error tracking with fingerprinting and entry-point detection:

```php
use DDD\Infrastructure\Services\IssuesLogService;

$issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
$issuesLogService->logThrowable($exception, LogLevel::CRITICAL, [
    'entityId' => $entity->id,
    'operation' => 'recalculate',
]);
```

Features: error fingerprinting for deduplication, auto-detects entry point (HTTP/CLI/Messenger), filtered stack traces showing only application code.

---

## Rights Protection System

> **Full documentation:** See `ddd-rights-specialist` for the complete rights system: all 6 patterns (direct filter, leftJoin chain, subquery, custom update rights, property hiding, RolesRequiredForUpdate), the `_rights` alias convention, snapshot/restore mechanism, and real-world examples from 18+ repository implementations.
