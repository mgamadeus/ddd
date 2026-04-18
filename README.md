# mgamadeus/ddd

A Domain-Driven Design entity framework built on top of Symfony 7.3 and Doctrine ORM for PHP 8.3+.

## Overview

`mgamadeus/ddd` provides a complete DDD stack for building PHP applications:

- **Entities & Value Objects** with identity, validation, serialization, parent-child relationships, and single table inheritance
- **Lazy Loading** via `#[LazyLoad]` attributes -- relationships load on-demand from DB, Virtual, or Class Method repositories
- **Repository Pattern** with DB (Doctrine), Virtual, and Class Method repository types, plus auto-generated ORM model classes
- **Service Layer** with `EntitiesService` for entity management and `Service` for cross-cutting concerns
- **OData-Style QueryOptions** -- `$filter`, `$select`, `$expand`, `$orderBy`, `$top`, `$skip` with 11 filter operators (eq, ne, gt, ge, lt, le, in, ni, bw, ft, fb)
- **Rights Protection** -- query-level access control via `applyReadRightsQuery()`, `applyUpdateRightsQuery()`, `applyDeleteRightsQuery()`
- **Multi-Language Support** via `#[Translatable]` with JSON storage, fulltext search indexes, and language/country/writing-style context
- **Change History** -- automatic `created`/`updated` timestamps via `ChangeHistoryTrait`
- **REST Presentation Layer** -- `HttpController`, typed DTOs (Request/Response), OpenAPI 3.0 documentation, request caching, specialized response types (Excel, PDF, ZIP, Image, HTML, Redirect)
- **Async Processing** -- Symfony Messenger integration with `AppMessage`/`AppMessageHandler` base classes, auth context propagation, and workspace routing
- **Module System** -- Composer packages self-register as DDD modules with automatic service discovery and entity inclusion
- **CLI Commands** -- Console command infrastructure with admin auth context, batch processing, progress tracking, and cron job management
- **Infrastructure Utilities** -- Config management, caching (APC/Redis/PhpFiles), encryption, JWT, text processing, input filtering, internationalized domain names

## Requirements

- PHP >= 8.3
- Symfony 7.3
- Doctrine ORM ^2.13
- Extensions: `ctype`, `dom`, `gd`, `iconv`, `libxml`, `openssl`, `zlib`, `imagick`

## Installation

```bash
composer require mgamadeus/ddd
```

Register the bundle in your Symfony application:

```php
// config/bundles.php
return [
    // ...
    DDD\DDDBundle::class => ['all' => true],
];
```

## Quick Start

### 1. Entity

```php
<?php
declare(strict_types=1);
namespace App\Domain\Common\Entities\Products;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Validation\Constraints\Length;
use App\Domain\Common\Repo\DB\Products\DBProduct;

/**
 * @method static ProductsService getService()
 * @method static DBProduct getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBProduct::class)]
class Product extends Entity
{
    use ChangeHistoryTrait;

    public ?int $id = null;

    #[Length(max: 255)]
    public string $name;

    public ?string $description = null;

    public ?int $categoryId = null;

    #[LazyLoad]
    public ?Category $category;

    public function uniqueKey(): string
    {
        return parent::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
```

### 2. EntitySet (Collection)

```php
<?php
declare(strict_types=1);
namespace App\Domain\Common\Entities\Products;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use App\Domain\Common\Repo\DB\Products\DBProducts;
use App\Domain\Common\Services\ProductsService;

/**
 * @property Product[] $elements
 * @method Product first()
 * @method static ProductsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBProducts::class)]
class Products extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = ProductsService::class;
}
```

### 3. Repositories

```php
// DB Repo -- Single Entity
class DBProduct extends DBEntity
{
    public const BASE_ENTITY_CLASS = Product::class;
    public const BASE_ORM_MODEL = DBProductModel::class;  // Auto-generated, never edit
}

// DB Repo -- Entity Set
class DBProducts extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBProduct::class;
    public const BASE_ENTITY_SET_CLASS = Products::class;
}
```

### 4. Service

```php
<?php
declare(strict_types=1);
namespace App\Domain\Common\Services;

use App\Domain\Common\Entities\Products\Product;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * @method Product find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method Products findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 */
class ProductsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = Product::class;
}
```

### 5. Use It

```php
// CRUD
$product = Product::byId(42);
echo $product->category->name;  // Lazy-loaded automatically
$product->name = 'Updated';
$product->update();              // Validates and persists
$product->delete();

// Service access
$service = Products::getService();
$allProducts = $service->findAll();

// Programmatic QueryOptions
$originalQO = clone Products::getDefaultQueryOptions();
$filters = FiltersOptions::fromString("status eq 'ACTIVE'");
Products::getDefaultQueryOptions()->setFilters($filters)->setTop(100);
$activeProducts = $service->findAll();
Products::setDefaultQueryOptions($originalQO);  // Always restore
```

## Key Features

### Lazy Loading

Relationships load automatically on property access -- no manual finder methods needed:

