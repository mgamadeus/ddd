---
name: ddd-serializer-specialist
description: Work with the SerializerTrait in mgamadeus/ddd — the one serialization layer behind ALL API output, DB persistence, request hydration and message payloads (every DefaultObject, RequestDto and RestResponseDto uses it). Covers toObject/toJSON and the forPersistence dual mode (DontPersistProperty vs HideProperty vs HidePropertyOnSystemSerialization), setPropertiesFromObject hydration (aliases are output-only), attribute and runtime hiding incl. dotted-path hides, renaming via OverwritePropertyName and Aliases, ExposePropertyInsteadOfClass flattening, per-class toObject overrides, SerializerRegistry cache semantics, TOON tabular serialization, and model-facing time zones (MODEL_FACING_DATETIME, withInputTimezone/withModelFacingTimezone, DateTime::fromStringInZone/formatForModel). Use when configuring serialization, hiding fields, renaming output, excluding fields from persistence, debugging missing or wrong-named output, emitting tabular formats, or converting date-times at an LLM/MCP tool boundary.
metadata:
  author: mgamadeus
  version: "1.3.2"
  framework: mgamadeus/ddd
---

# DDD Serializer Specialist

The serialization layer that powers entity-to-array, entity-to-JSON, and array/object-to-entity conversion across the framework.

## Universal Scope -- Every DDD Object Uses This Serializer

`SerializerTrait` is mixed into `DefaultObject`, the abstract base for every domain object in the framework. **All of these inherit it transparently:**

```
DefaultObject (uses SerializerTrait)
  ├─ Entity                  → all your domain entities
  ├─ ValueObject             → MoneyAmount, GeoPoint, PostalAddress, etc.
  │   ├─ ObjectSet           → all collections
  │   │   └─ EntitySet       → typed entity collections
  │   └─ AppMessage          → Symfony Messenger payloads

(NOTE: `RequestDto` and `RestResponseDto` do NOT extend `DefaultObject` — `RequestDto` is standalone and merely
`use`s `SerializerTrait`, and `RestResponseDto` extends Symfony's `JsonResponse` (also `use`s the trait). There is
no `ResponseDto` class. So the trait reaches them, but they are not in the `DefaultObject` hierarchy above.)
```

There is **no opt-in**. Whenever the framework reads or writes any of these types -- API responses, DB persistence, lazy-load cache, Argus payloads, Messenger transport, request hydration -- it goes through `toObject()` / `setPropertiesFromObject()`.

That means every serialization decision in this skill (hide rules, attributes, TOON, aliases) applies uniformly across all four layers: presentation, domain, persistence, and integration.

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
setPropertiesFromObject()  // JSON hydration: setPropertiesFromObject(json_decode($json)) — no setPropertiesFromSerializedObject() exists
  -> per-property: cast to typed value
     -> primitive: assign directly
     -> nested object: instantiate via reflection, recurse
     -> EntitySet: hydrate elements
     -> Date/DateTime: parse via fromString()
```

---

## Two Modes: User vs Persistence Serialization

The framework distinguishes two serialization contexts:

| Context | Triggered by | What's hidden |
|---------|-------------|---------------|
| **User serialization** | `toJSON()` for API responses | Properties marked `#[HideProperty]` |
| **Persistence serialization** | DB persistence (`toObject(forPersistence: true)` — the DEFAULT) | Properties marked `#[DontPersistProperty]` (and `#[DatabaseColumn(ignoreProperty: true)]`) |

`forPersistence` **defaults to `true`** (persistence context) and drops `#[DontPersistProperty]` fields. Pass `forPersistence: false` for user/API context — the response boundary (`RestResponseDto::getContent()`, `RestResponseDto.php:36-41`) does exactly this, which is the ONLY reason a `#[DontPersistProperty]` field reaches an API response. NOTE: `#[HidePropertyOnSystemSerialization]` is a SEPARATE mechanism consulted ONLY by PHP `__serialize()` (cache/session), NOT by `forPersistence` and NOT the DB.

This dual mode is why properties can be visible in API output but excluded from DB storage (e.g., computed fields, external API data) — and vice versa (passwords are stored but never returned).

---

## Property-Level Attributes

All in namespace `DDD\Infrastructure\Traits\Serializer\Attributes\`.

### `#[HideProperty]` -- Hide from API output
```php
#[HideProperty]
public string $password;  // never appears in toJSON() output, but IS persisted
```

