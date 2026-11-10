# Worker state between messages and the workspace origin

What the framework resets before every consumed message, what it does not, and why a message another checkout dispatched is refused.

## Every consumed job starts FRESH — never clear caches per handler

A long-lived worker otherwise serves job N+1 from job N's leftovers: a DB session whose REPEATABLE READ snapshot predates
the previous job's writes, the Doctrine unit of work, the process-static entity registries. The framework therefore
prepends `DDD\Symfony\Messenger\Middleware\FreshWorkerStateMiddleware` to EVERY bus (`FreshWorkerStateMiddlewarePass`,
registered by `DDDKernel::build()` ahead of Symfony's MessengerPass) — no app config, no handler code. It runs
`AppMessageHandler::resetWorkerStateForNewJob()` for messages the Worker consumed (`ConsumedByWorkerStamp`;
`app:process-cli-message` stamps it too). Synchronous dispatches keep the request's state and transaction — including
a `sync://` transport, which stamps `ReceivedStamp` but runs inside the dispatching request.

After every consumed job — returned or thrown, opt-out or not — the middleware rolls back a transaction the handler
left open on any existing connection scope and logs "rolled back a transaction left open by <handler>", so it cannot
swallow the next message's writes. A transaction that was already open before the handler is never touched.

- Do NOT add cache clearing, connection resets or registry wipes to a handler — it is already done, once, for all.
- Opt out per handler: `public const bool RESET_WORKER_STATE_BEFORE_JOB = false;` (a batch handler that accumulates
  across messages). The middleware skips the reset when ANY handler of the message says so.
- Opt out per app: parameter `ddd.messenger.fresh_worker_state: false` (the pass then registers nothing).
- **State of your own** (a static flag, a process memo, an open stack of a shared service): implement
  `DDD\Symfony\Messenger\WorkerStateResetterInterface` on a service — `resetWorkerStateForNewJob()` then runs before
  every consumed job, after the handler reset, whatever base class the handler extends. Collected by interface
  autoconfiguration (tag `ddd.worker_state_resetter`, also for module services); a resetter that throws stops
  neither the others nor the job; a handler that opts out skips the resetters too.
- A stale baseline read in a worker is a SESSION problem, never a reason for a per-write transaction: a transaction
  joins the already open one and keeps its snapshot. Renew the connection instead (`renewDatabaseConnection()`).
- Do not rely on registry-cached entities across messages; reload by id at the start of the job.

**A handler leaves no service state behind.** Container services are shared across messages. The framework resets
between two consumed messages — independent of the opt-outs above — `throwErrors` of every instantiated DDD service to
its declared default (`ServicesThrowErrorsResetter`, before each message; Symfony's `services_resetter` after it unless
`--no-reset`). It does NOT reset any other property a handler or service set on a shared service, static caches of the
application, or state inside one long job: a flag a handler switches early in a job still changes every later lookup
of that job. So a checked lookup in a handler or its service uses `enableThrowErrors()` / `restoreThrowErrors()` in
`finally` (`ddd-service-specialist`), never a bare `$service->throwErrors = true;`.

## Workspace Origin — refused, never rerouted

> **Framework services must survive being auto-registered.** Applications register the framework's own sources
> (`resource: '%kernel.project_dir%/vendor/mgamadeus/ddd/src/*'` with `autowire: true`), and such a definition
> REPLACES one the bundle registered explicitly. A framework service whose constructor takes a scalar therefore needs
> `#[Autowire]` attributes on those arguments, or the consuming app's container stops compiling entirely —
> "Cannot autowire service …: argument $projectDir is type-hinted string". That is what happened to
> `WorkspaceVhostEnvVarProcessor` in v2.66.0 and why v2.66.1 exists. When you add a framework service with scalar
> constructor arguments, annotate them; do not rely on the bundle's definition winning.

Handlers carry NO workspace logic. Several checkouts of one app (dev workspaces, test builds) share a machine, a
database and a message broker; each checkout has its OWN broker vhost (the app derives it from its code path, e.g.
`%env(workspace_vhost:…)%` in messenger.yaml), so a worker only ever consumes what its own checkout dispatched.

**A missing workspace vhost falls back to the `.env` vhost.** Inside a workspace the processor probes the broker once
per process (Symfony's own `Connection::fromDsn(…, ['connect_timeout' => 2])`, nothing declared). Only "530
NOT_ALLOWED - vhost <name> not found" falls back, to the DSN from `.env` unchanged, and writes one `error_log` line per
process ("broker vhost "<name>" does not exist, using the vhost of … instead"); a refused access to an existing vhost,
a broker that is down, bad credentials or an invalid DSN keep the workspace DSN, so the real error surfaces. Outside a
workspace nothing is probed. The fallback keeps a checkout alive, but it then shares the default vhost's queues with
every other consumer of it: messages without a workspace stamp may run on this checkout's code, and the checkout's own
stamped messages are refused by the other consumers (guard below). Create the vhost when the workspace is provisioned —
that stays the real fix. In FPM the probe runs once per request that builds a transport (statics do not survive
requests).

`dispatch()` stamps `dispatchedFromWorkspaceDir` and `dispatchedFromEnvironment` (the workspace NAME from
`DDDService::getWorkspaceName()`, null outside a workspace). The framework's `WorkspaceOriginGuardMiddleware`
(registered on every bus by `FreshWorkerStateMiddlewarePass`, right behind the fresh-state middleware) compares the
stamp with the consuming process's own name on every RECEIVED envelope and throws
`UnrecoverableMessageHandlingException` on a mismatch: no retry, the message lands in the failure transport, the log
names both workspaces. A message without the stamp passes.

| Situation | Outcome |
|---|---|
| stamp equals the consumer's workspace name | processed |
| no stamp (built outside `dispatch()`, or from a release before the stamp) | processed |
| stamp names another workspace, or the consumer is production/local and the stamp names a workspace | REFUSED — the two share a queue they should not share; fix the vhost, replay with `messenger:failed:retry` |
| `ddd.messenger.workspace_origin_guard: false` | the guard is not registered (an installation with one checkout per machine) |

Why refuse instead of re-executing in the right workspace (what `processOnWorkspaceIfNecessary()` did up to v2.65.x):
the reroute ran the whole job in a synchronous child console process, held the consuming worker for the job's
duration, died with every restart of that worker, and ran the child with `--no-debug` on a possibly stale container.
With a vhost per workspace a mismatch is a configuration error, and an error must be visible, not routed around.

`app:process-cli-message` stays: it replays a serialized message by hand (`persistToTempDir()` / `loadFromTempDir()`,
`encodeForCommandline()` / `decodeFromCommandline()` are its transports) through the bus with a `ReceivedStamp` whose
transport name is derived from the handler's `#[AsMessageHandler(fromTransport:)]`; hydration never runs the message
constructor (`newInstanceWithoutConstructor()`). A replay in the wrong workspace is refused by the same guard.
