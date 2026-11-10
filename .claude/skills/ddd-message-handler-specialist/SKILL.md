---
name: ddd-message-handler-specialist
description: "Symfony Messenger in mgamadeus/ddd, from an async job to watching its workers. Covers AppMessage and slim AppMessageHandler pairs, the service-side bool async pattern, auth context, fresh worker state per message, the workspace-origin guard, messenger.yaml transports and routing, supervisor consumers, worker recycling limits, the worker list (config/system/workers.yaml) and the worker monitoring with app:workers:* console commands (check, list, overview, transport, history, processes, runs, queues, stats, sample, restart; --format=json for agents), WorkersOverviewService, the admin endpoints, screen guide and UX examples. Use when adding an async background job, wiring a transport or consumer, messages wait and nothing consumes them, workers crash-loop or run stale config, state leaks between messages, a message refused for another workspace, which message fails or is slow, messages per minute or per worker, restarting workers, a process shows LOST, SILENT or stays BUSY, or building a workers admin screen."
metadata:
  author: mgamadeus
  version: "2.1.0"
  framework: mgamadeus/ddd
---

# DDD Message Handler Specialist

Symfony Messenger in `mgamadeus/ddd`: the message and its handler, the transport and its consumer, the worker list,
and the worker monitoring. This file holds the rules and the map; read the reference file of your task before you
write or run anything.

## Rules that always hold

- **The handler is a thin dispatcher** (about 20–30 lines): set the auth context, load by id, call ONE service method
  with `async: false`, catch and log. No business logic, no helper methods with logic.
- **The logic lives in a service method with `bool $async`**: `true` dispatches the message and returns, `false` does
  the work. The caller decides.
- **Payload = ids and primitives.** The handler reloads the entity.
- **`$this->setAuthAccountFromMessage($message)` first**, in every handler.
- **Never reset state in a handler.** The framework gives every consumed message fresh process state (database
  session, unit of work, entity registries, `throwErrors`). State of your own: a `WorkerStateResetterInterface`
  service. A checked lookup uses `enableThrowErrors()` / `restoreThrowErrors()` in `finally`.
- **No workspace logic in a handler.** Each checkout has its own broker vhost; a message another workspace dispatched
  is refused, never rerouted.
- **`#[AsMessageHandler(fromTransport: …)]` names a transport that exists** and that the message is routed to; a
  wrong name disconnects the handler without an error.
- **The worker list (`config/system/workers.yaml`) is the source of truth** for which transport gets workers. A new
  transport gets a consumer entry; `app:workers:check` in CI finds a transport without one.
- **After a code or transport change: `cache:clear` before the workers restart.** A `--no-debug` worker does not
  rebuild its container.
- **The framework observes workers and asks for a restart.** It never starts, stops or scales a process.
- **The monitoring is off by default** (`Common.Workers` `enabled` / `WORKERS_ENABLED`) and needs its four tables and
  the sampler every minute.
- Never `private`; services from the container, never cached in a property.

## Task or symptom → reference

| Task or symptom | Read |
|---|---|
| Write a message, a handler, the service method; checklist | [references/writing-messages-and-handlers.md](references/writing-messages-and-handlers.md) |
| State leaks between messages, stale reads in a worker, own state to reset, a message refused for another workspace, a missing workspace vhost, `app:process-cli-message` | [references/worker-state-and-workspace-origin.md](references/worker-state-and-workspace-origin.md) |
| Add a transport and routing, a supervisor consumer, the stale-container trap, own cache dir for a worker pool | [references/transports-routing-and-consumers.md](references/transports-routing-and-consumers.md) |
| `workers.yaml` keys, `app:workers:check` codes, `--limit` / `--time-limit` / `--memory-limit`, `busySilentSeconds` | [references/worker-list-and-limits.md](references/worker-list-and-limits.md) |
| Switch the monitoring on, its settings, what is stored, transport and process states, `WorkersOverviewService`, the admin controller | [references/monitoring-and-settings.md](references/monitoring-and-settings.md) |
| Ask what the workers do or did: failing or slow messages, throughput, processes, queue counts; JSON shapes | [references/console-for-agents.md](references/console-for-agents.md) |
| Build a workers admin screen: endpoints, payloads, rules | [references/admin-screen-guide.md](references/admin-screen-guide.md) |
| Wireframes and component sketches of that screen (live row, throughput table, charts, restart flow, wording) | [references/admin-screen-examples.md](references/admin-screen-examples.md) |
| Nothing consumes, crash loops, LOST / SILENT / BUSY after a kill, nothing is written, gaps in a chart | [references/troubleshooting.md](references/troubleshooting.md) |

## Commands

`php bin/console app:workers:<name>` — reads take `--format=json` (the payload of the matching admin endpoint):

| | |
|---|---|
| `check`, `list` | the worker list against the Messenger configuration (exit 1 on an error) / as the framework reads it |
| `overview`, `transport T`, `history` | now and the last hour / one transport over time / all transports over time |
| `processes`, `runs` | which processes ran and why they stopped / which messages failed, with which error |
| `queues`, `stats` | waiting messages at the broker right now (works with the monitoring off) / totals per message class |
| `sample`, `restart [T]` | one sample plus prune (every minute) / ask the live processes to stop after their message |

`app:process-cli-message` replays one serialized message by hand.

## Cross-Reference

- The service behind a handler, `enableThrowErrors()`: `ddd-service-specialist` · loading by id: `ddd-entity-specialist`
- Console commands, Crons (the sampler): `ddd-cli-command-specialist`
- The monitoring's tables: `ddd-database-schema-diff-specialist` · errors of a message run: `ddd-issues-log-specialist`
