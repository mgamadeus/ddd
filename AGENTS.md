# mgamadeus/ddd -- DDD Entity Framework

A Domain-Driven Design entity framework built on top of Symfony 7.3 and Doctrine ORM, providing a complete stack for building DDD-based PHP applications with entities, repositories, services, lazy loading, query options, and auto-generated database models.

**Package:** `mgamadeus/ddd` (v2.10.x)
**PHP:** >= 8.3
**License:** MIT
**Namespace:** `DDD\`

## Architecture Overview

```
src/
+-- DDDBundle.php                        [Symfony bundle entry point]
+-- Domain/
|   +-- Base/                            [Framework core]
|   |   +-- Entities/                    [Entity, EntitySet, DefaultObject, LazyLoad, ChangeHistory, QueryOptions, Translatable]
|   |   +-- Repo/                        [DB, Virtual, Doctrine repos + Database model generation]
|   |   +-- Services/                    [EntitiesService, TranslatableService]
|   +-- Common/                          [Shared domain entities & services]
|       +-- Entities/
|       +-- Repo/
|       +-- Services/                    [EntityModelGeneratorService]
+-- Infrastructure/
|   +-- Base/DateTime/                   [Date, DateTime value objects]
|   +-- Cache/                           [APC, Redis, Predis, PhpFiles]
|   +-- Exceptions/                      [BadRequest, NotFound, Forbidden, Unauthorized, InternalError, MethodNotAllowed]
|   +-- Libs/                            [ClassFinder, Config, Datafilter, Encrypt, StringFuncs, JWTPayload]
|   +-- Modules/                         [DDDModule base class for plugin system]
|   +-- Reflection/                      [Enhanced ReflectionClass, ReflectionProperty, Attributes]
|   +-- Services/                        [DDDService (singleton), AuthService, Service base class]
|   +-- Traits/                          [ReflectorTrait, SerializerTrait, ValidatorTrait, SingletonTrait]
|   +-- Validation/                      [ValidationResult, ValidationError, Constraints]
+-- Presentation/
|   +-- Base/
|   |   +-- Controller/                  [HttpController, BaseController, DocumentationController]
|   |   +-- Dtos/                        [RequestDto, RestResponseDto, Excel/PDF/Zip/File/Image/Html/Redirect response DTOs]
|   |   +-- OpenApi/                     [OpenAPI 3.0: Document, Attributes, Components, Paths]
|   |   +-- QueryOptions/               [OData-style: Filters, OrderBy, Select, Expand, Pagination]
|   |   +-- Router/                      [Route definitions: Get, Post, Patch, Update, Delete]
|   +-- Services/                        [RequestService]
+-- Symfony/
    +-- Commands/                        [Console commands including Doctrine model generation]
    +-- CompilerPasses/                  [ModuleCompilerPass, ServiceClassCollectorPass]
    +-- EventListeners/                  [CorsListener, ExceptionListener, RequestCacheSubscriber]
    +-- Kernels/                         [DDDKernel, Kernel]
    +-- Loaders/                         [Custom annotation loaders]
    +-- Security/                        [Authenticators, AccountProviders, AccessDeniedHandlers]
```

## Technology Stack

| Component | Technology | Version |
|-----------|------------|---------|
| Language | PHP | >= 8.3 |
| Framework | Symfony | 7.3.* |
| ORM | Doctrine | ^2.13 |
| Cache | Redis / Predis | 2.0 |
| HTTP | Guzzle | ^7.5 |
| Auth | Firebase JWT | ^6.10 |
| PDF | DomPDF | ^2.0 |
| Excel | PHPSpreadsheet | ^4.2 |
| Geo | brick/geo-doctrine | ^0.3.1 |
| Template | Twig | ^3.0 |

**Required PHP extensions:** `ctype`, `dom`, `gd`, `iconv`, `libxml`, `openssl`, `zlib`, `imagick`

---

## Core DDD Patterns

### 1. Entity -> Repository -> Service

All entities extend DDD base classes. Repositories handle persistence. Services contain business logic.

**Object Hierarchy:**
```
BaseObject (abstract)
  +- DefaultObject -- uses SerializerTrait, ValidatorTrait, ParentChildrenTrait, LazyLoadTrait, ReflectorTrait
      +- Entity -- domain entities with identity (EntityTrait)
      +- ValueObject -- immutable objects (ValueObjectTrait)
      |   +- ObjectSet -> EntitySet -- typed collections
      +- Other domain objects