```php
#[LazyLoad]                                                    // DB (default)
public ?Category $category;

#[LazyLoad(repoType: LazyLoadRepo::CLASS_METHOD, loadMethod: 'computeTotal')]
public ?Money $total;                                          // Custom method

#[LazyLoad(repoType: LazyLoadRepo::VIRTUAL, loadMethod: 'lazyloadRankings')]
public Rankings $rankings;                                     // Virtual/computed

#[LazyLoad(addAsParent: true)]
public ?ParentEntity $parent;                                  // Parent-child

#[LazyLoad(loadThrough: IntermediaryEntities::class)]
public ?RelatedEntities $related;                              // N-N via junction
```

### QueryOptions (OData-Style Filtering)

Applied at the database level with 11 filter operators:

```
GET /api/products?$filter=status eq 'ACTIVE' and price gt '10'&$select=id,name&$expand=category(select=id,name)&$orderBy=name asc&$top=20
```

| Operator | Meaning | Example |
|----------|---------|---------|
| `eq` / `ne` | Equals / Not equals | `status eq 'ACTIVE'` |
| `gt` / `ge` / `lt` / `le` | Comparison | `price gt '100'` |
| `in` / `ni` | In / Not in list | `status in ['ACTIVE','PENDING']` |
| `bw` | Between | `date bw ['2026-01-01','2026-12-31']` |
| `ft` | Fulltext (natural language) | `name ft 'search terms'` |
| `fb` | Fulltext (boolean, prefix matching) | `name fb 'alm*'` |

Expand supports nested clauses: `$expand=zones(filters=isActive eq 'true';orderBy=name asc;top=50;expand=tables)`

### Rights Protection

Query-level access control via overridable methods in DB repositories:

```php
class DBProduct extends DBEntity
{
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) return false;
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount) {
            $alias = static::getBaseModelAlias();
            $queryBuilder->andWhere("{$alias}.id is null");
            return true;
        }
        if ($authAccount?->roles?->isAdmin()) return true;
        // Non-admin restrictions...
        return parent::applyReadRightsQuery($queryBuilder);
    }
}
```

### Multi-Language (Translatable)

```php
class Product extends Entity
{
    use TranslatableTrait;

    #[Translatable]
    public string $name;

    #[Translatable(fullTextIndex: true)]  // Enables ft/fb search operators
    public ?string $description = null;
}
```

JSON storage with language/country/writing-style context. Fulltext indexes auto-generate virtual search columns.

### REST Controllers & DTOs

```php
#[Route('/api/products')]
#[Tag(group: 'Catalog', name: 'Products')]
class ProductsController extends HttpController
{
    #[Get('/list')]
    #[Summary('Products List')]
    public function list(
        ProductsGetRequestDto &$requestDto,
        ProductsService $productsService
    ): ProductsGetResponseDto {
        Products::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);
        $productsService->throwErrors = true;

        $responseDto = new ProductsGetResponseDto();
        $responseDto->products = $productsService->findAll();
        $responseDto->products->expand();
        return $responseDto;
    }
}
```

Response types beyond JSON: `ExcelResponseDto`, `PDFResponseDto`, `ImageResponseDto`, `ZipResponseDto`, `FileResponseDto`, `HtmlResponseDto`, `RedirectResponseDto`.

### Async Processing (Symfony Messenger)

```php
// Message
class ProcessItemMessage extends AppMessage
{
    public static string $messageHandler = ProcessItemHandler::class;
    public ?int $itemId = null;

    public function __construct(?int $itemId = null) {
        parent::__construct();
        $this->itemId = $itemId;
    }
}

// Handler
#[AsMessageHandler(fromTransport: 'process_item')]
class ProcessItemHandler extends AppMessageHandler
{
    public function __invoke(ProcessItemMessage $message): void {
        $this->setAuthAccountFromMessage($message);
        if ($message->processOnWorkspaceIfNecessary()) return;
        // Process...
    }
}

// Dispatch from service
public function processItem(int $itemId, bool $async = false): void {
    if ($async) { (new ProcessItemMessage($itemId))->dispatch(); return; }
    // Synchronous work...
}
```

### Module System

Composer packages self-register as DDD modules:

```json
{ "extra": { "ddd-module": "Vendor\\MyModule\\MyDDDModule" } }
```

```php
class MyDDDModule extends DDDModule
{
    public static function getSourcePath(): string { return __DIR__ . '/../src'; }
    public static function getConfigPath(): ?string { return __DIR__ . '/../config/app'; }
}
```

Modules are discovered automatically. Services auto-registered. Entities included in model generation. Config directories integrated with app > module > framework priority.

### CLI Commands

```php
#[AsCommand(name: 'app:recalculate', description: 'Recalculates data')]
class RecalculateCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        AuthService::instance()->setAccount($defaultAccount);

        $service = MyEntities::getService();
        $service->recalculate(new SymfonyStyle($input, $output));
        return Command::SUCCESS;
    }
}
```

Framework-provided commands: `app:generate-doctrine-models-for-entities`, `app:process-cli-message`, `app:crons:execute`, `app:crons:list`.

