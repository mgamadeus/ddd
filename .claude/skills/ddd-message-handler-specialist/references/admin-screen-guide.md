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
- Every date-time of a window and of a sequence point comes back as ISO 8601 UTC (`2026-01-31T10:00:00Z`): `interval`
  and `x`. Entity date-times (`startedAt`, `lastSeenAt`, …) are `Y-m-d H:i:s` in UTC.
- Every payload is also readable from the console (`app:workers:overview`, `transport`, `history`, `processes`,
  `runs` with `--format=json`), see the skill's section "From the console".
- `stepSeconds`: 60, 300, 900 or 3600; default chosen by the window. More than 1500 points per sequence: 400.
- Lists are newest first (`startedAt desc`) unless ordered otherwise; default page size 50.
- Every set serialises as `{"elements": [...]}`.

## 2. Payloads

**`workersOverview`**: `generatedAt`, `monitoringEnabled`, `samplerActive`, `sequenceWindowSeconds` (3600),
`stepSeconds` (60), `recentWindowSeconds` (900), `totals`, `transports`, `processes`, `loops`.

- `totals`: `waiting` (null: not every queue could be counted), `inFlight`, `liveProcessCount`,
  `handledCountLastHour`, `failedCountLastHour`, `sequences` (`waiting`, `inFlight`, `processes`, `failed`,
  `handled`), `throughputWindows`.
- `processes.elements[]`: the live processes of all transports, each with its `displayState` — no request per
  transport is needed for a process list.
- `throughputWindows.elements[]` (in `totals` and in every transport): `windowSeconds` (300, 900, 3600, 86400),
  `handledCount` (finished, failed included), `failedCount`, `workerSeconds`. Rate per minute = `handledCount` ÷
  (`windowSeconds` ÷ 60); rate per worker = `handledCount` ÷ (`workerSeconds` ÷ 60), undefined while `workerSeconds`
  is 0. In `totals` a process that consumes several transports counts its seconds for each.
- `transports.elements[]` (one per transport of the Messenger configuration and of the worker list): `transport`,
  `isDefined`, `definition`, `isAutoscaled`, `effectiveMin`, `effectiveMax`, `waitingState`, `waiting`,
  `waitingSampledAt`, `inFlight`, `liveProcessCount`, `state`, `stuckSince`, `restartPending`, `handledCount`,
  `thrownCount`, `caughtCount`, `durationAverageMs`, `waitAverageMs`, `lastErrorClass`, `lastErrorMessage`,
  `lastErrorAt`, `throughputWindows`, `sequences` (the last hour, for sparklines: `handled`, `failed`, `waiting`,
  `processes`). `handledCount`, `thrownCount`, `caughtCount`, `durationAverageMs` and `waitAverageMs` cover
  `recentWindowSeconds` (15 minutes); the last error and the sequences cover the last hour.
- `loops.elements[]`: the loops of the worker list (`name`, `args`, `sleepSeconds`, `prodOnly`). They are listed, not
  monitored.

**`workerTransportDetail`**: `transport` (the overview object above), `interval`, `stepSeconds`, `sequences`,
`messageClasses`, `processes` (the live ones).

- `messageClasses.elements[]`: `messageClass`, `handledCount`, `thrownCount`, `caughtCount`, `redeliveredCount`,
  `durationAverageMs`, `durationMaxMs`, `waitAverageMs`, `waitMaxMs`, `lastErrorClass`, `lastErrorMessage`,
  `lastErrorAt`.

**`workersHistory`**: `interval`, `stepSeconds`, `transports.elements[]` with `transport` and `sequences`
(`processes`, `processStarts`, `waiting`, `inFlight`, `handled`, `failed`), and `totals` with the same sequences for
all transports together (`transport` is empty). In `totals`, counts are sums; `waiting`, `inFlight` and `processes`
add up the transports' samples of one minute and show the highest minute of a step — do not add up the transports'
sequences in the client: their maxima were reached at different times.

**Sequences** (`DateTimeValueSequence`, one point per step) by name: `handled`, `thrown`, `caught`, `failed`,
`redelivered`, `durationAverageMs`, `durationMaxMs`, `waitAverageMs`, `waitMaxMs`, `waiting`, `inFlight`,
`processes`, `processStarts`. Every sequence has one point per step. `y` is 0 for a count without an event and null
for a measure without a reading: `waiting`, `inFlight`, `processes` in a step without a sample, `durationAverageMs` /
`durationMaxMs` in a step without a handled message, `waitAverageMs` / `waitMaxMs` in a step without a message that
carried its dispatch time.

**Enumerations** (in the OpenAPI document as `enum`): transport `state` (`IDLE`, `WORKING`, `BACKLOG`, `STUCK`,
`UNDEFINED`, `NOT_CONSUMED`), `waitingState` (`COUNTED`, `UNKNOWN`, `QUEUE_NOT_SET_UP`), process `displayState`
(`RUNNING`, `STOPPING`, `BUSY`, `SILENT`, `STOPPED`, `LOST`), run `outcome`.

**Process** (`workerProcesses.elements[]`): `processKey`, `hostName`, `processId`, `transport` (comma-separated when
a process consumes several), `startedAt`, `firstMessageAt`, `lastSeenAt`, `state`, `displayState`, `handledCount`,
`failedCount`, `memoryBytes`, `memoryPeakBytes`, `currentMessageClass`, `currentMessageSince`, `stoppedAt`,
`stopReason`, `restartRequestedAt`.

**Message run** (`workerMessageRuns.elements[]`): `transport`, `messageClass`, `processKey`, `dispatchedAt`,
`dispatchedFromRoute`, `dispatchedFromCommand`, `startedAt`, `durationMs`, `waitMs`, `outcome` (`HANDLED`, `CAUGHT`,
`THROWN`), `attempt`, `willRetry`, `errorClass`, `errorMessage`, `issueGroupHash`. A message dispatched by a handler
carries the route or command of the message that led to it.

## 3. Screen layout

1. **Header**: totals as tiles (waiting, in work, live processes, handled and failed in the last hour, throughput
   per window from `totals.throughputWindows`); two banners:
   monitoring off (`monitoringEnabled` false — only the worker list and live queue counts are shown) and sampler not
   running (`samplerActive` false — queue history has holes, lost processes are closed late).
2. **Transport table**, one row per transport, sorted by state severity (`NOT_CONSUMED`, `STUCK`, `UNDEFINED`,
   `BACKLOG`, `WORKING`, `IDLE`): state badge, waiting, in work, processes against `effectiveMin`–`effectiveMax`,
   finished / failed and the rates per minute and per worker from `throughputWindows`, average duration and wait
   (label them with `recentWindowSeconds`), sparklines from `sequences`, last error, restart button
   (`restartPending` shows a request is open).
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

A step without a reading has `y: null`: draw a gap, not a zero — a zero says "counted, nothing there". Charts of all
transports together use `totals`, not a client-side sum.

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
- [ ] Gaps instead of zeros in charts (`y: null`); `waiting` null handled
- [ ] Window labels come from the payload (`recentWindowSeconds`, `throughputWindows[].windowSeconds`)
- [ ] Restart shows pending state and its outcome
- [ ] No control that suggests scaling
