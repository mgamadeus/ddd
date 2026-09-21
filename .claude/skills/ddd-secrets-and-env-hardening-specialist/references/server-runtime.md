# Server runtime: containers, workers, crons, deploy

Contents: 1 Process model · 2 Container wiring · 3 The shell hook · 4 docker exec, crons, deploy · 5 supervisord · 6 php-fpm and php.ini · 7 nginx and VPN gating · 8 Verification · 9 Troubleshooting

## 1 Process model — who has the secrets

`op run` resolves the `op://` references of `$OP_ENV_FILE` once, at start, into the env of ONE child
process and its descendants. The entrypoint first filters the `op://` lines of `$OP_ENV_FILE` into
`/run/op/app.env.references` and hands op run ONLY that file — op run exports every key it is given as a real
env var, and real env outranks every `.env*` file inside PHP; passing the whole `.env` would let the committed
prod flags override `.env.local` in dev workspaces (it did, until 2026-09-21). `APP_ENV`, `APP_DEBUG`, URLs and
all other config reach PHP through Symfony's Dotenv cascade, never through op run. Nothing else in the
container has the secrets:

| Process | Has resolved env? | Because |
|---|---|---|
| php-fpm (PID 1 chain) | yes | started by `connect-entrypoint.sh` → `op run` |
| `docker exec … bash` (interactive) | yes | `/etc/bash.bashrc` sources `op-env-resolve.sh` |
| `docker exec … bash -c '…'` (cron, deploy) | only with `-e BASH_ENV=/usr/local/bin/op-env-resolve.sh` | `bash -c` never reads bashrc |
| `docker exec … php …` (no shell) | no | wrap it in `bash -c` |
| `service supervisor start` | **never** | `service(8)` runs the init script with `env -i` |
| `/etc/init.d/supervisor start` from a resolved bash | yes | start-stop-daemon keeps the env |

Docker's own container env (compose `environment:`) holds the pointers only: `OP_ENV_FILE`,
`OP_VAULT`, `OP_CONNECT_HOST`, `OP_CONFIG_DIR`, `BASH_ENV`, `APP_ENV`, `APP_DEBUG`. The token is a
compose secret file, never an env var; `connect-app-exec.sh` unsets all `OP_*` before exec'ing PHP.

## 2 Container wiring

Files (templates in this skill): `connect-entrypoint.sh` (waits for the local Connect heartbeat,
proves every reference resolves with `op run … -- /bin/true`, then `exec op run … -- connect-app-exec.sh "$@"`),
`connect-app-exec.sh` (drops `OP_*`, `exec docker-php-entrypoint "$@"`), `op-env-resolve.sh`,
`Dockerfile.snippet`, `docker-compose.php-service.yml`. Connect = two containers (`connect-api`,
`connect-sync`) fed by a `1password-credentials.json` for a **Connect server** with READ access to
exactly one vault per app. The token is the Connect token, stored as a compose secret file.

Persistence: the image COPY (Dockerfile.snippet) is the long-term source, and the compose service additionally bind-mounts
the same three files read-only from the compose dir (`./Dockerfiles/…`, `./conf/bash/bash.bashrc`) — see the compose
template — so a `docker-compose up -d` recreate never drops the entrypoint or the hook while the image lags behind.

## 3 The shell hook — `op-env-resolve.sh`

