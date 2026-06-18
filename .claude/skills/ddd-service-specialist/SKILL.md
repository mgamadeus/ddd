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

### Calling `find()` -- caller-side vs in-service

Distinct paths depending on where the lookup happens:

| Where | What to call | Returns |
|---|---|---|
| Caller (controller, handler, other service, CLI) loading a known id | `{EntityName}::getService()->find($id)` | single entity, with rights restrictions, registry cache |
| Caller loading a known id via the entity-set | `{EntityName}s::getService()->find($id)` | single entity (set service forwards) |
| Inside this service, custom single-entity query | `{EntityName}::getRepoClassInstance()->find($queryBuilder)` | single entity, raw |
| Inside this service, custom set/scalar query | `{EntityName}s::getRepoClassInstance()->find($queryBuilder)` | entity set or scalar |

**`getRepoClassInstance()` is for service-internal custom QueryBuilder queries only**, never as a substitute for `getService()->find($id)` from outside. Going through the service applies `applyReadRightsQuery`, entity registry caching, lazy-loading defaults and other invariants -- skipping the service silently bypasses them.

### `getService()` returning null — cross-namespace subclasses

If `{Entity}::getService()` returns `null` for an entity that clearly has a Service in the framework, the cause is almost always a missing App-side EntitySet: an App subclass extends a Framework entity from a different root namespace, and `EntityTrait::getEntitySetClass()` cannot resolve a same-namespace plural. The diagnosis is two checks:

1. The entity class lives in `App\…` and its parent lives in `DDD\…` (or another root namespace).
2. There is no `App\…\{Entity}s` class declared alongside it.

If the subclass adds **only** constants/type-aliases (no new persistent properties, no App service methods), tag it with `#[ReuseParentEntitySet]` from `DDD\Domain\Base\Entities\Attributes` — `getService()` will then resolve to the parent class's Service across the namespace boundary. See the **"Constants-Only Subclasses Across Root Namespaces"** section in `ddd-entity-specialist`.

If the subclass actually adds behavior, declare the parallel `App\…\{Entity}s` (EntitySet) and `App\…\{Entity}sService` explicitly — the attribute is not the right tool there.

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

### CRITICAL: `createQueryBuilder(true)` -- when to pass `true`

`createQueryBuilder(bool $includeModelSelectFromClause = false)` -- the `true` parameter adds the `SELECT ... FROM ...` clause, which establishes the **root alias**. You **MUST** pass `true` when:

1. **Using JOINs** (`leftJoin`, `innerJoin`, `join`) -- joins require a root alias. Without `true`: `RuntimeException: No alias was set before invoking getRootAlias()`
2. **Using `->select()` with scalar aggregates** (`COUNT`, `MAX`, `SUM`) followed by `->getQuery()->getSingleScalarResult()`
3. **Using `->groupBy()`**

When in doubt, **always pass `true`** -- it never hurts. The only case where omitting it is fine is simple filter queries where the framework's `find()` method adds its own SELECT/FROM clause internally.

```php
// Simple filter -- find() adds SELECT/FROM, so false (default) is fine
$qb = $repoClass::createQueryBuilder();
$qb->andWhere("{$a}.status = :s")->setParameter('s', 'ACTIVE');
return $repoClass->find($qb);

// JOIN -- MUST pass true
$qb = $repoClass::createQueryBuilder(true);
$qb->leftJoin("{$a}.children", $a . '_children')
   ->andWhere("{$a}.worldId = :wid")->setParameter('wid', $worldId);
return $repoClass->find($qb);

// Scalar aggregate -- MUST pass true
$qb = $repoClass::createQueryBuilder(true);
$qb->select("COUNT({$a}.id)")->where("{$a}.parentId = :pid")->setParameter('pid', $id);
return (int) $qb->getQuery()->getSingleScalarResult();
```

### Key Rules

- `createQueryBuilder()` and `getBaseModelAlias()` are called **statically** on the repo class (`$repoClass::`)
- Column references MUST be `{$baseModelAlias}.columnName` -- never bare `columnName`
- Use `getEntityRepoClassInstance()` (single entity repo) for `->find()` returning one entity
- Use `getEntitySetRepoClassInstance()` (entity set repo) for `->find()` returning a collection, or for scalar queries

---

## Vector / semantic search — embed in the service, search in the repo

Semantic search is **NOT a QueryOptions feature**; it splits across two layers:

