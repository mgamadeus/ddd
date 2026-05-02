---
name: ddd-entity-specialist
description: Create and design DDD entities, entity sets, value objects, DB repositories, lazy loading, relationships, and entity attributes in the mgamadeus/ddd framework. Use when creating, modifying, or reasoning about domain entities and their persistence layer.
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
---

# DDD Entity Specialist

Entities, entity sets, value objects, DB repositories, attributes, lazy loading, and relationships within the DDD Core framework (`mgamadeus/ddd`).

## When to Use

- Creating or modifying domain entities, entity sets, value objects
- Creating DB repository classes (single + set)
- Configuring entity attributes (LazyLoad, DatabaseColumn, Translatable, ChangeHistory, etc.)
- Designing entity relationships (parent-child, lazy loading, N-N)
- Understanding the object hierarchy and entity lifecycle

## Namespace

All code uses the `DDD\` root namespace (not `App\`). The autoload maps `DDD\` to `src/`.

## File Locations & Creation Order

| # | Component | File | Location |
|---|-----------|------|----------|
| 1 | Entity | `{EntityName}.php` | `src/Domain/{Domain}/Entities/{Group}/` |
| 2 | EntitySet | `{EntityName}s.php` | `src/Domain/{Domain}/Entities/{Group}/` |
| 3 | DB Repo (single) | `DB{EntityName}.php` | `src/Domain/{Domain}/Repo/DB/{Group}/` |
| 4 | DB Repo (set) | `DB{EntityName}s.php` | `src/Domain/{Domain}/Repo/DB/{Group}/` |

Services are covered by the `ddd-service-specialist` skill.

### Domain Placement

| Domain | Purpose | Location |
|--------|---------|----------|
| `Base` | Abstract base classes, traits, core framework behavior | `src/Domain/Base/` |
| `Common` | Shared concrete entities used across applications | `src/Domain/Common/` |

## Critical Rules

### NEVER Modify Auto-Generated Files

`DB{EntityName}Model.php` files are **auto-generated** from entity attributes. Never create, modify, or delete them manually.

### NEVER Use `private` -- ALWAYS `protected`

The `private` keyword **destroys extensibility** by preventing subclasses from overriding or accessing members. This is a DDD framework -- every class may be extended by consuming applications or modules. Properties, methods, constants -- ALL must be `protected` (or `public` where appropriate). **No exceptions, no edge cases, no "but this is internal".** If you write `private`, you are creating a wall that future developers cannot work around without forking the class.

### NEVER Use PHP's Native `\DateTime`

**ALWAYS** use the DDD framework classes instead:

| Use Case | DDD Class | Serializes As |
|----------|-----------|---------------|
| Date (no time) | `DDD\Infrastructure\Base\DateTime\Date` | `Y-m-d` |
| DateTime (with time) | `DDD\Infrastructure\Base\DateTime\DateTime` | `Y-m-d H:i:s` |

**Why:** The framework's `Date` and `DateTime` classes provide proper JSON serialization (`jsonSerialize()`), string conversion, factory methods (`fromString()`, `fromTimestamp()`), and are recognized by the Autodocumenter for correct OpenAPI type generation. Native `\DateTime` breaks serialization, produces wrong OpenAPI types, and bypasses framework formatting.

```php
public ?DateTime $createdAt = null;   // CORRECT
public ?\DateTime $createdAt = null;  // WRONG -- native PHP DateTime
```

### Other Critical Rules

- Multiple traits MUST be comma-separated on a single line: `use TraitA, TraitB;`
- `#[LazyLoadRepo]` is required on **both** Entity and EntitySet classes
- Implement `uniqueKey()` in every entity
- `EntitiesBaseService` does **NOT** exist -- always use `EntitiesService`

### Loading Entities by ID -- ALWAYS via the Service

Caller code (controllers, message handlers, other services, CLI commands) that needs to load a single entity by id MUST go through the entity service:

```php
// CORRECT -- single entity, by id, from anywhere outside the owning service
$pushNotification = PushNotification::getService()->find($pushNotificationId);
$account = Accounts::getService()->find($accountId);
```

