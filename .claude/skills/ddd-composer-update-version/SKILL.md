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

## DDD Module Locations

| Package | Path |
|---------|------|
| `mgamadeus/ddd` | `/Users/marius/Development/Own Packages/Composer/DDD/Core` |
| `mgamadeus/ddd-common-money` | `/Users/marius/Development/Own Packages/Composer/DDD/Modules/Money` |
| `mgamadeus/ddd-argus` | `/Users/marius/Development/Own Packages/Composer/DDD/Modules/Argus` |
| `mgamadeus/ddd-common-political` | `/Users/marius/Development/Own Packages/Composer/DDD/Modules/Political` |
| `mgamadeus/ddd-ai` | `/Users/marius/Development/Own Packages/Composer/DDD/Modules/AI` |
| `mgamadeus/ddd-common-geo` | `/Users/marius/Development/Own Packages/Composer/DDD/Modules/Geo` |
| `mgamadeus/ddd-common-translations` | `/Users/marius/Development/Own Packages/Composer/DDD/Modules/Translations` |

## Rules

- **Never** push a tag without first committing the version bump to `composer.json`
- The tag version (`vX.Y.Z`) must match `composer.json` version (`X.Y.Z`)
- Always include `Co-Authored-By` in commits
- Default to PATCH bump unless told otherwise
- **Never** use `--force` on tags or pushes unless explicitly asked