### `#[HidePropertyOnSystemSerialization]` -- Exclude from PHP `__serialize()` (cache/session), NOT the DB
```php
#[HidePropertyOnSystemSerialization]
public ?array $transient;  // dropped from __serialize() output only — to exclude from the DB use #[DontPersistProperty]
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

For API consumers transitioning from old field names. Aliases are emitted only in the **output** direction (`SerializerTrait.php:699-708`) — an alias key in an **input** payload is NOT read; hydration matches the real property name only.

### `#[ExposePropertyInsteadOfClass('innerProperty')]` -- Flatten nested object
```php
// The attribute is Attribute::TARGET_CLASS (read OFF THE CLASS) — it goes on the class, NOT on a
// property, and its argument is the property whose value REPLACES the whole object in output.
#[ExposePropertyInsteadOfClass('tagItems')]
class TagGroup
{
    public TagItems $tagItems;  // the whole TagGroup serializes as this property's value directly
}
// Without: the TagGroup object   With: the tagItems value in its place
// (Real example: TagGroups.php → `#[ExposePropertyInsteadOfClass('elements')] class TagGroups extends ObjectSet`.)
```

### `#[DontPersistProperty]` -- Visible in API, not in DB
```php
#[DontPersistProperty]
public ?int $totalRunningTimeInSeconds;  // computed at read time, never written
```

