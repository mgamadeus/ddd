# Workers admin screen — UX examples

Companion to [admin-screen-guide.md](admin-screen-guide.md): what a workers screen can look like, as wireframes and as component sketches. The
guide says which data exists and which rules hold; this file shows one arrangement that answers an operator's
questions. Everything here is an example — framework, component library and styling are the application's.

The sketches are React with TypeScript and use plain elements. The types (`WorkersOverview`,
`WorkerTransportOverview`, `WorkerProcess`, `WorkerThroughputWindows`, `DateTimeValue`) are the ones the SDK generator
writes from the OpenAPI document.

## Contents

1. The questions the screen answers
2. Page structure
3. Wireframes
4. Component sketches
5. Wording
6. Mistakes to avoid

## 1. The questions the screen answers

Order the page by these, top to bottom — an operator reads until the question is answered and stops.

| Question | Where | Data |
|---|---|---|
| Is anything wrong right now? | tiles, banners | `totals`, `monitoringEnabled`, `samplerActive`, transport `state` |
| How many workers run, of how many configured? | live row per transport | `liveProcessCount`, `effectiveMin`, `effectiveMax`, `isAutoscaled` |
| How many messages are in work, how many wait? | live row per transport | `inFlight`, `waiting`, `waitingState` |
| What is each worker doing? | process lines under the live row | `workersOverview.processes` |
| How fast is it, and when is the queue empty? | throughput table | `throughputWindows` |
| How did it get here? | history charts | `GET /history` |
| What exactly failed? | transport page, message runs | `GET /transports/{transport}/messageRuns` |

## 2. Page structure

Two pages. The overview polls `GET /overview` every 10 seconds and `GET /history` once a minute; both pause while the
browser tab is hidden. Window, tab and filters of the transport page live in the URL, so every view is a link.

```
/workers                      overview
/workers/{transport}          one transport: ?window=6h&tab=overview|runs|processes
```

A page, not a drawer, for one transport: five charts and two paged tables do not fit a side sheet.

## 3. Wireframes

### 3.1 Overview

```
Workers                                                    [ Restart all workers ]
Updated 18:37:47

! The sampler is not running — the queue history has holes …          (banner)

+-----------+ +-----------+ +-----------+ +-----------+ +-----------+ +-----------+
| RUNNING   | | WAITING   | | IN WORK   | | HANDLED   | | FAILED    | | NEED      |
| NOW       | | NOW       | | NOW       | |           | |           | | ATTENTION |
| 5         | | 227       | | 2         | | 387       | | 1         | | 1         |
| workers on| | in every  | | messages  | | in the    | | 0.3 % of  | | backlog,  |
| 4 transp. | | queue     | | handled   | | last hour | | last hour | | undefined |
| ~~~~~/\~~ | | ____/~~~~ | | ___/\____ | | ____/\___ | | __/\_____ | |           |
+-----------+ +-----------+ +-----------+ +-----------+ +-----------+ +-----------+

Live            workers against the configured range, messages in work and waiting
Throughput      per transport, four windows
History         [1 h] [6 h] [24 h] [7 d] [14 d]
Transports      every transport, most urgent state first, search
Loops           commands the cluster runs in a loop (from the worker list)
```

### 3.2 Live: one row per transport

Three labelled numbers per transport. A bare number next to a range is not readable — "2  0–1" does not say whether
2 counts workers or messages.

```
RIGHT NOW, PER TRANSPORT              5 workers running · 2 messages in work · 227 waiting
------------------------------------------------------------------------------------------
import_orders  >                      WORKERS          IN WORK          WAITING
                                      2 running        2 messages       227 messages
                                      configured 0–2   being handled    in the queue
  [Busy]    pod-lhrjz · 134  running since 18:22:25 · 15 min
            working on ImportOrderMessage since 18:37:43 · 4.0 s
  [Busy]    pod-9j89r · 23   running since 18:31:00 · 6 min
            working on ImportOrderMessage since 18:37:14 · 33 s
------------------------------------------------------------------------------------------
send_mail  >                          WORKERS          IN WORK          WAITING
                                      1 running        0 messages       ? messages
                                      configured 1–6   being handled    Queue not set up (i)
  [Running] pod-m55z5 · 23   running since 17:54:51 · 42 min
            idle, waiting for a message
------------------------------------------------------------------------------------------
12 more transports: no worker running, queue empty.
```

