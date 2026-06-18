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

> `{Group}` resolves per the **Folder Layout** section below — first-class entities sit at the top level of `Entities/`, child / junction entities and value objects nest under their owning parent.

Services are covered by the `ddd-service-specialist` skill.

### Domain Placement

| Domain | Purpose | Location |
|--------|---------|----------|
| `Base` | Abstract base classes, traits, core framework behavior | `src/Domain/Base/` |
| `Common` | Shared concrete entities used across applications | `src/Domain/Common/` |

## Folder Layout — First-class vs Child Entities

Every entity is one of two kinds. The classification dictates where the files live on disk.

**First-class entity:** has its own admin URL / CRUD UI, can be referenced from many places, and exists conceptually on its own. Gets its own top-level folder named after the EntitySet (plural): `Entities/{EntityNamePlural}/`.

Examples in the catalog domain: `Ingredient`, `Product`, `Menu`, `Allergen`, `ProductCategory`.

**Child entity:** exists only as part of a parent first-class entity. Includes:
- Junction entities for M:N relations whose write surface is "edit the parent and its children together" (e.g. `IngredientAllergen` is edited inside the Ingredient form, not standalone).
- 1:N children where the child has no standalone identity (e.g. `MenuSection` only exists inside a `Menu`).

Child entities are nested under the owning parent's folder: `Entities/{OwnerPlural}/{ChildEntityPlural}/`.

### Picking the owner for an M:N junction

The owner is the side that **manages** the relation in the admin UI — the side whose form contains the picker for the other side. For `IngredientAllergen`, that's `Ingredient` (the ingredient form lets you pick allergens, not the reverse). For `MenuSectionProduct`, that's `MenuSection`.

Tie-breakers when both sides could plausibly own the relation:

1. The side that holds the LazyLoad EntitySet collection in the parent direction wins.
2. The side whose deletion cascade-deletes the junction wins.
3. The side first introduced to the domain wins (cohesion with existing code).

### Value objects

Value objects embedded on an entity live under a meaningful subfolder of the owning entity: `Entities/{OwnerPlural}/{ValueObjectGroup}/{ValueObject}.php`. The group name describes the conceptual cluster (e.g. `Nutrition/`, `Pricing/`, `Geometry/`), not the VO type. A single-VO group is still a group — it's there for future cohesion.

**Multi-owner / cross-cutting VOs.** When a VO is used by multiple first-class entities in the same domain (e.g. `AvailabilityRule` consumed by `Menu`, `MenuSection`, `Product`, and `MenuLocationOverride`; `OpeningHours` consumed by `LocationOpeningHour` and potentially by `Business`, `Station` in future), place the VO group at the **domain top level** (e.g. `Catalog/Entities/Availability/`, `Locations/Entities/OpeningHours/`) rather than nesting under one consumer. The trigger is "more than one first-class entity owns this VO" — not "may be used elsewhere someday." Promote a VO from owner-nested to domain-top-level when the second consumer appears, not earlier.

### Repository mirror

The `Repo/DB/` tree mirrors `Entities/` 1:1 with the `DB` prefix on class names. No exceptions — the namespace symmetry simplifies generator output and code navigation.

```
Entities/Ingredients/IngredientComponents/IngredientComponent.php
                          ↓ mirrored as ↓
Repo/DB/Ingredients/IngredientComponents/DBIngredientComponent.php
```

### Reference: a complete catalog tree

```
Entities/
├── Ingredients/                     ← first-class
│   ├── Ingredient.php
│   ├── Ingredients.php
│   ├── IngredientAllergens/         ← child junction (owned by Ingredient)
│   │   ├── IngredientAllergen.php
│   │   └── IngredientAllergens.php
│   ├── IngredientComponents/        ← child self-junction (Ingredient ↔ Ingredient)
│   │   ├── IngredientComponent.php
│   │   └── IngredientComponents.php
│   └── Nutrition/                   ← value-object group
│       └── NutritionFacts.php
├── Allergens/                       ← first-class
│   ├── Allergen.php
│   └── Allergens.php
├── ProductCategories/               ← first-class (name-prefixed, still standalone)
│   ├── ProductCategory.php
│   └── ProductCategories.php
└── Products/
    ├── Product.php                  ← first-class
    ├── Products.php
    ├── ProductAllergens/            ← child junction
    │   ├── ProductAllergen.php
    │   └── ProductAllergens.php
    └── ProductIngredients/          ← child junction
        ├── ProductIngredient.php
        └── ProductIngredients.php
```