- **Service** owns embedding generation: turn the query text into an embedding via an embedding service, then
  run the distance-ordered query. Never put embedding generation in the entity/repo.
- **QueryBuilder** owns the query: `ORDER BY` a vector-distance DQL function over the `VECTOR(n)` column, the
  query embedding bound as a parameter, `setMaxResults($k)` for top-k.

```php
public function findSimilarByText(string $searchText, int $limit = 10): SupportTickets
{
    $embedding = AppService::instance()->getService(AITextEmbeddingsService::class)
        ->generateEmbeddingForText($searchText, Vector::DIMENSION_OPENAI_EMBEDDING_LARGE);
    if ($embedding->isEmpty()) {
        return new SupportTickets();
    }

    $vectorString = '[' . implode(',', $embedding->vectorValues) . ']';
    $zeroVector   = '[' . implode(',', array_fill(0, Vector::DIMENSION_OPENAI_EMBEDDING_LARGE, '0')) . ']';

    $repo  = $this->getEntitySetRepoClassInstance();
    $alias = $repo::getBaseModelAlias();
    $qb = $repo::createQueryBuilder()
        // exclude rows with no real embedding (un-embedded rows carry the default zero vector)
        ->andWhere("{$alias}.resolutionSummaryEmbedding != VEC_FROM_TEXT(:zeroVector)")
        ->addOrderBy("COSINE_DISTANCE({$alias}.resolutionSummaryEmbedding, VEC_FROM_TEXT(:searchVector))", 'ASC')
        ->setParameter('zeroVector', $zeroVector)
        ->setParameter('searchVector', $vectorString)
        ->setMaxResults($limit);

    return $repo->find($qb);
}
```

- **Zero-vector guard matters**: an un-embedded row stores the default zero vector, which would otherwise sort
  as a (meaningless) near match — exclude it with `!= VEC_FROM_TEXT(:zeroVector)`. Pair this with a backfill
  method that finds rows still `= VEC_FROM_TEXT(:zeroVector)` and generates their embeddings.
- Optional: widen the pool (e.g. 3× the limit) and LLM-rerank the candidates when precision matters.

Full DQL function catalog (`COSINE_DISTANCE` / `COSINE_SIMILARITY` / `EUCLIDEAN_DISTANCE` / `VEC_DISTANCE` /
`VEC_FROM_TEXT`) + ANN/spatial worked examples → **`ddd-geometry-and-vector-specialist`**; the `VECTOR` column +
index and the `TextEmbedding extends Vector` shape → **`ddd-entity-specialist` → "Spatial & vector columns"**.

---

## Fulltext search over Translatable properties

A `#[Translatable(fullTextIndex: true)]` property (e.g. `name`) is backed by a generated, FULLTEXT-indexed
stored column **`virtual{Property}Search`** (e.g. `virtualNameSearch`) holding the joined translation values.
You normally do NOT touch that column name — fulltext search is exposed declaratively as QueryOptions
`ft` / `fb` operators on the logical property (`filters=name ft 'pizza'`), and the generated query rewrites to
`virtualNameSearch` automatically (see `ddd-query-options-specialist`). Only drop to a raw repo
`MATCH(...) AGAINST(...)` on `virtualNameSearch` when you need a relevance score outside the QueryOptions flow.
The column-generation side (`name → virtualNameSearch`) is documented in `ddd-entity-specialist`.

---

## Concurrency-safe writes — atomic SQL/DQL, NEVER read-modify-write through `update()`

`$entity->update()` persists through `DoctrineEntityManager::upsert()` — an `INSERT … ON DUPLICATE KEY UPDATE <col> = VALUES(<col>)` over **every *initialized* field** of the model (a property that is unset *and* has no default is the only thing skipped). So `update()` is a **last-writer-wins snapshot of the whole row**.

Under concurrency this is a data race: two workers each load the entity, mutate one field in memory, and `update()` → the second write **clobbers the first's other columns** (and a counter read-modify-write loses increments). For any field on a row that more than one process can mutate — a counter, a status/flag, a timestamp, an exactly-once gate — do NOT go through `update()`. Issue a **single atomic statement** in the DB:

- **counter** → `SET col = col ± 1` in SQL (never `$e->col++; $e->update()`), guarded (`… AND col > 0`).
- **state transition / exactly-once gate** → compare-and-set `SET col = :to WHERE id = :id AND col = :from` (or `… AND col IS NULL`); the **affected-rows count tells you whether you won** the race (exactly one winner).
- mirror the new value back onto the in-memory entity afterwards if the rest of the request needs it.

