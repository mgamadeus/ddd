---
name: ddd-workers-specialist
description: "Define, monitor and restart the Messenger workers of a mgamadeus/ddd app. Covers the worker list (config/system/workers.yaml, consumers per transport, loops) and its check against the Messenger configuration (app:workers:check, app:workers:list), the worker monitoring (statistics, worker processes with heartbeat and stop reason, queue samples, message runs; Common.Workers settings, WORKERS_ENABLED), app:workers:sample, app:workers:stats, app:workers:restart, WorkersOverviewService (overview, transport detail, history as chart sequences, states IDLE/WORKING/BACKLOG/STUCK) and the admin endpoints via WorkersControllerTrait with a screen guide. Use when messages wait and nothing consumes them, a transport has no worker, how many workers ran when, which message class fails or is slow, restarting workers after a deploy, a process shows LOST or SILENT, the monitoring writes nothing, or building a workers / queues admin screen with charts. NOT for writing a message or handler (use ddd-message-handler-specialist)."
metadata:
  author: mgamadeus
  version: 1.0.0
  framework: mgamadeus/ddd
---

# DDD Workers Specialist

The Messenger workers of an application as a framework concept: which transports get workers (worker list), what the
workers do (monitoring), and a restart request. Writing messages and handlers: `ddd-message-handler-specialist`.

## When to Use

- A transport has no worker, or the worker list and `messenger.yaml` drifted apart
- Switching the worker monitoring on, reading it from the CLI, or exposing it in an admin API
- Building a workers / queues admin screen ([references/admin-screen-guide.md](references/admin-screen-guide.md))
- Restarting workers without access to whatever runs them
- Reading a process state (LOST, SILENT, stop reason) or a transport state (STUCK, BACKLOG)

## What the framework does and does not do

- It **reads** the worker list and **observes** the workers. It never starts, stops or scales a process: supervisor,
  a container orchestrator or an autoscaler does. There are no scaling controls.
- A restart is a **request**: the process stops itself after its current message; whatever runs the workers starts it
  again. Nothing restarts a process that is not supervised.
- Only what happens inside PHP is measured. The time before a process registers (image pull, container start, kernel
  boot) is invisible; a wait time is "dispatched → handler start" and needs the message's dispatch time.
- A stop reason is **inferred** (Messenger stops without a reason): restart request, received signal, then the
  consume command's limits against what the process did. A killed process reports nothing: it becomes LOST.
- A worker cannot report while a handler runs. A process with a current message is BUSY, not lost; SILENT once that
  message runs longer than `busySilentSeconds`.

## 1. Worker list

`config/system/workers.yaml` in the application (setting `definitionFile`); the application's release tooling reads
the same file to create supervisor blocks or deployments — the framework only reads it.

```yaml
consumers:
  - {transport: send_mail, replicas: 2, limit: 100, memoryLimit: 256M}
  - {transport: import_files, min: 0, max: 4, scaleDownAfter: 300, limit: 20, memoryLimit: 1024M}
loops:
  - {name: crons, args: 'app:crons:execute', sleepSeconds: 60}
```

| Consumer key | Meaning |
|---|---|
| `transport` | Messenger transport name (required, unique) |
| `replicas` | fixed number of processes |
| `min` / `max` / `scaleDownAfter` | autoscaled: bounds and seconds of idleness before scaling down |
| `limit` / `memoryLimit` | the consume command's `--limit` / `--memory-limit` |
| `devOnly` | not run in production |
| `messageLog` | `all`, `failures` or `off` for this transport (default: the `messageLog` setting) |

Loop keys: `name`, `args`, `sleepSeconds`, `prodOnly`. Unknown keys are kept as additional settings for the release
tooling.

```bash
php bin/console app:workers:list                 # the list as the framework reads it
php bin/console app:workers:check                # exits 1 on an error: run it in CI
```

`app:workers:check` compares the list with the transports and routing of the compiled container:

