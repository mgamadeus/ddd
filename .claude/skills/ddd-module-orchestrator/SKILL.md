---
name: ddd-module-orchestrator
description: Centralized orchestration of all DDD modules from the Core project. Study, update, release, and propagate changes across the entire module ecosystem. Use when making cross-module changes, releasing versions, updating documentation, or propagating dependency upgrades.
metadata:
  author: mgamadeus
  version: "1.0.0"
---

# DDD Module Orchestrator

Centralized control for studying, updating, releasing, and propagating changes across the entire DDD module ecosystem -- all from this Core project's working directory.

## When to Use

- Making changes that affect multiple DDD modules
- Releasing a new version of Core and propagating to dependent modules
- Updating AGENTS.md, skills, or README across modules
- Studying a module's codebase before making changes
- Running `composer update` in dependent modules after a release

## Module Ecosystem

### Packages & Paths

| Package | Path | Version Field |
|---------|------|---------------|
| `mgamadeus/ddd` (Core) | `/Users/marius/Development/Own Packages/Composer/DDD/Core` | `composer.json` |
| `mgamadeus/ddd-common-money` | `/Users/marius/Development/Own Packages/Composer/DDD/Modules/Money` | `composer.json` |
| `mgamadeus/ddd-argus` | `/Users/marius/Development/Own Packages/Composer/DDD/Modules/Argus` | `composer.json` |
| `mgamadeus/ddd-common-political` | `/Users/marius/Development/Own Packages/Composer/DDD/Modules/Political` | `composer.json` |
| `mgamadeus/ddd-ai` | `/Users/marius/Development/Own Packages/Composer/DDD/Modules/AI` | `composer.json` |
| `mgamadeus/ddd-common-geo` | `/Users/marius/Development/Own Packages/Composer/DDD/Modules/Geo` | `composer.json` |
| `mgamadeus/ddd-common-translations` | `/Users/marius/Development/Own Packages/Composer/DDD/Modules/Translations` | `composer.json` |

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

| If you change... | These need `composer update`: |
|------------------|-------------------------------|
| **ddd** (Core) | Money, Argus, Political, AI, Geo, Translations (ALL) |
| **ddd-common-money** | AI, Translations |
| **ddd-argus** | Political, AI |
| **ddd-common-political** | Geo, Translations |
| **ddd-ai** | Translations |
| **ddd-common-geo** | (none -- leaf module) |
| **ddd-common-translations** | (none -- leaf module) |

### Consuming Applications (Also Need `composer update`)

| Application | Path |
|-------------|------|
| Radbonus | `/Users/marius/Development/Radbonus/rb-backend` |
| Tavlo | `/Users/marius/Development/Tavlo/tavlo_backend/backend` |

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
# Quick study script for any module
MODULE_PATH="/Users/marius/Development/Own Packages/Composer/DDD/Modules/Geo"
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

Use the `ddd-composer-update-version` skill, or:

```bash
cd "$MODULE_PATH"
NEW_VERSION=$(python3 -c "
import json
with open('composer.json', 'r') as f: d = json.load(f)
v = d['version'].split('.')
v[-1] = str(int(v[-1]) + 1)
d['version'] = '.'.join(v)
with open('composer.json', 'w') as f: json.dump(d, f, indent=4, ensure_ascii=False); f.write('\n')
print(d['version'])
") && git add composer.json && git commit -m "Bump version to $NEW_VERSION

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>" && git push && git tag "v$NEW_VERSION" && git push origin "v$NEW_VERSION" && echo "Released v$NEW_VERSION"
```

### 4. Propagate to Dependent Modules

**Wait ~10 seconds** after the tag push for Packagist to register the new version, then run `composer update` in all dependent modules:

```bash
sleep 10

# Example: after releasing ddd-argus, update Political and AI
for DEP_PATH in \
  "/Users/marius/Development/Own Packages/Composer/DDD/Modules/Political" \
  "/Users/marius/Development/Own Packages/Composer/DDD/Modules/AI"; do
  echo "=== Updating $(basename $DEP_PATH) ==="
  cd "$DEP_PATH" && composer update --ignore-platform-reqs && echo "Done"
done
```

If `composer.lock` changed, commit it:

```bash
cd "$DEP_PATH"
if [ -n "$(git diff composer.lock)" ]; then
  git add composer.lock
  git commit -m "Update composer dependencies

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
  git push
fi
```

---

## Workflow: Release Core + Propagate to All

When releasing a new DDD Core version, ALL modules need updating:

### Step 1: Release Core

