# Troubleshooting

Symptom → check. Run a consume command in the foreground without `--no-debug` to see the real exception.

## Messages and consumers

| Symptom | Check |
|---|---|
| Messages wait, nothing consumes them | `app:workers:check` (`TRANSPORT_WITHOUT_CONSUMER`), `app:workers:queues`, then the consumer of that transport ([worker-list-and-limits.md](worker-list-and-limits.md)) |
| Workers crash-loop, are absent or use the old transport DSN after a change | stale compiled container of a `--no-debug` worker: `cache:clear` before the restart ([transports-routing-and-consumers.md](transports-routing-and-consumers.md)) |
| A handler is never called | `#[AsMessageHandler(fromTransport:)]` names a transport that does not exist or the message is not routed to; `debug:messenger` must show `(when from_transport=…)` |
| A lookup throws, or reads stale data, only after another job ran | state on a shared service or the process ([worker-state-and-workspace-origin.md](worker-state-and-workspace-origin.md)) |
| Message refused, log names two workspaces | two checkouts share a broker vhost ([worker-state-and-workspace-origin.md](worker-state-and-workspace-origin.md)); fix the vhost, replay with `messenger:failed:retry` |
| A publish runs inline instead of being queued | the transport is `sync://` in the compiled container the request uses |
| Workers are killed mid-message and the message comes again | `--memory-limit` below what the job needs ([worker-list-and-limits.md](worker-list-and-limits.md)) |

## Monitoring

| Symptom | Check |
|---|---|
| Nothing is written | `enabled`, tables exist (`app:workers:check`), workers restarted after switching on, parameter `ddd.messenger.worker_monitoring` not false |
| Log: "Worker monitoring paused … after a failed write" | the message names the cause (missing table or column, database down); the issues log has it once per process |
| Process LOST while its container runs | no heartbeat and no current message: the process hangs outside a handler, or its writes fail (see above) |
| Process stays RUNNING after it was killed | it is closed as LOST by the next prune (sampler command or another worker's flush) after 12 heartbeat intervals |
| Process stays BUSY after it was killed | a prune on the same host closes it at once; from another host only after twice its `busySilentSeconds` — set the consumer's `busySilentSeconds` to what its longest job needs |
| A running BUSY process is closed as LOST | containers share a host name without sharing process ids: `closeGoneProcessesOnOwnHost: false` |
| Banner or `app:workers:check` says the sampler is not running (`SAMPLER_NOT_RUNNING`, `samplerActive` false) | no Cron row for `app:workers:sample` in this environment's database (`app:crons:list`); no cron runner here (`app:crons:execute` every minute); the command is not in the deployed code (`app:crons:executions` shows the failed runs); the monitoring is off or its tables are missing (`app:workers:check`) — setup step 3 in [monitoring-and-settings.md](monitoring-and-settings.md) |
| A chart shows no line for minutes | a gap (null): no sample or no handled message in those steps — not a zero |
| Transport STUCK with processes in the list of the orchestrator | the processes did not register: they crash before the worker starts — run the consume command in the foreground |
| Queue count state `QUEUE_NOT_SET_UP` | nothing dispatched or consumed yet on this broker; the count never declares a queue |
| No wait times | the message carries no dispatch time (dispatched by an older release, or not through the bus) |
| `app:workers:restart` asks nobody | monitoring off, or no live process of that transport |
| Message runs missing for a transport | its `messageLog` is `failures` or `off` |
