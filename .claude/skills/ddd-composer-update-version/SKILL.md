---
name: ddd-composer-update-version
description: Bump composer.json version, commit, push, and create a git tag that triggers Packagist update. Use when releasing a new version of any DDD package (core or module).
metadata:
  author: mgamadeus
  version: "1.0.0"
---

# DDD Composer Version Update & Release

Bump the version in `composer.json`, commit, push, and tag to trigger a Packagist release.

## When to Use

- Releasing a new version of a DDD package after code changes
- After committing feature/fix changes, to bump version and tag
- When asked to "release", "bump version", "tag", or "publish"

## How Packagist Auto-Update Works

Packagist watches for git tags matching `v*` (e.g., `v2.10.12`). When a tag is pushed, Packagist automatically picks up the new version. The `version` field in `composer.json` must match the tag (without the `v` prefix).

## Release Process

### Step 1: Verify Clean State

> ⚠️ **cwd hazard — run every `git` command in this procedure from the package's OWN source checkout, never from a consuming app's `vendor/mgamadeus/<pkg>` copy.** A `vendor/` copy is **not its own git repository**: `git rev-parse --show-toplevel` there resolves to the *consuming application*, and `git status` / `git tag` / `git push` operate on the app. Following these steps verbatim from a vendor path will commit to, tag, and push the **wrong repository** (e.g. tagging the app `v4.7.0` instead of the package `v2.x`). Confirm your cwd first:
> ```bash
> git rev-parse --show-toplevel   # must be the package repo, NOT a consuming app
> python3 -c "import json; print(json.load(open('composer.json'))['name'])"  # must be the package you're releasing
> ```

```bash
git status
git log --oneline -3
```

Ensure all changes are committed. If there are uncommitted changes, commit them first before bumping the version.

### Step 2: Determine Version Bump

Read the current version from `composer.json`:

```bash
python3 -c "import json; print(json.load(open('composer.json'))['version'])"
```

**Version format:** `MAJOR.MINOR.PATCH` (e.g., `2.10.11`)

| Change Type | Bump | Example |
|-------------|------|---------|
| Bug fix, minor improvement | PATCH | `2.10.11` -> `2.10.12` |
| New feature, backward-compatible | MINOR | `2.10.11` -> `2.11.0` |
| Breaking change | MAJOR | `2.10.11` -> `3.0.0` |

**Default: bump PATCH** unless the user specifies otherwise.

### Step 3: Update composer.json

```bash
python3 -c "
import json
with open('composer.json', 'r') as f:
    d = json.load(f)
v = d['version'].split('.')
v[-1] = str(int(v[-1]) + 1)  # Bump patch
d['version'] = '.'.join(v)
print(f'Version: {d[\"version\"]}')
with open('composer.json', 'w') as f:
    json.dump(d, f, indent=4, ensure_ascii=False)
    f.write('\n')
"
```

For MINOR bump, replace the version update line with:
```python
v[1] = str(int(v[1]) + 1); v[2] = '0'
```

For MAJOR bump:
```python
v[0] = str(int(v[0]) + 1); v[1] = '0'; v[2] = '0'
```

### Step 4: Commit

```bash
git add composer.json
git commit -m "$(cat <<'EOF'
Bump version to X.Y.Z

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

If other files were changed alongside the version bump (e.g., AGENTS.md, skills, README), include them in the same commit with a descriptive message:

```bash
git add composer.json AGENTS.md .claude/ README.md
git commit -m "$(cat <<'EOF'
Add documentation and bump version to X.Y.Z

- Add AGENTS.md with module architecture documentation
- Add Claude Code skills for AI-assisted development
- Update README with comprehensive examples
- Bump version to X.Y.Z

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Step 5: Push and Tag

```bash
git push
git tag vX.Y.Z
git push origin vX.Y.Z
```

The tag **must** have the `v` prefix (e.g., `v2.10.12`). Packagist matches tags like `v*`.

### Step 6: Verify

```bash
echo "Released: $(python3 -c "import json; print(json.load(open('composer.json'))['version'])")"
git tag --list 'v*' | tail -3
```

Packagist usually updates within a few minutes after the tag push.

## Complete One-Liner (Patch Bump)

For a quick patch release after changes are already committed:

