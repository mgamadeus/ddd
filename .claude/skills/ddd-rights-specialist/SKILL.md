---
name: ddd-rights-specialist
description: Implement entity-level access control in the mgamadeus/ddd framework -- applyReadRightsQuery, applyUpdateRightsQuery, applyDeleteRightsQuery, mapToEntity property hiding, RolesRequiredForUpdate, and rights restriction snapshots. Use when implementing or debugging entity access control in DB repositories.
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
---

# DDD Rights Specialist

Entity-level access control via query-level rights restrictions in the DDD Core framework.

## When to Use

- Implementing read/update/delete access control in DB repository classes
- Choosing the right rights pattern (direct filter, leftJoin, subquery)
- Hiding sensitive properties after entity loading (mapToEntity)
- Understanding the complete rights pipeline from request to response
- Temporarily disabling rights for system operations
- Debugging "entity not found" issues caused by rights restrictions

---

## Rights Pipeline (Request to Response)

```
1. REQUEST ARRIVES (HTTP / CLI / Messenger)
2. Auth context established:
   - deactivateEntityRightsRestrictions()  -- snapshot + disable
   - Load Account by JWT/session (bypasses rights)
   - restoreEntityRightsRestrictionsStateSnapshot()  -- re-enable
   - AuthService::setAccount($account)

3. BUSINESS LOGIC EXECUTES
   |
   +-- Repository.find(id)
   |   +-- buildFindQueryBuilder(id)
   |   +-- if ($applyRightsRestrictions) applyReadRightsQuery($qb)
   |   +-- Execute query -> load entity
   |   +-- mapToEntity() -> optional property hiding
   |
   +-- Repository.update(entity)
   |   +-- canUpdateOrDeleteBasedOnRoles() -> early return if fails
   |   +-- if ($applyRightsRestrictions) applyUpdateRightsQuery($qb)
   |   +-- Execute upsert with rights-restricted WHERE
   |
   +-- Repository.delete(entity)
       +-- canUpdateOrDeleteBasedOnRoles() -> early return if fails
       +-- if ($applyRightsRestrictions) applyDeleteRightsQuery($qb)
       +-- Execute delete with rights-restricted WHERE

4. RESPONSE (entity serialized, hidden properties excluded)
```

---

## Three Rights Methods

Override these in `DB{EntityName}` repository classes (not on the EntitySet repo):

| Method | Default | Called During | Purpose |
|--------|---------|--------------|---------|
| `applyReadRightsQuery(&$qb): bool` | `false` (no restrictions) | `find()`, `findAll()`, `count()`, expand joins | Filter which records are visible |
| `applyUpdateRightsQuery(&$qb): bool` | Delegates to `applyReadRightsQuery()` | `update()` | Filter which records can be updated |
| `applyDeleteRightsQuery(&$qb): bool` | Delegates to `applyUpdateRightsQuery()` | `delete()` | Filter which records can be deleted |

**Return values:** `true` = restrictions applied (query modified), `false` = no restrictions.

> ⚠️ **CRITICAL — the return value is load-bearing for expand.**
>
> `DBEntity::find()` / `DBEntitySet::find()` apply rights via **side effect on the QueryBuilder** and ignore the return value. So a wrong return value won't break direct finds — the bug stays hidden.
>
> `ExpandOptions::applyConditionsFromReadRightsQueryBuilder()` is different: it calls your rights method on a **fresh temp QueryBuilder**, then merges WHERE/JOIN clauses into the main query **only if your method returned `true`**.
>
> **Rule:** if you added conditions to the QueryBuilder, you MUST `return true`. If you fall through to `return parent::applyReadRightsQuery($queryBuilder)` (the framework default returns `false`), the expand merger discards your conditions and the entity becomes invisibly readable on any nested `?$expand=...` join. This was the cause of a real silent rights bypass — see *Anti-Pattern* below.

**All gated by** `$applyRightsRestrictions` (static bool, default `true`). When `false`, all rights checks are bypassed.

---

## Anti-Pattern: `return parent::applyReadRightsQuery(...)` after adding conditions

