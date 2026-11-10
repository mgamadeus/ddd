# Workers admin screen

How to build the workers screen of an admin interface on the endpoints of `WorkersControllerTrait`. Paths are
relative to the prefix of the application's controller (e.g. `/api/admin/maintenance/workers`).

## Contents

1. Endpoints
2. Payloads
3. Screen layout
4. Charts
5. Rules the screen must follow
6. Checklist

## 1. Endpoints

| Method and path | Query / body | Response property |
|---|---|---|
| `GET /overview` | — | `workersOverview` |
| `GET /history` | `from`, `to`, `stepSeconds` | `workersHistory` |
| `GET /transports/{transport}` | `from`, `to`, `stepSeconds` | `workerTransportDetail` (404: unknown transport) |
| `GET /transports/{transport}/messageRuns` | QueryOptions `filters`, `orderBy`, `top`, `skip` | `workerMessageRuns` |
| `GET /transports/{transport}/processes` | QueryOptions `filters`, `orderBy`, `top`, `skip` | `workerProcesses` |
| `POST /restart` | body `{"transport": "send_mail"}` or `{}` for all | `requestedProcessCount`, `workerProcesses` (400: monitoring off) |

- `from` / `to`: UTC date-times (`2026-01-31 10:00:00` or ISO 8601); default: the last hour.
- `stepSeconds`: 60, 300, 900 or 3600; default chosen by the window. More than 1500 points per sequence: 400.
- Lists are newest first (`startedAt desc`) unless ordered otherwise; default page size 50.
- Every set serialises as `{"elements": [...]}`.

## 2. Payloads

**`workersOverview`**: `generatedAt`, `monitoringEnabled`, `samplerActive`, `totals`, `transports`, `loops`.

- `totals`: `waiting` (null: not every queue could be counted), `inFlight`, `liveProcessCount`,
  `handledCountLastHour`, `failedCountLastHour`, `sequences`.
- `transports.elements[]` (one per transport of the Messenger configuration and of the worker list): `transport`,
  `isDefined`, `definition`, `isAutoscaled`, `effectiveMin`, `effectiveMax`, `waitingState`, `waiting`,
  `waitingSampledAt`, `inFlight`, `liveProcessCount`, `state`, `stuckSince`, `restartPending`, `handledCount`,
  `thrownCount`, `caughtCount`, `durationAverageMs`, `waitAverageMs`, `lastErrorClass`, `lastErrorMessage`,
  `lastErrorAt`, `sequences` (the last hour, for sparklines). Counts and averages cover the last hour.
- `loops.elements[]`: the loops of the worker list (`name`, `args`, `sleepSeconds`, `prodOnly`). They are listed, not
  monitored.

**`workerTransportDetail`**: `transport` (the overview object above), `interval`, `stepSeconds`, `sequences`,
`messageClasses`, `processes` (the live ones).

- `messageClasses.elements[]`: `messageClass`, `handledCount`, `thrownCount`, `caughtCount`, `redeliveredCount`,
  `durationAverageMs`, `durationMaxMs`, `waitAverageMs`, `waitMaxMs`, `lastErrorClass`, `lastErrorMessage`,
  `lastErrorAt`.

**`workersHistory`**: `interval`, `stepSeconds`, `transports.elements[]` with `transport` and `sequences`.

**Sequences** (`DateTimeValueSequence`, one point per step) by name: `handled`, `thrown`, `caught`, `failed`,
`redelivered`, `durationAverageMs`, `durationMaxMs`, `waitAverageMs`, `waitMaxMs`, `waiting`, `inFlight`,
`processes`, `processStarts`.

**Process** (`workerProcesses.elements[]`): `processKey`, `hostName`, `processId`, `transport` (comma-separated when
a process consumes several), `startedAt`, `firstMessageAt`, `lastSeenAt`, `state`, `displayState`, `handledCount`,
`failedCount`, `memoryBytes`, `memoryPeakBytes`, `currentMessageClass`, `currentMessageSince`, `stoppedAt`,
`stopReason`, `restartRequestedAt`.

