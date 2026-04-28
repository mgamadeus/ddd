---
name: ddd-serializer-specialist
description: Work with the SerializerTrait in the mgamadeus/ddd framework — toObject, toJSON, setPropertiesFromObject, property hiding, aliases, persistence exclusion, and TOON (Token-Oriented Object Notation) compact array serialization. Use when configuring entity serialization, hiding sensitive fields, renaming output properties, or emitting compact tabular formats for high-cardinality arrays.
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
---

# DDD Serializer Specialist

The serialization layer that powers entity-to-array, entity-to-JSON, and array/object-to-entity conversion across the framework.

## When to Use

- Configuring how an entity is serialized to JSON for API responses
- Hiding sensitive properties (passwords, device IDs, internal state) from output
- Renaming properties in output (`#[OverwritePropertyName]`)
- Excluding properties from DB persistence while keeping them in API output (or vice versa)
- Reducing payload size for high-cardinality property arrays via TOON
- Deserializing API request bodies or DB rows back into typed entities
- Debugging "why is property X missing/visible/wrong-named in the output"

---

## The Serialization Pipeline

```
toJSON()                            -- entry point for JSON output
  -> toObject()                    -- entity -> array of plain values
     -> serializeProperty()         -- per-property logic
        -> apply attributes:        #[HideProperty], #[HidePropertyOnSystemSerialization],
                                    #[OverwritePropertyName], #[ExposePropertyInsteadOfClass],
                                    #[Aliases], #[DontPersistProperty], #[SerializeInToonFormat]
        -> recurse into nested objects/arrays
     -> apply runtime hides:        addPropertiesToHide(), addPropertiesToHideRecursively()
     -> apply TOON conversion:      convertArrayOfObjectsToToon() if activated
  -> json_encode()
```

```
setPropertiesFromObject() / setPropertiesFromSerializedObject()
  -> per-property: cast to typed value
     -> primitive: assign directly
     -> nested object: instantiate via reflection, recurse
     -> EntitySet: hydrate elements
     -> Date/DateTime: parse via fromString()
```

---

## Two Modes: User vs System Serialization

The framework distinguishes two serialization contexts:

| Context | Triggered by | What's hidden |
|---------|-------------|---------------|
| **User serialization** | `toJSON()` for API responses | Properties marked `#[HideProperty]` |
| **System serialization** | DB persistence, internal cache | Properties marked `#[HidePropertyOnSystemSerialization]` |

Pass `forPersistence: true` to `toObject()` for system context. The default is user context.

This dual mode is why properties can be visible in API output but excluded from DB storage (e.g., computed fields, external API data) — and vice versa (passwords are stored but never returned).

---

## Property-Level Attributes

All in namespace `DDD\Infrastructure\Traits\Serializer\Attributes\`.

### `#[HideProperty]` -- Hide from API output
```php
#[HideProperty]
public string $password;  // never appears in toJSON() output, but IS persisted
```

### `#[HidePropertyOnSystemSerialization]` -- Exclude from DB
```php
#[HidePropertyOnSystemSerialization]
public ?array $computedRanking;  // visible in API, never stored
```

### Both combined -- internal-only
```php
#[HideProperty]
#[HidePropertyOnSystemSerialization]
public ?string $internalNotes;  // hidden everywhere
```

### `#[OverwritePropertyName('aliasName')]` -- Rename in output
```php
#[OverwritePropertyName('x-tagGroups')]
public array $tagGroups;
// Output: { "x-tagGroups": [...] }
```

Common use: OpenAPI spec keys with reserved characters.

### `#[Aliases('oldName', 'legacyName')]` -- Backward-compatible aliases
```php
#[Aliases('userName', 'name')]
public string $nickname;
// Output emits ALL: { "nickname": "...", "userName": "...", "name": "..." }
```

For API consumers transitioning from old field names. Read direction also accepts any of the aliases.

### `#[ExposePropertyInsteadOfClass('innerProperty')]` -- Flatten nested object
```php
class TagGroup
{
    #[ExposePropertyInsteadOfClass('items')]
    public TagItems $tagItems;  // serializes as the items array directly, not a wrapper object
}
// Without: { "tagItems": { "items": [...] } }
// With:    { "tagItems": [...] }
```

### `#[DontPersistProperty]` -- Visible in API, not in DB
```php
#[DontPersistProperty]
public ?int $totalRunningTimeInSeconds;  // computed at read time, never written
```

Synonym for `#[HidePropertyOnSystemSerialization]` -- use whichever reads more naturally in context.

### `#[SerializeInToonFormat]` -- Compact tabular emission
See **TOON Section** below.

---