```

**Repository Types:**

| Type | Constant | Purpose |
|------|----------|---------|
| DB | `LazyLoadRepo::DB` | Doctrine ORM persistence |
| Virtual | `LazyLoadRepo::VIRTUAL` | Computed/derived data |
| Class Method | `LazyLoadRepo::CLASS_METHOD` | Entity method loading |
| Legacy DB | `LazyLoadRepo::LEGACY_DB` | Legacy database support |

> **CRITICAL:** `DB{EntityName}Model.php` files are **auto-generated** from entity attributes. Never create, modify, or delete them manually.

### 2. Lazy Loading

Properties marked `#[LazyLoad]` load on-demand from repositories, eliminating most manual finder methods.

```php
#[LazyLoad]
public ?World $world;

#[LazyLoad(loadMethod: 'getWorldsForAccount')]
public Worlds $worlds;

#[LazyLoad(repoType: LazyLoadRepo::VIRTUAL, loadMethod: 'lazyloadForAccount')]
public AccountInChallengeParticipations $challengeParticipations;
```

### 3. QueryOptions (OData-Style)

Filtering, sorting, pagination, and expansion applied at the database level:

- `$expand` -- Load related entities
- `$select` -- Choose specific fields
- `$filter` -- Filter results (eq, ne, gt, ge, lt, le, in, ni, bw, ft, fb operators)
- `$orderBy` -- Sort results
- `$top` / `$skip` -- Pagination

### 4. Controller -> DTO Pattern

Controllers extend `HttpController`. Request/response data flows through DTOs:

```
Client Request -> Symfony Router -> Controller
    -> RequestDto::setPropertiesFromObject()
    -> Service Layer (Business Logic)
    -> ResponseDto -> JSON Response
```

**DTO Types:** RequestDto (input), RestResponseDto (output), specialized response DTOs (Excel, PDF, Zip, Image, HTML, File, Redirect)

### 5. ChangeHistory

`ChangeHistoryTrait` provides automatic audit trail (`created`, `updated` timestamps).

### 6. Translatable

`TranslatableTrait` + `#[Translatable]` attribute enables multi-language property support with JSON storage and fulltext search.

### 7. Database Model Generation

`EntityModelGeneratorService` scans framework, application, and module directories for entities and generates Doctrine ORM model classes automatically.

### 8. Rights Protection System

Entities are rights-protected at the database query level for **read**, **update**, and **delete** operations via three overridable static methods in `DatabaseRepoEntity`:

| Method | Default Behavior | Called During |
|--------|-----------------|--------------|
| `applyReadRightsQuery()` | Returns `false` (no restrictions) | `find()`, `findAll()` |
| `applyUpdateRightsQuery()` | Delegates to `applyReadRightsQuery()` | `update()` |
| `applyDeleteRightsQuery()` | Delegates to `applyUpdateRightsQuery()` | `delete()` |

All three are gated by `$applyRightsRestrictions` (default `true`). Override these in DB repository classes (`DB{EntityName}`) to implement entity-level access control. See the `entity-creator` skill for implementation patterns.

### 9. Module System

DDD modules are Composer packages that self-register via `extra.ddd-module` in `composer.json`. Modules extend `DDDModule` and declare their source paths, config directories, and service namespaces. `ModuleCompilerPass` discovers and registers them automatically.

---

## Domain Structure

| Domain | Key Components |
|--------|---------------|
| **Base** | Entity, EntitySet, DefaultObject, ValueObject, ObjectSet, ChildrenSet |
| **Base/Entities** | LazyLoad system, ChangeHistory, QueryOptions, Translatable, StaticRegistry, Attributes |
| **Base/Repo** | DatabaseRepoEntity, DBEntity, DBEntitySet, VirtualEntity, Doctrine integration, Database model generation |
| **Base/Services** | EntitiesService, TranslatableService |
| **Common** | Shared domain entities, validators, interfaces |
| **Common/Services** | EntityModelGeneratorService |