`ProductCategory` is name-prefixed but first-class — the prefix is naming convention, not parent ownership. It has its own admin section, is referenced by many products, exists independently. The standard handles this correctly: the prefix does not make it a child.

## Property Naming Convention — Lazy-Loaded Entities, Sets, and Value Objects

### The rule

For lazy-loaded entity / entity set properties: **property name = `lowerCamel(typeName)`**. The property's name matches the lowercased version of the type name verbatim.

```php
public ?IngredientAllergens $ingredientAllergens = null;   // type-matched
public ?IngredientComponents $ingredientComponents = null; // type-matched
public ?MenuSections $menuSections = null;                 // type-matched
public ?ProductCategory $productCategory = null;           // type-matched FK
public ?Business $business = null;                         // type-matched FK
```

The rule is uniform: it does not matter whether the type's name "looks like" it contains the owner's name. A field of type `IngredientComponents` on an Ingredient is named `$ingredientComponents`, not `$components`.

### Why type-matched, not stripped-prefix

| Concern | Type-matched (`$ingredientComponents`) | Stripped (`$components`) |
|---|---|---|
| Cross-entity readability | Unambiguous at call sites | `Product.$allergens` vs `Ingredient.$allergens` look identical, types differ silently |
| IDE navigation | Property name = type name → "Go to type" works directly | Mental translation needed every time |
| SDK regen alignment | TypeScript model class name and the property reading it match letter-for-letter | Property diverges from type, costs constant cognitive overhead on FE |
| Consistency over time | Universal rule, no exceptions to remember | Requires per-case judgement; drifts as the codebase grows |

### Three carve-outs where role-naming is mandatory

1. **Embedded value objects where the property describes a role, not a type identity.** When a VO carries a specific role on the entity (or where multiple roles of the same VO type need to coexist), name by role:

    ```php
    public ?MoneyAmount $basePrice = null;          // role: base price
    public ?MoneyAmount $salePrice = null;          // role: sale price (same type, different role)
    public ?NutritionFacts $nutritionPer100g = null;// role: nutrition per 100g
    public ?AvailabilityRule $availability = null;  // role IS "availability"
    ```

    Rationale: VOs carry meaning beyond their type. A `MoneyAmount` could be base price, sale price, deposit, tip — the role disambiguates.

2. **Multiple FKs to the same target type.** When an entity has two FKs to the same target, role-name both — the type-matched name would collide:

    ```php
    public ?Ingredient $parentIngredient = null;     // role: parent in composition
    public ?Ingredient $componentIngredient = null;  // role: child in composition

    public ?Product $sourceGlobalProduct = null;     // role: lineage anchor
    ```

    Mark the parent-side FK with `#[LazyLoad(addAsParent: true)]` so the OneToMany inverse-resolver picks the correct side (see Junction/Pivot Entities section).

3. **Generic / polymorphic target types with multiple roles.** When the target type has a generic name (`Locale`, `Currency`, `MediaItem`, `PostalAddress`) and the entity holds more than one, role-name:

    ```php
    public ?PostalAddress $pickupAddress = null;
    public ?PostalAddress $deliveryAddress = null;
    public ?Locale $displayLocale = null;            // when multiple Locale fields exist on the entity
    ```

    When there's only one such field, type-matched is fine: `public ?Locale $locale = null;`.

### Anti-patterns to reject in review

- ❌ `public ?IngredientComponents $components` — stripping the owner-prefix even though no collision exists. Use `$ingredientComponents`.
- ❌ `public ?ProductAllergens $allergens` on Product — same issue. Use `$productAllergens`.
- ❌ `public ?AvailabilityRule $rule` — generic property name discards the role. The role *is* "availability" — use `$availability`.
- ❌ Two `$address: ?PostalAddress` fields disambiguated by `$address1` / `$address2` — name by role: `$pickupAddress` / `$deliveryAddress`.
- ❌ Mixing styles inside the same entity — pick a single rule and apply it across all properties.

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

### NOT NULL Columns -- Require `#[NotNull]`, Not the PHP Type

Column nullability is driven **exclusively** by the `Symfony\Component\Validator\Constraints\NotNull` attribute. The PHP `?Type` declaration is, by design, ignored by the schema generator (`DatabaseColumn::createFromReflectionProperty`).