### Two forms (both bypass the clobbering upsert)

```php
// (1) Raw DBAL — the common form for counters / compare-and-set
$connection = EntityManagerFactory::getInstance()->getConnection();
$won = (int) $connection->executeStatement(
    "UPDATE {$tableName} SET status = :to WHERE id = :id AND status = :from",
    ['to' => $toStatus, 'from' => $fromStatus, 'id' => $id]
);
// $won === 1 → this caller claimed the transition; 0 → another worker already did.

// (2) ORM DQL via the QueryBuilder ->update()->set()->execute()
$repoClass::createQueryBuilder()
    ->update($repoClass::BASE_ORM_MODEL, $alias)
    ->set("{$alias}.needsRegeneration", ':v')->setParameter('v', true)
    ->andWhere("{$alias}.someColumn IS NOT NULL")
    ->getQuery()->execute();
```

### ALWAYS derive the table name from the model — never hardcode the literal

A raw-SQL string MUST get its table name from the ORM model, so the table name lives in ONE place (the model) and survives a rename:

```php
$tableName = (self::BASE_ORM_MODEL)::getTableName();   // inside a repo (its own const) — preferred
$tableName = SomeDoctrineModel::getTableName();        // inside a service (reference the model class)
// NEVER: "UPDATE EntityFooBar SET …"  ← hardcoded literal — silently breaks on a table rename + duplicates the source of truth
```

(`getTableName()` is the static accessor on `DoctrineModel`; prefer it over reading the `TABLE_NAME` const directly.)

### Multi-writer guarantees (when a single CAS isn't enough)

When the decision depends on a **derived count or several rows** (e.g. "settle a batch once N children are terminal"), wrap the read+write in a transaction with `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` + a **lock-first `SELECT … FOR UPDATE`** on the coordinating row, then the conditional UPDATE under the held lock — so concurrent settlers serialize on that row and exactly one wins the `… WHERE gate IS NULL` flip. (Pattern reference: a fork-join "claim the wake exactly once" settle.)

### Key rules

- A field mutated by >1 process → atomic SQL/DQL, never `entity->update()`.
- Counter → `SET col = col ± 1`; state/flag → compare-and-set `WHERE col = :from` / `WHERE col IS NULL`; check affected-rows for the winner.
- Table name ALWAYS from `Model::getTableName()` / `(self::BASE_ORM_MODEL)::getTableName()` — never a hardcoded string.
- `entity->update()` stays correct for the single-writer case (creation, an aggregate only one process owns at a time).

---

## PHPDoc & @throws Convention

Every public service method MUST have complete PHPDoc with `@param`, `@return`, and `@throws`. The `@throws` declarations propagate upward — controllers that call service methods must declare the same exceptions.

### Standard @throws for DB operations:

```php
// Methods that call find() / findAll() / createQueryBuilder:
@throws BadRequestException
@throws InternalErrorException
@throws InvalidArgumentException
@throws ReflectionException

// Methods that additionally call $this->update():
@throws ORMException
@throws OptimisticLockException

// Methods that additionally call $this->delete():
@throws ORMException
@throws NonUniqueResultException
@throws OptimisticLockException
```

### Required imports:

```php
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
```

### Example with complete PHPDoc:

```php
/**
 * Finds a membership record for a specific account and channel.
 *
 * @param int $accountId
 * @param int $channelId
 * @return ChatChannelMember|null
 * @throws BadRequestException
 * @throws InternalErrorException
 * @throws InvalidArgumentException
 * @throws ReflectionException
 */
public function findMembership(int $accountId, int $channelId): ?ChatChannelMember
```

---

## Service Method Examples

### Find Single Entity by Unique Field

```php
/**
 * @param string $languageCode
 * @return Language|null
 * @throws BadRequestException
 * @throws InternalErrorException
 * @throws InvalidArgumentException
 * @throws ReflectionException
 */
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

---

## Cross-Reference

- **Async dispatch from a service** — `ddd-message-handler-specialist` (the message + handler pair behind the `$messageBus->dispatch(...)` in the *Async Message Dispatching* example: auth-context propagation, workspace routing, and messenger.yaml transport config).
- **CLI entry points that call services** — `ddd-cli-command-specialist` (console commands set up the admin auth context, then resolve and invoke these services for batch/maintenance work).
