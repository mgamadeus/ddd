---
name: ddd-strategy-shark-tank
description: Iterative adversarial optimization of a strategy or business plan through a SHARK TANK of maximally diverse frontier models (Claude Opus/Fable via the Agent tool, OpenAI via the codex CLI, Google via the gemini CLI — roster auto-discovered on the machine). Sharks issue investment verdicts (IN/OUT, amount, equity demanded → implied valuation) round after round; between rounds an equally diverse FOUNDER FRONT (same model breadth) absorbs the feedback and works out the next plan iteration, merged by the lead founder; the loop terminates on pre-committed SUCCESS criteria (sharks in with significant capital at low equity) or ABORT criteria (converged kill reasons that revisions cannot fix). Use when the user says "shark tank this", "pitch this plan to investors", "optimize this strategy iteratively/adversarially", "would anyone invest in this", "iterate this business plan until it holds", "stress-test and IMPROVE this concept in rounds". Do NOT use for a single-pass adversarial review of one artifact (use a devils-advocate-style review skill if the project has one) or for code review.
---

# Strategy Shark Tank — iterative multi-model adversarial plan optimization

Turn a strategy / business plan / product concept into the strongest version it can be — or prove it
cannot be saved — by pitching it to a panel of INVESTOR-personas ("sharks") run on maximally DIVERSE
frontier models, collecting priced verdicts, revising, and re-pitching until pre-committed success or
abort criteria fire. The pricing mechanic is the core: a shark saying "interesting" is noise; a shark
committing fictional capital at a stated equity share produces an **implied valuation** — a scalar that
makes plan quality measurable and comparable across rounds.

The whole protocol is model-agnostic and project-agnostic; it needs only a plan document and at least
two different model vendors reachable from the machine.

## Prerequisites

- The plan under optimization exists as a readable document (markdown preferred). An unwritten idea is
  not pitchable — write it first.
- At least 2 sharks from ≥2 model vendors (see Step 1). With only one vendor available, run the tank
  anyway but flag the mono-culture in every round artifact (correlated blind spots).
- The invoking agent plays the FOUNDER and must NOT sit on the panel (no self-judging).

## Step 1 — Discover the shark roster (CLI probe, run it — never assume)

Probe which agent CLIs exist and which top models they expose:

```bash
for cli in codex gemini claude opencode aider goose crush; do
  p=$(which $cli 2>/dev/null) && echo "FOUND: $cli -> $p"
done
grep -E "^model" ~/.codex/config.toml 2>/dev/null   # codex default model
gemini --version 2>/dev/null                         # gemini CLI presence
```

Known invocation patterns (verify flags with `--help` on first use in a session; CLIs move fast):

| Vendor | Runner | Non-interactive invocation | Force top quality |
|---|---|---|---|
| Anthropic | Agent tool (inside Claude Code) | spawn a subagent with `model: opus` / `model: fable` | pick the highest tier available |
| Anthropic | `claude` CLI (headless fallback) | `claude -p --model opus "<prompt>"` | `--model` to the top tier |
| OpenAI | `codex` CLI | `codex exec --model <top-model> -c 'model_reasoning_effort="high"' --sandbox read-only --output-last-message <outfile> "<prompt>"` | ALWAYS pass reasoning effort high — the config default is usually medium |
| Google | `gemini` CLI | `gemini -m <top-model> -p "<prompt>" > <outfile>` | `-m` to the current top model |

Roster rules:
- **3–5 sharks per round; maximize VENDOR diversity first, then persona diversity.** Two sharks on the
  same model with different personas beat one, but one shark each on three vendors beats both.
- **Top tiers only, highest reasoning effort where configurable.** A tank of mid-tier models produces
  polite noise. If the consuming project restricts which tiers may be used for reasoning panels, honor it.
- Sharks running through a repo-local CLI (`codex`, `gemini`) read the plan files THEMSELVES (pass
  paths); sharks spawned as subagents get the paths in their prompt. Never paste whole plans into
  prompts when the shark can read the file — it wastes tokens and drifts.
- **ABSOLUTE paths for everything a CLI shark touches** (output files, log files, `cd` targets):
  backgrounded shells do NOT inherit the session's working directory — relative paths silently land in
  the wrong place or fail (verified failure mode on first live run). `cd <repo-root>` explicitly inside
  the command AND make every `--output-last-message` / redirect target absolute.
- **Agent-tool sharks return their verdict as a message, not a file** — the founder persists it VERBATIM
  into the round folder (`shark-<vendor>-<persona>.md`) before tallying. Verbatim means verbatim: no
  summarizing, no reformatting beyond a one-line provenance header.

## Step 2 — Round 0: fix the ask (anti-gaming, do this ONCE)

