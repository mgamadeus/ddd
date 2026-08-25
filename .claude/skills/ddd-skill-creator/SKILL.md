---
name: ddd-skill-creator
description: Create, audit, and fix Claude Code skills (SKILL.md files) so they actually get discovered — the description is the ONLY routing signal a session sees, so every capability must surface there with literal "Use when" triggers. Covers the should-this-be-a-skill gate, naming and placement, the description formula with hard limits (1,024 chars max; no unquoted hash character — YAML silently truncates there), body structure and the 500-line budget, progressive disclosure via references/ and scripts/, frontmatter fields, behavioral fresh-session trigger tests, and a capability-vs-description gap audit for existing skills (grep with symlink dereferencing — app skill dirs are vendor symlinks). Use when creating a new skill, writing or fixing a skill description, auditing a skill that never triggers, checking a listing for truncated descriptions, or deciding whether something should be a skill at all.
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
---

# DDD Skill Creator

Create, audit, and repair Claude Code skills for the DDD ecosystem (Core, modules, consuming apps). Built from Anthropic's official skill-authoring guidance, practitioner findings on skill routing, and this ecosystem's own documented failures.

## The Iron Law (read this before anything else)

**The frontmatter `description:` is the ONLY routing signal.** Claude picks skills from the listing of name + description alone — the body is read only *after* selection. A capability documented only in the body **does not exist at routing time**.

Documented failure in this ecosystem: `app:db:read` (read-only DB query command) was fully documented in the cli-command skill body, but the description said only "Create Symfony console commands". Result: an entire session used raw `php -r 'new PDO(…)'` one-liners with a hardcoded password instead of the tool. The skill content was perfect; the routing was broken.

Corollaries:
1. Every capability the skill serves MUST appear in the description.
2. A skill that both *builds* X and *operates* X must say **both** — the operate side never routes otherwise.
3. ~80% of authoring effort belongs in the description; the body only matters post-trigger.

## Step 0 — Should this be a skill?

A skill is a prompt-pack, not an organizational unit. Gate (all three must hold):

1. **Repeatable methodology** — multi-step workflow or domain knowledge used across tasks, not a one-off.
2. **Reused across sessions** — you have needed it (or clearly will) at least ~3 times.
3. **Self-selectable trigger** — an agent can recognize from the user's words that this skill applies.

If any fails, prefer: CLAUDE.md / AGENTS.md (always-loaded rules), a `knowledge/` reference file (linked context), or nothing (single-session throwaway). Overlap check is mandatory: read the current skill listing and `ls .claude/skills/` — if an existing skill covers ≥70% of the scope, extend that skill instead of creating a near-duplicate description that splits routing.

## Step 1 — Name and placement

- **kebab-case, ≤64 chars, lowercase/digits/hyphens**; name MUST equal the directory name. No "anthropic"/"claude" in the name.
- Prefer noun-phrase or gerund (`ddd-entity-specialist`, `processing-pdfs`). Avoid `helper`, `utils`, `tools`, bare `documents`.
- Ecosystem conventions: Core framework skills `ddd-<topic>-specialist` (or `ddd-<verb-phrase>`), module skills `ddd-module-<name>-specialist`, app skills `<app>-<topic>` (e.g. `rc-backend-…`, `rb-…`).
- Placement: framework-wide → Core `.claude/skills/` (ships to every app via composer + symlink); module-specific → the module repo; app-specific → the app repo. NOTE: `composer update` refreshes an EXISTING symlink's target but does NOT create symlinks for NEWLY shipped skills — add the symlink by hand (git-tracked) when a new skill first ships.

## Step 2 — The description (the 80% step)

**Formula:**

```
<What it does — first sentence, third person, the key use case FIRST>.
Covers <dense enumeration of EVERY notable capability in the body — keywords, not prose>.
Use when <3–8 literal trigger phrases, phrased as the user/agent would think them>.
[NOT for <adjacent-but-wrong use> (use <other skill>).]
```

**Hard limits and syntax rules (violations are silent — nothing warns you):**

| Rule | Why |
|---|---|
| ≤ **1,024 chars** | Official validation limit; longer descriptions also burn shared listing budget and get truncated |
| Listing truncates description(+when_to_use) at **1,536 chars** | Put the key use case first |
| **NEVER an unquoted `` #`` (space-hash)** in the description | YAML treats ` #` as a comment start — everything after is SILENTLY DROPPED. This ecosystem shipped 4 descriptions truncated to 300–450 chars because they contained PHP attribute syntax with hash-bracket. Write `NotNull`, `the RequestCache attribute` — never the literal hash-bracket form |
| Third person only | "Creates/Covers/Use when" — never "I can…" / "You can…" (injected into the system prompt; POV mismatch hurts routing) |
| No XML tags, non-empty | Validation |
| One paragraph, no markdown | The listing renders it flat |

**Trigger phrasing:** literal words users type ("results cap at 50", "workers crash-loop", "shark tank this") beat abstract categories ("pagination issues"). Under-triggering is the dominant failure mode — Claude completes the task as it understands it and does not go looking for skills; be pushy with keywords, file types, error messages.

**Anti-patterns:** vague ("Helps with documents"), build-perspective-only (the app:db:read failure), first person, over-1,024 prose walls, describing the body's structure instead of its capabilities.

**Validate mechanically before shipping** — do not eyeball:

