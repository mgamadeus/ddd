---
name: ddd-query-options-specialist
description: Work with the OData-inspired QueryOptions system in the mgamadeus/ddd framework — database-level filtering, sorting, pagination, field selection, expansion, and fulltext search over Translatable properties. Wire syntax is plain filters=/expand=/orderBy=/select=/top=/skip= (plural filters, no dollar prefix; default top 50). Covers entity setup (QueryOptionsTrait on BOTH Entity and EntitySet), controller DTOs, the filter grammar (eq/ne/gt/ge/lt/le/in/ni/bw, ft/fb fulltext, and/or grouping, dot-notation), expand with nested clauses and read-rights on joins, propertyScore relevance, programmatic snapshot/restore, mandatory scope filters via addFiltersConnectedByAnd, the Argus shared-defaults rule, and why HideProperty fields are never filterable. Use when implementing or debugging QueryOptions, when a query param is ignored or rejected, when results cap at 50, when enforcing an inescapable scope filter, when Argus defaults have no effect, or when building fulltext search.
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

> **No `$` prefix.** Query parameters are matched **verbatim against the request-DTO property names** (`RequestDto.php:117`, `DtoQueryOptionsTrait.php:30-65`) — the filter parameter is `filters` (plural), NOT `$filter`.

| Parameter | Purpose | Example |
|-----------|---------|---------|
| `filters` | Conditions (WHERE) | `?filters=status eq 'ACTIVE'` |
| `expand` | Related entities (LEFT JOIN) | `?expand=business,zones` |
| `orderBy` | Sorting (ORDER BY) | `?orderBy=createdAt desc,name asc` |
| `select` | Field selection (partial SELECT) | `?select=id,name,business.name` |
| `top` | Limit (default: 50) | `?top=20` |
| `skip` | Offset | `?skip=40` |
| `skiptoken` | Parsed & stored but **read nowhere** — cursor pagination is NOT implemented (`AppliedQueryOptions.php:127-131`; absent from `DBEntitySet::applyQueryOptions()`). Use `top`/`skip`. | (no effect) |

**Default `top` is 50** — applies whether or not `#[QueryOptions]` is present (`QueryOptions.php:28`). Override with `?top=100` or `->setTop(100)`. A `#[QueryOptions(maxTop: N)]` caps it (`setTop()` throws above `N`); `#[DtoQueryOptions(expose: ...)]` controls which options a request DTO accepts.

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

> **Hidden properties are never filterable or sortable (since v2.59.5).** Auto-detection skips every property marked `#[HideProperty]`, so it is absent from the filter definitions — and therefore from `orderBy`, expand-clause filters and the generated filter documentation. A request that filters or sorts on one is rejected with 400. Reason: a filter is an observation channel — `password eq 'a*'` (LIKE prefix), `lt`/`gt`/`bw` comparisons or the sort order would let a caller extract a value that never appears in any response. If a hidden property genuinely has to be a query key, do not remove `#[HideProperty]`; filter on it server-side with raw QueryBuilder conditions instead.

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
- `setDefaultQueryOptionsSnapshot()` / `restoreDefaultQueryOptionsSnapshot()` -- static, push/pop the current default onto a per-class snapshot stack (`QueryOptionsTrait.php:103-149`); the built-in, nestable alternative to manual clone + restore. Restore is a no-op on an empty stack, so it is safe in `finally`.
- `getQueryOptions(): ?AppliedQueryOptions` -- instance, returns current query options or clones default
- `setQueryOptions(AppliedQueryOptions &$queryOptions)` -- instance
- `expand()` -- instance, expands lazy-loaded properties based on expand options (recursive)

> **Argus repo classes key their defaults on the PARENT domain class.** `getDefaultQueryOptions()`,
> `setDefaultQueryOptions()` and the snapshot pair all resolve a class carrying `isArgusEntity` to its parent
> (`resolveDefaultQueryOptionsClassKey()`), so `ArgusFoo` and `Foo` share **one** defaults slot. Two consequences:
> setting defaults "for the Argus repo" changes the domain class's defaults for **every** consumer in the process —
> always scope such a mutation with `setDefaultQueryOptionsSnapshot()` / `restoreDefaultQueryOptionsSnapshot()` in
> `try`/`finally`; and reading back through either class sees the same object.
>
> Historical note (fixed in v2.59.2): `setDefaultQueryOptions()` used to key on the raw `static::class`, so a set on
> an Argus repo class wrote into a slot no reader resolved — a silent no-op. The common idiom only appeared to work
> because callers mutated the get-returned (parent-keyed) object in place. On an older release, do not rely on
> `ArgusFoo::setDefaultQueryOptions(...)`; mutate the object returned by `getDefaultQueryOptions()` instead.

---

## QueryOptions in Controllers (via DTOs)

### Validation Flow

When a request arrives with query parameters:

1. `DtoQueryOptionsTrait::setPropertiesFromRequest()` parses HTTP query params into typed objects (`FiltersOptions`, `OrderByOptions`, `ExpandOptions`, `SelectOptions`)
2. **Expand** is validated against the entity's lazy-loadable properties (`ExpandDefinitions`)
3. **Filters** are validated against the entity's filterable properties (`FiltersDefinitions`)
4. **OrderBy** is validated against allowed properties
5. `AppliedQueryOptions::setQueryOptionsFromRequestDto()` copies all options to the entity's default QueryOptions (with `validateAgainstDefinitions=false` since validation already happened)

### Single Entity GET (with select/expand support)

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
    $resource->expand();  // Apply expand -- triggers lazy loading per expand options

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

### Value Rules (recommended form — the parser is more lenient than this)

- Single-quote scalars for safety: `'value'`, `'10'`, `'2026-01-01'`, `'true'` — but the parser also accepts **unquoted numbers** and **double-quoted** items (`FiltersOptionsParser.php:244-265`).
- NULL: `'NULL'` works — and so does bare `null` (`:294-297`).
- Lists: `['val1','val2']` — and the parser also accepts SQL-style **paren lists** after `in`/`ni`/`bw`, e.g. `in ('A','B')` (`:331-340`).
- **Logical operators:** `and`, `or` (case-insensitive), `(...)` for grouping/precedence (nesting allowed)
- Property names support **dot-notation** for expanded relations: `business.type`, `account.person.name`

### Filter Examples

```
?filters=isActive eq 'true'
?filters=business.type eq 'RESTAURANT'
?filters=deletedAt eq 'NULL'
?filters=status in ['PENDING','PROCESSING']
?filters=status ni ['CANCELLED','DELETED']
?filters=createdAt bw ['2026-01-01','2026-01-31']
?filters=(status eq 'PENDING' or status eq 'PROCESSING') and total gt '100'
?filters=(someId eq '1' and ((startDate le '2026-01-22' and endDate ge '2026-01-01') or (startDate bw ['2026-01-01','2026-01-22'])))
```

---

## Expand (Related Entity Loading)

Expand creates LEFT JOINs in the database query for lazy-loadable properties. **Read rights restrictions (`applyReadRightsQuery`) are automatically applied to expanded entities.**

### Basic Expand

```
?expand=business,zones
```

### Expand with Clauses

Clauses are **semicolon-separated** inside parentheses:

```
?expand=business(select=id,name,type)
?expand=zones(filters=isActive eq 'true';orderBy=name asc;top=50)
?expand=zones(expand=tables(select=id,name))
?expand=zones(filters=isActive eq 'true';orderBy=name asc;top=50;skip=0;expand=tables(select=id,name))
```

Supported clauses: `select`, `filters`, `orderBy`, `top`, `skip`, `expand` (recursive); `skiptoken` is parsed but has no effect

### Expand on Related Entity Properties

Filters and ordering can reference expanded entity properties using dot-notation:

```
?expand=business&filters=business.name ft 'kfc arad'&orderBy=business.nameScore desc
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
?select=id,name,status
?select=id,name,business.name
```

Reduces payload by applying `partial` SELECT in Doctrine and hiding unselected properties from serialization. The `id` field is always included automatically. Supports dot-notation for expanded entity fields.

---

## OrderBy (Sorting)

```
?orderBy=name asc
?orderBy=createdAt desc,name asc
?orderBy=nameScore desc
```

Multiple sort columns separated by commas. Each column: `propertyName asc|desc` (direction optional, defaults to `asc`).

### Fulltext Relevance Score Ordering

The `{propertyName}Score` suffix enables ordering by fulltext relevance (uses `MATCH...AGAINST` score). **Requirements:**

- A corresponding fulltext filter (`ft` or `fb`) must be active on the base property
- e.g., `?filters=name ft 'search'&orderBy=nameScore desc`

Works on expanded relations too: `?expand=business&filters=business.name ft 'kfc'&orderBy=business.nameScore desc`

---

## Pagination

```
?top=20              # Limit to 20 results (default: 50)
?skip=40             # Skip first 40 results
?top=20&skip=40     # Page 3 (20 per page)
```

`skiptoken` is parsed but has **no effect** — cursor pagination is not implemented (see the parameter table above). Use `top`/`skip`.

**Default `top` is 50** — applies whether or not `#[QueryOptions]` is present (see above).

---

## Combined Example

```
?select=id,name&filters=isActive eq 'true'&orderBy=name asc&top=10&expand=business(select=id,name)
```

---

## Fulltext Search (Translatable Properties)