Before any shark sees anything, the founder writes the **standing ask** into the run header — and it is
frozen for the whole run:

- The fictional instrument: every shark is offered to invest up to a standard amount (default:
  **€1,000,000**) into this product line / venture.
- Success thresholds (defaults, adjustable ONLY in round 0): SUCCESS needs **≥2/3 of sharks IN**, with
  **median equity demand ≤ 15%**, and median implied valuation non-declining vs the prior round.
- Abort thresholds: see Step 6.

The founder may NOT change the ask, the instrument, or the thresholds mid-run — moving the goalposts
manufactures success. Record all of it in `shark-tank/run-header.md` next to the plan (create the
`shark-tank/` folder as a sibling of the plan document, unless the project has its own review-artifact
convention — then follow that).

## Step 3 — The pitch package (per round)

Each round, sharks receive exactly three things:

1. **The plan document path(s)** — the real, current plan. Sharks judge the PLAN, not the pitch.
2. **A one-page pitch memo** (founder-written, ≤400 words): what it is, for whom, the ask, the three
   strongest proof points. Update it each round — but remember: revisions must land in the PLAN;
   a better memo over an unchanged plan is spin, and Step 5 forbids it.
3. **From round 2 on: the objection ledger** — every prior objection with its status
   (`ADDRESSED-BY-CHANGE §x` / `REBUTTED: <founder argument>` / `OPEN`), plus the plan-change log of the
   last revision. Sharks are explicitly told to verify claimed fixes against the plan text.

Sharks within a round are **blinded to each other** — independent verdicts, no cross-talk. The tally is
revealed to them only via the next round's ledger.

## Step 4 — The shark prompt (template)

One prompt per shark per round; identical protocol block, distinct persona block. Assign personas so the
panel covers different failure surfaces, and ROTATE persona↔model assignments between rounds (prevents
style-lock). Canonical persona set (pick 3–5):

| Persona | Attacks |
|---|---|
| **Unit-Economics Hawk** | margins, CAC/LTV, cost lines nobody priced, scaling costs |
| **GTM / Channel Operator** | who sells it, in how many seconds, migration/cannibalization of existing revenue |
| **Customer-Obsessed Angel** | does the target user actually behave as assumed; habit, latency, trust |
| **Moat & Defensibility Skeptic** | what stops the platform player / fast follower; is the moat a cost or a structure |
| **Execution Realist** | team/org capacity, sequencing, the plan's dependency chain, time-to-proof |

```text
You are a shark on an investment panel evaluating a strategy/business plan. Persona: {PERSONA — one
paragraph, what you attack, what convinces you}. You are known for hard, evidence-based verdicts —
politeness is a disservice. Interest is not commitment: only priced offers count.

Read fully: {PLAN_PATHS}. Pitch memo: {MEMO_TEXT_OR_PATH}. {ROUND>=2: Objection ledger + change log:
{LEDGER_PATH} — verify claimed fixes against the plan text; punish cosmetic fixes.}

The standing ask: you may invest up to {ASK_AMOUNT} in this line. Decide:
- IN  → state: amount (≤ ask), equity % you demand, and your conditions precedent (specific plan
  changes/proofs required before money moves).
- OUT → state: your ranked kill reasons (each anchored to a plan section), and what would change your mind.

Rules: cite plan sections for every major claim; attack the strongest version of the plan, not a straw
man; if the plan gamed a prior objection with wording instead of substance, say so and price it.
Answer in {LANGUAGE — default English}. VERDICT SEMANTICS (strict): a priced offer contingent on
conditions IS "IN" with CONDITIONS — "OUT" means you would not invest at ANY condition; your structured
block MUST match your prose (an OUT block above a prose offer of "€500k if you fix X" is a protocol
violation and will be re-parsed as IN).

End your answer with EXACTLY this block (machine-parsed):
VERDICT: IN|OUT
AMOUNT_EUR: <number or 0>
EQUITY_PCT: <number or 0>
CONDITIONS: <semicolon-separated list or ->
KILL_REASONS: <semicolon-separated list or ->
```

Run all sharks of a round IN PARALLEL (background subagents / parallel CLI processes); collect outputs
into `shark-tank/round-N/shark-<vendor>-<persona>.md`.

## Step 5 — Tally, then founder revision

**Tally** (`shark-tank/round-N/tally.md`): per shark — verdict, amount, equity, **implied valuation =
amount / (equity/100)**; per round — #IN, median equity, median implied valuation, and the **converged
objections** (raised by ≥half the sharks, matched by meaning not wording). Track the valuation
trajectory across rounds in a table — it is the plan-quality curve. Also record two more output classes
the tally must not flatten: **dissent BETWEEN sharks** (opposite instructions on the same point — the
founder must pick a side next round and rebut the other with evidence, satisfying both is impossible)
and **highest-praised elements** (what multiple sharks called the strongest parts — protected from
dilution in revisions).