```bash
python3 -c "
import re,sys
d=re.search(r'^description:[ \t]*(.+)$',open(sys.argv[1]).read(),re.M).group(1)
assert len(d)<=1024, f'{len(d)} chars > 1024'
assert not re.search(r'\s#',d), 'space-hash = YAML comment, silent truncation'
print('OK', len(d))" .claude/skills/<name>/SKILL.md
```

## Step 3 — Body structure

- **≤500 lines.** Beyond that, split into `references/` (Step 4).
- Order: What/When-to-Use → critical rules and footguns (put them ABOVE any code an LLM would pattern-copy) → templates/workflows as numbered steps → reference tables → troubleshooting → cross-references.
- **Calibrate degrees of freedom to fragility:** heuristic prose for judgment work (code review), templates with parameters for medium, exact do-not-modify commands for fragile sequences (releases, migrations).
- Assume Claude is smart — cut anything a competent engineer already knows. Every line spends context tokens in every session that loads the skill.
- One term per concept, consistently. Real, verified paths only — never invent an example path. No time-sensitive claims (they rot silently); if history matters, use a collapsed "old behavior" note.
- Worked input→output examples beat abstract rule lists. For workflows: numbered steps + a copyable checklist.
- Provide ONE default tool per job plus an escape hatch — not a menu of five.
- Documented framework quirks stay verbatim with a "(sic)" note (e.g. the `exectutionOrder` constructor typo) — "fixing" the doc breaks the reader's code.

## Step 4 — Progressive disclosure (large skills)

Three load levels: (1) name+description — always in context; (2) SKILL.md body — loaded on trigger; (3) bundled files — loaded/executed only when needed.

- `references/` — read-only deep context. Max ONE level from SKILL.md (nested chains get partially read). Files >100 lines need a TOC at top. Split by domain so only the relevant file loads.
- `scripts/` — executed, not read: only output costs tokens. State intent explicitly: "Run X" vs "See X for the algorithm". Scripts handle their own errors and justify their constants.
- `templates/` — scaffolds to copy, with `{{PLACEHOLDER}}` markers. Do not conflate with references.

## Step 5 — Frontmatter beyond description

Spec-portable fields (survive packaging/upload): `name`, `description`, `license`, `compatibility`, `metadata`, `allowed-tools`. Claude Code extensions (`when_to_use`, `argument-hint`, `disable-model-invocation`, `user-invocable`, `paths`, `context: fork`, `hooks`, …) work locally but are not portable — and at least one official tool silently discards `when_to_use`, so **put everything in `description`**. This ecosystem's convention: `metadata: {author, version, framework}`; bump `version` on substantive revisions.

## Step 6 — Verify behaviorally, then re-read

1. **Trigger test:** in a FRESH session, say 3 realistic phrasings of the need (not the skill name). The skill should route each time. Skill edits apply live — no restart needed.
2. **Chain of Verification** — re-read the draft asking: Does the description name every body capability? Would each trigger phrase actually occur to a user? Is anything only discoverable by reading the body? Does it overlap an existing skill's description? Do all paths exist? Is any code example self-contradictory with the rules stated above it?
3. Run the mechanical validator from Step 2 (limits + hash rule) over the final file.

## Auditing an EXISTING skill

1. Read the ENTIRE skill. List every capability/section in the body.
2. Diff that list against the description — every gap is a routing bug (the Iron Law).
3. Check the live skill listing for truncation: a description that stops mid-sentence at a hash character or ~1,536 chars is being cut.
4. Check internal consistency: rules vs the code examples beneath them (examples get pattern-copied; a contradicting example wins over the rule).
5. Never "verify" by grepping an app's `.claude/skills/` with plain `grep -r` — the `ddd-*` entries are **directory symlinks into vendor** and recursive grep skips them silently. Use `grep -R` (dereferences) or grep the vendor/source path directly. An empty grep proves nothing.
6. Fix from evidence, not memory: verify claims against the actual source before rewriting them.

## Anti-pattern quick list

- Routing-relevant info only in the body (the app:db:read failure)
- ` #`/attribute syntax in an unquoted YAML description (silent truncation)
- Description >1,024 chars (validation + listing-budget eviction)
- Build-perspective-only description for a skill that also operates something
- First person, vague verbs, no "Use when" triggers
- Two skills with overlapping descriptions splitting the same route
- Invented example paths; nested reference chains; deferring script errors to Claude
- Critical rules placed AFTER the code templates that violate them

## Quick reference

| Limit | Value |
|---|---|
| name | ≤64 chars, kebab-case, = directory name |
| description | ≤1,024 chars, third person, no unquoted hash |
| listing truncation | 1,536 chars (description + when_to_use) |
| SKILL.md body | ≤500 lines |
| reference nesting | 1 level, TOC if >100 lines |
| post-compaction re-attach | first 5,000 tokens/skill, 25,000 shared budget |

```
.claude/skills/<skill-name>/
├── SKILL.md          # frontmatter + body (the only required file)
├── references/       # optional deep context, 1 level, TOC'd
├── scripts/          # optional executables (output-only token cost)
└── templates/        # optional copy-scaffolds with {{PLACEHOLDERS}}
```

## Cross-Reference

- Shipping a new Core skill to apps (symlink creation, release, propagation): `ddd-module-orchestrator`
- Releasing the skill change as a package version: `ddd-composer-update-version`