---

## Coding Conventions

> **Full code templates:** See skills `ddd-entity-specialist`, `ddd-service-specialist`, `ddd-endpoint-specialist`, `ddd-query-options-specialist`, `ddd-message-handler-specialist`, and `ddd-cli-command-specialist`.

### Naming Conventions

| Type | Convention | Example |
|------|------------|---------|
| Entity | PascalCase | `Account.php` |
| EntitySet | PascalCase + `s` | `Accounts.php` |
| Service | PascalCase + `Service` | `AccountsService.php` |
| Controller | PascalCase + `Controller` | `AccountsController.php` |
| DTO | PascalCase + `Dto` suffix | `TrackPostRequestDto.php` |
| Repository | `DB` prefix + Entity | `DBAccount.php` |
| Trait | PascalCase + `Trait` | `ChangeHistoryTrait.php` |
| Interface | PascalCase + `Interface` | `AccountDependentEntityInterface.php` |

### Variable Naming

Variables must be **descriptive and named after what they represent**. Never use generic, cryptic, or abbreviated names.

```php
// CORRECT -- named after the class or what they represent
$entityModelGeneratorService = AppService::instance()->getService(EntityModelGeneratorService::class);
$reflectionClass = ReflectionClass::instance($className);

// WRONG -- generic, cryptic, or abbreviated
$service = AppService::instance()->getService(EntityModelGeneratorService::class);
$ref = ReflectionClass::instance($className);
```

**Service variables** must always be named after their service class in `$camelCase`:
- `EntityModelGeneratorService` -> `$entityModelGeneratorService`
- `TranslatableService` -> `$translatableService`

### Function & Method Naming

Functions must **describe what they do and what they return**.

```php
// CORRECT -- describes action AND return value
public function findByLanguageCode(string $languageCode): ?Language
public function getAllEntityClasses(?string $restrictToClasesWithLazyloadRepoType = null): array
public function isNameUnique(string $name, ?int $excludeId = null): bool

// WRONG -- too vague
public function getData(): array
public function process(): void
```

**Key principles:**
1. If the function returns something, include the return concept in the name
2. Use verbs that describe the action: `find`, `create`, `update`, `delete`, `get`, `generate`, `calculate`, `validate`
3. Never use unqualified generic verbs alone: `do`, `handle`, `process`

### Visibility Rules

> **NEVER use `private` for variables, functions, constants, or methods -- ALWAYS use `protected` instead.** The `private` keyword **destroys extensibility** by preventing subclasses from overriding or accessing members. This is a DDD framework -- every class may be extended by consuming applications or modules. Properties, methods, constants -- ALL must be `protected` (or `public` where appropriate). No exceptions, no edge cases, no "but this is internal". If you write `private`, you are creating a wall that future developers cannot work around without forking the class.

### Constants

```php
// Typed constants (PHP 8.3+)
public const string TYPE_REGISTERED = 'REGISTERED';
public const string STATUS_ACTIVE = 'ACTIVE';
public const int MAX_NAME_LENGTH = 255;
// Always use constants over magic values: self::STATUS_ACTIVE not 'ACTIVE'
```

### Type Declarations

```php
<?php
declare(strict_types=1);  // ALWAYS -- every file

// Properties
public string $name;                // Required
public ?string $description = null; // Optional (nullable)
public string|int|null $id;         // Union types
/** @var Account[] */
public array $accounts;             // Array typing via PHPDoc

// Returns
public function find(int $id): ?Entity    // Nullable return
public function setName(string $name): static  // Fluent
public function delete(): void             // Void
```

### Documentation Standards

```php
/**
 * Short description of the class/method.
 *
 * @param string $id Entity identifier
 * @return Account|null The account or null
 * @throws NotFoundException When entity doesn't exist
 * @throws BadRequestException When input invalid
 */
public function find(string $id): ?Account
```

### Trait Declaration Rule

