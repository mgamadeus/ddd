---
name: ddd-code-inspect-with-qodana
description: Run JetBrains Qodana PHP static analysis natively (Docker-free via QODANA_DIST), parse SARIF results with a ready-made Python script, and fix or suppress findings via per-inspection classification (fix vs @noinspection vs skip; never edit generated DB*Model.php) — plus single-file inspection through the JetBrains MCP (get_file_problems, build_project, reformat_file) without a full scan. Covers one-time setup (CLI install, Qodana Cloud project token — not organization token — as QODANA_TOKEN), profile selection (stub .idea profile pitfall vs qodana.yaml), nested-app monorepo scans via --project-dir, and troubleshooting (token declined, IDE not found, Docker errors, 0-problem scans). Use when running inspections, checking code quality, fixing static-analysis warnings, setting up Qodana, debugging a scan that finds nothing or fails to start, or getting inspection problems for a single file from the IDE.
metadata:
  author: mgamadeus
  version: "1.1.0"
---

# Qodana Inspect

Runs JetBrains Qodana PHP inspections, parses results, and applies fixes.

## When to Use

- User asks to run inspections, code quality checks, or static analysis
- After large refactoring sessions to verify no regressions
- Before creating a PR to check for issues
- Getting inspection problems for a single file via the JetBrains MCP (no full scan needed)

## Setup (One-Time)

### 1. Install Qodana CLI

```bash
brew install jetbrains/utils/qodana
```

Verify: `qodana --version`

### 2. Obtain the Qodana Cloud Project Token

Qodana requires a cloud connection for license verification, even when running locally. The free trial (60 days, "Ultimate Plus") is sufficient.