```php
use Symfony\Component\Validator\Constraints\NotNull;

// CORRECT — column emitted as NOT NULL
#[NotNull]
public ?int $accountId;

// WRONG — column emitted as DEFAULT NULL, even though PHP says non-nullable
public int $accountId;

// ALSO WRONG — without #[NotNull] the column is DEFAULT NULL
public ?int $accountId;
```

**Why this design:**
1. **Hydration safety.** Doctrine instantiates entities via `Doctrine\Instantiator` without invoking the constructor. Non-nullable scalars (`public int $x;` with no default) throw `"must not be accessed before initialization"` if any code reads them before hydration writes them. The codebase convention is therefore `?Type` everywhere — but that's about runtime ergonomics, not DB schema intent.
2. **Single source of truth.** `#[NotNull]` drives BOTH the application-layer validator (rejects null on `$entity->update()`) AND the DB column constraint, so the two stay in lockstep without drift.

**Canonical pattern:** `#[NotNull] public ?Type $name;` — nullable PHP type for hydration, attribute for DB constraint.

Reference uses in the codebase: `Track::$accountId`, `Track::$startsAt`, `Track::$endsAt`, `Account::$worldId`, `Campaign::$worldId/$startDate/$endDate`.

If you forget `#[NotNull]` on a property that should be NOT NULL, the schema-diff endpoint will surface it as a `MODIFY [allowsNull]` against the live database after the next regeneration.

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
use Symfony\Component\Validator\Constraints\Length;
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
| `#[ReuseParentEntitySet]` | Opt-in: reuse the parent's EntitySet across root namespaces (see "Constants-Only Subclasses" below) |

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

### Default index generation — what gets indexed automatically, and how

**The generator indexes EVERY scalar column by default.** Each persisted property becomes a column, and
unless it carries an explicit `#[DatabaseIndex]`, it gets a default index whose **type is chosen from the
column's SQL type** (`hasIndex` defaults to `true`; allocation in `DatabaseColumn::SQL_TYPES_TO_DEFAULT_INDEX_TYPE_ALLOCATIONS`):

| SQL type (from the PHP type, or `#[DatabaseColumn(sqlType:)]`) | Default index |
|---|---|
| `INT`, `BIGINT`, `FLOAT`, `DOUBLE`, `BOOL`, `VARCHAR`, `DATE`, `DATETIME` | `INDEX` (BTREE) |
| `TEXT`, `MEDIUMTEXT`, `LONGTEXT` | `FULLTEXT` |
| `POINT` (PHP `GeoPoint` / `Point2D`) | `SPATIAL` — **only if NOT NULL** (see gotcha) |
| `VECTOR` | `VECTOR` (MariaDB HNSW) |
| `JSON`, `BLOB`/`MEDIUMBLOB`/`LONGBLOB`, `LINESTRING`, `POLYGON` | none (`TYPE_NONE`) |

So a plain `public string $status;` or `public ?int $count;` is **already indexed** — do NOT add
`#[DatabaseIndex]` for a simple single-column lookup. (Conversely: many never-filtered scalar columns =
needless index bloat → suppress them, see below.)

**Auto-indexes that are NOT driven by a property's own type:**

| Trigger | Index emitted |
|---|---|
| `id` (every `Entity`) | `PRIMARY KEY` (`INT UNSIGNED AUTO_INCREMENT`) — no secondary index |
| Foreign-key column `{name}Id` (a `ManyToOne` / owning `OneToOne`) | BTREE `INDEX`, automatically; the column is also forced `UNSIGNED`. **Never hand-index an FK column.** |
| `created` / `updated` (from `ChangeHistoryTrait`) | one BTREE `INDEX` each (both are nullable `DATETIME`) |
| `#[Translatable(fullTextIndex: true)]` property | a stored `TEXT` virtual column `virtual{Name}Search` + a `FULLTEXT` index on it |

### Suppressing the default index

```php
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
public string $rarelyFiltered;   // column is created, but gets NO index
```

### Index types & where they apply

`DatabaseIndex::TYPE_NONE | TYPE_INDEX | TYPE_UNIQUE | TYPE_FULLTEXT | TYPE_SPATIAL | TYPE_VECTOR`.

- **Property-level** `#[DatabaseIndex]` always indexes that one property — `indexColumns` is ignored there, so pass only the type:
  ```php
  #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
  public string $name;            // single-column UNIQUE
  ```