**Founder revision — run by the FOUNDER FRONT, not by one agent.** Between rounds, the founders are
represented by an equally broad, equally diverse model front as the sharks (same discovery, same
top-tier rule, ≥2 vendors; fresh instances — a founder run is never a continued shark run). The front
works out the next plan iteration:

1. **Parallel founder drafts.** Each founder agent receives the plan, the tally, and the ledger, and
   returns: (a) per CONVERGED objection a concrete revision proposal at plan-diff level (which section
   changes to what — substance, not wording), (b) rebuttal drafts for single-shark objections it deems
   wrong, (c) a committed stance on every recorded shark-vs-shark dissent, (d) a "protect" list (the
   praised elements no revision may dilute). Founders are told: defend the vision — capitulating to
   every voice produces mush; but a converged objection is answered with change or evidence, never
   re-wording.
2. **Merge by the lead founder** (the invoking agent): where founder proposals agree → apply; where they
   conflict → the lead decides and LOGS the decision with its reason (`founder-round-N.md` next to the
   tally: each founder's proposals, the merge decisions, the resulting plan deltas). The lead may
   overrule the front only with a written reason — silent overrules are the mush-vector in the other
   direction.
3. **The discipline that makes the loop honest** (unchanged): every CONVERGED objection ends as
   `ADDRESSED-BY-CHANGE §x` (a real plan diff) or `ADDRESSED-BY-EVIDENCE` (data, a committed gate, a
   measurement) — never re-wording. A single-shark objection MAY be `REBUTTED: <argument>`; a rebutted
   objection that re-converges next round escalates to MUST-address. The change log lists concrete plan
   deltas — the next round's sharks verify them against the text.

Then the next round runs (Step 3) on the revised plan.

## Step 6 — Termination (pre-committed in round 0, checked after every tally)

**SUCCESS — "the sharks are in":** ≥2/3 IN **and** median equity ≤ the round-0 threshold **and** median
implied valuation ≥ prior round **and** zero OPEN converged kill reasons. → Write
`shark-tank/final-verdict.md`: the funding summary, the valuation trajectory, the ledger with all
resolutions, the surviving plan's delta list. The plan graduates.

**ABORT — "the plan is bad":** any of
- the SAME kill reason converges in 2 consecutive rounds despite a revision attempt (structural, not
  fixable by iteration),
- median implied valuation DECLINES two rounds running,
- round limit reached (default **4**) without meeting SUCCESS and without a rising valuation trend.

→ Write `shark-tank/post-mortem.md`: the fatal flaws in one page, which pivot (if any) the sharks
signaled would revive it, and the explicit recommendation to stop. Killing a bad plan cheaply IS the
success case of this skill — say it plainly, without softening.

**Neither** → next round (Step 3). Never run past the round limit "because it feels close".

## Anti-patterns

- **Sharks from one vendor only** when others are installed — correlated blind spots defeat the point.
  Probe first (Step 1), every run.
- **The founder judges, or summarizes shark output before tallying** — verdicts are taken from the
  sharks' structured blocks verbatim; the founder's lens enters only in the revision.
- **Pitch-polish instead of plan-change** — if the ledger shows `ADDRESSED` without a plan diff, the fix
  is cosmetic; next round's sharks are instructed to price exactly that.
- **Moving the ask/thresholds mid-run** — frozen in round 0; changing them converts the protocol into
  confirmation theater.
- **Unpriced enthusiasm** — "I love this, great vision" without AMOUNT/EQUITY is discarded as noise;
  re-ask the shark for the structured block if it is missing.
- **Running to the round limit on a flat trend** — a flat valuation across 3 rounds is an abort signal,
  not an invitation for round 4.

## Quick reference

```
shark-tank/                      (sibling folder of the plan doc)
├── run-header.md                ask, thresholds, roster, round-0 date — FROZEN
├── round-1/
│   ├── shark-openai-hawk.md     raw verdicts (one per shark)
│   ├── shark-google-operator.md
│   ├── shark-anthropic-angel.md
│   ├── tally.md                 offers, implied valuations, converged objections, dissents, praised
│   └── founder-round-1.md       founder-front drafts + lead merge decisions + plan deltas
├── round-1-ledger.md            objections → ADDRESSED/REBUTTED/OPEN + plan change log
├── round-2/ …
└── final-verdict.md | post-mortem.md
```

Implied valuation = AMOUNT / (EQUITY_PCT/100). Success default: ≥2/3 IN, median equity ≤15 %, valuation
non-declining, no OPEN converged kills. Abort default: same converged kill 2 rounds despite revision |
valuation declines 2 rounds | 4 rounds without success trend.
