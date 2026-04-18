---
name: ddd-query-options-specialist
description: Work with the OData-style QueryOptions system in the mgamadeus/ddd framework -- filtering, sorting, pagination, field selection, and entity expansion. Use when implementing or debugging QueryOptions in controllers, DTOs, services, or entities.
metadata:
  author: mgamadeus
  version: "1.1.0"
  framework: mgamadeus/ddd
---

# DDD QueryOptions Specialist

The OData-inspired QueryOptions system for filtering, sorting, pagination, field selection, and related-entity expansion in the DDD Core framework (`mgamadeus/ddd`).

## When to Use

- Configuring QueryOptions on entities and entity sets
- Using QueryOptions in controllers via DTOs
- Using QueryOptions programmatically in services
- Understanding filter operators, expand clauses, and pagination
- Debugging QueryOptions-related issues
- Working with fulltext search on Translatable properties

---

## QueryOptions Overview

QueryOptions provide OData-style data querying applied at the database level via `DBEntitySet::applyQueryOptions()`:

| Parameter | Purpose | Example |
|-----------|---------|---------|
| `$filter` | Conditions (WHERE) | `?$filter=status eq 'ACTIVE'` |
| `$expand` | Related entities (LEFT JOIN) | `?$expand=business,zones` |
| `$orderBy` | Sorting (ORDER BY) | `?$orderBy=createdAt desc,name asc` |
| `$select` | Field selection (partial SELECT) | `?$select=id,name,business.name` |
| `$top` | Limit (default: 50) | `?$top=20` |
| `$skip` | Offset | `?$skip=40` |
| `$skiptoken` | Cursor pagination | `?$skiptoken=abc123` |

**Default `$top` is 50** (defined in the `#[QueryOptions]` attribute). Override with `?$top=100` or programmatically via `->setTop(100)`.

### Database Application Flow

When `DBEntitySet::find()` executes, `applyQueryOptions()` applies in this order:

1. **top/skip** -> `LIMIT`/`OFFSET`
2. **expand** -> `LEFT JOIN` with nested filters, orderBy, select; also applies `applyReadRightsQuery()` on joined entities
3. **filters** -> `WHERE` clauses
4. **orderBy** -> `ORDER BY` clauses
5. **select** -> `partial SELECT` with property hiding for unselected fields

For single entities (`DBEntity::find()`), only **select** is applied from default QueryOptions.

---

## Entity Setup: `QueryOptionsTrait`

### `#[QueryOptions]` Class Attribute vs `QueryOptionsTrait`

The **`#[QueryOptions]` class-level attribute** is only needed when the entity requires **custom** filters, sorters, or expanders beyond what the framework auto-detects.

**Auto-detection:** The framework automatically discovers all filterable properties by scanning entity properties for built-in types (int, string, DateTime, etc.), ValueObjects, `#[DatabaseColumn]`, `#[DatabaseVirtualColumn]`, `#[Translatable]`, `#[ChangeHistory]`, and `#[Choice]` attributes. You do NOT need to explicitly list filters in `#[QueryOptions]` for standard property filtering.

However, **`QueryOptionsTrait`** must be added to **ALL entities and entity sets that will be exposed through API endpoints**. The framework requires it on any class referenced by `#[DtoQueryOptions(baseEntity: ...)]` in request DTOs. Without it, the framework throws: _"base entity has no QueryOptions attribute set on class"_.

> **Rule of thumb:** If an Entity or EntitySet will be used in a controller (directly or via DTOs with `#[DtoQueryOptions]`), it MUST have `use QueryOptionsTrait;`. This applies to **both** the single Entity class AND the EntitySet class.

```php
// Entity -- add QueryOptionsTrait
class Product extends Entity
{
    use QueryOptionsTrait;
    // ...
}

// EntitySet -- also needs QueryOptionsTrait
class Products extends EntitySet
{
    use QueryOptionsTrait;
    // ...
}
```