- Listed: a transport with a live process, a message in work, a message waiting, or a queue that could not be counted.
  The rest folds into the last line.
- Sorted by waiting, then in work, then live processes.
- `configured 0–2` for an autoscaled consumer, `configured fixed 2` otherwise, `no worker list entry` without one.
- More live processes than `effectiveMax`: add "· above range" in the warning colour. It happens for a moment when a
  stopping process and its successor overlap.

### 3.3 Throughput

```
TRANSPORT        WAITING NOW         IN WORK   LAST 5 MIN    LAST 15 MIN   LAST HOUR     LAST 24 H     PER WORKER
All transports   227                 2         11 · 2.2/min  36 · 2.4/min  390 · 6.5/min 392 · 16/h    26 /h
                 ≈ 1 h 43 min                                                            1 failed      last 15 min
import_orders    227                 2         11 · 2.2/min  26 · 1.7/min  375 · 6.3/min 375 · 16/h    1.1 /min
                 ≈ 1 h 43 min at this rate                                                             last 15 min
scan_file        0                   0         0             10 · 40/h     10 · 10/h     10 · 0.4/h    40 /h
```

- First row: every transport together, from `totals`. Below: transports with a message in 24 hours or a non-empty
  queue, most waiting first.
- A cell is the finished count, then the rate. Below 1 per minute switch the unit to per hour.
- Under "waiting": the time until the queue is empty at the rate of the last 5 minutes, or "nothing finished in
  5 min" when the rate is 0.

### 3.4 History

```
WORKERS OVER TIME   stacked per transport, step line          [legend: one colour per transport]
 8 |                              ___
 4 |      ______                 |   |_____
 0 |_____|      |________________|         |____
   process starts per transport (bars, same colours, same time axis)

MESSAGES WAITING PER TRANSPORT (stacked)     |   MESSAGES FINISHED PER TRANSPORT (stacked bars)
QUEUE, ALL TRANSPORTS (waiting / in work)    |   THROUGHPUT, ALL TRANSPORTS (succeeded / failed)
```

- One colour per transport across all per-transport charts. Name the seven busiest, fold the rest into "Other".
- The charts share one time axis and one hover: pointing at a step in one shows the same step in the others.
- Charts of all transports together read `workersHistory.totals`, never a client-side sum of the transports.

### 3.5 Transport page

```
import_orders   autoscaled 0–2 · limit 20 messages · 1024 M              [ Restart workers ]
[ Overview ] [ Message runs ] [ Processes ]                   [1 h] [6 h] [24 h] [7 d] [14 d]

Overview        facts card · Queue | Workers (min and max as dashed lines)
                Throughput (+ redelivered) · Duration avg/max · Queue wait avg/max
                message classes table · live processes
Message runs    chips: Failed / Thrown / Caught / Handled
                started · class · dispatched from · wait · duration · attempt · error · issue hash
Processes       timeline: one bar per process from start to stop, tick at its first message
                table: state · host and pid · started · stopped and stop reason · handled / failed · memory
```

## 4. Component sketches

### 4.1 Series: sequences to chart rows, gaps kept

```tsx
type ChartRow = { ms: number } & Record<string, number | null>;

const sequencePoints = (sequences: DateTimeValueSequences | undefined, name: string): DateTimeValue[] =>
  sequences?.elements?.find(sequence => sequence.name === name)?.elements ?? [];

/** Every sequence of one response shares its steps: zip them into one row per step. `null` stays a gap. */
const zipSeries = (series: Record<string, DateTimeValue[]>): ChartRow[] => {
  const rows = new Map<number, ChartRow>();
  for (const [key, points] of Object.entries(series)) {
    for (const point of points) {
      const ms = Date.parse(point.x ?? '');          // sequence points are ISO 8601 with Z
      if (Number.isNaN(ms)) continue;
      const row = rows.get(ms) ?? ({ ms } as ChartRow);
      row[key] = point.y ?? null;                    // never `?? 0`
      rows.set(ms, row);
    }
  }
  return [...rows.values()].sort((left, right) => left.ms - right.ms);
};
```