## Runtime Hide Methods (Per-Instance, Per-Class)

Beyond declarative attributes, properties can be hidden at runtime:

### Per-Instance

```php
$entity->addPropertiesToHide('email', 'phoneNumber', 'deviceId');
$entity->removePropertiesToHide('email');  // re-show one
$entity->getPropertiesToHide();             // returns current hide list
```

### Per-Class (static, lasts entire request)

```php
Account::setStaticPropertiesToHide(true, 'password', 'apiToken');     // current class only
Account::setStaticPropertiesToHide(false, 'password', 'apiToken');    // entire hierarchy
Account::removeStaticPropertiesToHide(true, 'password');
```

### Recursive (Dotted-Path)

```php
$entity->addPropertiesToHideRecursively([
    'account.email' => true,
    'account.person.age' => true,
    'tracks.elements.gpsRaw' => true,
]);
```

Hides nested properties via dot-path navigation through the entity graph during serialization.

### Common Pattern: mapToEntity hiding

In a DB repo's `mapToEntity()`, add hide rules based on auth context:

```php
public function mapToEntity(...): ?DefaultObject
{
    $entity = parent::mapToEntity(...);
    $authAccount = AuthService::instance()->getAccount();
    if (self::$applyRightsRestrictions && (!$authAccount || !$authAccount->roles->isAdmin())) {
        $entity->addPropertiesToHide('email', 'password', 'deviceTokenForNotifications');
    }
    return $entity;
}
```

See `ddd-rights-specialist` for the full rights-driven hiding pattern.

---

## TOON (Token-Oriented Object Notation)

**TOON** is a compact tabular format for arrays of homogeneous objects -- a single header row enumerates column names, then one short row per item. Reference: https://github.com/toon-format/toon

**Why TOON exists:** Arrays of 100+ similar objects (GPS track points, time-series samples, log entries) repeat the same JSON keys on every element. TOON emits the keys once.

```json
// Regular JSON (1000 GPS points × ~80 bytes per point with repeated keys = ~80KB)
[
  {"lat": 50.123, "lng": 8.456, "dateTime": "2026-04-27 10:00:00"},
  {"lat": 50.124, "lng": 8.457, "dateTime": "2026-04-27 10:00:01"},
  ...
]
```

```
// TOON (header once, ~30 bytes per row = ~30KB — 60% smaller)
lat,lng,dateTime
50.123,8.456,2026-04-27 10:00:00
50.124,8.457,2026-04-27 10:00:01
...
```

### Two Orthogonal Configuration Axes

The TOON system has two independent dimensions of configuration:

1. **ACTIVATION** -- "should this property's array be emitted as TOON?"
2. **COLUMN SPEC** -- "which inner-object fields appear, in what order, with what names?"

Both are independent. Activation without column spec uses default columns (union of all keys). Column spec without activation has no effect.

### Activation -- Three Sources (OR-combined)

#### A) Declarative -- `#[SerializeInToonFormat]` attribute

```php
class Track extends Entity
{
    #[SerializeInToonFormat]
    public ?TrackLocations $locations;
}
```

#### B) Class-wide imperative -- `setStaticPropertiesToSerializeAsToon()`

```php
Track::setStaticPropertiesToSerializeAsToon(true, 'locations', 'samples');
```

#### C) Per-instance imperative -- `addPropertiesToSerializeAsToon()`

```php
$track->addPropertiesToSerializeAsToon('locations');
$track->removePropertiesToSerializeAsToon('locations');
$track->isPropertySerializedAsToon('locations');  // true/false
```

All three are OR-combined inside `toObject()`. The output property name is the original name + `InToonFormat` suffix:
- `locations` -> `locationsInToonFormat` in the JSON output

This lets consumers distinguish TOON output from regular JSON arrays.

### Column Spec -- Two Sources (Instance Wins)

#### A) Class-wide -- `setStaticToonColumnsSpec()`

```php
TrackLocation::setStaticToonColumnsSpec(true, [
    'lat' => 'geoPoint.lat',
    'lng' => 'geoPoint.lng',
    't'   => 'dateTime',
]);
```

#### B) Per-instance -- `setToonColumnsSpec()`

```php
$trackLocations->setToonColumnsSpec([
    'lat' => 'geoPoint.lat',
    'lng' => 'geoPoint.lng',
    't'   => 'dateTime',
]);
$trackLocations->clearToonColumnsSpec();  // fall back to static or default
```

### Two Spec Input Formats

**List form** (numeric keys) -- path used as both column name AND lookup:
```php
$obj->setToonColumnsSpec(['geoPoint.lat', 'geoPoint.lng', 'dateTime']);
// Output columns: geoPoint.lat,geoPoint.lng,dateTime
```

