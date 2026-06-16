---
name: ddd-module-orchestrator
description: Centralized orchestration of all DDD modules from the Core project. Study, update, release, and propagate changes across the entire module ecosystem. Use when making cross-module changes, releasing versions, updating documentation, or propagating dependency upgrades.
metadata:
  author: mgamadeus
  version: "2.0.0"
---

# DDD Module Orchestrator

Centralized control for studying, updating, releasing, and propagating changes across the entire DDD module ecosystem.

## When to Use

- Making changes that affect multiple DDD modules
- Releasing a new version of Core and propagating to dependent modules
- Updating AGENTS.md, skills, or README across modules
- Studying a module's codebase before making changes
- Running `composer update` in dependent modules after a release

## Module Ecosystem

### Packages

| Package | Description |
|---------|-------------|
| `mgamadeus/ddd` | Core framework (entities, repos, services, QueryOptions, presentation) |
| `mgamadeus/ddd-common-money` | Currency and MoneyAmount value objects |
| `mgamadeus/ddd-argus` | External API repository layer with multi-tier caching |
| `mgamadeus/ddd-common-political` | Countries, languages, locales, states, localities |
| `mgamadeus/ddd-ai` | AI model management, prompts, Argus AI traits |
| `mgamadeus/ddd-common-geo` | Addresses, geocoding, GeoRegion hierarchy |
| `mgamadeus/ddd-common-translations` | Text translation, app UI translations, embeddings |

### Dependency Graph (Determines Release & Update Order)

```
Level 0 (no module deps):
  mgamadeus/ddd                    (Core)
  mgamadeus/ddd-common-money       (Money)
  mgamadeus/ddd-argus              (Argus)

Level 1 (depends on Level 0):
  mgamadeus/ddd-common-political   -> ddd, ddd-argus
  mgamadeus/ddd-ai                 -> ddd, ddd-argus, ddd-common-money

Level 2 (depends on Level 0+1):
  mgamadeus/ddd-common-geo         -> ddd, ddd-common-political
  mgamadeus/ddd-common-translations -> ddd, ddd-ai, ddd-common-money, ddd-common-political
```

### Reverse Dependencies (Who Depends on What)

| If you release a fix in... | These children must bump their floor + re-release: |
|------------------|-------------------------------|
| **ddd** (Core) | Money, Argus, Political, AI, Geo, Translations (ALL) |
| **ddd-common-money** | AI, Translations |
| **ddd-argus** | Political, AI |
| **ddd-common-political** | Geo, Translations |
| **ddd-ai** | Translations |
| **ddd-common-geo** | (none -- leaf module) |
| **ddd-common-translations** | (none -- leaf module) |

> **GitHub repo names ≠ package names for two packages:** `mgamadeus/ddd-common-translations` lives in the repo
> `mgamadeus/ddd-translations`, and `mgamadeus/ddd` (Core) lives in `mgamadeus/ddd`. When pushing via HTTPS, resolve
> the real repo with `git remote get-url origin` (or the Packagist `source.url`) — do not assume the repo matches the
> package name.

### Dependency-Floor Cascade (propagating a fix DOWNSTREAM, not just locally)

**A `composer update` in a child only refreshes that child's OWN lock — it does NOT change what downstream consumers
can resolve.** The published constraint is what gates consumers. If `ddd-ai` declares `ddd-common-money: ^1.0`, then
*any* app pulling `ddd-ai` may legally resolve money `1.0.x` and silently miss a fix shipped in `1.1.1` — even after
you `composer update`-d the `ddd-ai` working tree. (Real incident: the Currency `#[Choice]` DRY fix shipped in money
`1.1.1`, but `ddd-ai` + `ddd-common-translations` both still floored money at `^1.0`, so RC resolved the buggy money
behind `ddd-ai`. Fix: floors raised to `^1.1.1`, both children re-released → `ddd-ai 1.4.1`, `ddd-translations 1.0.23`.)

**Two distinct actions — do not confuse them:**

| Action | What it does | Forces downstream onto the fix? |
|--------|--------------|---------------------------------|
| `composer update` in the child | refreshes the child's resolved deps (its lock) | ❌ No — published `require` floor unchanged |
| Bump the `require` floor + **re-release** the child | raises the minimum the child (and its consumers) may resolve | ✅ Yes |

