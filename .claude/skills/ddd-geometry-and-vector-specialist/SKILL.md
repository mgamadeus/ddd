---
name: ddd-geometry-and-vector-specialist
description: Pick, declare, query, and migrate geometry and vector value objects in the mgamadeus/ddd framework. Use when working with GeoPoint, GeoBounds, Vector, or the Cartesian geometry family (Point2D, Polyline, Polygon, BoundingBox2D) — choosing the right type, mapping to the right native DB column, writing spatial / vector queries, or reasoning about schema-diff impact.
metadata:
  author: mgamadeus
  version: "1.0.0"
---

# DDD Geometry & Vector Specialist

The framework ships three families of typed value objects that map to native database storage:

- **Geo (SRID 4326)** — `GeoPoint`, `GeoBounds`. Latitude/longitude on the WGS84 ellipsoid. For real-world coordinates and Earth-distance math.
- **Cartesian (SRID 0)** — `Point2D`, `Polyline`, `Polygon`, `BoundingBox2D`. Flat 2D space — pixels, mm, normalised coords, drawing canvases. No Earth projection.
- **Vector** — `Vector`. N-dimensional float arrays for embeddings and ANN search.

Each family has its own Doctrine type registrations, its own SQL column types, and its own query operators. Picking the wrong family is the most common foot-gun — the diff system won't catch the mismatch because both sides agree on a typed column; the bug only surfaces when an operator returns nonsense.

## When to Use

- A new entity needs a point, line, polygon, bounding box, or embedding column.
- Debugging "why does my spatial query return zero rows" / "why is the diff showing a MODIFY on a column I haven't touched".
- Adding a new spatial / vector operator to a service.
- Deciding whether `Polygon` or `BoundingBox2D` (or four float columns) is the right storage shape.
- Reading the schema diff for a column that uses one of these types and wondering whether the diff is real or normaliser noise.

## Type-Selection Matrix

Pick the type by **what the data represents**, not by what looks similar.

| Data | Type | SRID | Storage column | Indexable? |
|---|---|---|---|---|
| Real-world coordinate (location, address centroid) | `GeoPoint` | 4326 | `POINT` (via brick/geo) | SPATIAL |
| Real-world rectangle (delivery zone bbox, map viewport) | `GeoBounds` | 4326 | usually JSON; convert to POLYGON via `toBrickPolygon` if you need spatial index | optional |
| Pixel / mm / normalised 2D coordinate | `Point2D` | 0 | `POINT` (our `cartesian_point` type) | SPATIAL |
| Free-form drawing stroke / route line / fence | `Polyline` | 0 | `LINESTRING` (our `cartesian_linestring` type) | SPATIAL |
| Filled region with optional holes | `Polygon` | 0 | `POLYGON` (our `cartesian_polygon` type) | SPATIAL |
| Axis-aligned rectangle (crop, ROI, image hit-test) | `BoundingBox2D` | 0 | `POLYGON` (our `cartesian_bbox` type) | SPATIAL |
| Embedding / ANN-search vector | `Vector` | n/a | `VECTOR(N)` (MariaDB only) | VECTOR |

**Decision rules:**