**Map form** (string keys) -- keys are emitted column names (aliases), values are dot-paths:
```php
$obj->setToonColumnsSpec([
    'lat' => 'geoPoint.lat',
    'lng' => 'geoPoint.lng',
    't'   => 'dateTime',
]);
// Output columns: lat,lng,t  (compact aliases)
```

Renames matter at scale: 1000 rows × saving 8 chars per column header per row = significant payload reduction.

### Default Behavior (No Spec)

Without an explicit column spec, TOON emits the **union of all flattened property paths across all rows**, in first-seen order. Robust for varied items, but verbose. Specify a spec for production payloads.

### Resolution Order

When `toObject()` encounters a TOON-activated property:

1. Check instance-level spec (`$this->toonColumnsSpec`)
2. Fall back to class-level spec (`StaticRegistry::$toonColumnsSpecByClass[$class]`)
3. Fall back to union-of-all-keys default

### Storage in StaticRegistry

The framework caches static configuration in `StaticRegistry`:

| Property | Purpose |
|----------|---------|
| `$toonColumnsSpecByClass` | `<className> => <columnAlias> => <dotPath>` |
| `$propertiesToSerializeAsToonOnSerialization` | `<className> => <propertyName> => true` |

Mirror of `$propertiesToHideOnSerialization` -- but on the activation axis.

---

## toObject() Hook -- Per-Property Override

Some entities customize their own serialization for performance:

```php
class GeoPoint extends ValueObject
{
    public function toObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true,
        int $flags = 0
    ): mixed {
        return ['lat' => $this->lat, 'lng' => $this->lng];  // skip reflection-based serialization
    }
}
```

When implementing custom `toObject()`:
- Match the parent signature exactly
- Decide whether to honor `$forPersistence`, `$ignoreHideAttributes`, etc.
- Return a plain array (or scalar for `$returnUniqueKeyInsteadOfContent`)

---

## setPropertiesFromObject() -- Deserialization

The reverse direction: hydrate a typed entity from a plain array/object/JSON-decoded structure.

```php
$entity = new MyEntity();
$entity->setPropertiesFromObject($requestData);

// JSON-decoded form
$entity->setPropertiesFromSerializedObject($jsonString);
```

The framework uses property reflection to:
- Cast scalars to declared types
- Instantiate nested objects (via `newInstance()` or constructor)
- Hydrate ObjectSets/EntitySets element by element
- Parse `Date`/`DateTime` from strings via `fromString()`
- Honor `#[Aliases]` for backward-compatible field names

Used by:
- `RequestDto::setPropertiesFromRequest()` (HTTP request bodies)
- `DBEntity::mapToEntity()` (Doctrine -> domain)
- `AppMessage::decode()` (Messenger payload deserialization)

---

## SerializerRegistry -- Cache

`DDD\Infrastructure\Traits\Serializer\SerializerRegistry::$toOjectCache` (typo `toOject` is intentional in the codebase) caches the result of `toObject()` calls within a single request to avoid re-serializing the same object graph.

When mutating serialization config (hide, TOON spec) on already-serialized objects, **clear the cache**:

```php
SerializerRegistry::$toOjectCache = [];
```

The setter methods in SerializerTrait do this automatically when they invalidate state:
- `setToonColumnsSpec()` clears the cache
- `clearToonColumnsSpec()` clears the cache

---

## Use Cases & Patterns

### Hide Sensitive Account Fields by Default

```php
class Account extends Entity
{
    #[HideProperty]
    public string $password;

    #[HideProperty]
    public ?string $deviceTokenForNotifications;

    public string $email;  // visible by default
}

// Then runtime hide for non-self access:
$account->addPropertiesToHide('email', 'gender', 'age');
```

### Output Field Renaming for OpenAPI

```php
class OpenApiSpec
{
    #[OverwritePropertyName('x-tagGroups')]
    public array $tagGroups;
}
```

### Computed Property (API only, not persisted)

```php
class Track extends Entity
{
    use ChangeHistoryTrait;

    #[DontPersistProperty]
    public ?int $totalRunningTimeInSeconds = null;

    public function calculateTotalRunningTime(): void
    {
        $this->totalRunningTimeInSeconds = ...;
    }
}
```

### Compact GPS Track Output

```php
class Track extends Entity
{
    #[SerializeInToonFormat]
    public ?TrackLocations $locations;
}

// Optional: configure columns class-wide
TrackLocation::setStaticToonColumnsSpec(true, [
    'lat' => 'geoPoint.lat',
    'lng' => 'geoPoint.lng',
    't' => 'dateTime',
]);

// Runtime activation override
$track->addPropertiesToSerializeAsToon('locations');
```