NOT a synonym for `#[HidePropertyOnSystemSerialization]` — they are unrelated attributes read at different points. `#[DontPersistProperty]` is the DB-persistence exclusion (selected via `forPersistence`, `SerializerTrait.php:1179`); `#[HidePropertyOnSystemSerialization]` is consulted only by `__serialize()` (`:662`).

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
Account::addStaticPropertiesToHide(true, 'password', 'apiToken');     // current class only
Account::addStaticPropertiesToHide(false, 'password', 'apiToken');    // entire hierarchy
Account::removeStaticPropertiesToHide(true, 'password');
```

### Recursive (Dotted-Path)

```php
// Shape is array<string path, string[] propertyNames> — the KEY is the path to the container, the VALUE is
// the LIST of property names to hide there. `'account.email' => true` is silently SKIPPED (wrong shape).
$entity->addPropertiesToHideRecursively([
    'account' => ['email'],
    'account.person' => ['age'],
    'tracks.elements' => ['gpsRaw'],
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
// TOON: the header line is `[<rowCount>](col1,col2,…):` — NOT a bare comma list. A consumer parsing a
// bare `lat,lng,…` header breaks on line 1 (SerializerTrait.php:920-937).
[2](lat,lng,dateTime):
50.123,8.456,2026-04-27 10:00:00
50.124,8.457,2026-04-27 10:00:01
```

### Two Orthogonal Configuration Axes

The TOON system has two independent dimensions of configuration:

1. **ACTIVATION** -- "should this property's array be emitted as TOON?"
2. **COLUMN SPEC** -- "which inner-object fields appear, in what order, with what names?"

Both are independent. Activation without column spec uses default columns (union of all keys). Column spec without activation has no effect.

### Activation -- Three Sources (gated by the master switch)

> **Master gate:** the DECLARATIVE route (`#[SerializeInToonFormat]`) fires only when `static::$toonEnabledSerialization` is `true` — and it defaults to **`false`** (`SerializerTrait.php:62`, `:680-693`). Set it (e.g. `Track::$toonEnabledSerialization = true`) or the attribute is inert. The two imperative routes are not gated. So the three are not simply "OR-combined": the attribute route additionally requires the master switch.

#### A) Declarative -- `#[SerializeInToonFormat]` attribute

```php
class Track extends Entity
{
    #[SerializeInToonFormat]
    public ?TrackLocations $locations;
}
```

#### B) Class-wide imperative -- `addStaticPropertiesToSerializeAsToon()`

```php
Track::addStaticPropertiesToSerializeAsToon(true, 'locations', 'samples');
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
$entity->setPropertiesFromObject(json_decode($jsonString)); // there is NO setPropertiesFromSerializedObject(); the arg must be an OBJECT (stdClass from json_decode is fine) — a raw string fatals
```

The framework uses property reflection to:
- Cast scalars to declared types
- Instantiate nested objects (via `newInstance()` or constructor)
- Hydrate ObjectSets/EntitySets element by element
- Parse `Date`/`DateTime` from strings via `fromString()`
- NOTE: `#[Aliases]` is NOT honored on input — hydration reads only the real declared property name (aliases are output-only, see above)

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

## Model-Facing Time Zones (`MODEL_FACING_DATETIME`, `$inputTimezone`, `$modelFacingTimezone`)

An LLM tool call is the one caller that reads and writes wall-clock time in somebody's local zone instead of the storage zone. Two request-scoped registry values open that conversion — and **nothing changes while both are null**, which is every REST, persistence, cache and Argus path.

```php
use DDD\Infrastructure\Traits\Serializer\Serializer;
use DDD\Infrastructure\Traits\Serializer\SerializerRegistry;

$zone = new DateTimeZone('America/New_York');

// INPUT: a DateTime property written as "2026-09-14 14:30:00" is read as 14:30 in New York
$arguments = SerializerRegistry::withInputTimezone($zone, fn () => $dto->setPropertiesFromObject($payload));

// OUTPUT: DateTime properties render as local time WITH their offset
$result = SerializerRegistry::withModelFacingTimezone(
    $zone,
    fn () => $entity->toObject(forPersistence: false, flags: Serializer::MODEL_FACING_DATETIME)
);
// → "2026-09-14 10:30:00-04:00"
```

Both helpers save and restore the previous value in a `finally`, so nesting restores the OUTER zone, not null, and an exception inside the region cannot leak a zone into the rest of the request.

**Output needs BOTH the flag and the zone**, plus the right type:

| Condition | Result |
|-----------|--------|
| no flag, no zone | unchanged (today's behaviour) |
| zone set, no flag | unchanged — persistence (`forPersistence: true`, no flags), cache serialization and REST output can never enter the branch |
| flag set, no zone | unchanged |
| flag + zone, `DateTime` property | local time with offset, e.g. `2026-09-14 10:30:00-04:00` |
| flag + zone, `Date` property | unchanged — a calendar day has no offset to render |

`formatForModel()` clones: the instance itself is never mutated.

**A repository read must never happen inside the region.** `DBEntity::mapToEntity()` and
`ValueObjectTrait::mapFromRepository()` throw an `InternalErrorException` naming the zone when
`SerializerRegistry::$inputTimezone` is set: a persisted date-time carries no offset, so hydrating it through an input
zone would shift the instant by the offset (and a stored value inside a spring-forward gap would throw out of
hydration). Wrap model-supplied ARGUMENTS in `withInputTimezone()`, never an entity load. (The geometry value objects
override `mapFromRepository()` and parse their own primitives, so they never reach the generic hydration this guards.)

**Input is keyed on `DateTime::class` exactly.** `SerializerRegistry::hydrateFromString()` routes only that one type through the zone-aware parser; `Date` (with its own `fromString()` and shared-instance cache) and every subclass stay on the unchanged path.

### `DateTime::fromStringInZone()` — what it accepts and what it refuses

```php
DateTime::fromStringInZone('2026-09-14 14:30:00', $zone);   // wall clock in $zone
DateTime::fromStringInZone('2026-09-14 14:30', $zone);      // seconds optional → :00, never "now"
DateTime::fromStringInZone('2026-09-14 14:30:00+02:00', $zone); // explicit offset WINS over $zone
DateTime::fromStringInZone('2026-09-14T14:30:00Z', $zone);  // Z == +00:00
DateTime::fromStringInZone('2026-9-14 1:34', $zone);        // false — canonical input only
DateTime::fromStringInZone('2026-03-08 02:30:00', $newYork);// throws NonexistentLocalTimeException
```

- **Canonical only.** The digits must print back byte-for-byte, so a sloppy `2026-9-14 1:34` is *no match* (returns `false`) rather than a silent guess.
- **A nonexistent local time is refused.** PHP shifts `02:30` to `03:30` on a spring-forward day; the parser compares the zone-parsed digits with the literal ones and throws `NonexistentLocalTimeException` (a `BadRequestException`) naming the next existing local time.
- **An ambiguous local time** (the repeated hour in autumn) resolves to the FIRST instance; when the model echoes back a value it rendered, the offset it carries disambiguates it exactly.
- `MODEL_INPUT_FORMATS` lists the accepted shapes, offset-bearing forms first. Pass your own array as the third argument to narrow them.

When a `DateTime` property cannot be parsed **and** an input zone is set, hydration throws a `BadRequestException` naming the expected shape instead of dropping the value — the model gets told how to write it. With `throwErrors: false` the existing suppression still applies.

**An EMPTY string is "not provided", never a malformed date-time.** `""` (and whitespace-only) on a `DateTime`
property leaves the property untouched instead of raising the corrective — agent models reliably fill every optional
field with `""` rather than omitting it, and rejecting that failed whole writes for a value carrying nothing. Off the
zone-aware path nothing changes: `DateTime::fromString('')` already returns `false`, which was never assigned either.
Non-empty malformed values keep the corrective.

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
  "locationsInToonFormat": "[2](lat,lng,t):\n50.123,8.456,2026-04-27 10:00:00\n50.124,8.457,2026-04-27 10:00:01"
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

Output emits both keys; input hydration matches ONLY the real property name (`nickname`) — aliases are not read on input.

### Flatten Wrapper Class

```php
#[ExposePropertyInsteadOfClass('tagItems')]  // ON THE CLASS (TARGET_CLASS) — names the property to expose
class TagGroup
{
    public string $name;
    public TagItems $tagItems;  // the TagGroup serializes as this property's value (the wrapper is dropped)
}

// Without: { "name": "...", "tagItems": { "items": [...] } }
// With:    { "name": "...", "tagItems": [...] }
```

---

## Performance Notes

- **Cache `toObject` results** -- The framework caches by `spl_object_id` + the four flags (`ignoreHideAttributes`, `ignoreNullValues`, `forPersistence`, `flags`) + the hide-list keys (`SerializerTrait.php:554-558`) — NOT `(uniqueKey, path, hide-config-hash)`. Don't fight it.
- **TOON for high-cardinality arrays** -- Net wins start around 50+ items per array.
- **`addPropertiesToHide()` does NOT invalidate any cache** -- it never touches `SerializerRegistry` (`:159-164`); staleness is a non-issue because the hide list is part of the cache key. Still, hide once at the entity boundary (e.g. `mapToEntity()`), not repeatedly in controllers.
- **Custom `toObject()` for hot paths** -- ValueObjects with 2-3 properties (GeoPoint, MoneyAmount) often override `toObject()` to skip reflection.
- **`addPropertiesToHideRecursively()` is O(depth × breadth)** -- For deep entity graphs with many recursive hides, prefer setting hides at the level where the property lives.

---

## Troubleshooting

| Symptom | Likely cause |
|---------|-------------|
| Property visible in API but should be hidden | Missing `#[HideProperty]` or `addPropertiesToHide()` not called for this account context |
| Property hidden in API but should be visible | `#[HideProperty]` present, or static class-level hide active. Check `getPropertiesToHide()` |
| Field renamed in output but you didn't ask | `#[OverwritePropertyName]` somewhere in the inheritance chain |
| Old/new field both appear | `#[Aliases]` is intentional -- emits all alias keys |
| Property persisted to DB but shouldn't be | Missing `#[DontPersistProperty]` — note `#[HidePropertyOnSystemSerialization]` does NOT affect the DB (it only affects `__serialize()`) |
| TOON output wrong columns | Check resolution order: instance spec -> class spec -> default. Use `getToonColumnsSpec()` to inspect |
| TOON not activating | Verify one of: `#[SerializeInToonFormat]`, `addStaticPropertiesToSerializeAsToon`, `addPropertiesToSerializeAsToon` |
| Stale serialized output after config change | `SerializerRegistry::$toOjectCache = [];` -- the setters clear it but manual cache mutations don't |
| `setPropertiesFromObject` ignores some fields | Field name must match the real declared property — `#[Aliases]` are output-only and NOT read on input; unmatched fields are silently dropped |
| Nested object not deserialized correctly | Property must be typed (`public ?MyEntity $foo`); an **untyped** property THROWS `InternalErrorException('… has no Type definition')` on hydration (`ReflectionAllowedTypes.php:29-32`) — it does not stay as stdClass |

---

## API Cheat Sheet

```php
// Output
$entity->toObject();                                  // -> array (forPersistence defaults to TRUE = persistence context)
$entity->toObject(forPersistence: false);             // user/API context — keeps #[DontPersistProperty] fields
$entity->toJSON();                                    // -> string
$entity->toJSON(ignoreHideAttributes: true);          // include hidden

// Hide control (instance)
$entity->addPropertiesToHide('a', 'b');
$entity->removePropertiesToHide('a');
$entity->getPropertiesToHide();
$entity->addPropertiesToHideRecursively(['a' => ['b']]);  // KEY = container path, VALUE = list of property names ('a.b' => true is silently skipped)

// Hide control (class)
MyEntity::addStaticPropertiesToHide(forCurrentClass: true, 'a', 'b');
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
$entity->setPropertiesFromObject(json_decode($jsonString)); // there is NO setPropertiesFromSerializedObject(); the arg must be an OBJECT (stdClass from json_decode is fine) — a raw string fatals

// Cache
SerializerRegistry::$toOjectCache = [];
```

---

## Cross-Reference

- **Entities carrying these attributes** (`#[HideProperty]`, `#[DontPersistProperty]`, `#[Translatable]`, custom `toObject()` on value objects) — see `ddd-entity-specialist`.
- **Serialized output as API responses** (`RestResponseDto`, `expand()`, the response payloads this trait emits) — see `ddd-endpoint-specialist`.
- **Auth-driven property hiding** (the `mapToEntity()` rights pattern that calls `addPropertiesToHide`) — see `ddd-rights-specialist`.
- **`$select` field selection** (the API-side way to narrow output, which composes with this trait's property hiding) — see `ddd-query-options-specialist`.