- If the coords carry an Earth meaning, use the **Geo** family. Distance math is geodesic (great-circle), and stays correct as longitudes get clipped near the poles. Cartesian distance on lat/lng silently miscalculates.
- If the coords are arbitrary 2D (pixels, mm, anything where x=10 doesn't mean "10° east of Greenwich"), use the **Cartesian** family. Trying to use `GeoPoint` here works mechanically but the SRID 4326 column rejects values outside the `(-180, +180), (-90, +90)` bounds — your pixel `(2000, 1500)` will fail validation.
- If you need approximate nearest-neighbour search over a learned embedding, use **`Vector`**. Don't try to fake it with separate float columns — there are no nearest-neighbour operators on bare floats.

**Don't be tempted to fold:**

- `Polygon` vs `BoundingBox2D` — both serialise to `POLYGON`, but `BoundingBox2D` is `(x, y, w, h)` with `O(1)` predicates and a tighter API. Fold to it whenever the shape is genuinely axis-aligned.
- `Polyline` vs `Polygon` — open vs closed. `Polyline` is for paths that have a start and end. `Polygon` is for areas. Don't store a polygon as a "closed polyline".
- `GeoPoint` vs `Point2D` — both have an `(x, y)`-shaped wire representation but the SRID is non-fungible. Mixing them in math produces silently wrong results.

## Where the Code Lives

### Value objects

```
src/Domain/Common/Entities/GeoEntities/
  GeoPoint.php         # SRID 4326, lat/lng
  GeoBounds.php        # SRID 4326, four corners
src/Domain/Common/Entities/Geometry/Cartesian/
  Point2D.php          # SRID 0, (x, y)
  Polyline.php         # SRID 0, open Point2D[]
  Polygon.php          # SRID 0, outer ring + optional inner holes
  BoundingBox2D.php    # SRID 0, (x, y, width, height)
src/Domain/Common/Entities/MathEntities/
  Vector.php           # N-dim float[]
```

### Doctrine types (Custom/Types/)

| Class | Registration name | Backing PHP type | DB type |
|---|---|---|---|
| `BrickPointType` (vendor `Brick\Geo\Doctrine\Types\PointType`) | `point` | `Brick\Geo\Point` | `POINT` |
| `BrickLineStringType` (vendor) | `linestring` | `Brick\Geo\LineString` | `LINESTRING` |
| `BrickPolygonType` (`DoctrineExtensions\Types\PolygonType`) | `polygon` | `Brick\Geo\Polygon` | `POLYGON` |
| `PointType` (ours) | `cartesian_point` | `Point2D` | `POINT` |
| `LineStringType` (ours) | `cartesian_linestring` | `Polyline` | `LINESTRING` |
| `PolygonType` (ours) | `cartesian_polygon` | `Polygon` | `POLYGON` |
| `BoundingBoxType` (ours) | `cartesian_bbox` | `BoundingBox2D` | `POLYGON` (4-vertex closed rectangle) |
| `VectorType` (ours) | `vector` | `Vector` (`float[]`) | `VECTOR(N)` MariaDB |

All eight register in `EntityManagerFactory`. The brick/geo imports are aliased there to free up the short names `PointType` / `LineStringType` / `PolygonType` for our own classes.

### Why brick/geo and Cartesian share underlying SQL types

`Point2D` and `GeoPoint` both sit on `POINT`. `Polyline` and any future Geo-LineString both sit on `LINESTRING`. `Polygon` / `BoundingBox2D` / a future Geo-Polygon all sit on `POLYGON`. We distinguish them by SRID (0 vs 4326) and by Doctrine type name (`cartesian_*` vs the bare brick/geo names).

The `DatabaseColumn` schema-allocation logic was reworked in v2.14.1 so it disambiguates **by PHP class**, not by SQL type alone — otherwise our `Point2D` would have been routed through brick/geo's `PointType` at runtime and produced `Brick\Geo\Point` objects instead of our VO.

## Declaring a Field

### Cartesian point

```php
use DDD\Domain\Common\Entities\Geometry\Cartesian\Point2D;
use Symfony\Component\Validator\Constraints\NotNull;

#[NotNull]
public Point2D $origin;          // → POINT column, type='cartesian_point', SPATIAL INDEX

public ?Point2D $optionalAnchor; // nullable: column is nullable POINT
```

The schema generator emits `#[ORM\Column(type: 'cartesian_point')]` on the DB model, registers a `SPATIAL INDEX` by default (via `SPATIAL_SQL_TYPES`), and the upsert path wraps the parameter with `ST_GeomFromText(?)`.

### Polyline / Polygon / BoundingBox2D

```php
public ?Polyline $stroke;        // → LINESTRING column, type='cartesian_linestring'

#[NotNull]
public Polygon $zone;            // → POLYGON column, type='cartesian_polygon'

public ?BoundingBox2D $cropROI;  // → POLYGON column, type='cartesian_bbox'
                                 //   (stored as 4-vertex axis-aligned rectangle)
```

### GeoPoint (real-world)

```php
use DDD\Domain\Common\Entities\GeoEntities\GeoPoint;

public ?GeoPoint $location;      // → POINT column, type='point' (brick/geo),
                                 //   stores Brick\Geo\Point under the hood
```

### Vector

```php
use DDD\Domain\Common\Entities\MathEntities\Vector;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Column(type: 'vector', length: 1536)]
#[DatabaseColumn(/* length: 1536 */)]
public ?Vector $embedding;
```

The `length` (= dimension) is **required** — MariaDB needs `VECTOR(N)` with an explicit N. The generator emits `length: N` on `#[ORM\Column]`. Forgetting it makes `VectorType::getSQLDeclaration()` throw.

### Indexing — POINT is auto-indexed, LINESTRING/POLYGON opt-in

The default index allocation is split by SQL type:

| SQL type | Default index | Reason |
|---|---|---|
| `POINT` | `TYPE_SPATIAL` | The canonical use case (`GeoPoint` proximity queries via `ST_Distance_Sphere`, `ST_Within`) almost always wants the R-tree. Auto-allocated unless the property is nullable (see below). |
| `LINESTRING` / `POLYGON` | `TYPE_NONE` (opt-in) | Lines and polygons are usually read-by-parent-FK (zones, drawing strokes) and rarely spatially queried — auto-emitting would just slow down inserts. |
| `VECTOR` | `TYPE_VECTOR` | ANN search is the only reason `VECTOR` columns exist; always indexed. |

**To enable a SPATIAL index on LINESTRING / POLYGON, opt in explicitly:**

```php
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use Symfony\Component\Validator\Constraints\NotNull;

#[NotNull]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_SPATIAL)]
public Polygon $zone;
```

### Hard MySQL constraint: SPATIAL requires NOT NULL

MySQL/MariaDB reject `CREATE SPATIAL INDEX` on a column that allows `NULL` (error `1252: All parts of a SPATIAL index must be NOT NULL`).

The framework handles this defensively in two places:
- **Auto-allocated SPATIAL on nullable POINT**: the index is silently downgraded to no index. The column itself works fine. Add `#[NotNull]` to opt back into the index.
- **Explicit `#[DatabaseIndex(TYPE_SPATIAL)]` on a nullable column**: same behaviour — silently dropped. Same pattern as `TYPE_FULLTEXT` on JSON columns.

**To get a SPATIAL index, the property MUST also be `#[NotNull]`** — see the example above.

### Suppressing an explicit index

If you previously declared an explicit `#[DatabaseIndex(TYPE_SPATIAL)]` and want to drop it:

```php
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
public ?Polygon $shape;
```

For columns without any `#[DatabaseIndex]` attribute, the default is already `TYPE_NONE` for spatial types — no action needed.

## Function Catalog

All registered as DQL functions in `EntityManagerFactory`. Use as `WHERE ST_Within(t.zone, :viewport) = 1`, never as bare-SQL strings.

### Geometry — geometry-returning (registered via `addCustomStringFunction`)

| DQL function | What it does |
|---|---|
| `ST_GeomFromText(wkt[, srid])` | Parse WKT → geometry. Most common write path. |
| `ST_PointFromText(wkt[, srid])` / `ST_LineFromText(wkt[, srid])` / `ST_PolyFromText(wkt[, srid])` | Type-checked parsers. Reject WKT of the wrong geometry kind. |
| `ST_AsText(geometry)` | Geometry → WKT. Use in SELECTs when you need a human-readable form. |
| `ST_GeomFromGeoJSON(json)` / `ST_AsGeoJSON(geometry)` | GeoJSON roundtrip. |
| `ST_GeometryType(geometry)` | Returns `'POINT'` / `'LINESTRING'` / etc. — useful in mixed-type columns. |
| `ST_Envelope(geometry)` | The bounding-box polygon of a geometry. |
| `ST_Boundary(geometry)` | The boundary curve / point set. |
| `ST_Buffer(geometry, distance)` | Inflate a shape by `distance` (input units). |
| `ST_Centroid(geometry)` | Centre of mass. |
| `ST_ConvexHull(geometry)` | Smallest convex polygon containing the geometry. |
| `ST_PointOnSurface(geometry)` | A point guaranteed to be on the geometry's surface (unlike centroid for non-convex shapes). |
| `ST_Simplify(geometry, tolerance)` | Douglas-Peucker simplification. |
| `ST_SnapToGrid(geometry, size)` | Snap vertices to a regular grid. |
| `ST_Difference` / `ST_Intersection` / `ST_Union` / `ST_SymDifference` | Set ops on geometries. |
| `ST_StartPoint` / `ST_EndPoint` | Endpoints of a LineString. |
| `ST_Transform(geometry, target_srid)` | Reproject between SRIDs. |

### Geometry — scalar-returning (registered via `addCustomNumericFunction`)

| DQL function | Returns |
|---|---|
| `ST_Area(geometry)` | Area in input units squared. |
| `ST_GeoLength(geometry)` | Perimeter / length. |
| `ST_X(point)` / `ST_Y(point)` / `ST_NumPoints(geometry)` / `ST_SRID(geometry)` | Coordinate / count / metadata accessors. |
| `ST_Distance(a, b)` | Cartesian distance (input units). |
| `ST_Distance_Sphere(a, b)` | Earth distance in metres for SRID 4326 inputs. **Use this** instead of `ST_Distance` for lat/lng. |
| `ST_MaxDistance(a, b)` | Maximum distance between any two points across the two geometries. |
| `ST_Within(a, b)` / `ST_Contains(a, b)` / `ST_Intersects(a, b)` / `ST_Disjoint(a, b)` / `ST_Crosses(a, b)` / `ST_Overlaps(a, b)` / `ST_Touches(a, b)` / `ST_Equals(a, b)` / `ST_Relate(a, b, pattern)` | Topological predicates. All return `0` or `1`. |
| `ST_IsValid(geometry)` / `ST_IsSimple(geometry)` / `ST_IsClosed(line_or_curve)` | Validity / shape checks. |
| `ST_Azimuth(a, b)` | Bearing between two points (radians). |
| `ST_LocateAlong(line, measure)` / `ST_LocateBetween(line, m1, m2)` | Linear-referencing. |

### Vector

| DQL function | Returns |
|---|---|
| `VEC_FROM_TEXT(json_array_string)` | Parse `'[1.0, 2.0, ...]'` → vector. Used by the upsert path implicitly via the `vector` Doctrine type. |
| `VEC_DISTANCE(a, b)` | Default vector distance (cosine on most MariaDB builds). |
| `COSINE_DISTANCE(a, b)` / `COSINE_SIMILARITY(a, b)` | Explicit cosine forms. |
| `EUCLIDEAN_DISTANCE(a, b)` | L2 distance. |

## Querying — Worked Examples

### "Find every zone whose polygon contains a click point"

```php
$queryBuilder = $repoClass::createQueryBuilder(true);
$queryBuilder
    ->andWhere('ST_Contains(Zone.polygon, ST_GeomFromText(:clickWkt, 0)) = 1')
    ->setParameter('clickWkt', sprintf('POINT(%.17g %.17g)', $x, $y));
```

Notes:
- Cartesian: pass SRID 0 explicitly in `ST_GeomFromText` so both sides agree.
- `ST_Contains` is a numeric function — compare `= 1`, not just truthiness, because MySQL spatial predicates return `0/1` ints (not booleans).
- Use `%.17g` for the floats — preserves full IEEE 754 double precision (the default `%f` truncates at 6 decimal places).

### "Nearest 10 restaurants to a user"

```php
$queryBuilder
    ->andWhere('ST_Distance_Sphere(Restaurant.location, ST_GeomFromText(:userWkt, 4326)) < :radiusM')
    ->setParameter('userWkt', sprintf('POINT(%.17g %.17g)', $userLng, $userLat))
    ->setParameter('radiusM', 5000)
    ->orderBy('ST_Distance_Sphere(Restaurant.location, ST_GeomFromText(:userWkt, 4326))', 'ASC')
    ->setMaxResults(10);
```

Notes:
- Note **lng, lat** order in WKT — POINT uses (x, y) which is (longitude, latitude).
- `ST_Distance_Sphere` returns metres. `ST_Distance` here would return degrees, which is nonsense over a sphere.
- Run an `EXPLAIN` — without a `SPATIAL INDEX` on `Restaurant.location`, this is a full table scan.

### "Top-K vector ANN with a filter"

```php
$queryBuilder
    ->andWhere('Article.languageId = :langId')
    ->setParameter('langId', $langId)
    ->orderBy('COSINE_DISTANCE(Article.embedding, VEC_FROM_TEXT(:queryVec))', 'ASC')
    ->setMaxResults($k);
```

Notes:
- `VEC_FROM_TEXT` takes a JSON-array string. The framework's `VectorType::convertToDatabaseValue` already produces this shape, so you can also pass an array via parameter binding when the column is `type: 'vector'`.
- MariaDB's vector index only accelerates ORDER BY on `VEC_DISTANCE` / `COSINE_DISTANCE` — combining with an `andWhere` filter forces a hybrid plan; check the EXPLAIN.

### "Compute the centroid of every zone and project it"

```php
$queryBuilder
    ->select('Zone.id', 'ST_AsText(ST_Centroid(Zone.polygon)) AS centroidWkt');
```

`ST_AsText` is required when SELECTing geometry — the wire format MySQL returns is the SRID-prefixed WKB binary, which Doctrine sends through `convertToPHPValue` only for properties of geometry types, not for scalar selects.

## Wire & Storage Format

| Direction | What happens |
|---|---|
| **PHP → DB (write)** | Property's typed value cast `(string)$vo` → WKT string → `ST_GeomFromText(?, srid)` bound parameter → MySQL parses → internal binary stored. |
| **DB → PHP (read, ORM path)** | MySQL returns 4-byte SRID prefix + WKB. Doctrine type's `convertToPHPValue` strips the prefix, parses the WKB via brick/geo's `WKBReader` (cached as `protected static`), instantiates the VO. |
| **DB → PHP (raw SQL via `ST_AsText`)** | Plain WKT string. Parse with `Point2D::fromString` etc. when bridging back into VOs. |

The `WKBReader` is stateless and shared across all four Cartesian Doctrine types as a class-static field — one allocation per process, not per hydrated row.

## Schema Diff Considerations

Cross-link to `ddd-database-schema-diff-specialist` for the full diff system. Specifically for geometry / vector:

- **`SPATIAL_SQL_TYPES`** in `DatabaseColumn` lists every Doctrine type name that triggers the upsert spatial branch — both the brick/geo `point/linestring/polygon` and our `cartesian_*` names. Adding a new spatial type requires extending this set.
- **VECTOR dimensionality change** is destructive — MariaDB can't ALTER the dimensions in place. The diff service marks `requiresFullReset` and routes through DROP + ADD + zero-vector backfill. The Production Guard refuses to apply this on large tables — operate via pt-osc instead (see `ddd-database-schema-diff-specialist`).
- **`Translatable` → `JSON`** is the same kind of column-type swap as the legacy varchar→JSON migrations, but with no special spatial handling needed.
- **`CHECK (json_valid(col))` on MariaDB** is auto-handled by the introspection normaliser — a JSON column on MariaDB looks like LONGTEXT + json_valid CHECK, and the diff service collapses that to a single `JSON` shape.

## Failure Modes & Triage

| Symptom | Likely cause |
|---|---|
| Hydrated VO is `null` despite the column having data | The Doctrine type's `convertToPHPValue` couldn't parse the WKB — either the column isn't actually our type, or brick/geo returned an unexpected shape. Add a `var_dump($value)` before the `WKBReader::read` to see what came back. |
| `Invalid GIS data: Bad geometry text` on insert | A Polyline with < 2 vertices, a Polygon with < 3-vertex outer ring, or a BoundingBox2D with both width=0 AND height=0 — the VO emits visible-shape invalid WKT (`'LINESTRING()'`, `'POLYGON(())'`) so the error is at least findable. Validate caller-side. |
| `MariaVector: VECTOR(N) requires a dimension` | Missing `length` on `#[ORM\Column(type: 'vector', length: 1536)]`. The DB model generator emits this from `DatabaseColumn::$vectorDimensions` — declare it on the entity attribute. |
| Spatial query returns 0 rows for a query that should match | Almost always an SRID mismatch — `ST_Within(geoPoint, ST_GeomFromText('POLYGON(...)', 0))` compares SRID 4326 against SRID 0 and silently returns 0. Make sure both sides agree. |
| The schema diff keeps showing a MODIFY on a Vector column even after `apply` | Default value mismatch: the `Vector` column is `NOT NULL` with a DB-side zero-vector default (`VEC_FromText('[0,0,...]')`); if your entity declares `?Vector $foo = null` but writes never set the property, the upsert emits the same zero-vector default and the diff is in-sync. If you see persistent MODIFYs, run `composer update mgamadeus/ddd` — the function-call default normaliser landed in v2.12.x. |
| `BoundingBox2D::fromPolygon` returns null | The input polygon isn't axis-aligned, has holes, or has the wrong vertex count. By design — fail closed. Use `Polygon` for the general case. |

## Performance Notes

- **`SPATIAL INDEX` is only used for the predicates MySQL knows how to index** — `ST_Contains`, `ST_Within`, `ST_Intersects`, `ST_Crosses`, `ST_Overlaps`, `ST_Touches`, `ST_Disjoint`, `ST_Equals`. Other operators (`ST_Area`, `ST_Distance`, `ST_Centroid`) require a scan — pair with an indexed filter or pre-compute.
- **Vector indexes** accelerate ORDER BY a distance function only when no other ORDER BY columns are mixed in. `ORDER BY COSINE_DISTANCE(...) ASC` ✓; `ORDER BY popularity DESC, COSINE_DISTANCE(...) ASC` triggers a full scan.
- **WKT/WKB allocations on the read path** are O(1) per row thanks to the cached `WKBReader`. The dominant cost is brick/geo's WKB parser itself, which allocates one immutable `Point` / `LineString` / `Polygon` per row. Don't `SELECT` 100k rows of geometry into PHP unless you actually need them — use `ST_AsText` or aggregate server-side.

## Adding a New Geometry-Like Type — Recipe

For when something new lands (e.g. `Circle`, `Polyline3D`, `MultiPolygon`):

1. **Build the VO** under `Domain/Common/Entities/Geometry/{Cartesian,…}/` extending `ValueObject`. Add `__toString` that emits valid WKT (or your serialised form), `uniqueKey()`, and at minimum a `fromArray` factory.
2. **Build the Doctrine type** under `Domain/Base/Repo/DB/Doctrine/Custom/Types/`:
   - Mirror the existing `PointType` / `LineStringType` / `PolygonType` pattern.
   - Cache `WKBReader` as `protected static ?WKBReader $wkbReader = null` and access via `??= new WKBReader()`.
   - `convertToDatabaseValue` should delegate to `(string)$vo` — never duplicate the WKT generation.
3. **Register** in `EntityManagerFactory::getInstance()` next to the existing four cartesian registrations.
4. **Wire the schema generator** in `DatabaseColumn`:
   - Add `SQL_TYPE_*` constant if it's a new SQL type.
   - Add the VO class to `SQL_TYPE_ALLOCATION`, `DOCTRINE_COLUMN_TYPE_ALLOCATIONS`, `DOCTRINE_PHP_TYPE_ALLOCATIONS`.
   - Add the SQL → Doctrine type mapping to `DOCTRINE_SQL_TYPE_ALLOCATIONS`.
   - Add default index allocation to `SQL_TYPES_TO_DEFAULT_INDEX_TYPE_ALLOCATIONS`.
   - Add the Doctrine type name to `SPATIAL_SQL_TYPES` (or `'vector'`-like equivalent) so the upsert spatial branch fires.
   - Add an `is_a` branch in `createFromReflectionProperty` so the VO is detected during reflection.
5. **Add custom DQL functions** in `EntityManagerFactory` if MariaDB / MySQL supports relevant new operators.
6. **Document** in this skill: the new row in the type-selection matrix and the SQL-function catalog.

## Cross-Reference

- **Schema migrations / diffs** — `ddd-database-schema-diff-specialist` (SRID changes, VECTOR re-dimensioning, the Production Guard for large-table COPY-forcing ops).
- **Entities owning these properties** — `ddd-entity-specialist` (the `#[NotNull]` rule, default-index suppression, the lazy-load vs eager-load decisions for geometry-heavy aggregates).
- **Serialization to API** — `ddd-serializer-specialist` (`__toString` is for SQL, not for JSON wire format; the serializer emits the VO's public properties directly).
- **Vector-search orchestration** — `ddd-service-specialist` (the embed-in-service → search-in-repo flow that drives the `COSINE_DISTANCE` order-by query from business logic).
