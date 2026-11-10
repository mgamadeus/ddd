# Worker list and limits

Which transports get workers (`config/system/workers.yaml`), the check against the Messenger configuration, and the limits that recycle a worker process.

## Worker list

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
| `busySilentSeconds` | how long a message of this transport may run before its process is SILENT (default: the setting) |

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

## Worker recycling — `--limit`, `--time-limit`, `--memory-limit`

Long-running PHP workers accumulate static state across messages: Translatable's static `currentLanguageCode` / `translationSettingsSnapshot`, the entity registry / `EntityCache`, AuthService's account, opaque caches inside Firebase / external SDKs, etc. **Symfony Messenger handlers DO NOT reset between messages** — only between worker processes. State leaks from message N into message N+1 in the same worker, causing non-deterministic alternating bugs that are hard to reproduce.

The defence is to recycle worker processes regularly. Symfony Messenger ships three command-line limits that exit the worker after a threshold; supervisor's `autorestart=true` then immediately spawns a fresh process with clean static state.

| Flag | Effect | Recommended baseline |
|---|---|---|
| `--limit=N` | Exit after N successfully-processed messages | per-transport, see below |
| `--time-limit=3600` | Exit after N seconds (defence against slow leaks) | `3600` for every transport |
| `--memory-limit=256M` | Exit if memory exceeds threshold | match the transport's real workload |

**Sizing `--limit`:**

| Transport profile | Suggested `--limit` |
|---|---|
| State-sensitive, high-volume (push notifications, chat post-process) | `100` |
| State-sensitive, lower-volume (translations, embeddings, support post-process) | `50` |
| Long-running single jobs (Strava import, AI resolution, summary generation) | `20` |
| Heavy single jobs that pin a worker for minutes (track migration) | `10` |
| State-light high-volume (track geohashes) | `200` |

Lower `--limit` = more frequent state hygiene at the cost of more PHP cold-start overhead. Cold start is ~1s; for a transport handling 10 msg/s, `--limit=100` means a 1s pause every 10s of work — negligible.

**Sizing `--memory-limit`:** match the transport's real footprint. Push notifications: `256M`. AI / translations / embeddings: `512M`. Strava imports: `1024M`. Track migration: `2048M`. Don't set it lower than the actual job needs — otherwise you'll see workers killed mid-message and the message redelivered.

**Why `startsecs=0` + `startretries=10`:** with `--limit=100` and 100 fast messages, a worker can complete in <1 s. Supervisor's default `startsecs=1` would treat that as a crash-loop and give up after `startretries=3`. Setting `startsecs=0` accepts sub-second completions as legitimate; `startretries=10` keeps the safety net for real failures.

**Reload after editing:** the user runs

```bash
sudo supervisorctl reread
sudo supervisorctl update
```

— this picks up the new config without bouncing all unaffected workers.
