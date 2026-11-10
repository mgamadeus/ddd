# Worker monitoring: setup, settings, stored data, reading in code

Off by default. Console commands: [console-for-agents.md](console-for-agents.md). Admin screen: [admin-screen-guide.md](admin-screen-guide.md).

## Contents

1. What the framework does and does not do
2. Monitoring: setup
3. What is stored
4. Reading it in code
5. Admin endpoints

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

## Monitoring: setup

1. Create the four tables (`WorkerStatBuckets`, `WorkerTransportSamples`, `WorkerProcesses`, `WorkerMessageRuns`)
   the way the application manages its schema (schema diff: `ddd-database-schema-diff-specialist`).
2. Switch it on: `config/app/Common/Workers.php` returning `['enabled' => true]`, or `WORKERS_ENABLED=1`. Every
   setting has an environment override: `WORKERS_` + the name in upper snake case (`flushSeconds` →
   `WORKERS_FLUSH_SECONDS`).
3. Run the sampler every minute — a Cron of the application (`app:crons:upsert`, see `ddd-cli-command-specialist`)
   or a loop of the worker list: `php bin/console app:workers:sample`. Without it a transport nobody consumes has no
   queue history, lost processes are closed late and `app:workers:check` warns `SAMPLER_NOT_RUNNING`. As a Cron
   (`--dryRun` shows the row, without it the row is written):

   ```bash
   php bin/console app:crons:upsert --name="Sample Worker Monitoring" \
     --description="Worker monitoring sampler: stores the waiting messages of every consumed transport and closes worker processes that died silently. Must run every minute." \
     --schedule="* * * * *" --command="app:workers:sample" --active=1 --dryRun
   ```

   - A Cron row runs only where a cron runner ticks (`app:crons:execute` every minute). An environment without one
     keeps reporting `SAMPLER_NOT_RUNNING`.
   - The row lives in the database: every environment needs its own registration.
   - While the monitoring is off the command returns success without sampling.
4. Restart the workers (they read the setting at start).

**One monitoring per database.** An environment that shares its database with another one (a test stage on the
production database) keeps the monitoring off: its workers and samples would mix into the other environment's
numbers.

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
| `closeGoneProcessesOnOwnHost` (true) | a busy process whose operating-system process is gone on the pruning host is closed as LOST at once; switch off where processes share a host name without sharing process ids |

A high-volume transport: set `messageLog: failures` on its consumer; the statistics stay complete.

## What is stored

| Entity | One row per | Holds |
|---|---|---|
| `WorkerStatBucket` | minute × transport × message class × process flush (additive) | handled, thrown, caught, failed, redelivered, duration and wait sums / maxima, last error |
| `WorkerTransportSample` | minute × transport | waiting messages, messages in work, live processes (last and maximum), state of the count |
| `WorkerProcess` | worker process (`processKey` = host:pid:start) | transports, start, first message, heartbeat, counts, memory, current message, stop and stop reason, restart request |
| `WorkerMessageRun` | consumed message (by `messageLog`) | class, transport, process, start, duration, wait, outcome, error, issue group hash |

- **thrown**: an exception left the handler. **caught**: the handler logged an exception through
  `AppMessageHandler::logThrowableToIssues()` and returned normally. **failed**: thrown or caught.
- **redelivered**: an attempt after the first — and a delivery Messenger refuses because the broker sent the message
  a second time (`RejectRedeliveredMessageException`): no handler ran, so it counts as redelivered only, not as
  handled or thrown, and has no message run.
- A message dispatched by a handler carries the route or command of the message that led to it
  (`dispatchedFromRoute` / `dispatchedFromCommand`), not the worker's consume command.
- A queue count has a state: `COUNTED`, `QUEUE_NOT_SET_UP` (the queue does not exist yet) or `UNKNOWN` (the
  transport cannot be counted, or the broker did not answer). The count uses the transport's own connection and
  never creates a queue.
- The monitoring is fail-soft: a failed write never touches a job. The statistics of that process pause for a minute
  (heartbeat intervals decide), its heartbeat goes on, the failure is logged and reaches the issues log once per
  process. A process closed as lost that reports again is running again.
- The tables are readable and writable by admin accounts; the framework's own writes switch rights off.

## Reading it in code

```php
/** @var WorkersOverviewService $workersOverviewService */
$workersOverviewService = DDDService::instance()->getService(WorkersOverviewService::class);
$workersOverview = $workersOverviewService->getWorkersOverview();                 // every transport now + last hour + live processes
$workerTransportDetail = $workersOverviewService->getWorkerTransportDetail('send_mail', $from, $to, 300);
$workersHistory = $workersOverviewService->getWorkersHistory($from, $to, null);   // null: step chosen by the window
```

- Steps: 60, 300, 900 or 3600 seconds; at most 1500 points per sequence, else `BadRequestException`.
- Sequences are `DateTimeValueSequence` chart objects, named by `WorkerTransportHistory::SEQUENCE_*`: `handled`,
  `thrown`, `caught`, `failed`, `redelivered`, `durationAverageMs`, `durationMaxMs`, `waitAverageMs`, `waitMaxMs`,
  `waiting`, `inFlight`, `processes`, `processStarts`. One point per step. A count without an event is 0; a measure
  without a reading is null (`WorkerTransportHistory::GAP_SEQUENCES`: `waiting`, `inFlight`, `processes` in a step
  without a sample, the duration sequences without a handled message, the wait sequences without a dispatch time).
- The overview names its windows: `sequenceWindowSeconds` (3600), `stepSeconds` (60), `recentWindowSeconds` (900, the
  counts and averages of a transport). `throughputWindows` (per transport and in `totals`): `windowSeconds` 300, 900,
  3600, 86400 with `handledCount`, `failedCount`, `workerSeconds` — rate = count ÷ minutes, rate per worker = count ÷
  worker minutes (`WorkerThroughputWindow::getHandledPerMinute()`, `getHandledPerWorkerMinute()`).
- Totals of all transports (`workersHistory.totals`, the overview's total sequences): counts are sums; `waiting`,
  `inFlight` and `processes` add up the transports' samples of one minute and show the highest minute of a step.
- Transport state (`WorkerTransportOverview::STATE_*`), the first that applies:

| State | Condition |
|---|---|
| `NOT_CONSUMED` | a consumer entry whose transport Messenger does not consume |
| `STUCK` | messages wait and no live process for `stuckAfterSeconds` |
| `UNDEFINED` | Messenger consumes the transport, the worker list has no entry |
| `BACKLOG` | messages wait, processes work |
| `WORKING` | messages in work, none waiting |
| `IDLE` | nothing to do |

- Process display state (`WorkerProcess::getDisplayState()`): `RUNNING`, `STOPPING`, `BUSY`, `SILENT`, `STOPPED`,
  `LOST`. `WorkerProcessesService::setDisplayStates()` sets it on a set with each process' own `busySilentSeconds`.
- Lists of runs and processes of a transport: `WorkerMessageRunsService::findWorkerMessageRunsOfTransport()`,
  `WorkerProcessesService::findWorkerProcessesOfTransport()` — the set's default QueryOptions apply on top and cannot
  leave the transport.

## Admin endpoints

One controller in the application; the trait brings the routes, DTOs and OpenAPI documentation:

```php
#[Route('/maintenance/workers')]
#[Tag(group: 'Maintenance', name: 'Workers', description: 'Messenger workers')]
class AdminWorkersController extends AdminController
{
    use WorkersControllerTrait;   // DDD\Presentation\Api\Admin\Common\Workers\WorkersControllerTrait
}
```

Routes, payloads and the screen: [admin-screen-guide.md](admin-screen-guide.md).