```bash
cd "/Users/marius/Development/Own Packages/Composer/DDD/Core"
# ... commit changes, bump version, push, tag (see above)
```

### Step 2: Wait for Packagist

```bash
sleep 10
```

### Step 3: Update All Modules (Dependency Order)

```bash
MODULES=(
  "/Users/marius/Development/Own Packages/Composer/DDD/Modules/Money"
  "/Users/marius/Development/Own Packages/Composer/DDD/Modules/Argus"
  "/Users/marius/Development/Own Packages/Composer/DDD/Modules/Political"
  "/Users/marius/Development/Own Packages/Composer/DDD/Modules/AI"
  "/Users/marius/Development/Own Packages/Composer/DDD/Modules/Geo"
  "/Users/marius/Development/Own Packages/Composer/DDD/Modules/Translations"
)

for MOD in "${MODULES[@]}"; do
  echo "=== Updating $(basename $MOD) ==="
  cd "$MOD" && composer update --ignore-platform-reqs
  if [ -n "$(git diff composer.lock)" ]; then
    git add composer.lock
    git commit -m "Update composer dependencies

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
    git push
    echo "Committed and pushed lock update"
  else
    echo "No dependency changes"
  fi
  echo
done
```

### Step 4: Update Consuming Applications

```bash
APPS=(
  "/Users/marius/Development/Radbonus/rb-backend"
  "/Users/marius/Development/Tavlo/tavlo_backend/backend"
)

for APP in "${APPS[@]}"; do
  echo "=== Updating $(basename $APP) ==="
  cd "$APP" && composer update --ignore-platform-reqs
  if [ -n "$(git diff composer.lock)" ]; then
    echo "composer.lock changed -- review and commit manually"
  fi
  echo
done
```

---

## Workflow: Update Documentation Across All Modules

When updating AGENTS.md, skills, or README across multiple modules:

### 1. Study Current State

```bash
for mod in Core Money Argus Political AI Geo Translations; do
  if [ "$mod" = "Core" ]; then
    P="/Users/marius/Development/Own Packages/Composer/DDD/Core"
  else
    P="/Users/marius/Development/Own Packages/Composer/DDD/Modules/$mod"
  fi
  echo "=== $mod ==="
  wc -l "$P/AGENTS.md" "$P/.claude/skills/"*/SKILL.md "$P/README.md" 2>/dev/null
  echo
done
```

### 2. Make Changes

Edit files directly in each module's path. All modules are accessible from this working directory via absolute paths.

### 3. Commit + Release Each

Process in dependency order. For each module:
1. `cd` to module path
2. `git add` changed files
3. Commit with descriptive message
4. Bump version, push, tag
5. Wait 10s, then `composer update` in dependents

---

## Workflow: Cascade Release (Multiple Modules Changed)

When changes span multiple modules (e.g., Core changes that require module updates):

### Process in dependency order:

```
1. Core           -> release, wait 10s
2. Money, Argus   -> composer update, make changes, release, wait 10s
3. Political, AI  -> composer update, make changes, release, wait 10s
4. Geo, Translations -> composer update, make changes, release, wait 10s
5. Apps (Radbonus, Tavlo) -> composer update
```

**At each level:** modules within the same level can be processed in parallel (they don't depend on each other).

---

## Key Conventions

- **`--ignore-platform-reqs`** is required for `composer update` because modules may require PHP extensions not available on the current machine
- **Wait ~10 seconds** between pushing a tag and running `composer update` in dependents -- Packagist needs time to register the new version
- **Always commit `composer.lock`** changes in modules after dependency updates
- **Never force-push tags** unless explicitly asked
- **Tag format:** `v` prefix required (e.g., `v2.10.12`) -- Packagist matches `v*` pattern
- **Version in `composer.json`** must match tag (without `v` prefix)
- **Co-Authored-By** header required on all commits

## What Lives Where

| Content | Location | Purpose |
|---------|----------|---------|
| Framework patterns | Core `.claude/skills/ddd-*` | Entity, service, endpoint, QueryOptions, message handler, CLI command patterns |
| Module domain knowledge | Module `.claude/skills/*-module-specialist` | Module-specific entities, services, APIs |
| Architecture overview | `AGENTS.md` in each repo | High-level reference for agents |
| Public documentation | `README.md` in each repo | Installation, setup, usage for humans |
| This orchestration | Core `.claude/skills/ddd-module-orchestrator` | Cross-module workflows |
| Version management | Core `.claude/skills/ddd-composer-update-version` | Release process |
| Code quality | Core `.claude/skills/ddd-code-inspect-with-qodana` | Static analysis |