### What QueryOptionsTrait Provides

- `getDefaultQueryOptions(): AppliedQueryOptions` -- static, returns cached default for the class (builds from `#[QueryOptions]` attribute or creates empty)
- `setDefaultQueryOptions(AppliedQueryOptions $queryOptions)` -- static, overwrites default (use for programmatic filtering)
- `getQueryOptions(): ?AppliedQueryOptions` -- instance, returns current query options or clones default
- `setQueryOptions(AppliedQueryOptions &$queryOptions)` -- instance
- `expand()` -- instance, expands lazy-loaded properties based on expand options (recursive)

---

## QueryOptions in Controllers (via DTOs)

### Validation Flow

When a request arrives with query parameters:

1. `DtoQueryOptionsTrait::setPropertiesFromRequest()` parses HTTP query params into typed objects (`FiltersOptions`, `OrderByOptions`, `ExpandOptions`, `SelectOptions`)
2. **Expand** is validated against the entity's lazy-loadable properties (`ExpandDefinitions`)
3. **Filters** are validated against the entity's filterable properties (`FiltersDefinitions`)
4. **OrderBy** is validated against allowed properties
5. `AppliedQueryOptions::setQueryOptionsFromRequestDto()` copies all options to the entity's default QueryOptions (with `validateAgainstDefinitions=false` since validation already happened)

### Single Entity GET (with $select/$expand support)

```php
use DDD\Presentation\Base\QueryOptions\{DtoQueryOptions, DtoQueryOptionsTrait};

#[DtoQueryOptions(baseEntity: Resource::class)]
class ResourceGetRequestDto extends RequestDto
{
    use DtoQueryOptionsTrait;

    #[Parameter(in: Parameter::PATH, required: true)]
    public int|string $resourceId;
}
```

Controller usage:
```php
public function get(ResourceGetRequestDto &$requestDto, ResourcesService $resourcesService): ResourceGetResponseDto
{
    $resourcesService->throwErrors = true;
    Resource::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);

    $resource = $resourcesService->find($requestDto->resourceId);
    $resource->expand();  // Apply $expand -- triggers lazy loading per expand options

    $responseDto = new ResourceGetResponseDto();
    $responseDto->resource = $resource;
    return $responseDto;
}
```

### Collection GET (with full QueryOptions)

```php
#[DtoQueryOptions(baseEntity: ResourcePlural::class)]
class ResourcesGetRequestDto extends RequestDto
{
    use DtoQueryOptionsTrait;
}
```

Controller usage:
```php
public function list(ResourcesGetRequestDto &$requestDto, ResourcesService $resourcesService): ResourcesGetResponseDto
{
    ResourcePlural::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);
    $resourcesService->throwErrors = true;

    $responseDto = new ResourcesGetResponseDto();
    $responseDto->resources = $resourcesService->findAll();
    $responseDto->resources->expand();
    return $responseDto;
}
```

**Important:**
- Use `&$requestDto` (pass-by-reference) for DTOs with `DtoQueryOptionsTrait`
- `#[DtoQueryOptions(baseEntity: ...)]` references the **EntitySet** class for list endpoints, the **Entity** class for single-entity endpoints

---

## Filter Operators

| Operator | Meaning | Value Type | Example |
|----------|---------|------------|---------|
| `eq` | Equals | scalar | `status eq 'ACTIVE'` |
| `ne` | Not equals | scalar | `status ne 'DELETED'` |
| `gt` | Greater than | scalar | `price gt '100'` |
| `ge` | Greater than or equal | scalar | `createdAt ge '2026-01-01'` |
| `lt` | Less than | scalar | `price lt '50'` |
| `le` | Less than or equal | scalar | `age le '30'` |
| `in` | In list | array | `status in ['ACTIVE','PENDING']` |
| `ni` | Not in list | array | `status ni ['CANCELLED','DELETED']` |
| `bw` | Between (inclusive) | array[2] | `createdAt bw ['2026-01-01','2026-12-31']` |
| `ft` | Fulltext (natural language) | scalar | `name ft 'search terms'` |
| `fb` | Fulltext (boolean mode) | scalar | `name fb '+required -excluded'` |