❌ **Wrong** — silent rights bypass on expand:

```php
public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
{
    if (!self::$applyRightsRestrictions) return false;
    $authAccount = AuthService::instance()->getAccount();
    $alias = static::getBaseModelAlias();

    $queryBuilder->andWhere("{$alias}.accountId = :authAccountId")
        ->setParameter('authAccountId', $authAccount->id);

    return parent::applyReadRightsQuery($queryBuilder);  // ← returns false!
}
```

The conditions are added to the QB, but the function reports "no restrictions applied" to `ExpandOptions`. Direct `find()` still works (side effect). Any `?$expand=thisEntity` from another entity reads it unrestricted.

✅ **Right** — return `true` exactly where conditions were added:

```php
public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
{
    if (!self::$applyRightsRestrictions) return false;
    $authAccount = AuthService::instance()->getAccount();
    $alias = static::getBaseModelAlias();

    $queryBuilder->andWhere("{$alias}.accountId = :authAccountId")
        ->setParameter('authAccountId', $authAccount->id);

    return true;
}
```

For partial branches (e.g. partner-admin adds, global-admin doesn't), put `return true;` **inside the branch** right after the `andWhere`, and keep `return false;` (or `parent::applyReadRightsQuery`) for the no-conditions terminal:

```php
if ($authAccount?->roles?->isAdmin()) {
    if ($authAccount->type == Account::TYPE_PARTNER) {
        try {
            $queryBuilder->andWhere("{$alias}.worldId IN (:worldIds)")
                ->setParameter('worldIds', $worldIds);
            return true;  // ← conditions added, signal merge
        } catch (Throwable $t) {
        }
    }
    // global admin: no conditions, falls through
}
return false;  // or `parent::applyReadRightsQuery(...)` — both return false, both correct here
```

---

## Standard Skeleton

Every `applyReadRightsQuery` follows this structure:

```php
public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
{
    // 1. Gate: skip if rights disabled
    if (!self::$applyRightsRestrictions) {
        return false;
    }

    // 2. Auth check: impossible condition if no account
    $authAccount = AuthService::instance()->getAccount();
    if (!$authAccount) {
        $alias = static::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.id is null");
        return true;
    }

    // 3. Admin check: full admins pass through
    if ($authAccount?->roles?->isAdmin()) {
        // 3a. Partner admin: world-scoped restrictions
        if ($authAccount->type == Account::TYPE_PARTNER) {
            $worldsService = Worlds::getService();
            $worldIds = $worldsService->getSubdWorldIdsForWorld($authAccount->world);
            $worldIds[] = $authAccount->world->id;
            $alias = static::getBaseModelAlias();
            $queryBuilder->andWhere("{$alias}.worldId IN (:worldIds)")
                ->setParameter('worldIds', $worldIds);
        }
        return true;  // Global admins: no restrictions
    }

    // 4. Non-admin restrictions
    $alias = static::getBaseModelAlias();
    $queryBuilder->andWhere("{$alias}.accountId = :authAccountId")
        ->setParameter('authAccountId', $authAccount->id);

    // Restrictions applied — MUST return true so ExpandOptions merges the
    // conditions into the main query. `parent::applyReadRightsQuery` returns
    // `false` (no restrictions) and would silently drop them on expand joins.
    return true;
}
```

---

## Pattern 1: Direct Column Filter (No Joins)

**When:** Entity has `accountId` or `worldId` column directly.

```php
// DBTrack -- non-admins see only own tracks
$alias = static::getBaseModelAlias();
$queryBuilder->andWhere("{$alias}.accountId = :authAccountId")
    ->setParameter('authAccountId', $authAccount->id);
```

```php
// DBChallenge -- partner admins see challenges in their worlds
$worldIds = $worldsService->getSubdWorldIdsForWorld($authAccount->world);
$worldIds[] = $authAccount->world->id;
$alias = static::getBaseModelAlias();
$queryBuilder->andWhere("{$alias}.worldId IN (:worldIds)")
    ->setParameter('worldIds', $worldIds);
```

**Use for:** Track, Challenge, Campaign, World -- entities with direct ownership/tenant columns.

---

## Pattern 2: LeftJoin for Related Entity Filtering

**When:** Entity has no direct filtering column but has a relationship to an entity that does.

### CRITICAL: `_rights` Alias Convention

Join aliases **MUST** use `{ModelAlias}_{propertyName}_rights` to prevent collision with the expand system's alias convention:

```php
$alias = static::getBaseModelAlias();

// WRONG -- collides with expand aliases
$queryBuilder->leftJoin("{$alias}.account", 'account');

// CORRECT -- _rights suffix prevents collision
$accountAlias = $alias . '_account_rights';
$queryBuilder->leftJoin("{$alias}.account", $accountAlias);
```

### Single-Level Join

```php
// DBRouteProblem -- partner admins see problems from accounts in their worlds
$alias = static::getBaseModelAlias();
$accountAlias = $alias . '_account_rights';
$queryBuilder->leftJoin("{$alias}.account", $accountAlias)
    ->andWhere("({$accountAlias}.worldId IN (:worldIds)) OR {$accountAlias}.id = :authAccountId")
    ->setParameter('worldIds', $worldIds)
    ->setParameter('authAccountId', $authAccount->id);
```

### Multi-Level Join Chain

Each alias builds on its parent:

```php
// DBSupportTicket -- non-admins see tickets via contact -> relatedAccounts
$alias = static::getBaseModelAlias();
$supportContactAlias = $alias . '_supportContact_rights';
$relatedAccountAlias = $supportContactAlias . '_relatedAccounts_rights';
$queryBuilder
    ->leftJoin("{$alias}.supportContact", $supportContactAlias)
    ->leftJoin("{$supportContactAlias}.relatedAccounts", $relatedAccountAlias)
    ->andWhere("{$relatedAccountAlias}.id = :authAccountId")
    ->setParameter('authAccountId', $authAccount->id);
```

### Deep Chain (4 Levels)

```php
// DBSupportMessageAttachment -- traverses message -> ticket -> contact -> accounts
$alias = static::getBaseModelAlias();
$supportMessageAlias = $alias . '_supportMessage_rights';
$supportTicketAlias = $supportMessageAlias . '_supportTicket_rights';
$supportContactAlias = $supportTicketAlias . '_supportContact_rights';
$relatedAccountAlias = $supportContactAlias . '_relatedAccounts_rights';
$queryBuilder
    ->leftJoin("{$alias}.supportMessage", $supportMessageAlias)
    ->leftJoin("{$supportMessageAlias}.supportTicket", $supportTicketAlias)
    ->leftJoin("{$supportTicketAlias}.supportContact", $supportContactAlias)
    ->leftJoin("{$supportContactAlias}.relatedAccounts", $relatedAccountAlias)
    ->andWhere("{$relatedAccountAlias}.id = :authAccountId AND {$supportMessageAlias}.isInternalNote = 0")
    ->setParameter('authAccountId', $authAccount->id);
```

**Use for:** Support system (Message -> Ticket -> Contact -> Accounts), RouteProblem -> Account.

---

## Pattern 3: Subquery (No LeftJoin)

**When:** Entity is 1+ relations away from the filtering target and joins are impractical.

### Single Subquery

```php
// DBGoal -- partner admins see goals via Challenge -> worldId
$alias = static::getBaseModelAlias();
$challengeRepoClass = Challenge::getRepoClassInstance();
$challengeModel = $challengeRepoClass::BASE_ORM_MODEL;
$queryBuilder->andWhere(
    "{$alias}.id IN (SELECT rightsChallenge.goalId FROM {$challengeModel} rightsChallenge
     WHERE rightsChallenge.worldId IN (:goalWorldIds))"
)->setParameter('goalWorldIds', $worldIds);
```

### Nested Subquery (2 Levels)

```php
// DBReward -- partner admins see rewards via Goal -> Challenge -> worldId
$goalModel = Goal::getRepoClassInstance()::BASE_ORM_MODEL;
$challengeModel = Challenge::getRepoClassInstance()::BASE_ORM_MODEL;
$queryBuilder->andWhere(
    "{$alias}.id IN (SELECT rightsGoal.rewardId FROM {$goalModel} rightsGoal
     WHERE rightsGoal.id IN (SELECT rightsChallenge.goalId FROM {$challengeModel} rightsChallenge
     WHERE rightsChallenge.worldId IN (:rewardWorldIds)))"
)->setParameter('rewardWorldIds', $worldIds);
```

### Dual-Path Subquery (OR Logic)

```php
// DBSponsor -- accessible via partner OR via challenge reward chain
$partnerModel = Partner::getRepoClassInstance()::BASE_ORM_MODEL;
$challengeModel = Challenge::getRepoClassInstance()::BASE_ORM_MODEL;
$queryBuilder->andWhere("
    ({$alias}.id IN (SELECT partner.sponsorId FROM {$partnerModel} partner
     WHERE partner.id IN (:partnerIds))
    OR {$alias}.id IN (
        SELECT reward.sponsorId FROM {$challengeModel} challenge
        LEFT JOIN challenge.goal goal LEFT JOIN goal.reward reward
        WHERE challenge.worldId IN (:worldIds)))
")->setParameter('worldIds', $worldIds)->setParameter('partnerIds', $partnerIds);
```

**Rule:** Always use `Entity::getRepoClassInstance()::BASE_ORM_MODEL` for model class names. Subquery aliases are scoped -- they don't need the `_rights` convention.

---

## Pattern 4: Custom Update Rights (Stricter Than Read)

```php
// DBAccount -- non-admins can only update their own account
public static function applyUpdateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
{
    if (!self::$applyRightsRestrictions) {
        return false;
    }

    // Optional: apply read restrictions first if write should be a strict subset
    // of read. Call static::applyReadRightsQuery (NOT parent::) so the actual
    // entity's read rules apply, not the empty default.
    static::applyReadRightsQuery($queryBuilder);

    $authAccount = AuthService::instance()->getAccount();
    $alias = static::getBaseModelAlias();
    if (!$authAccount) {
        $queryBuilder->andWhere("{$alias}.id is null");
        return true;
    }
    if (!($authAccount?->roles?->isAdmin())) {
        $queryBuilder->andWhere("{$alias}.id = :rightsAccountId")
            ->setParameter('rightsAccountId', $authAccount->id);
    }
    return true;
}
```

> ⚠️ **Don't `return parent::applyUpdateRightsQuery($queryBuilder)` at the end.**
>
> The default `DatabaseRepoEntity::applyUpdateRightsQuery` delegates to
> `static::applyReadRightsQuery($queryBuilder)` — i.e. it re-runs your own read
> rights on the same QueryBuilder. That **doubles every condition you already
> have** (duplicate WHERE clauses; with leftJoin-based rights it throws on
> duplicate aliases). If you want read rights applied, do it explicitly **once**
> at the top of the method, then `return true;`.

**Use for:** Entities where read access is broader than write access (accounts, shared resources).

---

## Pattern 5: Delete Delegation

Almost always delegates to update rights — use `static::` so a subclass's
`applyUpdateRightsQuery` override is honored. `parent::applyUpdateRightsQuery`
on `DatabaseRepoEntity` skips your own override and only re-applies read rights.

```php
public static function applyDeleteRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
{
    return static::applyUpdateRightsQuery($queryBuilder);
}
```

---

## Pattern 6: Post-Load Property Hiding (mapToEntity)

For hiding sensitive fields AFTER loading (not filtering rows):

```php
public function mapToEntity(
    bool $useEntityRegistryCache = true,
    array $initiatorClasses = []
): ?DefaultObject {
    $entity = parent::mapToEntity($useEntityRegistryCache, $initiatorClasses);
    if (!$entity) return null;

    $authAccount = AuthService::instance()->getAccount();
    if (self::$applyRightsRestrictions
        && (!$authAccount || !$authAccount->roles->isAdmin())
        && ($authAccount?->id !== $entity->id)
    ) {
        $entity->addPropertiesToHide(
            'email', 'password', 'gender', 'age',
            'deviceTokenForNotifications', 'operatingSystem',
            'placeOfResidence', 'placeOfWorkOrEducation'
        );
    }
    return $entity;
}
```

**When:** Entity is visible to the user but certain fields should be masked (PII, device info, internal data).

---

## RolesRequiredForUpdate (Entity-Level)

Applied on the entity class, checked before any write operation:

```php
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;

#[RolesRequiredForUpdate(Role::ADMIN)]
class World extends Entity { }
```

Checked by `canUpdateOrDeleteBasedOnRoles()` in `DatabaseRepoEntity`. If the account lacks required roles, `update()` silently returns the entity unchanged and `delete()` returns `false`.

---

## Temporarily Disabling Rights (System Operations)

For operations that need to bypass rights (CLI commands, message handlers, auth context setup):

```php
// Snapshot + disable
DDDService::instance()->deactivateEntityRightsRestrictions();

// Execute privileged operations
$account = Account::byId($id);  // Bypasses read rights

// Restore
DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
```

**Always use snapshot/restore.** Never set `$applyRightsRestrictions = false` directly without snapshotting first.

---

## Choosing the Right Pattern

| Scenario | Pattern | Example |
|----------|---------|---------|
| Entity has `accountId` -- restrict to own | Direct column filter | DBTrack |
| Entity has `worldId` -- scope to tenant | Direct column filter with world hierarchy | DBChallenge, DBCampaign |
| Entity relates to entity with `worldId` via FK | LeftJoin with `_rights` alias | DBRouteProblem -> Account |
| Entity is 2+ hops from filtering target | Subquery (single or nested) | DBGoal -> Challenge, DBReward -> Goal -> Challenge |
| Multiple access paths (OR logic) | Dual-path subquery | DBSponsor (via Partner OR via Challenge reward) |
| Complex membership chain | Multi-level leftJoin chain | Support system (Message -> Ticket -> Contact -> Accounts) |
| Write stricter than read | Override `applyUpdateRightsQuery`, call parent read first | DBAccount |
| Hide fields, not filter rows | Override `mapToEntity`, call `addPropertiesToHide()` | DBAccount (email, password, device info) |
| Entire entity class restricted | `#[RolesRequiredForUpdate]` attribute | DBWorld (ADMIN only) |
| CLI/system needs bypass | `deactivateEntityRightsRestrictions()` + restore | Auth context setup, imports |

## Expand System Interaction

**Read rights are automatically applied to expanded entities.** When `ExpandOptions::applyExpandOptionsToDoctrineQueryBuilder()` creates LEFT JOINs for `$expand`, it calls `applyReadRightsQuery()` on each expanded entity's repo. This means:

- If Account has read rights, expanding `$expand=account` on a Track will apply Account's rights to the join
- The `_rights` alias convention prevents collision between expand aliases and rights aliases

### How the merge actually works (and why the return value matters)

For each expanded property the framework:

1. creates a **fresh temp `DoctrineQueryBuilder`** via `$targetRepo::createQueryBuilder(true)`,
2. calls `$targetRepo::applyReadRightsQuery($tempQb)` and captures the bool return,
3. **only if `true`**, builds an `IN`-subquery from the temp QB's FROM + internal LEFT JOINs + WHERE and appends `targetAlias.id IN (<subquery>)` to the expand's LEFT JOIN ON-clause. Children failing rights are nulled; the parent always survives. Same for to-one and to-many.

If your `applyReadRightsQuery` adds WHEREs but returns `false` (e.g. via `return parent::applyReadRightsQuery(...)`), the merge step is skipped. Direct finds still apply the conditions (side effect on the QB passed by reference) — so the bug is invisible until someone uses `?$expand=yourEntity` from elsewhere. **This was a real, widespread silent rights bypass.** See Anti-Pattern above.

### EntitySet properties (e.g. `chatMessages : ChatMessages`)

Rights live on the single-entity repo (`DBChatMessage`), not on the set repo (`DBChatMessages`). When you expand a set-typed property, the framework must call `applyReadRightsQuery` on the **base entity repo**, not on the set. If you see rights silently bypassed on a set expand, suspect the resolution chain in `ExpandDefinition::getTargetPropertyRepoClass()` (it must yield the base repo, not the set repo).

### Cardinality no longer matters — unified semantic

**Rights propagate parent → child only.** A child that fails rights is nulled in the join; the parent always survives. Same rule for to-one and to-many, matching the lazy-load contract (`$parent->relation` returns `null` on rights failure, parent untouched).

Mechanism (both cardinalities): rights are pushed into the expand's **LEFT JOIN ON-clause** as `targetAlias.id IN (SELECT t.id FROM TargetModel t [LEFT JOIN …] WHERE <rights>)`. Children that fail rights are simply not joined.

If a parent's visibility legitimately depends on a child's rights, encode that **explicitly** in the parent's own `applyReadRightsQuery` (leftJoin + WHERE on the child's columns — Pattern 2 above). Implicit child→parent cascade is *not* supported by design — it conflates "I can't read X" with "X doesn't exist" and was the cause of silent parent-collapse bugs.

