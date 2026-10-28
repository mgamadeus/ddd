---
name: ddd-issues-log-specialist
description: "Log, cluster, ship and track errors with the mgamadeus/ddd issues log: IssuesLogService::logThrowable (log-once), the issues channel sinks (ISSUES_LOG_TARGET file/stderr/none, ECS JSON), capture, redaction, hashes error.group_hash / error.issue_hash; the registry (app:issues:sync over Loki, Elasticsearch / OpenSearch or Sentry, per environment / dev workspace, seed mode, explosion guard, regressions, Slack alerts, app:issues:health); agent CLI app:issues:list / show / search / resolve / trend --format=json; RuntimeErrorsService and app:runtime-errors:extract / probe (rankingCoach-compatible, ES_LOGS_*); app:issues:forward-sentry; admin boilerplate; DevOps examples. Use when logging an exception, nobody sees production errors, configuring where errors go or alerts, shipping logs, errors logged twice, one bug in many groups, finding and fixing errors of an environment or workspace as an agent, querying runtime errors in Elasticsearch, forwarding to Sentry, or building an issues admin screen."
metadata:
  author: mgamadeus
  version: "1.6.0"
  framework: mgamadeus/ddd
---

# DDD Issues Log Specialist

Every production error as one structured line, grouped by root cause, shipped by a collector. The request only appends
one line to a local file (or stderr) — no network, no database on the error path.

Three parts, each optional on top of the previous one:

1. **Write side** — `IssuesLogService`, the capture subscriber, the sink, the two hashes. Always on.
2. **Shipping** — a collector (Alloy, Filebeat) moves the lines into a log backend. Infrastructure, no code.
3. **Registry** — a job queries the backend, keeps one row per root cause in the database, alerts a Slack webhook on new
   causes and regressions, and serves the discovery CLI. On with `ISSUES_QUERY_BACKEND`.

Query backends: Loki, Elasticsearch / OpenSearch (with rankingCoach's `ES_LOGS_*` configuration and its
`app:runtime-errors:*` commands) and Sentry (fed by `app:issues:forward-sentry`).

## Logging in code

```php
use DDD\Infrastructure\Services\IssuesLogService;
use Psr\Log\LogLevel;

$issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
$issuesLogService->logThrowable(
    $exception,
    LogLevel::ERROR,                       // ERROR+ are errors; WARNING for an expected degradation
    ['orderId' => $order->id],             // explicit context — ids, never personal data
    section: IssuesLogService::LOG_MESSAGE_SECTION_MESSENGER, // HTTP (default) | CLI | MESSENGER
);
```

- **Log or rethrow:** an error the code recovers from (fallback, retry, degraded result) → `logThrowable` at `WARNING`
  or `ERROR` and continue. An error the code cannot handle → let it bubble: the capture subscriber logs it once.
  Logging and rethrowing is fine — a throwable is logged **once** per process (`IssuesLogService::wasLogged()`), the
  capture sees the mark and skips it.
- `AppMessageHandler` and the cron runner already log their failures.

## Where it goes — configuration

The channel is `issues`. The DDD bundle registers its sink (`IssuesLogSinkHandler`) as the first handler of the channel
and reads the environment at runtime:

| Variable | Values | Default |
|---|---|---|
| `ISSUES_LOG_TARGET` | unset: the application's own Monolog handlers (unchanged behaviour) · `file`: ECS JSON lines · `stderr` · `none` | unset |
| `ISSUES_LOG_PATH` | directory of the file (`file`) | `%kernel.logs_dir%/issues` |
| `ISSUES_LOG_FILE_NAME` | file name; `{environment}` = kernel environment, `{date}` = Y-m-d (a new file per day; no size rotation — many FPM children append to one file) | `issues_{environment}.log` |
| `ISSUES_LOG_LEVEL` | lowest PSR-3 level written | `warning` |
| `ISSUES_LOG_USE_LOCKING` | `flock()` per write | on |
| `ISSUES_CAPTURE_ENABLED` | the capture subscriber (`0` while an app runs its own) | on |
| `ISSUES_LOG_4XX_LEVEL` | level for uncaught 4xx; unset = not logged | unset |
| `ISSUES_LOG_HEADER_PREFIXES` | comma list of request header prefixes copied into `request_headers` (e.g. `x-`) | none |
| `ISSUES_LOG_INCLUDE_QUERY` / `ISSUES_LOG_INCLUDE_REQUEST_BODY` | add `url.query` / `http.request.body.content` | off |
| `ISSUES_LOG_MAX_EVENT_BYTES` | size cap of one event | 8192 |
| `ISSUES_SERVICE_NAME` | `service.name` | `APP_NAME`, else `symfony` |
| `ISSUES_RELEASE` | `service.version` | `APP_VERSION` |
| `ISSUES_WORKSPACE` | `service.workspace` | the dev workspace of the checkout path (`/dev-workspaces/<name>/`), else `APP_STAGE` |

Variables are read from `.env` (Config) and from the process environment, so a container can set them directly.
With `file`, `stderr` or `none` the records stop at the sink — they no longer reach the application's main log.