```bash
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

## Updating Consuming Apps After a Release

After tagging and pushing a new version, the consuming apps need a `composer update` to pick it up.

**Default: plain `composer update`, no platform flag — the app's `config.platform.php` already pins resolution.**

```bash
cd /path/to/consuming-app && composer update mgamadeus/ddd-... -W
```

### Why — `config.platform.php` is the mechanism now

Every DDD-consuming app in this ecosystem pins the deployment PHP in its `composer.json`:

```json
"config": { "platform": { "php": "8.3" } }
```

With that pin present, `composer update` resolves against **8.3** regardless of the local runtime (even a local 8.4/8.5), and writes an 8.3-safe `composer.lock`. No flag is required, and the lock stays deployable.

> ⚠️ **Do NOT reflexively add `--ignore-platform-reqs`.** The broad form overrides the `config.platform.php` pin as well, so composer becomes free to resolve packages that only run on a **newer** PHP than the target and commit them to the lock — exactly how five PHP-8.4-only packages (`doctrine/instantiator 2.1`, `symfony/mime|property-access|property-info|type-info v8.1.x`) once landed in a committed 8.3 lock. The old "ALWAYS `--ignore-platform-reqs`" rule predates the `config.platform.php` pins and has been **revised out** across the apps; it is no longer needed and is now a footgun.

### When you DO need a targeted ignore

Only when the resolver stops on an **extension** that is genuinely absent on the local machine **and** not already listed in `config.platform` — ignore that one extension, never `php`:

```bash
composer update mgamadeus/ddd -W --ignore-platform-req=ext-redis   # only the missing ext
```

| Command | Effect |
|---------|--------|
| `composer update X -W` (no flag) | ✅ Default. `config.platform.php` pins resolution to the target PHP; lock stays deployable. |
| `composer update X -W --ignore-platform-req=ext-<name>` | ✅ Only when that extension is genuinely missing locally and not in `config.platform`. Never ignores `php`. |
| `composer update X --ignore-platform-reqs` | ❌ Overrides the `config.platform.php` pin too — can pull newer-PHP-only packages into the lock. Avoid. |
| `composer update X --ignore-platform-req=php` | ❌ Defeats the very pin that expresses the production target. Avoid. |

If a lock was already poisoned by the broad form, re-run a plain `composer update` (with `config.platform.php` set) to re-resolve back onto the pinned PHP.

### Verify the new version landed

```bash
composer show mgamadeus/ddd-... | grep '^versions'
```

If the version did not advance, the most common causes are:
1. A transitive dependency constraint blocks the new version — diagnose with `composer prohibits mgamadeus/ddd-... <new-version>`.
2. Stale Packagist cache — clear with `composer clear-cache` and retry.
3. The tag was pushed but Packagist hasn't picked it up yet (usually <1 min).

## Multi-Module Release

When releasing multiple DDD modules, process in dependency order:

1. **mgamadeus/ddd** (core) -- no deps
2. **mgamadeus/ddd-common-money** -- depends on ddd
3. **mgamadeus/ddd-argus** -- depends on ddd
4. **mgamadeus/ddd-common-political** -- depends on ddd, argus
5. **mgamadeus/ddd-ai** -- depends on ddd, argus, money
6. **mgamadeus/ddd-common-geo** -- depends on ddd, political
7. **mgamadeus/ddd-common-translations** -- depends on ddd, ai, money, political

If updating dependency version constraints (e.g., requiring a new core version), update `composer.json` `require` entries before bumping.

### Propagating a fix downstream = floor bump + re-release (NOT just `composer update`)

A `composer update` in a dependent only refreshes that dependent's own lock; it does **not** change the published
`require` floor, so downstream apps can still resolve the old (buggy) upstream version. To actually force a fix
through the ecosystem you must, for **every transitive dependent** (walk the reverse-dependency graph in the
`ddd-module-orchestrator` skill): raise its `require` floor to the **exact fixed version** (`^1.1.1`, not `^1.1` —
the latter re-admits the buggy `1.1.0`), bump its own version (PATCH for a pure floor raise), and re-release it. The
re-release makes that dependent a new upstream release, so the cascade repeats for its children down to the leaves.
Only then `composer update` the consuming apps. See **Dependency-Floor Cascade** in `ddd-module-orchestrator` for the
full rule and a worked example (money `1.1.1` → `ddd-ai 1.4.1` → `ddd-translations 1.0.23`).

## DDD Module Packages

See the `ddd-module-orchestrator` skill for the complete module ecosystem, dependency graph, and release order.

## Cross-Reference

- **Pre-release static analysis** — run a Qodana/IntelliJ inspection pass and clear findings before bumping; see `ddd-code-inspect-with-qodana`.

## Rules

- **Never** push a tag without first committing the version bump to `composer.json`
- The tag version (`vX.Y.Z`) must match `composer.json` version (`X.Y.Z`)
- Always include `Co-Authored-By` in commits
- Default to PATCH bump unless told otherwise
- **Never** use `--force` on tags or pushes unless explicitly asked
- **Prefer a plain `composer update -W`** in consuming apps — every app sets `config.platform.php`, which pins resolution to the deployment PHP. Do **not** reflexively add `--ignore-platform-reqs`: the broad form overrides that pin and can commit newer-PHP-only packages into the lock. Ignore only a genuinely-missing local **extension** (`--ignore-platform-req=ext-<name>`), never `php`. (See "Updating Consuming Apps" above.)