You don't have to do anything special in your `applyReadRightsQuery` — just ensure it returns `true` when it adds conditions. The framework picks the merge mechanism.

**Historical note (2026-05):** to-one used to merge rights into the main `WHERE` with `($alias.id IS NULL OR (<rights>))`, which dropped the parent when a FK-set to-one failed rights. This was asymmetric with to-many and with LazyLoad and is gone. Any older skill/code comment that says "parent with an unreadable to-one is excluded" is outdated.

| Expand source | Both to-one and to-many |
|---|---|
| Implicit rights (`applyReadRightsQuery`) | **ON-clause via `IN (subquery)`** — child nulled on failure, parent always visible |
| Top-level `filter=x.y eq Z` (dot-path) | WHERE — caller-intent is "filter the parent by child"; parent drops |
| Expand-scoped `expand=x(filters=…)` | ON-clause via `applyFiltersToJoin` — only child filtered |

---

## Checklist (New Rights Implementation)

- [ ] Override `applyReadRightsQuery()` in `DB{Entity}` (not DBEntitySet)
- [ ] First line: `if (!self::$applyRightsRestrictions) return false;`
- [ ] No auth account: add impossible condition (`id is null` or `id = 0`), then `return true`
- [ ] Admin check: return `true` for global admins, add world filter for partner admins (then `return true` inside the branch)
- [ ] Non-admin: restrict to own data
- [ ] LeftJoin aliases use `{ModelAlias}_{property}_rights` convention
- [ ] Subquery model names from `Entity::getRepoClassInstance()::BASE_ORM_MODEL`
- [ ] **Return `true` exactly where conditions were added; return `false` only on paths where no conditions were added.** Never `return parent::applyReadRightsQuery(...)` after adding conditions — the parent returns `false` and the expand merger drops them. See *Anti-Pattern* section.
- [ ] If update stricter than read: override `applyUpdateRightsQuery()`. Apply read rights once via `static::applyReadRightsQuery($queryBuilder)` (NOT `parent::`), then `return true` — never `return parent::applyUpdateRightsQuery(...)`, that double-applies read rights.
- [ ] If property hiding needed: override `mapToEntity()`, check `$applyRightsRestrictions` + auth
- [ ] Never use `private` -- always `protected`

---

## Cross-Reference

- **Entity declarations these rights protect** — `ddd-entity-specialist` (the `DB{Entity}` repo where you override the rights methods, the `#[RolesRequiredForUpdate]` attribute on the entity class, and the `#[HideProperty]` properties `mapToEntity` masks).
- **Services that go through the rights gate** — `ddd-service-specialist` (`getService()->find()` applies `applyReadRightsQuery`; custom QueryBuilder queries inherit the same restrictions — and why direct repo access bypasses them).
- **Where the auth context and `$expand` originate** — `ddd-endpoint-specialist` (controllers establish the `AuthService` account on each request and accept `?$expand=...`, which is what triggers the expand-merge return-value rule documented above).
