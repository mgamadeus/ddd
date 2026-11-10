# Transports, routing and consumers

Wiring a transport in `messenger.yaml`, its supervisor consumer, and the compiled container the workers run on. Limits of the consume command: [worker-list-and-limits.md](worker-list-and-limits.md).

## Wire Messenger Transport + Routing

Update `<app>/config/symfony/default/packages/messenger.yaml` (this is the consuming **app's** config, relative to the app root — not a vendor path):

```yaml
framework:
  messenger:
    transports:
      # Add transport (RabbitMQ queue)
      process_foo_bar: '%env(MESSENGER_TRANSPORT_DSN_RABBITMQ)%/process_foo_bar'

    routing:
      # Route message to transport
      '{Namespace}\Domain\{Domain}\MessageHandlers\FooBarMessage': process_foo_bar
```

**Debug tip:** For local troubleshooting, temporarily switch the transport to `sync://`.

## Add Supervisor Consumer

**IMPORTANT:** Search the project for an existing supervisor config file. Common locations:

```
config/system/supervisorWorkers.conf
config/supervisor/*.conf
docker/supervisor/*.conf
```

Use `Glob` with patterns like `**/*supervisor*` or `**/*Workers.conf` to find it.

Once found:
1. **Read the existing file** to learn the project's conventions — the `command=` path, `user=`, `numprocs` patterns, etc. Every project is different.
2. **Copy an existing `[program:]` block** as a template for the new entry.
3. **Replace only** the transport name (in `[program:]` and `messenger:consume` argument) with the new transport name.
4. **Append** the new block to the end of the file.

**General structure of a supervisor consumer block:**

```ini
[program:{transport_name}]
command=php {path_from_existing_entries}/bin/console messenger:consume {transport_name} --no-debug --limit={N} --time-limit=3600 --memory-limit={M}
process_name=%(program_name)s_%(process_num)02d
numprocs={see below}
autostart=true
autorestart=true
startsecs=0
startretries=10
user={copy from existing entries}
redirect_stderr=true
```

**Rules:**
- **Never hardcode paths** — always copy the `command=` path prefix from an existing entry in the same file
- **Never guess `user=`** — copy from existing entries
- Transport name in `[program:]` and `messenger:consume` must match the transport name in `messenger.yaml`
- Set `numprocs` based on job cost:
  - IO-bound / short tasks: more workers (e.g., push notifications: 5-10)
  - Heavy CPU/memory: fewer workers (e.g., imports: 1)
  - Standard async processing: 1-2

## Rebuild the container BEFORE (re)starting workers — `--no-debug` does NOT auto-rebuild

**Symptom pattern:** you change a transport (e.g. flip `sync://` ↔ AMQP, add a new transport), `supervisorctl start`/`restart` the workers, and they're either absent, crash-looping, or behave as if the old config were still active — the queue is never declared, messages aren't consumed, or a publish runs inline instead of being queued.

**Cause:** supervisor consumer commands run with `--no-debug` (`messenger:consume <transport> --no-debug …`). `--no-debug` disables the debug kernel, which is the thing that **rebuilds the compiled container on a config-file change**. So a `--no-debug` worker reads the *stale* compiled container — it keeps seeing the OLD transport DSN (e.g. still `sync://`), so `messenger:consume <transport>` on a now-sync transport crashes immediately, or consumes the wrong transport. FPM and any `messenger:consume` run *without* `--no-debug` self-heal (they rebuild on change), which is why a foreground `messenger:consume <transport> -vv` "works" while the supervisor-managed `--no-debug` workers silently don't.

**Rule: rebuild the container + reset the cache BEFORE every supervisor `start`/`stop`/`restart` that follows a code or transport change.** Either:
- `bin/console cache:clear` (the kernel the workers run under), or
- start the worker once **without** `--no-debug` (`messenger:consume <transport> -vv`) — the debug kernel rebuilds the shared container, then Ctrl-C and let supervisor take over.

FPM and the workers share the same `var/cache/<env>` dir, so one rebuild fixes both the consume side (workers) and the publish side (the dispatching request). **Diagnose a silently-failing worker by running it in the foreground without `--no-debug`** — that prints the real exception (transport-not-found / does-not-support-receiving / AMQP error) instead of swallowing it into a supervisor logfile.

## Worker pools on a checkout FPM also serves

`APP_CACHE_DIR` / `APP_LOG_DIR` (absolute paths, read by `DDDKernel::getCacheDir()` / `getLogDir()`) give a process its
own compiled container and log directory. Set them for the worker pool, so a sync that makes FPM rebuild its container
never deletes the container a running worker uses.