Multiple traits MUST be comma-separated on a single `use` statement:
```php
use ChangeHistoryTrait, TranslatableTrait;  // CORRECT
```
```php
use ChangeHistoryTrait;  // WRONG -- separate use statements break PHP trait resolution
use TranslatableTrait;
```

### Date & DateTime Types

**NEVER** use PHP's native `\DateTime` or `\DateTimeImmutable` for entity properties. **ALWAYS** use the DDD framework classes:

| Use Case | DDD Class | Serializes As |
|----------|-----------|---------------|
| Date (no time) | `DDD\Infrastructure\Base\DateTime\Date` | `Y-m-d` |
| DateTime (with time) | `DDD\Infrastructure\Base\DateTime\DateTime` | `Y-m-d H:i:s` |

**Why:** The framework's `Date` and `DateTime` classes provide proper JSON serialization (`jsonSerialize()`), string conversion, factory methods (`fromString()`, `fromTimestamp()`), and are recognized by the Autodocumenter for correct OpenAPI type generation. Native `\DateTime` breaks serialization, produces wrong OpenAPI types, and bypasses framework formatting.

### `EntitiesBaseService` Does NOT Exist

A common mistake: `EntitiesBaseService` does **not exist**. Always use `DDD\Domain\Base\Services\EntitiesService`. If you see or generate code referencing `EntitiesBaseService`, it is wrong.

### Code Style

- **Indentation:** 4 spaces (no tabs)
- **Braces:** Opening on same line
- **Classes:** One per file
- **Blank line** after namespace declaration

### Import Order

```php
<?php
declare(strict_types=1);
namespace DDD\Domain\Base\Entities;

// 1. DDD framework imports (same package)
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;

// 2. External library imports
use Symfony\Component\Validator\Constraints\Email;

// 3. PHP built-in
use ReflectionException;
```

### Service Instantiation

**ALWAYS** use container-based service resolution. NEVER use `new ServiceClass()` directly. NEVER cache services in class properties.

```php
// Via Entity shorthand
$service = Accounts::getService();

// Via DDDService/AppService
/** @var AccountsService $service */
$service = AppService::instance()->getService(AccountsService::class);
```

### Database Management Rules

**CRITICAL: NEVER create migrations, SQL statements, or any database management artifacts.**

Database tables and schema changes are managed **manually and separately** by the developer. The framework only generates PHP model classes:

- No `doctrine:migrations:*` commands
- No `doctrine:schema:update` commands
- No SQL `CREATE TABLE` / `ALTER TABLE` statements
- No migration files

**What the framework DOES:**
- Creates PHP entity files
- Creates PHP repository files
- Creates PHP service files
- Generates `DB*Model.php` Doctrine model classes (auto-generated, never edit manually)

### Entity Attributes Reference

- `#[LazyLoad]` -- Deferred loading of relationships
- `#[LazyLoadRepo]` -- Bind entity to repository class
- `#[ChangeHistory]` -- Automatic audit trail
- `#[QueryOptions]` -- Support for OData-like queries
- `#[Translatable]` -- Multi-language support
- `#[DatabaseColumn]` -- Column mapping and SQL type control
- `#[DatabaseVirtualColumn]` -- Computed columns
- `#[DatabaseIndex]` -- Index definitions
- `#[DatabaseForeignKey]` -- Foreign key relationships
- `#[HideProperty]` -- Exclude from serialization
- `#[HidePropertyOnSystemSerialization]` -- Exclude from DB persistence
- `#[NoRecursiveUpdate]` -- Prevent cascade updates from parent
- `#[RolesRequiredForUpdate]` -- Role-based write authorization
- `#[SubclassIndicator]` -- Single Table Inheritance (discriminator pattern)
- `#[DatabaseTrigger]` -- SQL trigger integration
- `#[OverwritePropertyName]` -- Rename property in serialized output
- `#[ExposePropertyInsteadOfClass]` -- Flatten nested objects in serialization
- `#[Aliases]` -- Backward-compatible property aliases
- `#[DontPersistProperty]` -- Exclude from DB persistence (visible in API)
- `#[RequestCache]` -- GET endpoint response caching with TTL

---

## Key Files Reference