**Message run** (`workerMessageRuns.elements[]`): `transport`, `messageClass`, `processKey`, `dispatchedAt`,
`dispatchedFromRoute`, `dispatchedFromCommand`, `startedAt`, `durationMs`, `waitMs`, `outcome` (`HANDLED`, `CAUGHT`,
`THROWN`), `attempt`, `willRetry`, `errorClass`, `errorMessage`, `issueGroupHash`.

## 3. Screen layout

1. **Header**: totals as tiles (waiting, in work, live processes, handled and failed in the last hour); two banners:
   monitoring off (`monitoringEnabled` false — only the worker list and live queue counts are shown) and sampler not
   running (`samplerActive` false — queue history has holes, lost processes are closed late).
2. **Transport table**, one row per transport, sorted by state severity (`NOT_CONSUMED`, `STUCK`, `UNDEFINED`,
   `BACKLOG`, `WORKING`, `IDLE`): state badge, waiting, in work, processes against `effectiveMin`–`effectiveMax`,
   handled / failed of the last hour, average duration and wait, sparklines from `sequences`, last error, restart
   button (`restartPending` shows a request is open).
3. **Transport drawer or page** (`/transports/{transport}`): window picker, the charts below, the message class
   table, tabs for processes and message runs (paged lists; filter the runs with `filters=outcome ne 'HANDLED'`).
4. **History view** (`/history`): all transports over a window — processes and process starts per transport, to see
   how many workers of which kind ran when.

Refresh the overview every 10–30 seconds; a shorter interval shows nothing new (workers write every
`flushSeconds` / `heartbeatSeconds`).

## 4. Charts

| Chart | Sequences | Form |
|---|---|---|
| Throughput | `handled`, `failed` (or `thrown` + `caught`), `redelivered` | stacked bars per step |
| Queue | `waiting`, `inFlight` | area / line |
| Workers | `processes` (line, step-wise), `processStarts` (bars) | combined; draw `effectiveMin` / `effectiveMax` as reference lines |
| Duration | `durationAverageMs`, `durationMaxMs` | lines, logarithmic axis when the maximum dwarfs the average |
| Wait | `waitAverageMs`, `waitMaxMs` | lines |

A step without data has no point or a null value: draw a gap, not a zero — a zero says "counted, nothing there".

## 5. Rules the screen must follow

- **No scaling controls.** The framework does not start or scale processes; show the definition, never an input for
  it. The only action is the restart request.
- **Restart is a request.** After `POST /restart` show the asked processes and wait for them to stop
  (`stopReason` `RESTART_REQUESTED`); a process finishes its current message first, which can take as long as the job.
- **A stop reason is inferred.** Label it as such ("probably message limit"); `LOST` means the process reported no
  stop (killed, crashed, node gone).
- **Time before PHP starts is invisible.** A process appears when its worker registers; container start and image
  pull are not in `startedAt`. Do not present `processStarts` as scaling latency.
- **`BUSY` is not `LOST`.** A worker cannot report while a handler runs; use `displayState`, not `lastSeenAt`.
- **`waiting` null is "unknown"**, not zero; show `waitingState` (`QUEUE_NOT_SET_UP`, `UNKNOWN`) instead.
- **Wait time can be missing** (`waitMs` / the wait sequences null) when a message carries no dispatch time.
- Error messages are shortened and may contain data of the job: admin accounts only.

## 6. Checklist

- [ ] Controller with `WorkersControllerTrait` mounted, access restricted to admins
- [ ] Monitoring tables exist, `enabled` set, workers restarted, sampler runs every minute
- [ ] Both banners (monitoring off, sampler not running) implemented
- [ ] Gaps instead of zeros in charts; `waiting` null handled
- [ ] Restart shows pending state and its outcome
- [ ] No control that suggests scaling