| Code | Severity | Meaning |
|---|---|---|
| `TRANSPORT_WITHOUT_CONSUMER` | error | messages of this transport wait forever, with no error anywhere |
| `CONSUMER_WITHOUT_TRANSPORT` / `CONSUMER_OF_SYNC_TRANSPORT` | error | the consume command of this entry fails at start |
| `DUPLICATE_CONSUMER`, `MIN_ABOVE_MAX`, `INVALID_VALUE` | error | the entry itself |
| `ROUTED_TO_UNKNOWN_TRANSPORT`, `ROUTED_CLASS_MISSING` | error / warning | the routing |
| `DEFINITION_FILE_MISSING` | warning | no worker list |
| `SAMPLER_NOT_RUNNING`, `MONITORING_TABLES_MISSING` | warning | only with the monitoring on, see below |

## 2. Monitoring: setup

1. Create the four tables (`WorkerStatBuckets`, `WorkerTransportSamples`, `WorkerProcesses`, `WorkerMessageRuns`)
   the way the application manages its schema (schema diff: `ddd-database-schema-diff-specialist`).
2. Switch it on: `config/app/Common/Workers.php` returning `['enabled' => true]`, or `WORKERS_ENABLED=1`. Every
   setting has an environment override: `WORKERS_` + the name in upper snake case (`flushSeconds` →
   `WORKERS_FLUSH_SECONDS`).
3. Run the sampler every minute — a Cron of the application (`app:crons:upsert`, see `ddd-cli-command-specialist`)
   or a loop of the worker list: `php bin/console app:workers:sample`. Without it a transport nobody consumes has no
   queue history, lost processes are closed late and `app:workers:check` warns `SAMPLER_NOT_RUNNING`.
4. Restart the workers (they read the setting at start).

No handler changes: a middleware on every bus measures each consumed message, an event subscriber follows the worker.
Opt out for a whole app (nothing is registered): container parameter `ddd.messenger.worker_monitoring: false`.

| Setting (default) | Meaning |
|---|---|
| `enabled` (false) | the monitoring writes |
| `definitionFile` (`config/system/workers.yaml`) | the worker list |
| `messageLog` (`all`) | one message run per message (`all`), only for failed ones (`failures`), none (`off`) |
| `flushSeconds` (10), `heartbeatSeconds` (10) | how often a worker writes statistics / reports itself |
| `statRetentionDays` (14), `processRetentionDays` (14) | buckets and samples / stopped processes |
| `handledRunRetentionDays` (3), `failedRunRetentionDays` (14) | message runs |
| `stuckAfterSeconds` (180) | waiting messages without a live process for this long: STUCK |
| `busySilentSeconds` (3600) | a current message running longer: the process is SILENT |
| `sampleMaxAgeSeconds` (90) | a stored queue count younger than this is used instead of asking the broker |

A high-volume transport: set `messageLog: failures` on its consumer; the statistics stay complete.

## 3. What is stored

| Entity | One row per | Holds |
|---|---|---|
| `WorkerStatBucket` | minute × transport × message class × process flush (additive) | handled, thrown, caught, failed, redelivered, duration and wait sums / maxima, last error |
| `WorkerTransportSample` | minute × transport | waiting messages, messages in work, live processes (last and maximum), state of the count |
| `WorkerProcess` | worker process (`processKey` = host:pid:start) | transports, start, first message, heartbeat, counts, memory, current message, stop and stop reason, restart request |
| `WorkerMessageRun` | consumed message (by `messageLog`) | class, transport, process, start, duration, wait, outcome, error, issue group hash |

- **thrown**: an exception left the handler. **caught**: the handler logged an exception through
  `AppMessageHandler::logThrowableToIssues()` and returned normally. **failed**: thrown and not retried again.
- A queue count has a state: `COUNTED`, `QUEUE_NOT_SET_UP` (the queue does not exist yet) or `UNKNOWN` (the
  transport cannot be counted, or the broker did not answer). The count uses the transport's own connection and
  never creates a queue.
- The monitoring is fail-soft: a failed write never touches a job. The statistics of that process pause for a minute
  (heartbeat intervals decide), its heartbeat goes on, the failure is logged and reaches the issues log once per
  process. A process closed as lost that reports again is running again.
- The tables are readable and writable by admin accounts; the framework's own writes switch rights off.

## 4. CLI