| What | Where |
|------|-------|
| Entity base class | `src/Domain/Base/Entities/Entity.php` |
| DefaultObject base | `src/Domain/Base/Entities/DefaultObject.php` |
| EntitySet base | `src/Domain/Base/Entities/EntitySet.php` |
| ValueObject base | `src/Domain/Base/Entities/ValueObject.php` |
| LazyLoad system | `src/Domain/Base/Entities/LazyLoad/` |
| ChangeHistory | `src/Domain/Base/Entities/ChangeHistory/` |
| QueryOptions | `src/Domain/Base/Entities/QueryOptions/` |
| Translatable | `src/Domain/Base/Entities/Translatable/` |
| DB Entity repo | `src/Domain/Base/Repo/DB/DBEntity.php` |
| DB EntitySet repo | `src/Domain/Base/Repo/DB/DBEntitySet.php` |
| Database model gen | `src/Domain/Base/Repo/DB/Database/DatabaseModel.php` |
| Doctrine integration | `src/Domain/Base/Repo/DB/Doctrine/` |
| Virtual repo | `src/Domain/Base/Repo/Virtual/VirtualEntity.php` |
| EntitiesService | `src/Domain/Base/Services/EntitiesService.php` |
| DDDService | `src/Infrastructure/Services/DDDService.php` |
| Service base class | `src/Infrastructure/Services/Service.php` |
| HttpController | `src/Presentation/Base/Controller/HttpController.php` |
| RequestDto | `src/Presentation/Base/Dtos/RequestDto.php` |
| RestResponseDto | `src/Presentation/Base/Dtos/RestResponseDto.php` |
| OpenAPI support | `src/Presentation/Base/OpenApi/` |
| Config system | `src/Infrastructure/Libs/Config.php` |
| ClassFinder | `src/Infrastructure/Libs/ClassFinder.php` |
| Module system | `src/Infrastructure/Modules/DDDModule.php` |
| Module compiler pass | `src/Symfony/CompilerPasses/ModuleCompilerPass.php` |
| DDDBundle | `src/DDDBundle.php` |
| DDDKernel | `src/Symfony/Kernels/DDDKernel.php` |

---

## Module System

### How Modules Work

DDD modules are Composer packages that self-register. A module declares itself via `composer.json`:

```json
{
  "extra": {
    "ddd-module": "Vendor\\MyModule\\MyDDDModule"
  }
}
```

The module class extends `DDDModule`:

```php
abstract class DDDModule
{
    abstract public static function getSourcePath(): string;      // Required: path to src/
    public static function getConfigPath(): ?string               // Optional: config directory
    public static function getPublicServiceNamespaces(): array    // Optional: public services
    public static function getExcludePatterns(): array            // Optional: autowiring exclusions
    public static function getControllerPaths(): array            // Optional: controller directories
}
```

**Discovery:** `ModuleCompilerPass` reads `vendor/composer/installed.json`, finds packages with `extra.ddd-module`, validates the class exists and extends `DDDModule`, then auto-registers all module services.

**Entity Discovery:** `EntityModelGeneratorService` scans framework, application, AND module `Domain/` directories for entity classes when generating database models.

**Config Priority:** App configs > Module configs > Framework configs.

---

## Common Operations

```php
// Entity CRUD
$entity = EntityName::byId($id);
$entity->name = 'Updated';
$entity = $entity->update();
$entity->delete();

// Service access
$service = EntityNames::getService();

// Collection operations
$set = $service->findAll();
$set->expand();
$ids = $set->getEntityIds();
$first = $set->first();

// QueryOptions (programmatic)
$originalQO = clone EntityNames::getDefaultQueryOptions();
$filters = FiltersOptions::fromString("status eq 'ACTIVE'");
EntityNames::getDefaultQueryOptions()->setFilters($filters);
$results = $service->findAll();
EntityNames::setDefaultQueryOptions($originalQO);  // Always restore
```

---

## Infrastructure Utilities Reference

The framework provides these cross-cutting utilities available from any layer:

### Configuration