**The line** (Elastic Common Schema, `Elastic\Monolog\Formatter\ElasticCommonSchemaFormatter` from `elastic/ecs-logging`):
`@timestamp`, `log.level`, `message`, `ecs.version`, `log.logger`, plus the context — `event.id` (UUID per event),
`service.name` / `service.environment` (`APP_STAGE`, else `APP_ENV`) / `service.version` / `service.workspace` (the
dev workspace, e.g. `mgn`, or the stage — all dev workspaces share `service.environment=dev`), `host.hostname`, `section`,
`route_name`, `http.request.method`, `http.request.id` (`X-Request-Id`), `traceparent`, `account.id`, `application`,
`environment`, and the error fields: `error.type`, `error.kind`, `error.code`, `error.handled`, `error.stack_trace`,
`log.origin.file.name/line`, `error.fingerprint`, `error.fingerprint_hash`, `error.fingerprint_short` (their meaning is
unchanged) and the stable hashes below.

**Privacy, before any sink:** values under secret-looking keys (password, secret, token, authorization, cookie, API key,
credential, session) become `[REDACTED]`, also nested; the message loses e-mail addresses, bearer/basic credentials and
digit runs of 9+; the event is capped (full trace first, then the short trace, then the message is cut).

**Labels vs body:** only low-cardinality values become Loki labels or index keywords you aggregate on in dashboards
(`service.name`, `service.environment`, `log.level`, `section`). Hashes, routes and ids stay in the JSON body.

## What is captured

`IssuesCaptureSubscriber` (on unless `ISSUES_CAPTURE_ENABLED=0`):
- **HTTP**, priority 100 (before `ExceptionListener` renders DDD exceptions): status = DDD exception code, else the
  HttpException status, else 500. ≥ 500 → `CRITICAL`, `error.handled=false`, `http_status`. 4xx only with
  `ISSUES_LOG_4XX_LEVEL`. Symfony's own "Uncaught PHP Exception" record of the same exception is dropped
  (`IssuesDuplicateRequestRecordFilterHandler`, first handler of the `request` channel) — one line per error.
- **Console** errors → `CRITICAL`, `command`, `exit_code`.
- **Messenger** failures (`WorkerMessageFailedEvent`) → `CRITICAL`, `messenger.message_class`, `transports`, `will_retry`.
- **PHP fatals** → a shutdown handler (registered with the first request or command) logs an `ErrorException`.
- **Not reachable from PHP:** OOM kills, crash loops, start-up failures before the kernel boots — alert on them in
  Kubernetes (kube-state-metrics: `kube_pod_container_status_last_terminated_reason{reason="OOMKilled"}`,
  `kube_pod_container_status_waiting_reason{reason="CrashLoopBackOff"}`, restart rate).

## Errors in message handlers

The capture subscriber sees only *uncaught* job failures. A handler that catches its error reports it itself:

```php
try {
    $qrScansService->process($appMessage->qrScanId);
} catch (Throwable $throwable) {
    $this->logIssue($throwable, $appMessage, 'QR scan ' . $appMessage->qrScanId);   // into the issues log
}
```