Entity date-times (`startedAt`, `lastSeenAt`) are `Y-m-d H:i:s` in UTC without an offset: parse them as UTC, not
with `new Date(value)`, which reads them as local time.

### 4.2 Live row

```tsx
const configuredRange = (transport: WorkerTransportOverview): string | null => {
  if (transport.effectiveMax == null) return null;
  return transport.isAutoscaled
    ? `${transport.effectiveMin ?? 0}–${transport.effectiveMax}`
    : `fixed ${transport.effectiveMax}`;
};

const LiveStat = ({ label, value, unit, hint }: { label: string; value: string; unit: string; hint: ReactNode }) => (
  <div className="live-stat">
    <div className="live-stat__label">{label}</div>
    <div><strong>{value}</strong> <span>{unit}</span></div>
    <div className="live-stat__hint">{hint}</div>
  </div>
);

const LiveTransportRow = ({ transport, processes }: { transport: WorkerTransportOverview; processes: WorkerProcess[] }) => {
  const live = transport.liveProcessCount ?? 0;
  const range = configuredRange(transport);
  const isAboveRange = transport.effectiveMax != null && live > transport.effectiveMax;
  const isCounted = transport.waiting != null;
  return (
    <li>
      <a href={`/workers/${transport.transport}?tab=processes`}>{transport.transport}</a>
      <LiveStat
        label="Workers" value={String(live)} unit="running"
        hint={range ? `configured ${range}${isAboveRange ? ' · above range' : ''}` : 'no worker list entry'}
      />
      <LiveStat label="In work" value={String(transport.inFlight ?? 0)} unit="messages" hint="being handled" />
      <LiveStat
        label="Waiting" value={isCounted ? String(transport.waiting) : '?'} unit="messages"
        hint={isCounted ? 'in the queue' : waitingStateLabel(transport.waitingState)}
      />
      {processes.map(process => <LiveProcessLine key={process.processKey} process={process} />)}
    </li>
  );
};
```

The processes come from `workersOverview.processes`, grouped by `transport` — one request for the whole section.

### 4.3 Throughput cell and time to drain

```tsx
interface WindowCount { handled: number; failed: number; minutes: number; workerMinutes: number }

const windowOf = (windows: WorkerThroughputWindows | undefined, windowSeconds: number): WindowCount => {
  const found = windows?.elements?.find(entry => entry.windowSeconds === windowSeconds);
  return {
    handled: found?.handledCount ?? 0,
    failed: found?.failedCount ?? 0,
    minutes: windowSeconds / 60,
    workerMinutes: (found?.workerSeconds ?? 0) / 60,
  };
};

const formatRate = (perMinute: number): string =>
  perMinute >= 1 ? `${perMinute.toFixed(1)} /min` : `${(perMinute * 60).toFixed(1)} /h`;

/** Messages one worker finished per minute it was running; null when no worker ran in the window. */
const perWorkerMinute = (count: WindowCount): number | null =>
  count.workerMinutes > 0 ? count.handled / count.workerMinutes : null;

/** Milliseconds until the queue is empty at the rate of the last 5 minutes; null when nothing finished. */
const drainMs = (waiting: number, last5: WindowCount): number | null => {
  const perMinute = last5.handled / last5.minutes;
  return perMinute > 0 ? (waiting / perMinute) * 60_000 : null;
};
```

### 4.4 Process count against its range