```bash
php bin/console app:workers:stats --since=60 [--transport=send_mail] [--format=json]   # per transport and class
php bin/console app:workers:restart [transport] [--dryRun]     # asks the live processes to stop after their message
php bin/console app:workers:sample                             # one sample of every consumed transport + prune
```

After a deploy that changes code the workers run: `app:workers:restart` (needs the monitoring: the processes must be
known). A process answers at its next tick — after its current message.

## 5. Reading it in code

```php
/** @var WorkersOverviewService $workersOverviewService */
$workersOverviewService = DDDService::instance()->getService(WorkersOverviewService::class);
$workersOverview = $workersOverviewService->getWorkersOverview();                 // every transport now + last hour
$workerTransportDetail = $workersOverviewService->getWorkerTransportDetail('send_mail', $from, $to, 300);
$workersHistory = $workersOverviewService->getWorkersHistory($from, $to, null);   // null: step chosen by the window
```

- Steps: 60, 300, 900 or 3600 seconds; at most 1500 points per sequence, else `BadRequestException`.
- Sequences are `DateTimeValueSequence` chart objects, named by `WorkerTransportHistory::SEQUENCE_*`: `handled`,
  `thrown`, `caught`, `failed`, `redelivered`, `durationAverageMs`, `durationMaxMs`, `waitAverageMs`, `waitMaxMs`,
  `waiting`, `inFlight`, `processes`, `processStarts`.
- Transport state (`WorkerTransportOverview::STATE_*`), the first that applies:

| State | Condition |
|---|---|
| `NOT_CONSUMED` | a consumer entry whose transport Messenger does not consume |
| `STUCK` | messages wait and no live process for `stuckAfterSeconds` |
| `UNDEFINED` | Messenger consumes the transport, the worker list has no entry |
| `BACKLOG` | messages wait, processes work |
| `WORKING` | messages in work, none waiting |
| `IDLE` | nothing to do |

- Process display state (`WorkerProcess::getDisplayState()`): `RUNNING`, `BUSY`, `SILENT`, `STOPPED`, `LOST`.
- Lists of runs and processes of a transport: `WorkerMessageRunsService::findWorkerMessageRunsOfTransport()`,
  `WorkerProcessesService::findWorkerProcessesOfTransport()` — the set's default QueryOptions apply on top and cannot
  leave the transport.

## 6. Admin endpoints

One controller in the application; the trait brings the routes, DTOs and OpenAPI documentation:

```php
#[Route('/maintenance/workers')]
#[Tag(group: 'Maintenance', name: 'Workers', description: 'Messenger workers')]
class AdminWorkersController extends AdminController
{
    use WorkersControllerTrait;   // DDD\Presentation\Api\Admin\Common\Workers\WorkersControllerTrait
}
```

Routes, payloads and the screen: [references/admin-screen-guide.md](references/admin-screen-guide.md).

## Troubleshooting

| Symptom | Check |
|---|---|
| Nothing is written | `enabled`, tables exist (`app:workers:check`), workers restarted after switching on, parameter `ddd.messenger.worker_monitoring` not false |
| Log: "Worker monitoring paused … after a failed write" | the message names the cause (missing table or column, database down); the issues log has it once per process |
| Process LOST while its container runs | no heartbeat and no current message: the process hangs outside a handler, or its writes fail (see above) |
| Process stays RUNNING after it was killed | it is closed as LOST by the next prune (sampler command or another worker's flush) after 12 heartbeat intervals |
| Transport STUCK with processes in the list of the orchestrator | the processes did not register: they crash before the worker starts — run the consume command in the foreground |
| Queue count state `QUEUE_NOT_SET_UP` | nothing dispatched or consumed yet on this broker; the count never declares a queue |
| No wait times | the message carries no dispatch time (dispatched by an older release, or not through the bus) |
| `app:workers:restart` asks nobody | monitoring off, or no live process of that transport |
| Message runs missing for a transport | its `messageLog` is `failures` or `off` |

## Cross-Reference

- Messages, handlers, transports, supervisor blocks, worker recycling: `ddd-message-handler-specialist`
- Crons (the sampler), console commands: `ddd-cli-command-specialist`
- The monitoring's tables: `ddd-database-schema-diff-specialist`
- Errors behind a message run's issue group hash: `ddd-issues-log-specialist`
