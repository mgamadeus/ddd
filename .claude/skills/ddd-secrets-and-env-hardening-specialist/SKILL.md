---
name: ddd-secrets-and-env-hardening-specialist
description: "Run a mgamadeus/ddd app with NO plaintext secrets anywhere: the committed .env holds op:// 1Password references resolved at run time by op run (Connect server in the container, the developer login locally). Covers .env / .env.local / .envrc layering, project migration (op_envify.py, op_import_env_secrets.py, rotate_env_secrets.py, pre-commit guard check-env-secrets.py), container runtime (connect-entrypoint.sh, op-env-resolve.sh shell hook, BASH_ENV for docker exec, crons, CI deploy steps, supervisord workers), local dev (bin/php wrapper, PhpStorm, direnv pointers, npm ignore-scripts), hardening (web_profiler collect false, nginx 403 on /_profiler, only index.php to php-fpm, php.ini-production, variables_order E, VPN-gated dev vhosts), rotation order and cleanup. Use when: Access denied for user op://, workers crash-loop after deploy, cron or docker exec has no env, 1Password Connect setup, rotating leaked keys, removing .env files from dev machines, profiler leaked $_ENV, npm postinstall scans for .env."
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
---

# Secrets & Environment Hardening

How a DDD app runs — in production containers, on remote dev workspaces and on developer laptops —
without a single plaintext secret on disk, and what else must be closed so the environment cannot be
read back out (profiler, phpinfo, web root, dev vhosts). Distilled from the September 2026 incident:
a public Symfony profiler on dev vhosts served the full `$_ENV` (OpenRouter key, `APP_SECRET`, JWT keys,
DB password) to anyone; a brand-new key was drained two days after creation the same way.

## When to use

- Migrating a project from a plaintext `.env` to 1Password references (Workflow A)
- Container / server side: `Access denied for user 'op://…'`, workers crash-looping, crons or
  `docker exec` without env, TeamCity deploy steps, supervisord (Workflow B, `references/server-runtime.md`)
- Local development without `.env` files: `bin/php`, `.envrc`, PhpStorm, npm (Workflow C, `references/local-development.md`)
- Rotating leaked or aged secrets and cleaning up afterwards (`references/rotation-and-cleanup.md`)
- Hardening: profiler, nginx, php.ini, VPN gating (§ Hardening checklist)

Bundled: `scripts/` (run them), `templates/` (copy, fill `{{PLACEHOLDERS}}`), `references/` (read on demand).

## The model in one picture

```
1Password vault  AUT-<APP>                 item env_secrets-<app>_backend  (one concealed field per KEY)
        │ read-only Connect server (prod container)      │ developer login + Touch ID (laptop)
        ▼                                                 ▼
.env (committed)      KEY=op://$OP_VAULT/env_secrets-<app>_backend/KEY   + all non-secret config, APP_ENV=prod, APP_DEBUG=0
.env.local (ignored)  APP_ENV=dev, APP_DEBUG=1, dev URLs, DEV-ONLY ad-hoc values    ← rsync'd to dev workspaces, never to prod
.envrc (committed)    export OP_VAULT=AUT-<APP>  export OP_ACCOUNT=<org>.1password.com   ← pointers only, never `dotenv`
        │
        ▼  op run --env-file=.env [--env-file=.env.local] -- <process>
   resolved values exist ONLY in that process's env (php-fpm / one console command) and its children
```

Symfony cascade: `.env < .env.local < .env.$APP_ENV < .env.$APP_ENV.local < real env`. The real env
(op run, compose `environment:`) always wins, which is why the prod container also sets `APP_ENV=prod`
and `APP_DEBUG=0` as real env: no file can ever boot prod in debug.

## Critical rules (read before copying anything below)

1. **Never a plaintext secret in any file inside a repo or a dev workspace.** The working copy for
   rotation is `.env_backup` (gitignored, deleted when done). The pre-commit guard enforces it.
2. **`op://` references need `$OP_VAULT` in the PROCESS env.** From compose `environment:` in the
   container, from `.envrc` (direnv) locally. A value in `.env.local` does not reach `op run`.
3. **Only children of `op run` have secrets.** php-fpm via the entrypoint; everything else must go
   through the shell hook: interactive `docker exec … bash` (bashrc), `bash -c` with
   `-e BASH_ENV=/usr/local/bin/op-env-resolve.sh` (crons, deploy steps), `/etc/init.d/supervisor start`
   from such a bash. **`service supervisor start` can never work** — `service(8)` uses `env -i`.