- Use the **entity** class (singular: `PushNotification`) for `find($id)` returning one entity.
- Use the **entity-set** class (plural: `Accounts`) when you intend `find($queryBuilder)` returning a set, **or** when the framework registers the lookup helper on the set class -- both forms are valid `getService()->find($id)` callsites in this codebase.
- Going through `getService()` applies rights restrictions (`applyReadRightsQuery`), entity registry caching, and any service-level invariants.

```php
// WRONG -- bypasses the service layer's rights / lazy-load / cache wiring
$pushNotification = PushNotifications::getRepoClassInstance()->find($id); // also: this is the EntitySet repo, whose find() expects a QueryBuilder
$account = Accounts::getRepoClassInstance()->find($id);
```

Direct repo access (`getEntityRepoClassInstance()` / `getEntitySetRepoClassInstance()`) belongs **inside service methods** running custom QueryBuilder queries -- see the ddd-service-specialist skill for that pattern. Caller code never needs it.

## Step 0: Load Domain Context

Before creating any entity:

1. **Identify the domain** (Base or Common)
2. **Read existing entities** in the target domain
3. **Read related repositories** if the entity requires DB persistence

---

## Object Hierarchy

```
BaseObject (abstract)
  +- DefaultObject -- uses SerializerTrait, ValidatorTrait, ParentChildrenTrait, LazyLoadTrait, ReflectorTrait
      +- Entity -- domain entities with identity (EntityTrait)
      +- ValueObject -- immutable objects (ValueObjectTrait)
      |   +- ObjectSet -> EntitySet -> typed collections
      |   +- custom value objects
      +- Other domain objects
```

---

## 1. Entity Template

**Path:** `src/Domain/{DomainName}/Entities/{Group}/{EntityName}.php`

```php
<?php
declare(strict_types=1);
namespace DDD\Domain\{DomainName}\Entities\{Group};

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Infrastructure\Validation\Constraints\Length;
use DDD\Domain\{DomainName}\Repo\DB\{Group}\DB{EntityName};

/**
 * @method static {EntityName}sService getService()
 * @method static DB{EntityName} getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DB{EntityName}::class)]
class {EntityName} extends Entity
{
    public const string STATUS_ACTIVE = 'ACTIVE';
    public const string STATUS_INACTIVE = 'INACTIVE';

    public ?int $id = null;

    #[Length(max: 255)]
    public string $name;

    #[Choice(choices: [self::STATUS_ACTIVE, self::STATUS_INACTIVE])]
    #[Length(max: 16)]
    public string $status = self::STATUS_ACTIVE;

    #[Length(max: 1000)]
    public ?string $description = null;

    public ?int $parentId = null;

    #[LazyLoad]
    public ?ParentEntity $parent;

    #[LazyLoad]
    public ?ChildEntities $children;

    public ?DateTime $createdAt = null;
    public ?DateTime $updatedAt = null;

    public function uniqueKey(): string
    {
        return parent::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
```

---

## 2. EntitySet Template

**Path:** `src/Domain/{DomainName}/Entities/{Group}/{EntityName}s.php`

```php
<?php
declare(strict_types=1);
namespace DDD\Domain\{DomainName}\Entities\{Group};

use DDD\Domain\{DomainName}\Repo\DB\{Group}\DB{EntityName}s;
use DDD\Domain\{DomainName}\Services\{EntityName}sService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * Collection of {EntityName} entities
 *
 * @property {EntityName}[] $elements
 * @method {EntityName} getByUniqueKey(string $uniqueKey)
 * @method {EntityName}[] getElements()
 * @method {EntityName} first()
 * @method static {EntityName}sService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DB{EntityName}s::class)]
class {EntityName}s extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = {EntityName}sService::class;
}
```

`#[LazyLoadRepo]` on EntitySets is **mandatory** -- binds the collection to its repository.

`QueryOptionsTrait` is required on **all** entities and entity sets exposed through API endpoints (see `ddd-query-options-specialist`).

---

## 3. DB Repository -- Single Entity

**Path:** `src/Domain/{DomainName}/Repo/DB/{Group}/DB{EntityName}.php`

```php
<?php
declare(strict_types=1);
namespace DDD\Domain\{DomainName}\Repo\DB\{Group};

use DDD\Domain\{DomainName}\Entities\{Group}\{EntityName};
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method {EntityName} find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method {EntityName} update(DefaultObject &$entity, int $depth = 1)
 * @property DB{EntityName}Model $ormInstance
 */
class DB{EntityName} extends DBEntity
{
    public const BASE_ENTITY_CLASS = {EntityName}::class;
    public const BASE_ORM_MODEL = DB{EntityName}Model::class;
}
```

