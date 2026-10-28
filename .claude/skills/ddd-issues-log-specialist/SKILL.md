---
name: ddd-issues-log-specialist
description: "Log, cluster and ship production errors with the mgamadeus/ddd issues log: IssuesLogService::logThrowable (log vs rethrow, log-once), the issues Monolog channel and its sinks by environment (ISSUES_LOG_TARGET file/stderr/none, ECS JSON via elastic/ecs-logging, date rotation), the capture subscriber (uncaught HTTP 5xx, console, messenger, PHP fatals; ISSUES_CAPTURE_ENABLED), redaction and size caps, the two-level hashes error.group_hash / error.issue_hash next to the unchanged error.fingerprint fields, app:issues:test, the RC-compatible configuration, and the DevOps setup as worked examples (Kubernetes Alloy DaemonSet or sidecar on an emptyDir, stderr with PHP-FPM settings, VM with Filebeat or Alloy, Loki / Elasticsearch / OpenSearch / Sentry backends). Use when logging an exception, nobody sees production errors, configuring where errors go, shipping logs from Kubernetes or a VM, an error is logged twice or not at all, hashes split one bug into many groups, or verifying the error pipeline end to end."
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
---

# DDD Issues Log Specialist

Every production error as one structured line, grouped by root cause, shipped by a collector. The request only appends
one line to a local file (or stderr) — no network, no database on the error path.

**In this release:** the write side, clustering, capture, `app:issues:test`, the shipping setup.
**Next releases:** the fingerprint registry with the alert job and the Loki query backend, then the Elasticsearch /
OpenSearch and Sentry backends with the `app:runtime-errors:*` discovery CLI.

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

Variables are read from `.env` (Config) and from the process environment, so a container can set them directly.
With `file`, `stderr` or `none` the records stop at the sink — they no longer reach the application's main log.

**The line** (Elastic Common Schema, `Elastic\Monolog\Formatter\ElasticCommonSchemaFormatter` from `elastic/ecs-logging`):
`@timestamp`, `log.level`, `message`, `ecs.version`, `log.logger`, plus the context — `event.id` (UUID per event),
`service.name` / `service.environment` (`APP_STAGE`, else `APP_ENV`) / `service.version`, `host.hostname`, `section`,
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

## Grouping — the two hashes

No line numbers and no free-text message in either hash, so a deploy that moves code and messages with ids keep the groups:

| Field | Level | Built from |
|---|---|---|
| `error.group_hash` | root cause — what alerts | exception class + throw site (relative file + function) + first application frame + discriminator |
| `error.issue_hash` | one bug through one entry point | group hash + first 4 application frames + entry point (route / `CLI:command` / `MessageHandler:transport`) |

- **Discriminator:** the SQLSTATE of a database error, the code of a DDD exception, and only for generic engine errors
  (`Error`, `ErrorException`) the message with numbers, UUIDs, hex ids, quoted literals and timestamps replaced.
- An application frame is one whose file is not under `vendor/`. Closure names lose their line.
- Same bug through two routes = one group, two issues. File, line, release and message are display-only.
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

### 7. Backends

- **Loki:** self-hosted monolithic with object storage, or a cloud stack. Query: `{service="…"} | json | error_group_hash="…"`.
- **Elasticsearch / OpenSearch:** an index template mapping the ECS fields (hashes and `log.level` as `keyword`), ILM
  retention, a read-only API key for queries; an ingest pipeline that sets `event.ingested`.
- **Sentry (optional):** set `ISSUES_RELEASE`; events are forwarded from the files by an async worker (next releases),
  never sent from the request.

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

## Troubleshooting

| Symptom | Check |
|---|---|
| Nothing in the file | `ISSUES_LOG_TARGET` really set in the PHP process (`app:issues:test` prints the target); the directory writable |
| Every error twice | two capture subscribers (`ISSUES_CAPTURE_ENABLED=0` for the app's own), or a `logThrowable` override that skips `wasLogged()` |
| "Uncaught PHP Exception" lines next to the issues line | the request-channel filter is not first: an app handler on `request` with a priority above 100 |
| Lines cut at 1024 bytes (stderr) | the FPM `log_limit` |
| One bug, many groups | a throw site in generated code with varying function names; look at `error.group_hash` inputs (`IssuesFingerprintBuilder::getFrames()`) |