4. **`APP_DEBUG=0`, never `false`.** `(bool)"false"` is `true` in PHP.
5. **`variables_order` must contain `E`** in the container. `Config::getEnv()` reads `$_ENV`;
   `php.ini-production` sets `GPCS` and silently empties it.
6. **Profiler collects nothing by default** — `web_profiler.yaml` dev block:
   `profiler: { collect: false, collect_parameter: 'profile' }`. Stored profiles contain `$_SERVER`, i.e.
   the full env, on disk. `/_profiler` and `/_wdt` return 403 from nginx on every vhost; dev vhosts are VPN-only.
7. **Only `index.php` reaches php-fpm.** `location ~ \.php$` executes any file in `public/` for anyone
   (a build helper there ran `opcache_reset` + `cache:clear` on prod for whoever asked).
8. **`.envrc` holds pointers only.** `dotenv` in `.envrc` exports the whole `.env` into every shell and
   every child process, including `npm install`. The pre-commit guard blocks it.
9. **`npm config set ignore-scripts true`** on every dev machine and CI. Enable a package's install
   script deliberately with `npm rebuild <pkg>`.
10. **DB usernames are secrets** (half of the credential pair): `op://` references, checker rule `^DB_.*_USER$`.
11. **JWT_HASH_KEY == AUTH_JWT_HASH_KEY**, always rotated as a pair. `AUTH_PASSWORD_HASH_KEY` is a live
    pepper when the app uses `hash_hmac` for passwords — check before rotating (`references/rotation-and-cleanup.md`).
12. **Never print a secret value** — in scripts, in tool output, in handoffs. Print lengths, counts, "resolved/placeholder".
13. **Rotation is additive first, destructive last**: new key → deploy → verify → delete old. Exceptions
    (non-additive providers such as Strava client secrets) go in one change with the deploy.
14. Scripts refuse to run without an explicit `--env`/`--file`: the tempting default is the placeholder `.env`.

## Workflow A — migrate a project to 1Password references

1. Create the vault `AUT-<APP>` and decide the item name `env_secrets-<app>_backend`. One item per app,
   one field per key; values are concealed fields.
2. Copy the plaintext `.env` to `.env_backup`; add `.env_backup*`, `.env.bak*`, `.env_bak*` to `.gitignore`;
   add `!/.env` so the placeholder file IS committed.
3. Pick the secret keys. `scripts/op_import_env_secrets.py --env .env_backup --vault AUT-<APP> --item env_secrets-<app>_backend --auto --dry-run`
   prints the candidate list by the name/value rule; adjust with `--keys A,B,C`. Then run it without `--dry-run`.
4. `scripts/op_envify.py --env .env --item env_secrets-<app>_backend --keys <same list>` rewrites the
   committed `.env` to references (`$OP_VAULT` form). Set `APP_ENV=prod`, `APP_DEBUG=0`, prod `PUBLIC_URL` in it.
5. Create `.env.local` from `templates/env.local` (dev flags), `.envrc` from `templates/envrc`, `bin/php`
   from `templates/bin-php`. `direnv allow`.
6. Install the guard: `.githooks/pre-commit` (template) + `.githooks/check-env-secrets.py` (script),
   `git config core.hooksPath .githooks`. Verify: `python3 .githooks/check-env-secrets.py --file .env` → `ok`.
7. Regenerate internals: `scripts/rotate_env_secrets.py --file .env_backup` (after the pepper check),
   then `op_import_env_secrets.py --env .env_backup …` again — the item is a copy of the file.
8. Verify item == file: field count equals the key list, `op item get <item> --vault <vault> --format json`
   shows no empty field. Commit `.env`, `.envrc`, `bin/php`, `.githooks/`, `.gitignore`.
9. Server side: Workflow B. Then the cleanup list in `references/rotation-and-cleanup.md` §5.

## Workflow B — container and server runtime

Full detail in `references/server-runtime.md`; the checklist:

1. Connect server (1Password → Developer → Connect) with READ on `AUT-<APP>` only; `1password-credentials.json`
   for the `connect-api`/`connect-sync` containers; the Connect token as a compose **secret file**.
2. Image: `templates/Dockerfile.snippet` — `op` binary, `connect-entrypoint.sh`, `connect-app-exec.sh`,
   `op-env-resolve.sh` + bashrc line, `php.ini-production` + `variables_order = "EGPCS"`, `expose_php = Off`,
   `disable_functions = phpinfo`. All image variants (xdebug, blackfire) alike.