```php
use DDD\Infrastructure\Libs\Config;

$value = Config::get('database.host');              // Dot-notation config path
$env = Config::getEnv('DATABASE_URL');              // Env var with type coercion (parses "true"/"false", numerics)
Config::addConfigDirectory($path, isModule: true);  // Register config directory (app > module priority)
```

### Caching

```php
use DDD\Infrastructure\Cache\Cache;

$cache = Cache::instance();                         // Auto-selects backend from config (APC, Redis, PhpFiles)
// Backends: Apc (fastest, single-server), Redis (distributed), PhpFiles (no dependencies)
// TTL constants: CACHE_TTL_ONE_HOUR, CACHE_TTL_ONE_DAY, CACHE_TTL_TEN_MINUTES, CACHE_TTL_ONE_WEEK
```

### Encryption & Hashing

```php
use DDD\Infrastructure\Libs\Encrypt;

$encrypted = Encrypt::encrypt($data, $password);    // AES-256-CBC with random IV
$decrypted = Encrypt::decrypt($encrypted, $password);
$hashed = Encrypt::hashWithSalt($data);             // SHA256 with PASSWORD_HASH env salt
```

### JWT Tokens

```php
use DDD\Infrastructure\Libs\JWTPayload;

$jwt = JWTPayload::createJWTFromParameters(['userId' => 42], validityInSeconds: 3600);
$params = JWTPayload::getParametersFromJWT($jwt);   // Returns params or false if expired
```

### Text Processing

```php
use DDD\Infrastructure\Libs\StringFuncs;

StringFuncs::generateAlias('Hello World!');          // 'hello-world'
StringFuncs::shortenText($text, 100);               // Truncate at word boundary
StringFuncs::generateRandomString(32);              // Random token/password
StringFuncs::isJson($string);                       // JSON validation

use DDD\Infrastructure\Libs\Datafilter;

Datafilter::cleanUrl($url);                         // Normalize URL
Datafilter::validEmail($email);                     // Validate email
Datafilter::validatePhoneNumber($phone);            // International phone validation
Datafilter::filter_diacritics($text);               // Remove diacritics
```

### Translation

```php
$translated = __('greeting.hello', 'de', 'DE', 'FORMAL', ['%name%' => 'Max']);
```

### Cron Jobs

Entities `Cron` and `CronExecution` in `Domain/Common/Entities/Crons/` provide scheduled task execution via Symfony console commands. Managed by `app:crons:execute` and `app:crons:list` commands.

### Common Value Objects

| Class | Purpose |
|-------|---------|
| `File` | File upload metadata (name, mimeType, path, error) |
| `Person` | Person details (name, title, gender) with name combination generation |
| `Roles` | Role collection with `isAdmin()` and `hasRoles()` checks |

---

## Best Practices

1. **Always strict types** -- `declare(strict_types=1)` in every file, no exceptions
2. **Type everything** -- properties, parameters, returns; use PHPDoc `@var` for arrays
3. **Never `private`** -- always `protected`; this is a framework, everything gets extended
4. **Constants over magic values** -- `self::STATUS_ACTIVE` not `'ACTIVE'`
5. **Lazy load expensive relations** -- `#[LazyLoad]` eliminates manual finder methods
6. **Validate at entity level** -- constraint attributes on properties, not in controllers
7. **Pass entity objects, not IDs** -- domain logic operates on rich objects
8. **Entities own domain logic** -- services own only repo-dependent logic for their own entity type
9. **Never cache services in properties** -- always resolve inline from the container
10. **Never manually edit `DB*Model.php`** -- they are auto-generated from entity attributes

---

## Troubleshooting

| Problem | Check |
|---------|-------|
| Entity not loading | `#[LazyLoadRepo]` attribute, repo class exists, `uniqueKey()` implemented |
| Service method failing | Extends `EntitiesService`, `DEFAULT_ENTITY_CLASS` set |
| Validation errors | Constraint attributes on properties |
| DB model issues | Regenerate models (consumer app command) |
| Module not discovered | `extra.ddd-module` in composer.json, class extends `DDDModule` |
| Config not loading | Check `Config::addConfigDirectory()` call order, app > module > framework priority |
| N+1 queries | Use `#[LazyLoad]` and `$expand` query options |