## 4. DB Repository -- Entity Set

**Path:** `src/Domain/{DomainName}/Repo/DB/{Group}/DB{EntityName}s.php`

```php
<?php
declare(strict_types=1);
namespace DDD\Domain\{DomainName}\Repo\DB\{Group};

use DDD\Domain\{DomainName}\Entities\{Group}\{EntityName}s;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method {EntityName}s find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DB{EntityName}s extends DBEntitySet
{
    public const BASE_REPO_CLASS = DB{EntityName}::class;
    public const BASE_ENTITY_SET_CLASS = {EntityName}s::class;
}
```

`BASE_ORM_MODEL` does **NOT** belong on DBEntitySet -- only on `DBEntity`.

---

## Entity Attributes Reference

### Class-Level

| Attribute | Purpose |
|-----------|---------|
| `#[LazyLoadRepo(LazyLoadRepo::DB, DBEntity::class)]` | Bind to repository |
| `#[QueryOptions]` | Enable custom filtering (only if entity needs custom filters beyond standard) |
| `#[ChangeHistory]` | Customize column names (rare -- trait alone suffices) |
| `#[NoRecursiveUpdate]` | Prevent recursive updates from parent |
| `#[RolesRequiredForUpdate(Role::SUPERADMIN)]` | Restrict updates to specific roles |

### Property-Level

```php
// Lazy loading
#[LazyLoad]                                          // Load from default repo
#[LazyLoad(repoType: LazyLoadRepo::VIRTUAL)]         // Virtual repo
#[LazyLoad(repoType: LazyLoadRepo::CLASS_METHOD, loadMethod: 'getCustomValue')]

// Database (from DDD\Domain\Base\Repo\DB\Database namespace)
#[DatabaseColumn(ignoreProperty: true)]              // Not persisted
#[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
#[DatabaseVirtualColumn(as: '(IFNULL(propertyName, 0))')]
#[DatabaseForeignKey(onUpdateAction: DatabaseForeignKey::ACTION_NO_ACTION)]

// Validation
#[Choice(choices: ['A', 'B'])]
#[Length(max: 255)]

// Serialization & Security
#[HideProperty]                       // Exclude from JSON/API responses
#[HidePropertyOnSystemSerialization]  // Exclude from DB persistence
```

**`#[HideProperty]` vs `#[HidePropertyOnSystemSerialization]`:**

| Attribute | API Output | DB Storage | Use Case |
|-----------|-----------|------------|----------|
| `#[HideProperty]` | Hidden | Saved | Passwords, API keys |
| `#[HidePropertyOnSystemSerialization]` | Visible | Not saved | External API data, computed values |
| Both combined | Hidden | Not saved | Internal-only notes |

