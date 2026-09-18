# Local development without secrets on disk

Contents: 1 Model · 2 One-time setup · 3 Per project · 4 IDE · 5 What still leaks and what to do about it · 6 npm

## 1 Model

Secrets exist only in the env of the one PHP process that needs them, fetched just-in-time from
1Password by `op run` with the developer's own 1Password login (Touch ID). No `.env` with values, no
values in the shell, nothing an `npm install` postinstall scanner can collect.

```
.env         committed   KEY=op://$OP_VAULT/<item>/KEY  (+ all non-secret config, APP_ENV=prod, APP_DEBUG=0)
.env.local   gitignored  APP_ENV=dev, APP_DEBUG=1, dev URLs, ad-hoc DEV-ONLY values of unreleased features
.envrc       committed   export OP_VAULT=…  export OP_ACCOUNT=…   (direnv; pointers only, NEVER `dotenv`)
bin/php      committed   exec op run --env-file=.env --env-file=.env.local -- php "$@"
```

`op run` accepts several `--env-file`; later files override earlier ones, mirroring Symfony's cascade.
`$OP_VAULT` inside a reference is expanded from the **process env only** — a value in `.env.local`
does not reach it (verified: `invalid secret reference 'op:///…': vault can't be empty`). Hence `.envrc`.

Without a separate dev vault the laptop resolves PROD secrets — same exposure as the plaintext
`.env` had, minus the file. A dev vault (same item and field names, dev DB user/keys, `OP_VAULT`
switched in `.envrc`) caps the damage of a compromised laptop at dev secrets; recommended.

## 2 One-time per developer

1. **Connect the CLI to 1Password** — no `op signin`, no service account, no token on the laptop:
   - `brew install 1password-cli` (the `op` binary; the container uses the same binary with Connect instead).
   - 1Password app → Settings → Developer → **Integrate with 1Password CLI** (macOS also: enable Touch ID
     for the app). The app then answers every `op` call; the first call per terminal session asks for Touch ID.
   - `op account list` shows the accounts known to the app. With more than one account, `op` needs
     `--account <org>.1password.com` or `OP_ACCOUNT` — that is why `.envrc` exports `OP_ACCOUNT`.
   - Verify access: `op vault list --account <org>.1password.com` lists the app's vault;
     `op item get env_secrets-<app>_backend --vault AUT-<APP> --format json | grep -c '"label"'` counts the fields.
   - Several apps under one account share `OP_ACCOUNT` and differ only in `OP_VAULT` (e.g. `AUT-MYAPP`,
     `AUT-OTHERAPP`) — one `.envrc` per repo, same account line, different vault line.
   - Failure signatures: `1Password CLI couldn't connect to the 1Password desktop app` → app not running or
     integration off; `account is not signed in` → app locked (`op run` itself may still work once unlocked);
     `multiple accounts found` → set `OP_ACCOUNT`.
2. `git config core.hooksPath .githooks` in every repo (pre-commit secret guard).
3. `npm config set ignore-scripts true` (§6).
4. direnv hooked into the shell (`eval "$(direnv hook zsh)"`), then `direnv allow` in the repo.

## 3 Per project

- `bin/php bin/console <cmd>`, `bin/php vendor/bin/phpunit`, `op run --env-file=.env --env-file=.env.local -- symfony serve`.
- Ad-hoc secret for a feature in development → `.env.local` (dev value). When it ships: value into
  the working file → `op_import_env_secrets.py` → `KEY=op://…` in `.env` → line removed from `.env.local`.
- Remote dev workspaces (rsync'd tree on the server, `docker exec -it <container> bash` with the
  shell hook) need nothing on the laptop at all.

## 4 IDE

PhpStorm: Settings → PHP → CLI Interpreter → add `<repo>/bin/php` as the interpreter path. Run
configurations, PHPUnit and the debugger then run under op run. One Touch ID prompt per session.

## 5 What still leaks locally, and the fix

| Leak | Fix |
|---|---|
| **Symfony profiler storage** `var/cache/dev/profiler` — every stored request holds `$_SERVER` = the full env | `web_profiler.yaml` dev block: `profiler: { collect: false, collect_parameter: 'profile' }` (opt in with `?profile=1`); delete existing `var/cache/*/profiler` |
| `.envrc` with `dotenv` — direnv exports the whole `.env` into every shell and child process (this ecosystem shipped one for five months) | pointers only; the pre-commit template blocks `dotenv`/`source_env`/`source_up` |
| `eval "$(op run … export -p)"` in a shell | never locally; the container hook does it deliberately for one exec session |
| same-user processes can read a running process's env (`ps -E`, `/proc`) and can call `op` while the CLI session is unlocked | targeted malware, not a scanner; the dev vault bounds it |
| exception/log context, Sentry, `dump($_SERVER)` | scrub env in log processors; never dump superglobals |

## 6 npm and other package managers

`npm config set ignore-scripts true` globally (user `~/.npmrc`, which covers every nvm version) and in
CI. Lifecycle scripts (`preinstall/install/postinstall/prepare`) of all 1000+ packages then never run;
`npm run <script>` still works (npm ≥ 7), only pre/post hooks of run-scripts are skipped. When a package
genuinely needs its script (binary downloaders: puppeteer, cypress, electron; node-gyp builds without
prebuilds; husky `prepare`), enable it deliberately: `npm rebuild <package>` or `npm run prepare`, and
put that into a `setup` script in the repo. esbuild / @swc/core / vite work without scripts
(platform binaries come via optionalDependencies). Composer runs scripts only from the root package
and plugins only via `allow-plugins` — no equivalent problem.