Output:
```json
{
  "id": 42,
  "locationsInToonFormat": "lat,lng,t\n50.123,8.456,2026-04-27 10:00:00\n..."
}
```

### Backward-Compatible API Field Rename

```php
class Account extends Entity
{
    #[Aliases('userName')]  // legacy clients used "userName"
    public string $nickname;
}
```

Output emits both keys; input accepts either.

### Flatten Wrapper Class

```php
class TagGroup
{
    public string $name;

    #[ExposePropertyInsteadOfClass('items')]
    public TagItems $tagItems;  // serialize as the items array, drop the wrapper
}

// Without: { "name": "...", "tagItems": { "items": [...] } }
// With:    { "name": "...", "tagItems": [...] }
```

---

## Performance Notes

- **Cache `toObject` results** -- The framework caches by `(uniqueKey, path, hide-config-hash)`. Don't fight it.
- **TOON for high-cardinality arrays** -- Net wins start around 50+ items per array.
- **`addPropertiesToHide()` invalidates the cache** -- When hiding fields based on auth, do it once at the entity boundary (e.g. in `mapToEntity()`), not repeatedly in controllers.
- **Custom `toObject()` for hot paths** -- ValueObjects with 2-3 properties (GeoPoint, MoneyAmount) often override `toObject()` to skip reflection.
- **`setPropertiesToHideRecursively()` is O(depth × breadth)** -- For deep entity graphs with many recursive hides, prefer setting hides at the level where the property lives.

---

## Troubleshooting

| Symptom | Likely cause |
|---------|-------------|
| Property visible in API but should be hidden | Missing `#[HideProperty]` or `addPropertiesToHide()` not called for this account context |
| Property hidden in API but should be visible | `#[HideProperty]` present, or static class-level hide active. Check `getPropertiesToHide()` |
| Field renamed in output but you didn't ask | `#[OverwritePropertyName]` somewhere in the inheritance chain |
| Old/new field both appear | `#[Aliases]` is intentional -- emits all alias keys |
| Property persisted to DB but shouldn't be | Missing `#[HidePropertyOnSystemSerialization]` or `#[DontPersistProperty]` |
| TOON output wrong columns | Check resolution order: instance spec -> class spec -> default. Use `getToonColumnsSpec()` to inspect |
| TOON not activating | Verify one of: `#[SerializeInToonFormat]`, `setStaticPropertiesToSerializeAsToon`, `addPropertiesToSerializeAsToon` |
| Stale serialized output after config change | `SerializerRegistry::$toOjectCache = [];` -- the setters clear it but manual cache mutations don't |
| `setPropertiesFromObject` ignores some fields | Check `#[Aliases]` mappings; fields without matching declared property + alias are silently dropped |
| Nested object not deserialized correctly | Property must be typed (`public ?MyEntity $foo`); untyped properties stay as stdClass |

---

## API Cheat Sheet

```php
// Output
$entity->toObject();                                  // -> array
$entity->toObject(forPersistence: true);              // system context
$entity->toJSON();                                    // -> string
$entity->toJSON(ignoreHideAttributes: true);          // include hidden

// Hide control (instance)
$entity->addPropertiesToHide('a', 'b');
$entity->removePropertiesToHide('a');
$entity->getPropertiesToHide();
$entity->addPropertiesToHideRecursively(['a.b' => true]);

// Hide control (class)
MyEntity::setStaticPropertiesToHide(forCurrentClass: true, 'a', 'b');
MyEntity::removeStaticPropertiesToHide(forCurrentClass: true, 'a');

// TOON activation
$entity->addPropertiesToSerializeAsToon('locations');
$entity->removePropertiesToSerializeAsToon('locations');
$entity->isPropertySerializedAsToon('locations');
MyEntity::addStaticPropertiesToSerializeAsToon(forCurrentClass: true, 'locations');
MyEntity::removeStaticPropertiesToSerializeAsToon(forCurrentClass: true, 'locations');

// TOON column spec
$entity->setToonColumnsSpec(['lat' => 'geoPoint.lat', ...]);
$entity->clearToonColumnsSpec();
$entity->getToonColumnsSpec();
MyEntity::setStaticToonColumnsSpec(forCurrentClass: true, ['lat' => 'geoPoint.lat']);
MyEntity::clearStaticToonColumnsSpec(forCurrentClass: true);

// Input
$entity->setPropertiesFromObject($arrayOrObject);
$entity->setPropertiesFromSerializedObject($jsonString);

// Cache
SerializerRegistry::$toOjectCache = [];
```