### Value Rules (Strict)

- Every scalar MUST be wrapped in **single quotes**: `'value'`, `'10'`, `'2026-01-01'`, `'true'`
- NULL MUST be written as the scalar `'NULL'`
- Lists MUST use **brackets** with every item quoted: `['val1','val2']`
- **Logical operators:** `and`, `or` (case-insensitive), `(...)` for grouping/precedence (nesting allowed)
- Property names support **dot-notation** for expanded relations: `business.type`, `account.person.name`

### Filter Examples

```
?$filter=isActive eq 'true'
?$filter=business.type eq 'RESTAURANT'
?$filter=deletedAt eq 'NULL'
?$filter=status in ['PENDING','PROCESSING']
?$filter=status ni ['CANCELLED','DELETED']
?$filter=createdAt bw ['2026-01-01','2026-01-31']
?$filter=(status eq 'PENDING' or status eq 'PROCESSING') and total gt '100'
?$filter=(someId eq '1' and ((startDate le '2026-01-22' and endDate ge '2026-01-01') or (startDate bw ['2026-01-01','2026-01-22'])))
```

---

## Expand (Related Entity Loading)

Expand creates LEFT JOINs in the database query for lazy-loadable properties. **Read rights restrictions (`applyReadRightsQuery`) are automatically applied to expanded entities.**

### Basic Expand

```
?$expand=business,zones
```

### Expand with Clauses

Clauses are **semicolon-separated** inside parentheses:

```
?$expand=business(select=id,name,type)
?$expand=zones(filters=isActive eq 'true';orderBy=name asc;top=50)
?$expand=zones(expand=tables(select=id,name))
?$expand=zones(filters=isActive eq 'true';orderBy=name asc;top=50;skip=0;expand=tables(select=id,name))
```

Supported clauses: `select`, `filters`, `orderBy`, `top`, `skip`, `skiptoken`, `expand` (recursive)

### Expand on Related Entity Properties

Filters and ordering can reference expanded entity properties using dot-notation:

```
?$expand=business&$filter=business.name ft 'kfc arad'&$orderBy=business.nameScore desc
```

### The `expand()` Method on Entities

After loading entities, call `$entity->expand()` or `$entitySet->expand()` to trigger lazy loading per the expand options set on the entity's QueryOptions. This method:

1. Iterates over expand options
2. For each expanded property, triggers lazy loading
3. Applies scoped QueryOptions (filters, orderBy, top, skip, select) from the expand clause
4. Recurses into nested expands

---

## Select (Field Selection)

```
?$select=id,name,status
?$select=id,name,business.name
```

Reduces payload by applying `partial` SELECT in Doctrine and hiding unselected properties from serialization. The `id` field is always included automatically. Supports dot-notation for expanded entity fields.

---

## OrderBy (Sorting)

```
?$orderBy=name asc
?$orderBy=createdAt desc,name asc
?$orderBy=nameScore desc
```

Multiple sort columns separated by commas. Each column: `propertyName asc|desc` (direction optional, defaults to `asc`).

### Fulltext Relevance Score Ordering

The `{propertyName}Score` suffix enables ordering by fulltext relevance (uses `MATCH...AGAINST` score). **Requirements:**

- A corresponding fulltext filter (`ft` or `fb`) must be active on the base property
- e.g., `?$filter=name ft 'search'&$orderBy=nameScore desc`

Works on expanded relations too: `?$expand=business&$filter=business.name ft 'kfc'&$orderBy=business.nameScore desc`

---

## Pagination