**The rule:** when you release a module with a fix/feature that consumers **must not be able to resolve around**,
walk the reverse-dependency graph and, for **every** dependent (transitively, down to the leaves):

1. Raise the `require` floor in the dependent's `composer.json` to the **exact fixed version** — `^1.1.1`, NOT `^1.1`
   (a caret on the full `MAJOR.MINOR.PATCH` excludes the buggy `1.1.0`; `^1.1` would re-admit it).
2. Bump the dependent's own version (PATCH for a pure floor raise) and **re-release** it (commit + tag + push).
3. That re-release makes the dependent a *new upstream release* → repeat for **its** children. The cascade is
   transitive: `money 1.1.1` → bump `ddd-ai` floor (re-release `1.4.1`) → `ddd-ai` is now upgraded → bump
   `ddd-common-translations`'s `ddd-ai` floor (and its `money` floor) → re-release `translations 1.0.23` → done
   (translations is a leaf).
4. Only after all module floors are raised + re-released do you `composer update` the **consuming apps** — they then
   resolve the whole fixed graph (verify with `composer why mgamadeus/<pkg>` that the floor, not luck, pins it).

**Release the cascade in dependency order** (parent before child, so the child can resolve the parent's new tag —
wait for Packagist between each; see [Multi-Module Release](#workflow-release-core--propagate-to-all)). A floor raise
that the existing caret already satisfies (e.g. bumping `^2.10`→`^2.10` for a Core patch) needs no child re-release —
but a *new minimum* (`^1.0`→`^1.1.1`) always does.

### Consuming Applications

Any application using DDD modules needs `composer update --ignore-platform-reqs` after module releases. Check the application's `composer.json` for `mgamadeus/*` dependencies.

The known consuming apps and their workspace paths:

| App | Path | DATABASE_TABLE_PREFIX |
|---|---|---|
| Tavlo | `/Users/marius/Development/Tavlo/tavlo_backend/backend` | `''` (none) |
| Radbonus | `/Users/marius/Development/Radbonus/rb-backend` | `''` (none) |
| RC | `/Users/marius/Development/RC/rc-app-backend/app` | `Entity` |

#### CRITICAL: never pass `--no-scripts` when updating a consuming app

Consuming apps wire the Doctrine model generator into composer's lifecycle:

```jsonc
"scripts": {
  "post-update-cmd":  ["php bin/console cache:clear",
                       "php bin/console app:generate-doctrine-models-for-entities"],
  "post-install-cmd": ["php bin/console cache:clear",
                       "php bin/console app:generate-doctrine-models-for-entities"]
}
```

`composer update` overwrites `vendor/` with each package's **shipped** `DB*Model` files —
generated upstream with the upstream prefix (none) and without the consumer's entity
overrides. The `post-update-cmd` hook **regenerates** them against the consumer's own
`DATABASE_TABLE_PREFIX` and overridden entities. Skipping it with `--no-scripts` leaves the
vendor models on the upstream table names, so any framework-internal code that uses a vendor
repo directly (`new DBCacheScopeInvalidation()`, etc.) hits a non-existent table at runtime
(`SQLSTATE[42S02] … doesn't exist`) — surfacing only on the code path that touches it (e.g. a
cache-hit). This caused a real production 500 on RC (`CacheScopeInvalidations` vs
`EntityCacheScopeInvalidations`).

Therefore, the canonical update form **differs between modules and apps**:

```bash
# Framework MODULES (Core, AI, Argus, Geo, Money, Political, Translations) — no gen hook,
# --no-scripts is safe and faster:
composer update mgamadeus/ddd --ignore-platform-reqs --no-scripts -W

# Consuming APPS (Tavlo, Radbonus, RC) — let the scripts run, NEVER --no-scripts:
composer update mgamadeus/ddd --ignore-platform-reqs
```

If a throwaway resolver-only check genuinely needs `--no-scripts` on an app, run the generator
yourself immediately after: `php bin/console app:generate-doctrine-models-for-entities`.

RC additionally needs `--ignore-platform-req=php` (its `require.php` is strict `8.3.*` with no
`config.platform.php` override) — combine the targeted ignores rather than the broad form when
you must preserve the lockfile's platform constraints; but still without `--no-scripts`.

---

## Workflow: Study a Module Before Making Changes

Before modifying any module, read these files in order:

1. **`composer.json`** -- package name, version, dependencies
2. **`AGENTS.md`** -- architecture, entity overview, patterns, conventions
3. **`.claude/skills/*/SKILL.md`** -- detailed domain knowledge
4. **`README.md`** -- public documentation
5. **`src/Modules/*/Module.php`** -- DDDModule entry point (source path, config path, public namespaces)
6. **Key entity files** -- read `src/Domain/` entities to understand the domain model
7. **Key service files** -- read services for business logic patterns

```bash
# Quick study script for any module (pass path as argument)
cat "$MODULE_PATH/composer.json" | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'Package: {d[\"name\"]} v{d[\"version\"]}'); print('Requires:'); [print(f'  {k}: {v}') for k,v in d.get('require',{}).items() if 'mgamadeus' in k]"
```

---

## Workflow: Release a Single Module

### 1. Make Changes

Edit files in the module's directory (entities, services, skills, docs).

### 2. Commit

```bash
cd "$MODULE_PATH"
git add <changed-files>
git commit -m "$(cat <<'EOF'
Description of changes

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

### 3. Bump Version + Tag + Push

Use the `ddd-composer-update-version` skill for the full process.

### 4. Propagate to Dependent Modules

**Wait ~10 seconds** after the tag push for Packagist to register the new version, then run `composer update --ignore-platform-reqs` in all dependent modules (see reverse dependency table above).

If `composer.lock` changed, commit and push it.

---

## Workflow: Release Core + Propagate to All

When releasing a new DDD Core version, ALL modules need updating.

### Process in dependency order:

```
1. Core           -> release, wait 10s
2. Money, Argus   -> composer update (--no-scripts ok), make changes if needed, release, wait 10s
3. Political, AI  -> composer update (--no-scripts ok), make changes if needed, release, wait 10s
4. Geo, Translations -> composer update (--no-scripts ok), make changes if needed, release, wait 10s
5. Consuming apps -> composer update (NEVER --no-scripts — see "Consuming Applications")
```

**At each level:** modules within the same level can be processed in parallel (they don't depend on each other).

For each MODULE at each level (modules have no gen hook, so `--no-scripts` is safe and faster):
```bash
cd "$MODULE_PATH" && composer update --ignore-platform-reqs --no-scripts -W
if [ -n "$(git diff composer.lock)" ]; then
  git add composer.lock
  git commit -m "Update composer dependencies

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
  git push
fi
```

For each consuming APP at level 5 (let the `post-update-cmd` model generator run — **no `--no-scripts`**):
```bash
cd "$APP_PATH" && composer update mgamadeus/ddd --ignore-platform-reqs   # RC: add --ignore-platform-req=php
# post-update-cmd auto-runs: cache:clear + app:generate-doctrine-models-for-entities
```

---

## Workflow: Update Documentation Across All Modules

When updating AGENTS.md, skills, or README across multiple modules:

1. **Study current state** -- check line counts and content of AGENTS.md, skills, README in each module
2. **Make changes** -- edit files directly in each module's directory
3. **Commit + Release each** -- process in dependency order, bump version, push, tag
4. **Propagate** -- `composer update` in dependents after each release

---

## Key Conventions

- **`--ignore-platform-reqs`** is required for `composer update` because modules may require PHP extensions not available on the current machine
- **`--no-scripts` on framework modules only, NEVER on consuming apps.** Modules have no composer lifecycle hooks; apps wire `app:generate-doctrine-models-for-entities` into `post-update-cmd`, and skipping it leaves vendor `DB*Model` files on upstream (prefix-less, override-less) table names → runtime `SQLSTATE[42S02]` 500. See [Consuming Applications](#consuming-applications).
- **Wait ~10 seconds** between pushing a tag and running `composer update` in dependents -- Packagist needs time to register the new version (in practice often longer; if `composer update` reports the old version, clear the cache with `composer clear-cache` and retry, or poll `https://repo.packagist.org/p2/<vendor>/<pkg>.json`)
- **Always commit `composer.lock`** changes in modules after dependency updates
- **Never force-push tags** unless explicitly asked
- **Tag format:** `v` prefix required (e.g., `v2.10.12`) -- Packagist matches `v*` pattern
- **Version in `composer.json`** must match tag (without `v` prefix)
- **Co-Authored-By** header required on all commits

---

## Documentation Architecture

### Layered Knowledge System

```
DDD Core (vendor/mgamadeus/ddd/)
+-- AGENTS.md               [Framework architecture, conventions, best practices, utilities reference]
+-- README.md                [Public documentation for humans]
+-- .claude/skills/ddd-*     [10 framework skills]

DDD Modules (vendor/mgamadeus/ddd-{name}/)
+-- AGENTS.md               [Module architecture, entities, services -- references DDD Core AGENTS.md]
+-- README.md                [Public module documentation]
+-- .claude/skills/ddd-module-{name}-specialist  [Module-specific domain knowledge]

Consuming Applications (.claude/skills/)
+-- SYMLINKS to vendor/      [ddd-* and ddd-module-* skills auto-update via composer]
+-- {app}-entity-specialist  [Thin extension: app domains, file paths, strategy docs]
+-- {app}-endpoint-specialist [Thin extension: app API audiences, conventions]
+-- {app}-*                  [App-specific skills: tests, frontend, business strategy]
+-- AGENTS.md (project root) [App business context, domain vocabulary -- references vendor AGENTS.md]
```

### Cross-Reference Conventions

Every AGENTS.md and skill must reference its parent layer:

- **Module AGENTS.md** must contain: `> For base patterns, see vendor/mgamadeus/ddd/AGENTS.md and skills in vendor/mgamadeus/ddd.`
- **Module skills** must contain: `> Base patterns: See core skills in vendor/mgamadeus/ddd.`
- **App AGENTS.md** must contain: `> For all DDD patterns, see vendor/mgamadeus/ddd/AGENTS.md and the DDD Core skills (symlinked in .claude/skills/ddd-*).`
- **App extension skills** must contain: `> For base templates, see the ddd-{name}-specialist skill.`

Modules that depend on other modules must also reference them (e.g., "For Argus patterns, see `vendor/mgamadeus/ddd-argus`.").

### Skill Naming Convention

| Prefix | Meaning | Example |
|--------|---------|---------|
| `ddd-` | DDD Core framework skill | `ddd-entity-specialist` |
| `ddd-module-` | DDD module skill | `ddd-module-geo-specialist` |
| `{app}-` | App-specific extension | (defined per consuming application) |
| (no prefix) | Generic tooling | `planning-with-files`, `skill-creator` |

### Symlink Pattern for Consuming Applications

Applications symlink vendor skills into `.claude/skills/` for auto-updating:

```bash
# Relative path from .claude/skills/{skill-name} to vendor/
# Adjust depth based on project structure (where .claude/ sits relative to vendor/)
ln -sfn "../../vendor/mgamadeus/ddd/.claude/skills/ddd-entity-specialist" .claude/skills/ddd-entity-specialist
ln -sfn "../../vendor/mgamadeus/ddd-common-geo/.claude/skills/ddd-module-geo-specialist" .claude/skills/ddd-module-geo-specialist
```

**Key:** From `.claude/skills/{skill-name}`, go `../../` to reach the directory containing `vendor/`. If `vendor/` is in a subdirectory (e.g., `backend/vendor/`), adjust the path accordingly (e.g., `../../backend/vendor/...`).

Symlinks auto-update when `composer update` pulls new versions. Git tracks symlinks as path text.

### What NOT to Put in DDD Core

- Project-specific domain knowledge (app-specific entities, business vocabulary)
- Project-specific file paths or directory structures
- Project-specific strategy documents or business context
- Project-specific environment variables beyond framework defaults
- References to specific consuming applications

### What Lives Where

| Content | Location | Purpose |
|---------|----------|---------|
| Framework patterns | Core `.claude/skills/ddd-*` | Entity, service, endpoint, QueryOptions, rights, message handler, CLI command |
| Module domain knowledge | Module `.claude/skills/ddd-module-*` | Module-specific entities, services, APIs |
| Architecture overview | `AGENTS.md` in each repo | High-level reference (must reference parent layer) |
| Public documentation | `README.md` in each repo | Installation, setup, usage for humans |
| Orchestration | Core `.claude/skills/ddd-module-orchestrator` | Cross-module workflows, documentation architecture |
| Version management | Core `.claude/skills/ddd-composer-update-version` | Release process |
| Code quality | Core `.claude/skills/ddd-code-inspect-with-qodana` | Static analysis |
| Rights system | Core `.claude/skills/ddd-rights-specialist` | Access control patterns |