- **Class-level** is the ONLY place `indexColumns` is honored — use it for composite / multi-column indexes (see below). Repeatable on both property and class.
- A **single-column `UNIQUE` index on a `{name}Id` FK flips the relation `ManyToOne` → `OneToOne`** — this is how you declare a 1:1 owning side:
  ```php
  #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
  public ?int $profileId = null;  // → OneToOne
  ```

### Spatial & vector columns (geometry, embeddings)

Geometry (`POINT` / `LINESTRING` / `POLYGON`) and `VECTOR` columns have their own index rules and a dedicated
skill: **`ddd-geometry-and-vector-specialist`** owns the full type-selection matrix, declaration, DQL function
catalog, and worked spatial/ANN query examples. The essentials as they touch index generation:

- **`POINT`** (PHP `GeoPoint` / `Point2D`) → `SPATIAL` index by default — **but MySQL/MariaDB reject SPATIAL on a NULLable column** (error 1252), so a nullable POINT silently gets NO index. Add `#[NotNull]` to keep it.
- **`LINESTRING` / `POLYGON`** → no index by default; opt in with `#[DatabaseIndex(indexType: DatabaseIndex::TYPE_SPATIAL)]` + `#[NotNull]`.
- **`VECTOR(n)`** → always gets a `VECTOR` index (HNSW; defaults cosine, `M=8`). Declared by a `Vector`-typed property — typically an entity that **`extends Vector`** (e.g. `TextEmbedding` in `ddd-common-translations`) — or `#[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_VECTOR, vectorDimensions: n)]`. The dimension `n` is required.

**How vector (semantic) search works — schematically.** A `Vector`-extending entity (e.g. `TextEmbedding`)
maps to a `VECTOR(n)` column; the embedding is produced/written through its (Argus) repo, and the column
carries the VECTOR index. Search is **NOT a QueryOptions feature** — it is a repo `createQueryBuilder()` that
`ORDER BY`s a vector-distance DQL function, driven from a service that turns the query text into an embedding:

```php
// repo: order by cosine distance ASC (nearest first), bound query embedding, top-k
$qb->addOrderBy("COSINE_DISTANCE({$alias}.embedding, VEC_FROM_TEXT(:searchVector))", 'ASC')
   ->setParameter('searchVector', $vectorString)   // '[0.12,-0.04,…]' (the query embedding)
   ->setMaxResults($k);
```

Full DQL function catalog (`COSINE_DISTANCE` / `COSINE_SIMILARITY` / `EUCLIDEAN_DISTANCE` / `VEC_DISTANCE` /
`VEC_FROM_TEXT`) + ANN worked examples → **`ddd-geometry-and-vector-specialist`**; the embed-in-service →
search-in-repo orchestration → **`ddd-service-specialist` → "Vector / semantic search"**.

### `#[DatabaseColumn]` options (beyond `ignoreProperty` / `sqlType`)

| Option | Effect |
|---|---|
| `ignoreProperty: true` | property is not persisted (no column) |
| `sqlType: SQL_TYPE_*` | force the SQL type instead of deriving from the PHP type |
| `allowsNull` | nullability (default `true`) — but prefer `#[NotNull]`, see Critical Rules |
| `varCharLength` (default 255) / `isUnsigned` / `hasAutoIncrement` | VARCHAR length / INT modifiers |
| `vectorDimensions` (default 1024) | `VECTOR(n)` dimension |
| `encrypted: true` + `encryptionScope:` | at-rest column encryption (stored as `VARCHAR`/`TEXT`) |
| `collation:` (`COLLATION_*`) | per-column `CHARACTER SET … COLLATE …` |
| `isMergableJSONColumn: true` | JSON column upserted via `JSON_MERGE_PATCH` |
| `onUpdateAction:` | custom `ON DUPLICATE KEY UPDATE` expression (e.g. counter increment) |

### Virtual (generated) columns

`#[DatabaseVirtualColumn(as: …, stored: true, createIndex: false)]` on property `foo` creates a
`GENERATED ALWAYS AS (…) [STORED]` column named `virtualFoo`. Indexing is gated on **`createIndex` (default `false`)**:
- `createIndex: false` → column only, no index.
- `createIndex: true` + no `#[DatabaseIndex]` → a plain `INDEX` on `virtualFoo`.
- `createIndex: true` + `#[DatabaseIndex(type)]` → that index type (e.g. `SPATIAL` on a generated geometry column).

A common use is unique/composite indexes over a nullable column: NULL ≠ NULL in SQL breaks UNIQUE integrity, so index a NULL-collapsing virtual column instead:

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