```
?$top=20              # Limit to 20 results (default: 50)
?$skip=40             # Skip first 40 results
?$top=20&$skip=40     # Page 3 (20 per page)
?$skiptoken=abc123    # Cursor-based pagination
```

**Default `$top` is 50** when the `#[QueryOptions]` attribute is present with no explicit override.

---

## Combined Example

```
?$select=id,name&$filter=isActive eq 'true'&$orderBy=name asc&$top=10&$expand=business(select=id,name)
```

---

## Fulltext Search (Translatable Properties)

Entities using `#[Translatable(fullTextIndex: true)]` support fulltext search:

```php
#[Translatable(fullTextIndex: true)]
public ?string $name = null;
```

The DB model generator creates a stored virtual search column (`virtualNameSearch`) with a FULLTEXT index. API consumers filter on the logical property name, but queries target the virtual column automatically.

### `ft` vs `fb` -- Choosing the Right Operator

| Operator | Mode | Use For | Prefix Matching |
|----------|------|---------|-----------------|
| `ft` | Natural language | Complete phrase matching | No |
| `fb` | Boolean | Search-as-you-type, interactive search | Yes (`word*`) |

**Use `fb` for interactive search fields.** Boolean mode supports prefix matching with `*`, so `name fb 'Alm*'` finds "Allergen", "Almond", etc. Natural language mode (`ft`) only works with complete words.

### API Examples

```
?$filter=name ft 'chicken breast'             # Natural language
?$filter=name fb '+chicken -fried'            # Boolean: require "chicken", exclude "fried"
?$filter=name fb 'alm*'                       # Boolean: prefix match
?$orderBy=nameScore desc                       # Relevance ordering (requires ft/fb filter)
```

### With Expanded Relations

```
?$expand=business&$filter=business.name ft 'kfc arad'&$orderBy=business.nameScore desc
```

Database schema changes (virtual column + FULLTEXT index) are managed manually by the developer; the framework generates the ORM/model metadata.

---

## QueryOptions in Services (Programmatic Usage)

When querying entities from within services (not via API request DTOs), use `getDefaultQueryOptions()` / `setDefaultQueryOptions()`:

### Required Imports

```php
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
```

### Pattern: Filter + Order + Limit

```php
// 1. ALWAYS save original QueryOptions first
$originalQueryOptions = clone EntityNames::getDefaultQueryOptions();

// 2. Apply filters
$filtersOptions = FiltersOptions::fromString("isActive eq '1'");
$orderBy = OrderByOptions::fromString('created DESC');
EntityNames::getDefaultQueryOptions()
    ->setFilters($filtersOptions)
    ->setOrderBy($orderBy)
    ->setTop(100);

// 3. Query -- findAll() now returns filtered results
$results = $service->findAll();

// 4. ALWAYS restore original QueryOptions
EntityNames::setDefaultQueryOptions($originalQueryOptions);
```

**Always clone and restore** the original QueryOptions. Other code (lazy loading, controllers) relies on defaults being unmodified.

### All Operators Available Programmatically

| Operator | Meaning | Example String |
|----------|---------|----------------|
| `eq` | Equals | `"status eq 'ACTIVE'"` |
| `ne` | Not equals | `"status ne 'DELETED'"` |
| `gt` / `ge` | Greater (or equal) | `"price gt '100'"` |
| `lt` / `le` | Less (or equal) | `"price lt '50'"` |
| `in` | In list | `"status in ['ACTIVE','PENDING']"` |
| `ni` | Not in list | `"status ni ['CANCELLED','DELETED']"` |
| `bw` | Between | `"createdAt bw ['2026-01-01','2026-12-31']"` |
| `ft` | Fulltext | `"name ft 'search terms'"` |
| `fb` | Fulltext boolean | `"name fb '+required -excluded'"` |

**Logical:** `and`, `or` (case-insensitive), `(...)` for grouping. All values in single quotes.

### Scoped QueryOptions for LazyLoad