> **Fulltext ≠ vector search.** This section is keyword/`MATCH … AGAINST` fulltext over `#[Translatable(fullTextIndex: true)]` columns, exposed as QueryOptions `ft`/`fb` operators. **Vector / semantic (embedding) search is NOT a QueryOptions feature** — it's a DB-repo QueryBuilder pattern (`COSINE_DISTANCE` order-by) driven from the service; see `ddd-service-specialist` → "Vector / semantic search" and `ddd-entity-specialist` → "Vector search (querying a `VECTOR` column)".

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
?filters=name ft 'chicken breast'             # Natural language
?filters=name fb '+chicken -fried'            # Boolean: require "chicken", exclude "fried"
?filters=name fb 'alm*'                       # Boolean: prefix match
?orderBy=nameScore desc                       # Relevance ordering (requires ft/fb filter)
```

### With Expanded Relations

```
?expand=business&filters=business.name ft 'kfc arad'&orderBy=business.nameScore desc
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

Equivalent built-in (preferred, nestable): `EntityNames::setDefaultQueryOptionsSnapshot();` before mutating, then `finally { EntityNames::restoreDefaultQueryOptionsSnapshot(); }` — same effect as the manual clone/restore above.

### Adding a MANDATORY server-side filter on top of a client filter (`addFiltersConnectedByAnd`)

A very common need: a request DTO carries a client `filters` (via `DtoQueryOptions`), and the controller must additionally enforce a **server-side mandatory scope** — a tenant / owner / parent-id condition the client must not be able to escape. Use:

```php
// Client filters / top / orderBy / expand already applied:
$queryOptions = SomeEntities::getDefaultQueryOptions();
$queryOptions->setQueryOptionsFromRequestDto($requestDto);

// AND-connect the mandatory scope on top — guaranteed top-level AND, client filter kept as an atomic group:
$scopeFilters = FiltersOptions::fromString("accountId eq '{$authAccount->id}'");
$queryOptions->addFiltersConnectedByAnd($scopeFilters);
```

- **`AppliedQueryOptions::addFiltersConnectedByAnd(FiltersOptions $additional)`** wraps the existing filter tree AND `$additional` as two **atomic children** of a fresh top-level AND (via `FiltersOptions::buildConnected`). The client filter — **whatever its shape, including a top-level `or`** — stays parenthesized as one nested group, so the scope is always a top-level AND and **can never be OR-ed around**. If there is no existing filter, `$additional` simply becomes the filter. OR-sibling: **`addFiltersConnectedByOr`** (widens the set).
- **DO NOT** enforce a scope with `FiltersOptions::addExpressionsFromFiltersOptions()` — it **flattens** the other tree's top-level expressions into the receiver and, on a `TYPE_OPERATION` (e.g. `or`-rooted) receiver, makes them **inherit the receiver's join operator** → the scope becomes an `or`-sibling and the guard is bypassed. `addExpressionsFromFiltersOptions` is for merging same-property expressions, not for a mandatory AND-scope.
- **DO NOT** enforce a scope by building the cursor/scope as a separate typed param + `setFilters(serverString)` overwriting the client filter — that throws away the client's QueryOptions navigation. Keep the client on pure QueryOptions (`filters`) and add the scope with `addFiltersConnectedByAnd`.
- Still wrap in snapshot/restore (the static-default leak rule above): `SomeEntities::setDefaultQueryOptionsSnapshot()` … `finally { restoreDefaultQueryOptionsSnapshot(); }`.

`FiltersOptions::buildConnected(string $joinOperator, FiltersOptions ...$trees): static` is the low-level factory (builds a new operation node connecting the given trees as atomic children; throws `\InvalidArgumentException` on an invalid join operator).

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
| Filter on expanded property fails | Ensure `expand=relation` is also present |
| `ni` operator not working | Verify value is an array: `ni ['A','B']` not `ni 'A'` |
| Expand not loading in response | Verify `expand` in query string AND `->expand()` called in controller after loading |
| Expand returns no results | Read rights (`applyReadRightsQuery`) are applied to expanded entities -- check rights |
| Fulltext `ft` not matching partial words | Use `fb` (boolean mode) with `*` prefix for partial matching |
| `nameScore` ordering ignored | Requires active `ft` or `fb` filter on the same property |
| Default top=50 truncating results | Explicitly pass `?top=1000` or set programmatically |
| Programmatic QueryOptions leaking | Always clone + restore original QueryOptions |
| OrderBy ignored | Check `setQueryOptionsFromRequestDto()` is called before `findAll()` |
| Select not hiding properties | Properties are hidden from serialization, not from the query itself |

---

## Cross-Reference

- **Where QueryOptions arrive over HTTP** (request DTOs with `#[DtoQueryOptions]` + `DtoQueryOptionsTrait`, the `&$requestDto` controller signature, `expand()` in the action) — see `ddd-endpoint-specialist`.
- **Entities exposing `QueryOptionsTrait`** (auto-detected filterable/expandable properties, `#[LazyLoad]`, `#[Translatable(fullTextIndex: true)]`) — see `ddd-entity-specialist`.
- **Programmatic QueryOptions in services** (clone/restore the static default, mandatory server-side scope filters) — see `ddd-service-specialist`.
- **How `select`-narrowed output is serialized** (property hiding / output-key renaming that composes with `select`) — see `ddd-serializer-specialist`.