3. Compose: `templates/docker-compose.php-service.yml` — entrypoint, `OP_ENV_FILE`, `OP_VAULT`, `OP_CONNECT_HOST`,
   `OP_CONFIG_DIR`, `BASH_ENV`, `APP_ENV=prod`, `APP_DEBUG=0`, the secret.
4. Deploy step, cron line, supervisor start: `templates/teamcity-and-cron.sh`. Every `docker exec … bash -c`
   gets `-e BASH_ENV=…` and `--user www-data`.
5. nginx: `templates/nginx.hardening.conf` on prod AND dev vhosts; dev vhosts VPN-only.
6. Verify (`references/server-runtime.md` §8): no `Access denied for user 'op://`, workers RUNNING with
   growing uptime, supervisord's `/proc/<pid>/environ` holds the DB user, no root-owned files in `src/`/`var/`.

Manual work inside a container: `docker exec -it <container> bash` → the hook prints
`[1Password] env resolved: N vars`, plain `php bin/console …` works. In an already open shell:
`. /usr/local/bin/op-env-resolve.sh`.

## Workflow C — developer laptop

`references/local-development.md` (§2 has the exact steps to connect the CLI to the 1Password app: brew, the
"Integrate with 1Password CLI" switch, `OP_ACCOUNT` for multi-account setups, verification commands, failure signatures).
Per developer once: 1Password app + CLI integration, vault access,
`git config core.hooksPath .githooks`, `npm config set ignore-scripts true`, direnv. Per project:
`bin/php bin/console …`, `bin/php vendor/bin/phpunit`, PhpStorm interpreter = `bin/php`.
Delete `var/cache/*/profiler` once. Prefer a dev vault with dev credentials (same item/field names,
different `OP_VAULT`) so laptops never hold prod secrets; without one, the model still removes every
file-based leak, and the remote dev workspace with the shell hook needs nothing on the laptop at all.

## Hardening checklist (prod and every dev vhost)

- [ ] `web_profiler.yaml`: dev `collect: false, collect_parameter: 'profile'`; test `collect: false`; `WebProfilerBundle` not in prod
- [ ] nginx: `/_profiler`, `/_wdt` → 403; only `index.php` → php-fpm; dotfiles denied
- [ ] no PHP file in `public/` except `index.php`
- [ ] dev/staging vhosts reachable only from the VPN
- [ ] container env: `APP_ENV=prod`, `APP_DEBUG=0`; `php.ini-production`; `variables_order` with `E`; `expose_php=Off`; `phpinfo` disabled
- [ ] TeamCity / CI: no parameter holding a plaintext `.env`; deploy steps use `BASH_ENV`, run as `www-data`
- [ ] repo: `.env` secret-free (checker), `.envrc` pointers only, pre-commit guard active for every developer
- [ ] per-consumer reference files where it matters (web vs workers vs crons) so a leak in one process does not expose keys it never needed
- [ ] laptops: `ignore-scripts=true`, no `var/cache/*/profiler` with old env, no `.env_backup` left behind

## Troubleshooting (fast path)

| Symptom | See |
|---|---|
| `Access denied for user 'op://VAULT/item/KEY'@…` | Critical rule 3; `references/server-runtime.md` §1, §9 |
| supervisor workers `RUNNING` but `uptime 0:00:00`, thousands of `exited … exit status 1` | `references/server-runtime.md` §5 |
| `op … can't safely access "/tmp/op"` as www-data | hook sets `OP_CONFIG_DIR=/tmp/op-uid-<uid>`; update the hook |
| `invalid secret reference 'op:///…': vault can't be empty` | `OP_VAULT` missing from the process env (Critical rule 2) |
| `1Password CLI couldn't connect to the desktop app` / `account is not signed in` (laptop) | unlock the app, enable CLI integration, `OP_ACCOUNT` set |
| `[ERROR] multiple accounts found` | `--account <org>.1password.com` or `OP_ACCOUNT` |
| resolved everywhere but `Config::getEnv()` returns null | `variables_order` lacks `E` (rule 5) |
| values shown as `<concealed by 1Password>` | op run masking; expected |
| hook worked, then placeholders after a recreate | hook not in the image — `templates/Dockerfile.snippet` |
| pre-commit says `BLOCKED … DB username must be an op:// reference` | policy: DB users are secrets (rule 10) |

## Cross-Reference

- CLI commands run through the resolved env (`app:db:read`, `app:crons:*`): `ddd-cli-command-specialist`
- Messenger workers, `--no-debug` stale-container trap, supervisor program blocks: `ddd-message-handler-specialist`
- Releasing this skill / shipping it to apps (symlink per app): `ddd-composer-update-version`, `ddd-module-orchestrator`