QueryOptions can be scoped to control lazy-loaded child collections:

```php
$originalQueryOptions = clone ChildEntities::getDefaultQueryOptions();

$filters = FiltersOptions::fromString("parentId eq '{$parent->id}' AND status eq 'ACTIVE'");
$orderBy = OrderByOptions::fromString('created ASC');
ChildEntities::getDefaultQueryOptions()->setFilters($filters)->setOrderBy($orderBy)->setTop(3);

// Trigger lazy load -- will use the scoped QueryOptions
$children = $parent->children;

ChildEntities::setDefaultQueryOptions($originalQueryOptions);
```

---

## Key Classes Reference

| Class | Location | Purpose |
|-------|----------|---------|
| `QueryOptions` | `src/Domain/Base/Entities/QueryOptions/QueryOptions.php` | Attribute defining default constraints (top=50) |
| `QueryOptionsTrait` | `src/Domain/Base/Entities/QueryOptions/QueryOptionsTrait.php` | Trait providing getDefault/setDefault/expand |
| `AppliedQueryOptions` | `src/Domain/Base/Entities/QueryOptions/AppliedQueryOptions.php` | Runtime instance with setQueryOptionsFromRequestDto() |
| `FiltersOptions` | `src/Domain/Base/Entities/QueryOptions/FiltersOptions.php` | Tree-like filter expression structure |
| `FiltersOptionsParser` | `src/Domain/Base/Entities/QueryOptions/FiltersOptionsParser.php` | Parses filter strings into FiltersOptions trees |
| `FiltersDefinitions` | `src/Domain/Base/Entities/QueryOptions/FiltersDefinitions.php` | Auto-detects filterable properties per entity |
| `OrderByOptions` | `src/Domain/Base/Entities/QueryOptions/OrderByOptions.php` | Collection of OrderByOption with score support |
| `SelectOptions` | `src/Domain/Base/Entities/QueryOptions/SelectOptions.php` | Partial select with property hiding |
| `ExpandOptions` | `src/Domain/Base/Entities/QueryOptions/ExpandOptions.php` | Expand specs with LEFT JOIN generation |
| `ExpandDefinitions` | `src/Domain/Base/Entities/QueryOptions/ExpandDefinitions.php` | Auto-detects expandable properties (from #[LazyLoad]) |
| `DtoQueryOptions` | `src/Presentation/Base/QueryOptions/DtoQueryOptions.php` | Attribute linking DTO to base entity |
| `DtoQueryOptionsTrait` | `src/Presentation/Base/QueryOptions/DtoQueryOptionsTrait.php` | Bridges HTTP query params to domain QueryOptions |

---

## Troubleshooting

| Problem | Check |
|---------|-------|
| "base entity has no QueryOptions attribute set on class" | Add `use QueryOptionsTrait;` to BOTH Entity and EntitySet |
| Filters not working | Check value quoting: `'value'` not `value`. Check property is filterable (auto-detected from entity). |
| Filter on expanded property fails | Ensure `$expand=relation` is also present |
| `ni` operator not working | Verify value is an array: `ni ['A','B']` not `ni 'A'` |
| Expand not loading in response | Verify `$expand` in query string AND `->expand()` called in controller after loading |
| Expand returns no results | Read rights (`applyReadRightsQuery`) are applied to expanded entities -- check rights |
| Fulltext `ft` not matching partial words | Use `fb` (boolean mode) with `*` prefix for partial matching |
| `nameScore` ordering ignored | Requires active `ft` or `fb` filter on the same property |
| Default top=50 truncating results | Explicitly pass `?$top=1000` or set programmatically |
| Programmatic QueryOptions leaking | Always clone + restore original QueryOptions |
| OrderBy ignored | Check `setQueryOptionsFromRequestDto()` is called before `findAll()` |
| Select not hiding properties | Properties are hidden from serialization, not from the query itself |