Sourced (not executed). Idempotent via `OP_ENV_RESOLVED=1`. Reads the token file, filters the `op://` lines of
`$OP_ENV_FILE` into a temp file (secrets only — `APP_ENV` & Co stay with Dotenv), runs
`op run --no-masking --env-file=<temp> -- sh -c 'export -p'`, filters out
`PWD OLDPWD SHLVL _ HOME PATH TERM HOSTNAME OP_CONNECT_TOKEN`, `eval`s the rest. Prints only a
count; never a value. Non-fatal: if Connect is down the shell opens with a WARNING on stderr.
Interactive shells additionally `cd` to the app dir. As `www-data` it switches `OP_CONFIG_DIR` to
`/tmp/op-uid-<uid>` because `op` refuses a config dir it does not own (the container default
`/tmp/op` is root's). Cost: one Connect round-trip (~1 s) per shell start.

Install: `COPY` + append two lines to `/etc/bash.bashrc` (Dockerfile.snippet). Debian bullseye
interactive non-login bash reads `/etc/bash.bashrc`, not `/etc/profile.d`. A hook installed by hand
into a running container survives `docker restart`, NOT a recreate — then `BASH_ENV` points at a
missing file, bash continues silently and every exec sees placeholders again.

## 4 docker exec, crons, deploy

See `templates/teamcity-and-cron.sh`. Rule of thumb: every `docker exec … bash -c` against a PHP
container gets `-e BASH_ENV=/usr/local/bin/op-env-resolve.sh`, or put `BASH_ENV` once into the
compose `environment:` and drop the flag. Run app commands as `www-data` (the model generator writes
PHP files into `src/`; root-owned files there break the next deploy). The token file must be readable
by that user (`0640 root:www-data`).

The hook resolves at exec time against the `.env` on disk — a deploy that adds a new `op://` key
resolves it immediately, as long as the field exists in the item. php-fpm keeps the values from its
last start until the restart at the end of the deploy.

## 5 supervisord (Symfony Messenger workers)

Symptom of a wrong start: `supervisorctl status` shows N RUNNING with `uptime 0:00:00` forever,
`supervisord.log` full of `exited: … (exit status 1; not expected)`, and `/proc/<supervisord pid>/environ`
holds ~18 `LC_*` vars and no `DB_*`. Cause: `service supervisor start` (`env -i`). Fix: the init
script called directly from a `bash -c` with `BASH_ENV` (template). Add `stderr_logfile` to every
`[program:*]` — without it `supervisorctl tail` says "no log file" and the crash reason is invisible.
Structural fix: supervisord as the container main process under the entrypoint, php-fpm as a program.

## 6 php-fpm and php.ini

- The official image ships **no php.ini**: `display_errors=1`, `expose_php=On`, `variables_order=EGPCS`
  by default. Copy `php.ini-production` and re-add `variables_order = "EGPCS"` — production sets `GPCS`,
  which empties `$_ENV`, and the framework's `Config::getEnv()` reads `$_ENV` only. (Locally, Symfony's
  Dotenv copies `$_SERVER` → `$_ENV`, so `GPCS` happens to work there; do not rely on it in the container.)
- `clear_env = no` in the fpm pool (image default `docker.conf`) is what forwards the op-run env to
  the workers. An `env[KEY]=$KEY` allowlist can narrow it, but the real scoping is per-consumer
  reference files (§1 of SKILL.md).
- `disable_functions = phpinfo` in prod. `phpinfo()` prints the whole env.

## 7 nginx and VPN gating

`templates/nginx.hardening.conf`: 403 on `/_profiler` and `/_wdt`, only `index.php` to php-fpm,
dotfiles denied, dev vhosts VPN-only. The Sept 2026 incident: 20 dev/staging subdomains with
`APP_DEBUG=1` were public; `/_profiler/phpinfo` answered 1.37M times over two years (mostly crawlers),
and one scripted client fetched the env dump nine times two days after a fresh key was created.
A dev vhost is a public env dump unless it is behind the VPN AND the profiler routes are blocked
AND `web_profiler.yaml` collects nothing by default.

## 8 Verification after a deploy

1. Build log: both execs exit 0, no `Access denied for user 'op://…'`.
2. `supervisorctl status | awk '{print $2}' | sort | uniq -c` → N RUNNING; a minute later uptime grew.
3. `tr '\0' '\n' < /proc/$(cat /var/run/supervisord.pid)/environ | grep -c '^DB_DEFAULT_CONNECTION_USER=[^o]'` → 1.
4. Run the cron line by hand once without the `/dev/null` redirect.
5. `find <app>/src <app>/var -user root | head` → empty.
6. `php -i | grep variables_order` in the container → contains `E`.

## 9 Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Access denied for user 'op://VAULT/item/KEY'` | process not under op run / no BASH_ENV | §1 table |
| workers RUNNING with uptime 0:00:00, 1000s of exits | supervisord started via `service` | §5 |
| `op … can't safely access "/tmp/op" … not owned by the current user` | running as www-data | hook v2 sets a per-uid `OP_CONFIG_DIR` |
| `FAIL: Connect cannot resolve required references` at container start | token scope, vault name, field missing, sync lag | `op item get <item> --vault <vault>` with an admin session; check `connect-sync` logs |
| hook prints `WARNING: could not resolve` | Connect unreachable or token unreadable for this uid | `curl $OP_CONNECT_HOST/heartbeat`; token file mode |
| everything resolved but `Config::getEnv` empty | `variables_order` without `E` | §6 |
| secret values show as `<concealed by 1Password>` in command output | `op run` masks stdout | expected; `--no-masking` only inside the hook |
| exec works, cron does not | `bash -c` skips bashrc | `-e BASH_ENV=…` |
| worked yesterday, placeholders today | container recreated, hook not in image | Dockerfile.snippet |
