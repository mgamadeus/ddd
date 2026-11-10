# Workers from the console (agents)

Every monitoring read as a console command, with `--format=json` for agents.

## Commands

Everything the admin endpoints return is readable from the console. `--format=json` prints the payload of the
matching endpoint as one object (`{"workersOverview": {...}}`); the default is a table. Reading needs no account.

| Question | Command | JSON property (endpoint) |
|---|---|---|
| What is going on now? | `app:workers:overview [--transport=T] [--onlyActive]` | `workersOverview` (`/overview`) |
| One transport over time | `app:workers:transport T [--since=60 \| --from= --to=] [--stepSeconds=] [--sequences]` | `workerTransportDetail` (`/transports/{transport}`) |
| All transports over time | `app:workers:history [--since= \| --from= --to=] [--stepSeconds=] [--transport=T]… [--sequence=S]… [--onlyActive]` | `workersHistory` (`/history`) |
| Which processes ran, why did they stop? | `app:workers:processes [T] [--live] [--state=] [--stopReason=] [--since=] [--limit=50]` | `workerProcesses` (`/transports/{transport}/processes`) |
| Which messages failed, with which error? | `app:workers:runs [T] [--outcome=FAILED] [--messageClass='*Import*'] [--processKey=] [--since=] [--limit=50]` | `workerMessageRuns` (`/transports/{transport}/messageRuns`) |
| How many messages wait at the broker right now? | `app:workers:queues [T…]` | `workerQueueCounts` (no endpoint) |
| Totals per transport and message class | `app:workers:stats --since=60 [--transport=T]` | a set of summed stat buckets |

```bash
php bin/console app:workers:overview --onlyActive                      # states, queue, throughput windows
php bin/console app:workers:runs import_files --outcome=FAILED --since=120 --format=json
php bin/console app:workers:processes --stopReason=LOST --since=1440   # who vanished in the last day
php bin/console app:workers:transport send_mail --since=360 --sequences
php bin/console app:workers:restart [transport] [--dryRun]             # asks the live processes to stop after their message
php bin/console app:workers:sample                                     # one sample of every consumed transport + prune
```

- `--since` is minutes back from now; `--from` / `--to` are UTC date-times. `--limit` is at most 1000.
- `processes` and `runs` work with and without a transport; `--state` filters the stored state (`RUNNING`,
  `STOPPING`, `STOPPED`), `--live` uses the display state (the other filters are then not applied).
- `--outcome`: `HANDLED`, `CAUGHT`, `THROWN` or `FAILED` (caught or thrown). `--messageClass` takes `*` as wildcard.
- `overview` and `stats` print the throughput windows per transport: finished messages, per minute, per worker
  minute, failed — for the last 5 minutes, 15 minutes, hour and day.
- `--onlyActive`: in `overview` the transports with a live process, a waiting or running message, a state other
  than IDLE or messages finished in the recent window; in `history` those with a value above 0 in a shown sequence.
- `queues` asks the broker and writes nothing; it works with the monitoring off. Every other read shows a note on the
  error output when the monitoring is off. The exit code is 1 only for an invalid argument or an unknown transport
  (also in `queues`, which still prints the counts of the others).

After a deploy that changes code the workers run: `app:workers:restart` (needs the monitoring: the processes must be
known). A process answers at its next tick — after its current message.