```tsx
/** A bar from 0 to the maximum, filled to the live count, a tick at the minimum. */
const ProcessRange = ({ live, min, max }: { live: number; min: number; max: number }) => {
  const scale = Math.max(max, live, 1);
  return (
    <div className="range" title={`Between ${min} and ${max}`}>
      <div className="range__fill" style={{ width: `${(live / scale) * 100}%` }} />
      {min > 0 && <div className="range__tick" style={{ left: `${(min / scale) * 100}%` }} />}
    </div>
  );
};
```

Use it in the dense transport table, with the number and the range written next to it. In the live section the
labelled numbers of 4.2 read better than a bar.

### 4.5 One transport, one colour

```tsx
const NAMED_SLOTS = 7;

/** Busiest transports first; the first seven get a colour, the rest is summed into "Other". */
const rankTransports = (histories: WorkerTransportHistory[]) =>
  histories
    .map(history => ({ history, peak: Math.max(0, ...sequencePoints(history.sequences, 'processes').map(p => p.y ?? 0)) }))
    .sort((left, right) => right.peak - left.peak || (left.history.transport ?? '').localeCompare(right.history.transport ?? ''));
```

Build the series of every per-transport chart (workers, starts, waiting, finished) from the same ranking, so a
transport keeps its colour across the charts of one window.

### 4.6 Restart

```tsx
const RestartButton = ({ transport, canWrite, monitoringEnabled }: Props) => {
  const [restart] = useRestartWorkersMutation();
  const [asked, setAsked] = useState<WorkerProcess[] | null>(null);
  const disabledReason = !canWrite ? 'You may not restart workers.'
    : !monitoringEnabled ? 'The monitoring is off: the live processes are not known.'
    : undefined;

  const confirm = async () => {
    const response = await restart(transport ? { transport } : {}).unwrap();
    setAsked(response.workerProcesses.elements ?? []);
  };
  // dialog: explain first, then show the asked processes — never close silently
};
```

Dialog text before the request: "Each process stops after its current message and is started again by whatever runs
the workers. A process inside a long job finishes that job first." After it: the number of asked processes and their
list. Zero asked processes is a valid answer ("No process is running for this transport").

### 4.7 Process timeline without a chart library

```tsx
/** One lane per process from start to stop (or now). A lost process ends at its last heartbeat, not at `stoppedAt`. */
const laneEnd = (process: WorkerProcess, nowMs: number): number => {
  const stopped = parseUtc(process.stoppedAt);
  const lastSeen = parseUtc(process.lastSeenAt);
  return process.stopReason === 'LOST' && lastSeen !== null ? lastSeen : (stopped ?? nowMs);
};
// lane: <div style={{ left: percent(start), width: percent(end) - percent(start) }} /> with a tick at firstMessageAt
```

## 5. Wording

| Value | Shown as |
|---|---|
| `waiting` null | "?" plus `waitingState` in words — never 0 |
| `stopReason` other than `RESTART_REQUESTED`, `LOST` | "Probably message limit", "Probably time limit", … |
| `stopReason` `LOST` | "Lost (no stop reported)" |
| `displayState` `BUSY` | "Busy — handling a message" |
| `displayState` `SILENT` | "Silent — its message runs longer than the busy limit" |
| counts of a transport row | label them with `recentWindowSeconds` ("Last 15 min"), not a fixed text |
| a process without a current message | "idle, waiting for a message" |
| the queue section | "Counts only: the monitoring does not list the waiting messages" |

## 6. Mistakes to avoid

- **Unlabelled numbers.** A live count next to a range ("2  0–1") is read as a message count. Every number in the live
  section carries its unit and what the range means.
- **Two panels for one question.** "Running now" and "In queue now" side by side make the reader match transports
  across panels. One row per transport with workers, in work and waiting together answers it at a glance.
- **Throughput only as a chart.** Rates per window as numbers in a table are what an operator quotes; the chart shows
  the shape, not the number.
- **Sums on the client.** Adding per-transport maxima overstates the total; adding history points approximates the
  windows. Both come counted from the backend (`totals`, `throughputWindows`).
- **A request per transport.** The live processes of all transports are in the overview; do not poll the process list
  of each transport for the live section.