### Entity Attributes

| Attribute | Purpose |
|-----------|---------|
| `#[LazyLoadRepo]` | Bind entity to repository class |
| `#[LazyLoad]` | Deferred relationship loading |
| `#[ChangeHistory]` | Automatic created/updated timestamps |
| `#[Translatable]` | Multi-language properties with fulltext search |
| `#[QueryOptions]` | Custom OData-style query support |
| `#[DatabaseColumn]` | Column mapping and SQL type control |
| `#[DatabaseVirtualColumn]` | Computed/extracted database columns |
| `#[DatabaseIndex]` | Index definitions (unique, composite) |
| `#[DatabaseForeignKey]` | Foreign key relationships |
| `#[DatabaseTrigger]` | SQL trigger integration |
| `#[SubclassIndicator]` | Single Table Inheritance (discriminator) |
| `#[HideProperty]` | Exclude from API serialization |
| `#[HidePropertyOnSystemSerialization]` | Exclude from DB persistence |
| `#[DontPersistProperty]` | Exclude from persistence (visible in API) |
| `#[OverwritePropertyName]` | Rename property in serialized output |
| `#[Aliases]` | Backward-compatible property aliases |
| `#[NoRecursiveUpdate]` | Prevent cascade updates from parent |
| `#[RolesRequiredForUpdate]` | Role-based write authorization |
| `#[RequestCache]` | GET endpoint response caching |
| `#[Choice]` | Enum-like validation with dynamic choices |
| `#[Length]` | String length validation |
| `#[UniqueProperty]` | Database uniqueness validation |

### Infrastructure Utilities

```php
Config::get('database.host');                          // Hierarchical config (dot-notation)
Config::getEnv('DATABASE_URL');                        // Env with type coercion
Cache::instance();                                     // Auto-selects APC/Redis/PhpFiles
Encrypt::encrypt($data, $password);                    // AES-256-CBC
JWTPayload::createJWTFromParameters($params, 3600);   // JWT tokens
StringFuncs::generateAlias('Hello World!');             // URL slugs
Datafilter::validEmail($email);                        // Input validation
__('key', 'de', 'DE', 'FORMAL', ['%name%' => 'Max']); // Translation
```

## Project Structure (Consuming Application)

```
your-app/
+-- src/
|   +-- Domain/                              # Business logic (DDD)
|   |   +-- {DomainName}/
|   |       +-- Entities/{Group}/            # Entity.php, Entities.php
|   |       +-- Repo/DB/{Group}/             # DBEntity.php, DBEntities.php (+ auto-generated Model)
|   |       +-- Services/                    # EntitiesService.php
|   |       +-- MessageHandlers/             # AppMessage.php, AppMessageHandler.php
|   +-- Infrastructure/                      # Cross-cutting: AuthService, AppService
|   +-- Presentation/Api/                    # Controllers & DTOs
|   |   +-- Admin/                           # Admin endpoints (ROLE_ADMIN)
|   |   +-- Client/                          # Client endpoints (JWT)
|   |   +-- Public/                          # Public endpoints (no auth)
|   |   +-- Batch/                           # Integration endpoints
|   +-- Symfony/Commands/                    # Console commands
+-- config/
|   +-- app/                                 # App-specific config
|   +-- symfony/default/                     # services.yaml, routes.yaml, messenger.yaml
+-- vendor/mgamadeus/ddd/src/                # This framework
```

### Domain Directory Pattern

```
Domain/{DomainName}/
+-- Entities/{Group}/{Entity}.php, {Entity}s.php
+-- Repo/DB/{Group}/DB{Entity}.php, DB{Entity}s.php, DB{Entity}Model.php (auto-generated)
+-- Services/{Entity}sService.php
+-- MessageHandlers/{Action}Message.php, {Action}Handler.php

Presentation/Api/{Audience}/{DomainName}/
+-- Controller/{Entity}Controller.php
+-- Dtos/{Entity}*Dto.php
```

## Conventions

- **Always** `declare(strict_types=1)` in every file
- **Never** use `private` -- always `protected` (the framework is built for extensibility)
- **Never** manually edit `DB*Model.php` files (auto-generated from entity attributes)
- **Never** cache services in class properties -- always resolve from the container
- **Never** use PHP's `\DateTime` -- use `DDD\Infrastructure\Base\DateTime\DateTime` or `Date`
- **Always** pass entity objects to functions, not raw IDs
- Traits are comma-separated on a single line: `use TraitA, TraitB;`
- Constants over magic values: `self::STATUS_ACTIVE` not `'ACTIVE'`
- Services via container: `Products::getService()` or `AppService::instance()->getService(ProductsService::class)`

## Object Hierarchy

```
BaseObject (abstract)
  +- DefaultObject -- SerializerTrait, ValidatorTrait, ParentChildrenTrait, LazyLoadTrait, ReflectorTrait
      +- Entity -- domain entities with identity
      +- ValueObject -- immutable objects
      |   +- ObjectSet -> EntitySet -- typed collections
      +- Other domain objects
```

## License

MIT