## Constants-Only Subclasses Across Root Namespaces (`#[ReuseParentEntitySet]`)

### The problem

When an App entity extends a Framework entity from a different root namespace (App vs DDD), `Entity::getService()` silently returns `null` unless the App also declares a parallel App-side EntitySet:

```php
// Framework:
namespace DDD\Domain\AI\Entities\Prompts;
class AIPrompt extends Entity { ... }
class AIPrompts extends EntitySet {
    public const SERVICE_NAME = AIPromptsService::class;
}

// App:
namespace App\Domain\AI\Entities\Prompts;
class AIPrompt extends \DDD\Domain\AI\Entities\Prompts\AIPrompt {
    public const string SOME_APP_CONST = 'X';
}

// At runtime — RETURNS NULL:
App\AIPrompt::getService();
```

Cause: `EntityTrait::getEntitySetClass()` first tries `getParentEntityClassName(considerOnlyClassesFromSameRootNamespace: true)`. The guard is deliberate — it prevents an App entity from accidentally inheriting a Framework EntitySet that's missing App-specific service behavior. The fallback path then pluralizes in the current namespace (`App\AIPrompts`) and finds nothing. Result: `null` → callers downstream NPE on `->throwErrors = true` or similar.

### The opt-in fix

Tag the subclass with `#[ReuseParentEntitySet]`:

```php
use DDD\Domain\Base\Entities\Attributes\ReuseParentEntitySet;

#[ReuseParentEntitySet]
class AIPrompt extends \DDD\Domain\AI\Entities\Prompts\AIPrompt {
    public const string SOME_APP_CONST = 'X';
}
```

Now `getEntitySetClass()` falls through to the parent class's EntitySet across namespaces as a **last resort**, after all other resolution paths have failed. `App\AIPrompt::getService()` resolves to the framework's `AIPromptsService`.

### When to use it

- **Yes:** The subclass adds **only** constants, type aliases, or other non-persistent declarations.
- **Yes:** The subclass introduces no new database columns and needs no App-specific service methods.

### When NOT to use it

- **No:** The subclass adds new persistent properties (DatabaseColumn fields, translatable strings, FK columns, etc.). Declare a parallel App EntitySet + Service that knows about those columns.
- **No:** The subclass needs App-specific service methods (custom queries, scope-aware finders, business-rule helpers). Declare the App Service explicitly.
- **No:** The subclass alters the DB schema in any way. The framework's EntitySet/Service won't expose the App schema.

The attribute is a deliberate escape hatch for the "constants-only" case — using it on a behavior-adding subclass silently routes calls through the wrong service.

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

The DB model generator creates a **stored virtual column named `virtual{Property}Search`** (e.g. property `name` → column `virtualNameSearch`) holding the ` | `-joined translation values, with a `FULLTEXT` index on it. API consumers filter on the logical property name (`name`), but the generated query targets `virtualNameSearch` automatically. (This `name → virtualNameSearch` mapping is the same one referenced by the `ddd-query-options-specialist` `ft`/`fb` operators.)

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
| `#[NoValidation]` | Skip validation for this property | On computed/internal properties |
| `#[UniqueProperty]` | Database uniqueness check | Email, username fields |

> `#[Length]`, `#[NotNull]`, `#[NotBlank]`, `#[Email]`, `#[Positive]`, `#[Regex]` etc. come from `Symfony\Component\Validator\Constraints\`. They are recognised by the schema generator (Length drives `varCharLength`, NotNull drives `allowsNull`) and apply at validation time. See "Symfony constraints" section below.

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

---

## Cross-Reference

- **Entity-level access control** — `ddd-rights-specialist` (`applyReadRightsQuery` / `applyUpdateRightsQuery` on the `DB{Entity}` repo, `#[RolesRequiredForUpdate]`, and `mapToEntity` property hiding — the runtime enforcement behind the `#[HideProperty]` / role attributes declared here).
- **Serialization of these properties** — `ddd-serializer-specialist` (how `#[HideProperty]`, `#[HidePropertyOnSystemSerialization]`, `#[OverwritePropertyName]`, `#[Aliases]`, and `#[DontPersistProperty]` actually drive API vs DB output via the SerializerTrait every entity inherits).
- **Migrating the generated schema** — `ddd-database-schema-diff-specialist` (how the columns/indexes derived from these attributes are diffed against the live DB and applied — e.g. a forgotten `#[NotNull]` surfacing as a `MODIFY [allowsNull]`).