**Additional serializer attributes** (from `DDD\Infrastructure\Traits\Serializer\Attributes\`):

| Attribute | Purpose |
|-----------|---------|
| `#[OverwritePropertyName('x-custom')]` | Rename property in serialized output |
| `#[ExposePropertyInsteadOfClass('propertyName')]` | Flatten: serialize property value directly instead of wrapping in object |
| `#[Aliases('oldName', 'legacyName')]` | Backward-compatible aliases (copies value to old property names) |
| `#[DontPersistProperty]` | Exclude from persistence serialization (visible in API, not saved to DB) |

### Security Attributes

**`#[NoRecursiveUpdate]`** prevents this entity from being recursively updated when a **parent** entity is updated. Does NOT block own children.

```php
#[NoRecursiveUpdate]
class Location extends Entity {
    // $business->update() will NOT cascade to Locations
    // $location->update() WILL cascade to Location's own children
}
```

### Single Table Inheritance (`#[SubclassIndicator]`)

Map a property value to different entity subclasses (discriminator pattern):

```php
use DDD\Domain\Base\Entities\Attributes\SubclassIndicator;

#[SubclassIndicator(indicators: ['POST' => Post::class, 'EVENT' => Event::class])]
class ContentItem extends Entity
{
    public string $type;  // Discriminator property
    // Shared properties...
}

class Post extends ContentItem { /* Post-specific properties */ }
class Event extends ContentItem { /* Event-specific properties */ }
```

The framework auto-generates the Doctrine `DiscriminatorMap` and creates joined DB schema with parent/child columns. When loading, the correct subclass is instantiated based on the discriminator value.

### Database Triggers (`#[DatabaseTrigger]`)

Integrate SQL triggers with entity lifecycle:

```php
use DDD\Domain\Base\Repo\DB\Database\DatabaseTrigger;

#[DatabaseTrigger(
    executionOrder: DatabaseTrigger::BEFORE,
    executeOnOperations: [DatabaseTrigger::INSERT, DatabaseTrigger::UPDATE]
)]
class MyEntity extends Entity { }
```

SQL is auto-loaded from `Domain/Repo/DB/{Entity}/BeforeInsertTrigger.sql` (or matching combination).

**`#[RolesRequiredForUpdate]`** restricts write operations to specific roles:
```php
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;

#[RolesRequiredForUpdate(Role::ADMIN, Role::SUPERADMIN)]
class SystemConfig extends Entity { }
```
Checked by `DatabaseRepoEntity::canUpdateOrDeleteBasedOnRoles()` before both `update()` and `delete()`. If the authenticated account lacks the required roles, the operation silently returns without persisting.

---

## Database Indexes & Virtual Columns

Nullable fields in unique/composite indexes break integrity (NULL != NULL in SQL). Use virtual columns:

```php
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;

#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['virtualTableNumber', 'locationId'])]
class Table extends Entity {
    public ?int $locationId = null;

    // Property: tableNumber -> Virtual column: virtualTableNumber = IFNULL(tableNumber, 0)
    #[DatabaseVirtualColumn(as: '(IFNULL(tableNumber, 0))')]
    public ?int $tableNumber = null;
}
```

**Rules:**
- `#[DatabaseVirtualColumn]` on property `foo` creates DB column `virtualFoo`
- SQL expression uses original property name: `IFNULL(foo, 0)`
- Indexes reference virtual column name (`virtualFoo`), NOT the property
- Do NOT create a separate PHP property for the virtual column

### JSON Virtual Column Extraction

Extract values from JSON-stored properties into indexed virtual columns:

```php
#[DatabaseColumn(ignoreProperty: true)]
#[DatabaseVirtualColumn(as: "(JSON_UNQUOTE(JSON_EXTRACT(mediaItemContent, '$.contentHash')))", createIndex: true)]
public string $contentHash;
```

Useful for: indexing specific keys from JSON columns, enabling efficient WHERE clauses on nested JSON data.

### Composite Indexes (Non-Virtual)

```php
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;

#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['accountId', 'status', 'createdAt'])]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['externalId'])]
class Track extends Entity {
    public ?int $accountId = null;
    public string $status = self::STATUS_ACTIVE;
    public ?DateTime $createdAt = null;
    public ?string $externalId = null;
}
```

---

## Junction/Pivot Entities (Many-to-Many)

For M:N relationships, create a thin junction entity with two foreign keys:

```php
#[LazyLoadRepo(LazyLoadRepo::DB, DBIngredientAllergen::class)]
class IngredientAllergen extends Entity
{
    public ?int $id = null;
    public int $ingredientId;
    public int $allergenId;

    #[LazyLoad]
    public ?Ingredient $ingredient;

    #[LazyLoad]
    public ?Allergen $allergen;

    public function uniqueKey(): string
    {
        return parent::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
```

**Key rules:**
- Junction entity gets its own Entity, EntitySet, DB Repo (single + set), and Service
- Junction service is typically pure CRUD (no custom methods needed)
- Use `#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['ingredientId', 'allergenId'])]` to enforce uniqueness
- Parent entities lazy-load the junction: `#[LazyLoad] public ?IngredientAllergens $ingredientAllergens;`
- Loading the related entity through the junction: use `#[LazyLoad(loadThrough: IngredientAllergens::class)]`

---

## Display Ordering Pattern

Entities that appear in ordered lists should have a `displayOrder` property:

```php
class MenuSection extends Entity
{
    public ?int $menuId = null;
    public ?int $parentSectionId = null;
    public int $displayOrder = 0;

    // ...
}
```

The corresponding service provides `getNextDisplayOrder()` (see `ddd-service-specialist`).

---

## Lazy Loading

**Lazy loading makes most service query methods unnecessary.** Relationships load automatically on property access.

### Repository Types

| Type | Constant | Use Case |
|------|----------|----------|
| DB | `LazyLoadRepo::DB` | Database (default) |
| CLASS_METHOD | `LazyLoadRepo::CLASS_METHOD` | Custom logic within entity |
| VIRTUAL | `LazyLoadRepo::VIRTUAL` | Complex loading, N-N relationships |

### DB Loading (Most Common)

```php
class Product extends Entity {
    public ?int $categoryId;
    #[LazyLoad]
    public ?Category $category;  // Loaded via categoryId -> DBCategory
}

$product = Product::byId(1);
echo $product->category->name;  // Auto-loaded on access
```

### Parent-Child Loading

```php
// Child entity
public ?int $parentId;
#[LazyLoad(addAsParent: true)]
public ?ParentEntity $parent;

// Parent entity
#[LazyLoad]
public ?ChildEntities $children;
```

Both directions auto-load -- no manual finder methods needed.

### CLASS_METHOD Loading

```php
#[LazyLoad(repoType: LazyLoadRepo::CLASS_METHOD, loadMethod: 'getCampaigns')]
public ?Campaigns $campaigns;

public function getCampaigns(): ?Campaigns
{
    $campaigns = new Campaigns();
    foreach ($this->getElements() as $element) {
        if (isset($element->campaignId)) {
            $campaigns->add($element->campaign);
        }
    }
    return $campaigns;
}
```

### VIRTUAL Loading (N-N, Complex Queries)

```php
#[LazyLoad(repoType: LazyLoadRepo::VIRTUAL, loadMethod: 'lazyloadForAccount')]
public Participations $participations;
```

Requires a `VirtualEntity` class with the corresponding `lazyload*` method.

### LazyLoad Attribute Options

```php
#[LazyLoad(
    repoType: LazyLoadRepo::DB,
    loadMethod: 'lazyload',
    propertyContainingId: 'customId',
    addAsChild: true,
    addAsParent: false,
    useCache: true,
    repoClass: CustomRepo::class,
    loadThrough: IntermediaryEntities::class,
    entityClassName: SpecificEntity::class
)]
```

---

## Multi-Language Support (Translatable)

```php
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\Translatable\TranslatableTrait;

class Product extends Entity
{
    use TranslatableTrait;

    #[Translatable]
    public string $name;

    #[Translatable]
    public ?string $description = null;
}
```

**Translatable properties ONLY get `#[Translatable]`** -- no `#[Length]`, `#[NotBlank]`, etc. They're stored as JSON.

### Fulltext Search for Translatable Properties

```php
#[Translatable(fullTextIndex: true)]
public ?string $name = null;
```

The DB model generator creates a stored virtual search column with a FULLTEXT index. API consumers filter on the logical property name, but queries target the virtual column.

```
filters=name ft 'search terms'
filters=name fb '+required -excluded'
orderBy=nameScore desc
```

### Translatable Storage & Configuration

**Storage format:** JSON with key `{lang}::{style}`
```json
{"de::FORMAL": "Name DE", "en::FORMAL": "Name EN"}
```

**Configuration (.env):**
```
TRANSLATABLE_ACTIVE_LANGUAGE_CODES=en,de,nl,fr
TRANSLATABLE_ACTIVE_LOCALES=en_US,de_DE,nl_NL,fr_FR
TRANSLATABLE_DEFAULT_LANGUAGE_CODE=en
TRANSLATABLE_DEFAULT_WRITING_STYLE=FORMAL
TRANSLATABLE_FALLBACK_TO_DEFAULT_LANGUAGE_CODE=true
```

**Working with translations:**
```php
Translatable::setCurrentLanguageCode('de');
$name = $product->name;                                    // Current language
$product->getTranslationForProperty('name', 'de');         // Specific language
$product->setTranslationForProperty('name', 'Nombre', 'es');
$product->getTranslationsForProperty('name');              // All translations
Translatable::setTranslationSettingsSnapshot();            // Snapshot
Translatable::restoreTranslationSettingsSnapshot();        // Restore
```

**Fallback:** If translation missing for current language, falls back to `TRANSLATABLE_DEFAULT_LANGUAGE_CODE`.

---

## ChangeHistory Trait

Standard usage (99% of cases) -- trait only, NO attribute:
```php
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;

class Product extends Entity
{
    use ChangeHistoryTrait;
    // Provides: created (DateTime), updated (DateTime)
}
```

Access methods: `$entity->getCreatedTime()`, `$entity->getModifiedTime()`, `$entity->getLastEditTime()`

---

## Common Entity Patterns

### Scoped Entity (Global/Business/Location)

```php
class Ingredient extends Entity
{
    public const string SCOPE_GLOBAL = 'GLOBAL';
    public const string SCOPE_BUSINESS = 'BUSINESS';
    public const string SCOPE_LOCATION = 'LOCATION';

    #[Choice(choices: [self::SCOPE_GLOBAL, self::SCOPE_BUSINESS, self::SCOPE_LOCATION])]
    public string $scope = self::SCOPE_GLOBAL;
    public ?int $businessId = null;
    public ?int $locationId = null;
}
```

### Account-Restricted Entity

```php
class MyEntity extends Entity
{
    public ?int $accountId;
    #[LazyLoad]
    public ?Account $account;
}
```

---

## Entity Methods Reference

**CRUD:**
```php
$entity = {EntityName}::byId($id);
$entity = $entity->update();          // Create or update (validates)
$entity->delete();
```

**Service & Repo access:**
```php
$service = {EntityName}s::getService();
$repo = {EntityName}::getRepoClassInstance();
```

**Identity & Relationships:**
```php
$entity->uniqueKey();
{EntityName}::uniqueKeyStatic($id);
{EntityName}::getEntitySetClass();
{EntityName}::dependsOn($otherEntity);
```

**Object operations (inherited from DefaultObject):**
```php
DefaultObject::isEntity($obj);
$obj1->equals($obj2);                 // Same type & unique key
$obj1->isEqualTo($obj2);             // Deep equality
$entity->overwritePropertiesFromOtherObject($other);
$clone = $entity->clone();
```

## EntitySet Methods Reference

**Basic:** `add()`, `remove()`, `replace()`, `clear()`, `count()`, `isEmpty()`
**Access:** `first()`, `last()`, `rand()`, `getElements()`, `getByUniqueKey()`, `getElementsSlice(start, count)`
**Array access:** `$set[0]`, `isset($set[0])`, `unset($set[0])`
**Contains:** `contains(...)`, `containsOneOf(...)`, `containsSameElements($set2)`
**Sorting:** `$set->sort(fn($a, $b) => $a->displayOrder <=> $b->displayOrder)`
**Merging:** `$set1->mergeFromOtherSet($set2)`
**Entity-specific:** `$set->getEntityIds()`, `$set->update()`, `$set->batchUpdate()`, `$set->delete()`
**Iteration:** EntitySets are `Iterable` -- use `foreach` directly.

## Validation

Entities validate automatically on `update()` based on constraint attributes:
```php
try {
    $entity->update();
} catch (\DDD\Infrastructure\Exceptions\ValidationException $e) {
    // Handle validation error
}
```

### Validation Constraints Reference

**DDD Framework constraints** (from `DDD\Infrastructure\Validation\Constraints\`):

| Constraint | Purpose | Example |
|-----------|---------|---------|
| `#[Choice(choices: [...])]` | Enum-like values (supports callable for dynamic choices) | `#[Choice(choices: [self::STATUS_ACTIVE, self::STATUS_INACTIVE])]` |
| `#[Length(max: 255)]` | String length limits | `#[Length(min: 3, max: 100)]` |
| `#[NoValidation]` | Skip validation for this property | On computed/internal properties |
| `#[UniqueProperty]` | Database uniqueness check | Email, username fields |

**DDD Common validators** (from `DDD\Domain\Common\Validators\`):

| Constraint | Purpose |
|-----------|---------|
| `#[PunctuationLimitConstraint(maxPunctuations: 3)]` | Limit consecutive punctuation |
| `#[NotContainingOnlyDigitsConstraint]` | Reject digit-only strings (names must have letters) |
| `#[NotContainingEmailConstraint]` | Reject text containing emails |
| `#[NotContainingUrlConstraint]` | Reject text containing URLs |

**Symfony constraints** (also available):

```php
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
```

**Translatable properties** -- ONLY get `#[Translatable]`, never validation constraints (they're stored as JSON).