1. Go to [qodana.cloud](https://qodana.cloud) and sign up / log in
2. Create an **organization** (any name)
3. Create a **project** inside the organization (any name)
4. Navigate to: **Your project > Settings > General > Project token**
5. Copy the **project token** (a JWT starting with `eyJ...`)

**Important:** Use the **project token**, not the organization token. The project token JWT payload contains a `"project"` field.

### 3. Store the Token

Add it to `~/.zshenv` so it's available in all terminal sessions:

```bash
echo 'export QODANA_TOKEN=eyJhbGci...<your full token>' >> ~/.zshenv
source ~/.zshenv
```

**Do not** store the token in `.env`, CLAUDE.md, memory files, or skill files.

### 4. Bootstrap the PhpStorm Distribution (older Qodana CLI only)

On first run, Qodana downloads a PhpStorm distribution:

```bash
qodana scan --ide QDPHP
```

> **CLI note.** `--ide` is **deprecated** as of Qodana CLI 2025.3.x (`qodana scan --help`: *"Flag --ide has been deprecated, use --linter … and --within-docker=false"*). This skill's native, Docker-free path does not need it: Steps 1–2 below obtain the distribution via `QODANA_DIST` and never pass `--ide`. Use the bootstrap above only on an older CLI; on a current CLI just run the `QODANA_DIST=… qodana scan` form directly.

## IntelliJ MCP Integration (Per-File Inspections)

The JetBrains MCP server allows running IntelliJ inspections on individual files directly from the IDE.

### Usage

```
mcp__jetbrains__get_file_problems(
  filePath: "src/Domain/...",
  projectPath: "/path/to/project",
  errorsOnly: false
)
```

### MCP vs Qodana

| | MCP (`get_file_problems`) | Qodana CLI |
|---|---|---|
| Scope | Single file | Full `src/` directory |
| Speed | Instant | 30-60 seconds |
| Requires | IntelliJ open | Only CLI + token |
| Use for | Verifying a fix | Full project scan |

### Other Useful MCP Tools

| Tool | Purpose |
|---|---|
| `mcp__jetbrains__get_file_problems` | Run inspections on a file |
| `mcp__jetbrains__build_project` | Trigger a build and get errors |
| `mcp__jetbrains__reformat_file` | Apply code formatting rules |

## Running Qodana

### Step 1: Find the PhpStorm distribution

```bash
PHPSTORM_DIST=$(find ~/Library/Caches/JetBrains/Qodana -name "PhpStorm.app" -maxdepth 3 2>/dev/null | head -1)
```

### Step 2: Run the scan

**With project inspection profile (preferred):**
```bash
QODANA_DIST="$PHPSTORM_DIST/Contents" qodana scan \
  --only-directory src \
  --profile-path .idea/inspectionProfiles/Project_Default.xml
```

**With default profile (fallback):**
```bash
QODANA_DIST="$PHPSTORM_DIST/Contents" qodana scan \
  --only-directory src
```

Check whether `.idea/inspectionProfiles/Project_Default.xml` exists **and is a real profile** before using it — do not blindly `--profile-path` it. In some repos this file is a tiny legacy stub (e.g. a ~475-byte 2020 file whose entire body *disables* a couple of inspections); pointing Qodana at that selects a near-empty profile and the scan reports almost nothing. If the file is a stub (or missing), prefer `qodana.yaml` (below) or fall back to the default profile. A per-project `qodana.yaml` at the repo/app root is the modern home for profile, scope and excludes — check for it first and let Qodana pick it up automatically; `--profile-path` is only for an explicitly maintained `.idea` profile.

For a **nested app inside a monorepo**, pass `-i`/`--project-dir` pointing at the app root (e.g. `-i app/`). Without it, a root-level scan indexes the entire monorepo instead of the app.

### Step 3: Parse results

```python
python3 << 'PYEOF'
import json, glob, os

files = glob.glob(os.path.expanduser("~/Library/Caches/JetBrains/Qodana/*/results/qodana.sarif.json"))
latest = max(files, key=os.path.getmtime)

data = json.load(open(latest))
for run in data.get("runs", []):
    results = run.get("results", [])
    by_rule = {}
    for r in results:
        rule = r.get("ruleId", "unknown")
        level = r.get("level", "unknown")
        if rule not in by_rule:
            by_rule[rule] = {"count": 0, "level": level, "files": []}
        by_rule[rule]["count"] += 1
        for loc in r.get("locations", []):
            uri = loc.get("physicalLocation", {}).get("artifactLocation", {}).get("uri", "")
            line = loc.get("physicalLocation", {}).get("region", {}).get("startLine", "?")
            msg = r.get("message", {}).get("text", "")
            by_rule[rule]["files"].append({"uri": uri, "line": line, "msg": msg})

    print(f"Total: {len(results)} problems\n")
    for rule, info in sorted(by_rule.items(), key=lambda x: -x[1]["count"]):
        print(f"  {rule} [{info['level']}]: {info['count']}")
        for f in info["files"][:5]:
            print(f"    {f['uri']}:{f['line']} - {f['msg']}")
        if len(info["files"]) > 5:
            print(f"    ... and {len(info['files']) - 5} more")
        print()
PYEOF
```

## Fixing Issues

### CRITICAL: No bulk regex replacements

**NEVER** run regex replacements across the entire `src/` directory. Always fix file by file using the Read + Edit tools.

### Issue Classification

#### Fix manually (read file, apply Edit tool per file):

| Inspection | Fix |
|---|---|
| `PhpIssetCanBeReplacedWithCoalesceInspection` | `isset($x) ? $x : $d` to `$x ?? $d` |
| `PhpNullSafeOperatorCanBeUsedInspection` | `if ($x) { $x->m(); }` to `$x?->m()` |
| `PhpSeparateElseIfInspection` | `} else { if (` to `} elseif (` |
| `PhpCastIsUnnecessaryInspection` | Remove redundant casts |
| `PhpUnnecessaryDoubleQuotesInspection` | `""` to `''` when no interpolation (skip DB*Model.php) |
| `PhpIncompatibleReturnTypeInspection` | Fix wrong type annotations -- can be real bug |
| `PhpParamsInspection` | Fix parameter type mismatches -- can be real bug |
| `PhpUndefinedVariableInspection` | Initialize variable before conditional blocks |
| `PhpUndefinedFieldInspection` | Add curly braces: `"track_{$track->id}_export"` -- real bug |
| `PhpUnreachableStatementInspection` | Remove dead code after throw/return |
| `PhpUnnecessaryCurlyVarSyntaxInspection` | Remove `{}` from `"{$simpleVar}"` → `"$simpleVar"`. Do NOT concatenate with `.` — use direct interpolation: `"From: $var"` not `'From: ' . $var` |

#### Suppress with `@noinspection` (intentional patterns):

| Inspection | When to suppress |
|---|---|
| `PhpDynamicFieldDeclarationInspection` | Property inherited from parent (false positive) |
| `PhpDocFinalChecksInspection` | Intentionally extending `@final` class |

#### Skip (not code issues or unfixable):

| Inspection | Reason |
|---|---|
| `GrazieStyle` | Grammar suggestions, not code |
| `SqlNoDataSourceInspection` | IDE config, not code |
| `PhpVariableIsUsedOnlyInClosureInspection` | False positive — variables used in closures within match arms or shared across multiple closures. `@noinspection` does NOT work for this inspection in Qodana. Ignore these. |
| Files matching `DB*Model.php` | Auto-generated -- never edit |

### Suppression syntax

```php
/** @noinspection PhpVariableIsUsedOnlyInClosureInspection -- reason */
$traceReflector = new ReflectionProperty(Exception::class, 'trace');
```

### Fix workflow

1. **Parse results** -- group by rule, prioritize warnings over notes
2. **Fix real bugs first** -- undefined fields, wrong types, wrong parameters
3. **Fix code style** -- `??`, `?->`, `elseif`, redundant casts
4. **Suppress intentional patterns** -- closures, dynamic properties
5. **Skip noise** -- Grazie, SQL, auto-generated models
6. **Verify each fix** -- `php -l <file>` or MCP `get_file_problems`

## Troubleshooting

| Problem | Solution |
|---|---|
| `token was declined` | Use **project** token, not organization token |
| `IDE to run is not found` | Set `QODANA_DIST` to cached PhpStorm path |
| `Cannot connect to Docker daemon` | Use `QODANA_DIST`, not `--linter` |
| 0 problems but IntelliJ finds many | Verify the profile is real, not a disabling stub — pass `--profile-path` with a genuine project profile, or use `qodana.yaml`. A near-empty scan usually means a stub `Project_Default.xml` |
| Scan indexes the whole monorepo | Pass `-i`/`--project-dir <app-root>` so only the nested app is scanned |
| MCP tools not available | Restart Claude Code after MCP setup |

## Cross-Reference

- **Where inspection fits in the release/QA flow** — running a scan is a pre-release gate in the cross-module study/release pipeline; see `ddd-module-orchestrator`.
- **Releasing once findings are clean** — bump, commit, tag, and Packagist release via `ddd-composer-update-version`.
