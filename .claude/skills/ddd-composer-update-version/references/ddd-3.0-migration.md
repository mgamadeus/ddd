# Moving an app to mgamadeus/ddd 3.0

3.0 has one breaking change: a root filter on an expanded collection no longer cuts that collection down. Everything
else behaves as in 2.71.x. Raise the constraint to `"mgamadeus/ddd": "^3.0"` (and every `ddd-*` module to a release
that allows 3.0), then `composer update mgamadeus/ddd -W`.

## What changed

A root filter on a property of an expanded **collection** selects the roots; each selected root comes back with its
**complete** collection (OData semantics). Before, the filter also removed the non-matching children from the
response.

```
GET /orders/list?expand=orderItems&filters=orderItems.name eq 'Pizza'
```

| | 2.x | 3.0 |
|---|---|---|
| Which orders | orders with a Pizza item | the same |
| Their `orderItems` | only the Pizza items | all items |

To get only the matching children, filter in the expand — it filters the collection and keeps every root:

```
GET /orders/list?expand=orderItems(filters=name eq 'Pizza')
```

Both together give "orders with a Pizza, showing only their Pizza items":
`?expand=orderItems(filters=name eq 'Pizza')&filters=orderItems.name eq 'Pizza'`.

Unchanged:
- To-one paths (`filters=account.email eq …` with `expand=account`): the joined entity is one row either way.
- Several conditions on the same collection in one filter hold for one and the same child
  (`orderItems.name eq 'Pizza' and orderItems.size eq 'L'` = an item that is a large Pizza).
- Expand filters (`expand=x(filters=…)`), expand read rights and the child entity's own `applyReadRightsQuery`
  (applied in the join's ON clause).

## Where it shows up

- **Frontend list screens** that send `filters=<collection>.<field> …` together with `expand=<collection>` and
  display the collection: they now show every child. Move the condition into the expand if only the matching
  children should be shown (keep the root filter if the roots must still be restricted).
- **Root read rights that join a collection** (`applyReadRightsQuery` with `leftJoin` on a to-many relation + WHERE on
  it, the `_rights` alias pattern) — **check these before the update, they widen what non-admins see**: an `expand`
  of that same relation reuses the join. Its WHERE used to trim the expanded collection as well (often to the caller's
  own row); now the collection is complete, restricted only by the child entity's own read rights. Children that must
  stay hidden need rights on the child entity, or the property hidden for non-admins in `mapToEntity`. Example:
  support tickets whose rights join `supportContact.relatedAccounts` on the caller's account — a client
  `expand=supportContact(expand=relatedAccounts)` returned the caller's account only and now returns every account of
  the contact. The same collection was usually reachable without the rights join already (an expand from the caller's
  own account), so audit the collection itself, not only the rights join.
- **Hand-built query builders** that fetch-join a collection (`leftJoin` + `addSelect`) and restrict it in `andWhere`
  to load only some children: put that condition into the join (`leftJoin($path, $alias, Join::WITH, $condition)`).
  A WHERE now only selects the roots.

## Also in 3.0

- `count()` / `countAll()` with an expand counts each root once (`COUNT(DISTINCT id)`); it used to fail with a DQL
  error as soon as an expand was set.
- A query that filters and fetch-joins a collection runs as two steps in one statement (the qualifying root ids,
  then the roots with their joins), with or without a limit — the same shape a paginated query always had.

## Later 3.x releases

- 3.1.0 (2.x line: 2.72.0) — strict `insert()` next to `update()`: `$entity->insert()`, `EntitiesService::insert()`,
  `DBEntity::insert($entity, $depth)`, `DoctrineEntityManager::insert($model, $createRightsQueryBuilder)`. A plain
  `INSERT` (no `ON DUPLICATE KEY UPDATE`, no `ON CONFLICT`), never an update; a unique-key or primary-key collision
  throws `UniqueConstraintViolationException` unchanged. No change to `update()`. Code that implements
  `Dialect\Write\UpsertBuilder` itself adds `insert()`.
- 3.1.1 — the schema diff (`DatabaseSchemaDiffService`, `/databaseModels/diff` and apply) works on PostgreSQL: the
  target is built from the entities in a rolled-back scratch schema and compared with the live schema
  (`current_schema()`) through `PostgresSchemaIntrospectionService`; the apply runs in one transaction with
  `SET LOCAL lock_timeout`. Needs the CREATE privilege on the database (for the scratch schema) and the `ensure()`
  objects on the live database. MariaDB unchanged. The large-table guard message no longer names an app skill.
- 3.1.2 — PostgreSQL schema diff: the container-built `DatabaseSchemaDiffService` (autowired with the MariaDB
  introspection) uses the PostgreSQL introspection, so `/databaseModels/diff` answers 200; tables an extension owns
  (PostGIS `spatial_ref_sys`) are no longer offered as DROP_TABLE.