`AppMessageHandler::logIssue($throwable, $appMessage, ?$customMessage)` logs CRITICAL, section `MESSENGER`, with
`messenger.custom_message` and `messenger.message_payload` (the message's scalar public properties as JSON — what
rankingCoach's handlers write). `logShortException($logger, $context, $throwable)` keeps its Monolog line and since
3.7.1 also reaches the issues log (`messenger.custom_message` = the context), so existing handlers are captured
unchanged; prefer `logIssue()` for the payload. One throwable is one event, whichever is called first; a failing
issues log never leaves the catch block. A handler run in its workspace's CLI process (`ProcessCLIMessage`) logs its
own error there, uncaught, before the parent sees the exit code.

## Grouping — the two hashes

No line numbers and no free-text message in either hash, so a deploy that moves code and messages with ids keep the groups:

| Field | Level | Built from |
|---|---|---|
| `error.group_hash` | root cause — what alerts | exception class + throw site (relative file + function) + first application frame + discriminator |
| `error.issue_hash` | one bug through one entry point | group hash + first 4 application frames + entry point (route / `CLI:command` / `MessageHandler:transport`) |

- **Discriminator:** the SQLSTATE of a database error, the code of a DDD exception, and only for generic engine errors
  (`Error`, `ErrorException`) the message with numbers, UUIDs, hex ids, quoted literals and timestamps replaced.
- An application frame is one whose file is not under `vendor/`. Closure names lose their line.
- Same bug through two routes = one group, two issues. The entry point itself is `error.entry_point`. File, line,
  release and message are display-only.
- `error.fingerprint*` keep their old meaning (entry point + top frames with lines) for existing dashboards.

## Verify — `app:issues:test`

```bash
php bin/console app:issues:test               # logs a test error through IssuesLogService; prints target, file, group hash
php bin/console app:issues:test --uncaught    # throws it: the console capture logs it (exit code 1)
```

The test error is the same root cause on every run. Through PHP-FPM: call any route of the app that throws (or a test
route of your own) and look for its `error.group_hash`. Within the collector's interval the line must be in the backend.

## Kubernetes and VM setup — worked examples

### 1. Kubernetes, Alloy DaemonSet (recommended)

App: `ISSUES_LOG_TARGET=file`, `ISSUES_LOG_PATH=/var/log/app/issues`, `ISSUES_LOG_FILE_NAME=issues_{environment}_{date}.log`.

```yaml
# app Deployment (excerpt)
metadata:
  annotations:
    logs.ddd/path: /var/log/app/issues            # discovered by Alloy
spec:
  terminationGracePeriodSeconds: 45
  containers:
    - name: php
      env:
        - { name: ISSUES_LOG_TARGET, value: file }
        - { name: ISSUES_LOG_PATH, value: /var/log/app/issues }
      volumeMounts: [{ name: issues, mountPath: /var/log/app/issues }]
      lifecycle:
        preStop: { exec: { command: ["sleep", "15"] } }   # Alloy reads the last lines before the volume goes
  volumes:
    - name: issues
      emptyDir: { sizeLimit: 512Mi }                       # wide margin: the kubelet evicts a pod above it
```

```alloy
// Alloy DaemonSet: one pod per node, kubelet pod dir mounted read-only at /var/lib/kubelet/pods
discovery.kubernetes "pods" { role = "pod" }

discovery.relabel "issues" {
  targets = discovery.kubernetes.pods.targets
  rule { source_labels = ["__meta_kubernetes_pod_annotation_logs_ddd_path"] regex = ".+" action = "keep" }
  rule { source_labels = ["__meta_kubernetes_pod_uid"] target_label = "__pod_uid__" }
  rule {
    source_labels = ["__meta_kubernetes_pod_uid"]
    target_label  = "__path__"
    replacement   = "/var/lib/kubelet/pods/$1/volumes/kubernetes.io~empty-dir/issues/*.log"
  }
  rule { source_labels = ["__meta_kubernetes_namespace"] target_label = "namespace" }
  rule { source_labels = ["__meta_kubernetes_pod_name"] target_label = "pod" }
}

local.file_match "issues" { path_targets = discovery.relabel.issues.output }

loki.source.file "issues" {
  targets    = local.file_match.issues.targets
  forward_to = [loki.process.issues.receiver]
}

loki.process "issues" {
  stage.json   { expressions = { level = "\"log.level\"", service = "\"service.name\"", environment = "\"service.environment\"", section = "section" } }
  stage.labels { values = { level = "", service = "", environment = "", section = "" } }
  forward_to = [loki.write.default.receiver]
}

loki.write "default" {
  endpoint { url = "http://loki-gateway.monitoring/loki/api/v1/push" }
}
```

- Read positions on a hostPath (`--storage.path=/var/lib/alloy`) survive Alloy restarts. While the backend is down the
  files themselves are the buffer (the `loki.write` WAL is experimental — enable it consciously).
- Abrupt kills (node loss) can still lose the last seconds.
- Other Monolog channels on Kubernetes go to the same `emptyDir` or to stderr, never to the container filesystem.

### 2. Kubernetes, Alloy sidecar

The same `emptyDir`, read by a small Alloy container in the app pod (`local.file_match` on `/var/log/app/issues/*.log`).
No host mount, one extra container per pod.

### 3. Kubernetes, stderr

`ISSUES_LOG_TARGET=stderr`, Alloy (or any node agent) reads the container logs. PHP-FPM discards worker stdout and
mangles stderr unless the pool sets:

```ini
catch_workers_output = yes
decorate_workers_output = no
; the [global] section: lines up to 64 KB (default 1024 bytes cuts the JSON)
log_limit = 65536
```

containerd splits lines at 16 KB — the 8 KB event cap stays below it.

### 4. VM / Docker host, Filebeat

`ISSUES_LOG_TARGET=file` + Filebeat:

```yaml
filebeat.inputs:
  - type: filestream
    id: ddd-issues
    paths: ["/var/log/symfony/issues_*.log"]
    parsers: [{ ndjson: { target: "", add_error_key: true, overwrite_keys: true } }]
output.elasticsearch:
  hosts: ["https://es.internal:9200"]
  index: "logs-issues-%{+yyyy.MM.dd}"
```

### 5. VM / Docker host, Alloy

Alloy as a system service with `local.file_match` on the file path and `loki.source.file` → Loki (or
`otelcol.exporter.elasticsearch` → Elasticsearch).

### 6. The registry job

One instance per application and environment, never in the web pods. Either a cron every minute or a worker:

```yaml
# Kubernetes: a one-replica Deployment running the loop (or a CronJob "* * * * *" running app:issues:sync once)
containers:
  - name: issues-sync
    image: <app image>
    command: ["php", "bin/console", "app:issues:sync", "--loop", "--interval=60"]
    env:
      - { name: ISSUES_QUERY_BACKEND, value: loki }
      - { name: ISSUES_QUERY_URL, value: "http://loki-gateway.monitoring.svc:80" }
      - { name: ISSUES_QUERY_SELECTOR, value: '{service="dineful", environment="prod"}' }
      - { name: ISSUES_ALERT_WEBHOOK, valueFrom: { secretKeyRef: { name: issues, key: slack-webhook } } }
      - { name: ISSUES_ALERT_LINK_TEMPLATE, value: "https://admin.example.com/issues/{group_hash}" }
    livenessProbe:
      exec: { command: ["php", "bin/console", "app:issues:health"] }
      periodSeconds: 300
```

A second instance is harmless (a database lock skips concurrent runs), but costs queries. The first run starts **seed
mode** for `ISSUES_SEED_HOURS` (24): the existing errors are registered without 200 alerts. Seed again on demand, e.g.
after pointing the selector at a new environment: `app:issues:sync --seed=12`.

### 7. Backends

- **Loki:** self-hosted monolithic with object storage, or a cloud stack. Query: `{service="…"} | json | error_group_hash="…"`.
  `ISSUES_QUERY_BACKEND=loki`, `ISSUES_QUERY_SELECTOR={service="…", environment="…"}`.
- **Elasticsearch / OpenSearch:** `ISSUES_QUERY_BACKEND=elasticsearch` (or `opensearch`), `ISSUES_QUERY_SELECTOR` = the
  index pattern. Plain `_search` over HTTP — ES 7, ES 8 and OpenSearch 2 alike, only `_search` is called, so a
  read-only user suffices. String fields are read on their `.keyword` sub-field (Logstash / Filebeat dynamic mapping);
  with an index template that maps them as `keyword`, set `ISSUES_ES_KEYWORD_SUFFIX=` (empty). Retention by ILM / ISM.
- **Sentry:** for applications without Loki or Elasticsearch. `app:issues:forward-sentry` runs next to the files (a
  sidecar or cron in the pod / on the host, `--loop`) and sends every error line as a Sentry event — never from the
  request. Sentry groups by `error.group_hash` (its issues are the registry's groups), the event id is `event.id` (a
  line sent twice is one event), the hashes, entry point, route, account and section are tags. Read positions per file
  in a state file next to the logs (inode + offset: rotation and truncation start over, a file seen first starts at its
  end unless `--from-start`, a half-written line waits). A rate limit or outage stops the pass; the rest follows.
  `ISSUES_SENTRY_DSN` (or `SENTRY_DSN`), `--min-level=error` (uncaught errors always). As the registry's query
  backend: `ISSUES_QUERY_BACKEND=sentry`, `ISSUES_QUERY_URL=https://sentry.io` (or self-hosted),
  `ISSUES_QUERY_SELECTOR=<organization>/<project>`, `ISSUES_QUERY_TOKEN` with `event:read`.

### 8. Slack alerts

`ISSUES_ALERT_WEBHOOK` is a Slack (or Mattermost / Rocket.Chat-compatible) incoming webhook. One message per new root
cause (`:new: New error`) and per regression (`:repeat: Regression`) — never per event, never per entry point. A message
carries type, message, slug, entry points, release, environment, event count and the `ISSUES_ALERT_LINK_TEMPLATE` link.
The message is sent after the database write; a failed send stays pending (`alertPending`) and is retried every run.
Above `ISSUES_GROUP_EXPLOSION_LIMIT` new causes within an hour (a deploy that breaks fingerprints, a dependency outage)
the causes are registered silently and one `:rotating_light:` message is sent instead, at most hourly.

### 9. Monitoring the pipeline

`app:issues:health` (exit 1 = unhealthy, `--format=json`) checks two things the registry itself cannot alert on:
**job stalled** (no successful run within `ISSUES_JOB_STALE_MINUTES`, 10) and **collector silent** (no event of any level
within `ISSUES_COLLECTOR_SILENT_MINUTES`, 60 — set it above the quietest hour of the application). Wire it into the
liveness probe of the job (example 6) and into the external uptime monitor; the job's last error is in the output.

### 10. Local development

`tests/Integration/docker-compose.yml` of the framework carries a Loki (`docker compose … up -d loki`, port 3110). Push
lines with Alloy or `curl` to `/loki/api/v1/push`, then run the job against it:

```dotenv
ISSUES_QUERY_BACKEND=loki
ISSUES_QUERY_URL=http://127.0.0.1:3110
ISSUES_QUERY_SELECTOR={service="myapp"}
```

## The registry — job, tables, configuration

Three tables, generated like every DDD entity (`app:generate-doctrine-models-for-entities`; the schema is the
application's, as always): `IssueGroups` (one row per `error.group_hash`: status, sample, first/last seen, count,
releases, entry points, alert state), `Issues` (one row per `error.issue_hash`: the cause per entry point) and
`IssuesJobStates` (watermark and self-monitoring). The repositories are admin-only (`applyReadRightsQuery`); the job and
the commands run without rights restrictions.

**One run of `IssuesRegistryJobService::run()`:**

1. **Discovery** — the backend aggregates the error events (error and above, or uncaught) per hash over the last
   `ISSUES_DISCOVERY_WINDOW_MINUTES` (120: set it to the collector's maximum buffer age). An unknown group hash becomes
   a group (with one sample event), an unknown issue hash an issue. Lines a collector replays late under their original
   timestamp are still found; the unique hashes make it idempotent.
2. **Regression** — a RESOLVED group with an event after its `resolvedAt` is reopened. A replay of older lines is not.
3. **Counting** — over [watermark, now − 1 min]: counts aggregated by the backend (a storm of a million lines is one
   number, not a million rows), last seen and release of the newest event. After an outage the next run counts the gap.
4. **Alerts** (see example 8) and the job state.

Counts are approximate under collector replays; first seen, the group set and the regression rule are exact.

**Environments.** One job can watch several environments — production, testing, dev workspaces — each with a selector
of its own:

```dotenv
ISSUES_QUERY_ENVIRONMENTS={"prod": "{project=\"dineful\", workspace=\"prod\"}", "testing": "{project=\"dineful\", workspace=\"testing\"}", "mgn": "{project=\"dineful\", workspace=\"mgn\"}"}
```

The names are free keys, typically the workspace codes. Unset, there is one environment: `ISSUES_QUERY_SELECTOR` under
the name of `APP_STAGE` (else `APP_ENV`). Everything is per environment: the key (`environment` + `groupHash` on
`IssueGroups`, `environment` + `issueHash` on `Issues`), status, alerts, regressions, the job state (one
`IssuesJobStates` row per environment), the watermark, **seed mode** (an environment added later seeds on its own) and
the **explosion guard** (counted and messaged per environment). An environment whose backend fails records its error
and the run goes on with the others. One hash in two environments is two groups: resolving it in prod leaves testing
open.

From a release before environments: with one environment the job adopts the `default` job state (no new seed period)
and the groups and issues without environment. With several, rows without environment stay as they are.

| Variable | Default | Purpose |
|---|---|---|
| `ISSUES_QUERY_BACKEND` | `none` | `loki`, `elasticsearch`, `opensearch`, `sentry`; `none` = no registry, the application only logs |
| `ISSUES_QUERY_URL` | | base URL (Loki, Elasticsearch, Sentry) |
| `ISSUES_QUERY_SELECTOR` | | Loki stream selector / Elasticsearch index pattern / Sentry `<organization>/<project>` |
| `ISSUES_QUERY_SELECTOR_DEVELOPMENT` | the selector | Elasticsearch index pattern of `--logstashEnv=DEVELOPMENT` |
| `ISSUES_QUERY_USER` / `_TOKEN` | | user + token = basic auth, token alone = bearer (a token with a space, e.g. `ApiKey …`, is sent as given) |
| `ISSUES_QUERY_TENANT` | | Loki `X-Scope-OrgID` |
| `ISSUES_QUERY_TIMEOUT_SECONDS` / `ISSUES_QUERY_TLS_VERIFY` | 10 / true | |
| `ISSUES_ES_KEYWORD_SUFFIX` | `.keyword` | sub-field of string fields; empty = mapped as keyword |
| `ISSUES_ES_LOCATION_FIELD` / `ISSUES_ES_WORKSPACE_FIELD` | `lastLocationId` / `workspace` | "affected locations" and `--workspace` fields |
| `ISSUES_SENTRY_DSN` | `SENTRY_DSN` | target of `app:issues:forward-sentry` |
| `ISSUES_ALERT_WEBHOOK` | | Slack-compatible webhook; unset = no alerts |
| `ISSUES_ALERT_LINK_TEMPLATE` | | link in the alert, `{group_hash}` and `{environment}` replaced |
| `ISSUES_QUERY_ENVIRONMENTS` | | JSON `{"<environment>": "<selector>", …}`; replaces `ISSUES_QUERY_SELECTOR` |
| `ISSUES_DISCOVERY_WINDOW_MINUTES` | 120 | |
| `ISSUES_SEED_HOURS` | 24 | seed mode after the first run |
| `ISSUES_GROUP_EXPLOSION_LIMIT` | 50 | new causes per hour before the explosion message |
| `ISSUES_JOB_STALE_MINUTES` / `ISSUES_COLLECTOR_SILENT_MINUTES` | 10 / 60 | health thresholds |

## Discovery CLI — the agent workflow

Every command takes `--format=json`: stdout then carries only the JSON, messages go to stderr; exit 0 = success,
1 = failure (backend unreachable, unhealthy), 2 = invalid input or unknown group.

| Command | Purpose |
|---|---|
| `app:issues:list [--environment=prod] [--status=OPEN] [--new-since=24h] [--release=…] [--section=HTTP] [--limit=50]` | groups, last seen first; the status counts per environment |
| `app:issues:show <groupHash> [--environment=…] [--events=3] [--since=7d]` | one group, its issues, the newest events (from its environment's selector) with full context |
| `app:issues:search [--environment=…] [--workspace=mgn] [--text=…] [--level=error,warning] [--route=…] [--account=…] [--group=…] [--issue=…] [--since=1h] [--until=…] [--limit=20] [--oldest-first]` | events straight from the backend, any level; every environment merged by time unless `--environment`; `--workspace` filters `service.workspace`, also without an environment for it |
| `app:issues:resolve <groupHash> [--environment=…]` / `ignore` / `reopen` | status; resolve = only a later event reopens it, ignore = never alerts again |
| `app:issues:trend [--environment=…] [--group=<hash>] [--since=24h] [--until=…] [--step=auto]` | error events per time bucket (gap-free), one environment or all summed, one group or all |
| `app:issues:sync [--loop] [--interval=60] [--seed=HOURS]` | the job |
| `app:issues:health` | the self-monitoring (example 9) |
| `app:issues:forward-sentry [--loop] [--interval=10] [--file=…] [--state-file=…] [--min-level=error] [--from-start]` | the Sentry forwarder (example 7) |

Times are relative (`30m`, `24h`, `7d`) or absolute dates. JSON shapes:

A hash registered in several environments needs `--environment` on `show` / `resolve` / `ignore` / `reopen` (exit 2
otherwise, naming the environments).

- `list` → `{"count": n, "groups": [{groupHash, environment, workspace, status, exceptionType, sampleMessage, slug,
  logOrigin, section, sampleEventId, firstSeenAt, lastSeenAt, registeredAt, eventCount, releaseFirstSeen, releaseLastSeen,
  entryPoints: {"elements": [{"name": …}]}, resolvedAt, isNew, isRegression, alertPending, alertSentAt, link}]}`
  , "statusCounts": [{environment, status, count}]}` (other null fields are omitted; serialised objects also carry the
  framework's `objectType`).
- `show` → `{"environment", "workspace", "group": {…as above}, "issues": [{issueHash, environment, entryPoint,
  firstSeenAt, lastSeenAt, eventCount}], "events": [<event>], "eventsError": null|"…"}`.
- `search` → `{"environments": [...], "workspace": null|"…", "count": n, "events": [<event>]}`.
- An `<event>` is the raw ECS line as an object with `issues.environment` (the environment it was found in) and
  `issues.workspace` (its `service.workspace`) in front.
- `resolve` / `ignore` / `reopen` → `{"groupHash", "environment", "workspace", "status"}`.
- `trend` → `{"environment": null|"…", "groupHash": null|"…", "name", "interval": {"startDate", "endDate"},
  "stepSeconds", "total", "values": [{"x": "2026-10-28T10:00:00Z", "y": n}]}` — the framework's
  `DateTimeValueSequence` (chart types: `ddd-entity-specialist`); `--step=auto` is 1m up to 1 h, 10m up to 6 h, 1h up
  to 24 h, then 6h; a step too fine is coarsened to at most 2 000 buckets.
- `health` → `{backendConfigured, lastSuccessfulRunAt, newestEventSeenAt, lastError, jobStalled, collectorSilent,
  environments: {"elements": [{…the same per environment, environment}]}, healthy}` — unhealthy when one environment is.

**Fix loop for an agent:**

```bash
php bin/console app:issues:list --status=OPEN --new-since=24h --format=json      # what broke
php bin/console app:issues:show <groupHash> --events=3 --format=json             # stack trace, request, entry points
# … fix, test, deploy …
php bin/console app:issues:resolve <groupHash> --format=json                     # a new event now = regression alert
php bin/console app:issues:search --since=5m --format=json                       # right after running something locally
```

**You developed on workspace X** (e.g. `mgn`): its errors are `app:issues:list --environment=mgn --format=json` when the
registry watches it, and always `app:issues:search --workspace=mgn --since=30m --format=json` straight from the
backend (from any environment's tooling, prod included).

## Admin UI — controller, DTO, list

The registry is application data; an admin screen is a normal DDD admin endpoint in the application (conventions in
`ddd-endpoint-specialist`; `AdminController` is the application's admin base). Boilerplate:

```php
#[Route('/admin/issues')]
#[Tag(group: 'Admin', name: 'Issues', description: 'Production error groups')]
class AdminIssuesController extends AdminController
{
    #[Get('/groups')]
    #[Summary('Issue Groups List')]
    public function listGroups(
        IssueGroupsGetRequestDto &$requestDto,
        IssueGroupsService $issueGroupsService
    ): IssueGroupsGetResponseDto {
        $responseDto = new IssueGroupsGetResponseDto();
        $responseDto->issueGroups = $issueGroupsService->listGroups(new IssueGroupsFilter(
            status: $requestDto->status,
            environment: $requestDto->environment,
            newSince: $requestDto->newSince,
            release: $requestDto->release,
            limit: $requestDto->limit ?? 50,
        ));
        // the environment switch and the status tabs: counts per environment and status
        $responseDto->statusCounts = $issueGroupsService->countGroupsByEnvironmentAndStatus();
        return $responseDto;
    }

    /** The chart above the list: errors over time, the environment switch's environment or all */
    #[Get('/trend')]
    #[Summary('Issues Trend')]
    public function trend(IssuesTrendGetRequestDto &$requestDto, IssuesTrendService $issuesTrendService): IssuesTrendGetResponseDto
    {
        $interval = new TimeInterval(DateTime::fromTimestamp(time() - ($requestDto->hours ?? 24) * 3600), DateTime::fromTimestamp(time()));
        $responseDto = new IssuesTrendGetResponseDto();
        $responseDto->trend = $issuesTrendService->getTrend($requestDto->environment, null, $interval);
        return $responseDto;
    }

    /** The chart in a group's drawer */
    #[Get('/groups/{groupHash}/trend')]
    #[Summary('Issue Group Trend')]
    public function groupTrend(IssueGroupTrendGetRequestDto &$requestDto, IssuesTrendService $issuesTrendService): IssuesTrendGetResponseDto
    {
        $interval = new TimeInterval(DateTime::fromTimestamp(time() - ($requestDto->hours ?? 24 * 7) * 3600), DateTime::fromTimestamp(time()));
        $responseDto = new IssuesTrendGetResponseDto();
        $responseDto->trend = $issuesTrendService->getTrend($requestDto->environment, $requestDto->groupHash, $interval);
        return $responseDto;
    }

    #[Get('/groups/{groupHash}')]
    #[Summary('Issue Group Details')]
    public function getGroup(
        IssueGroupGetRequestDto &$requestDto,
        IssueGroupsService $issueGroupsService
    ): IssueGroupGetResponseDto {
        $issueGroup = $issueGroupsService->findByGroupHash($requestDto->groupHash, $requestDto->environment)
            ?? throw new NotFoundException('Issue group not found');
        $issueGroup->issues->getElements();   // loads the LazyLoad issues: the cause per entry point
        $responseDto = new IssueGroupGetResponseDto();
        $responseDto->issueGroup = $issueGroup;
        return $responseDto;
    }

    #[Post('/groups/{groupHash}/resolve')]
    #[Summary('Issue Group Resolve')]
    public function resolveGroup(
        IssueGroupGetRequestDto &$requestDto,
        IssueGroupsService $issueGroupsService
    ): IssueGroupGetResponseDto {
        $issueGroup = $issueGroupsService->findByGroupHash($requestDto->groupHash, $requestDto->environment)
            ?? throw new NotFoundException('Issue group not found');
        $responseDto = new IssueGroupGetResponseDto();
        $responseDto->issueGroup = $issueGroupsService->resolve($issueGroup);   // ignore() / reopen() alike
        return $responseDto;
    }
}

class IssueGroupsGetRequestDto extends RequestDto
{
    /** @var string|null The environment switch (prod, testing, mgn, …); null = all */
    #[Parameter(in: Parameter::QUERY)]
    public ?string $environment = null;

    /** @var string|null OPEN, RESOLVED or IGNORED */
    #[Parameter(in: Parameter::QUERY)]
    #[Choice(choices: IssueGroup::STATUSES)]
    public ?string $status = null;

    /** @var DateTime|null Registered at or after */
    #[Parameter(in: Parameter::QUERY)]
    public ?DateTime $newSince = null;

    #[Parameter(in: Parameter::QUERY)]
    public ?string $release = null;

    #[Parameter(in: Parameter::QUERY)]
    public ?int $limit = null;
}

class IssueGroupGetRequestDto extends RequestDto
{
    /** @var string error.group_hash of the group */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $groupHash;

    /** @var string The group's environment (one hash is one group per environment) */
    #[Parameter(in: Parameter::QUERY, required: true)]
    public string $environment;
}

class IssuesTrendGetRequestDto extends RequestDto
{
    /** @var string|null The environment switch; null = all environments summed */
    #[Parameter(in: Parameter::QUERY)]
    public ?string $environment = null;

    /** @var int|null Window in hours (default 24); the step follows (1m … 6h) */
    #[Parameter(in: Parameter::QUERY)]
    public ?int $hours = null;
}

class IssueGroupTrendGetRequestDto extends IssuesTrendGetRequestDto
{
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $groupHash;
}

class IssuesTrendGetResponseDto extends RestResponseDto
{
    /** @var DateTimeValueSequence Gap-free points {x, y}, stepSeconds and interval */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DateTimeValueSequence $trend;
}

class IssueGroupsGetResponseDto extends RestResponseDto
{
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public IssueGroups $issueGroups;

    /** @var IssueGroupStatusCounts Groups per environment and status: the switch and the tab badges */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public IssueGroupStatusCounts $statusCounts;
}

class IssueGroupGetResponseDto extends RestResponseDto
{
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public IssueGroup $issueGroup;
}
```

The repositories already return nothing to non-admins; the admin base restricts the route as well. The
list screen: an environment switch (`statusCounts->getEnvironments()`, "all" = no filter), status filter tabs (Open /
Resolved / Ignored, badges from `statusCounts->getCount($environment, $status)`), an environment column when "all" is
selected, a trend chart above the list (`/trend`, the selected environment) and one in the drawer
(`/groups/{hash}/trend`) — `IssuesTrendService` caches each for 60 s, columns type · message · events · last seen · release, flags
"new" and "regression", row → detail with entry points, issues and a link into the log backend
(`{service="…"} | json | error_group_hash="<hash>"`); the same URL is the `ISSUES_ALERT_LINK_TEMPLATE`.

## Runtime errors straight from Elasticsearch — `app:runtime-errors:*`

Next to the registry, `RuntimeErrorsService` groups the errors directly in Elasticsearch / OpenSearch — no tables, no
job; the read side of rankingCoach's runtime-error dashboard, with the same query bodies (byte for byte), result objects
and JSON. Configuration: the `ISSUES_QUERY_*` variables, falling back to `ES_LOGS_HOST` / `_PORT` (9200) / `_SCHEME`
(https) / `_USER` / `_PASSWORD` / `_INDEX` / `_INDEX_DEV`, whatever `ISSUES_QUERY_BACKEND` says (`ES_LOGS_*` alone does
not turn the registry on).

- `findErrorGroups($hours, $levels, $size, $groupingDimension, $logstashEnvironment, $accountId, $locationId,
  $searchTerm, $workspace, $minutes)` → `RuntimeErrorGroups`. COARSE (default) groups by error type + throw-site file
  (`multi_terms`, ES ≥ 7.12 / OpenSearch ≥ 2.1), FINGERPRINT by `error.fingerprint_short`.
- `findErrorTrend($hours, $levels, $fixedInterval, $logstashEnvironment, $workspace)` → `RuntimeErrorTrend`.
- `findErrorGroupDetail($groupKey, …, $sampleSize)` → `RuntimeErrorGroupDetail` (last seen, gap-free trend, newest
  occurrences, per-route breakdown). Message, stack trace and route are redacted on read (`RuntimeErrorFieldRedactor`).

Commands, same names, options and JSON as rankingCoach's:

```bash
php bin/console app:runtime-errors:extract --logstashEnv=DEVELOPMENT --workspace=mgn --minutes=5 --limit=5
#   → {"filters": {environment, workspace, accountId, locationId, window, minutes, hours, levels, limit},
#      "count": n, "errors": [{errorType, message, throwSite, route, count, affectedLocationCount, environment,
#      errorCode, errorKind, firstSeen, lastSeen, fingerprint, stackTrace}]}
#   options: --logstashEnv=PRODUCTION|DEVELOPMENT --account --location --hours=24 --minutes --workspace
#            --level=ERROR,CRITICAL --limit=20; unknown --logstashEnv = exit 2, a failed query = exit 1
php bin/console app:runtime-errors:probe [--hours=24] [--size=5] [--compositeSize=10] [--index=…]
#   hit count, top fingerprints, trend buckets, and which grouping / field names work on this index
```

**A framework command yields to an application command of the same name** (a compiler pass removes the framework's):
an application with its own `app:runtime-errors:extract` keeps it until it deletes it.

## RC-compatible configuration

An application that already writes `issues` lines with a `stream` handler and the ECS formatter (rankingCoach) gets the
identical line from the DDD sink with:

```dotenv
ISSUES_LOG_TARGET=file
ISSUES_LOG_PATH=/var/log/symfony
ISSUES_LOG_FILE_NAME=issues_{environment}.log
ISSUES_LOG_LEVEL=warning
ISSUES_LOG_HEADER_PREFIXES=x-
```

Its own Monolog processors on the `issues` channel keep working (they run before any handler). While it still runs its
own capture subscriber, it sets `ISSUES_CAPTURE_ENABLED=0` — from the DDD bump on, because its `logThrowable` override
bypasses the log-once mark and both subscribers would log every uncaught error.

Its runtime-error dashboard can move onto the framework: the `ES_LOGS_*` variables already configure it (the index
patterns, bound as service parameters there, become `ES_LOGS_INDEX` / `ES_LOGS_INDEX_DEV` or `ISSUES_QUERY_SELECTOR` /
`_DEVELOPMENT`). Then `App\…\RuntimeErrors\*` → `DDD\Infrastructure\Logging\Query\Elasticsearch\*` (same class
names and public properties), `RuntimeErrorsService` → `DDD\Domain\Common\Services\RuntimeErrorsService` (same
methods), and its two commands and `ElasticsearchLogReaderService` are deleted — the framework's commands take over.
For the registry on top: `ISSUES_QUERY_BACKEND=elasticsearch`.

## Troubleshooting

| Symptom | Check |
|---|---|
| Nothing in the file | `ISSUES_LOG_TARGET` really set in the PHP process (`app:issues:test` prints the target); the directory writable |
| Every error twice | two capture subscribers (`ISSUES_CAPTURE_ENABLED=0` for the app's own), or a `logThrowable` override that skips `wasLogged()` |
| "Uncaught PHP Exception" lines next to the issues line | the request-channel filter is not first: an app handler on `request` with a priority above 100 |
| Lines cut at 1024 bytes (stderr) | the FPM `log_limit` |
| Registry stays empty | `ISSUES_QUERY_BACKEND` set for the job process; `app:issues:search --since=24h` finds lines (selector, auth); only error and above or uncaught lines are registered |
| A sync right after an event counts 0 | expected: discovery and counting stop at now − 1 min (events may still be in the collector's buffer); an event younger than a minute is registered and counted in the next run. `app:issues:search` sees it at once |
| No alerts | seed mode (first `ISSUES_SEED_HOURS` after the first run); `ISSUES_ALERT_WEBHOOK` set; group IGNORED; explosion guard (`app:issues:sync` prints it) |
| `app:issues:health` fails | `lastError` in its output; the job not running; the collector not shipping (collector silent) |
| Elasticsearch: groups empty, hits not | the fields are mapped as keyword: `ISSUES_ES_KEYWORD_SUFFIX=` (empty); `app:runtime-errors:probe` shows which field names aggregate |
| Elasticsearch: `multi_terms` unknown | ES < 7.12: COARSE grouping needs 7.12; the registry (composite aggregation) works from ES 7 |
| Sentry forwarder sends nothing | a file seen for the first time starts at its end (`--from-start` for history); `--min-level`; state file `.issues-sentry-forward.json` next to the logs |
| One bug, many groups | compare `log.origin.file.name` and the frames of two lines (`app:issues:show`): paths are relative to the project, an absolute path means a file outside the project and outside `vendor/`; a throw site in generated code with varying function names; look at `error.group_hash` inputs (`IssuesFingerprintBuilder::getFrames()`) |
