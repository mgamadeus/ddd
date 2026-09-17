#!/usr/bin/env python3
"""
Import the SECRET values of a plaintext env file into ONE 1Password item
(Secure Note, one concealed field per key), so references resolve as
    op://<VAULT>/<ITEM>/<KEY>

The item is a COPY of the file: run it again after every change to the file.
Values are never printed.

Usage:
  op_import_env_secrets.py --env .env_backup --vault AUT-MYAPP --item env_secrets-myapp_backend \
      --account myorg.1password.com --keys APP_SECRET,DB_DEFAULT_CONNECTION_PASSWORD,... [--dry-run]
  op_import_env_secrets.py --env .env_backup --vault ... --item ... --auto      # keys by name/value rule

--auto = the same rule as check-env-secrets.py: secret-named keys (…_KEY, …_SECRET, …PASSWORD…,
…_TOKEN, …_DSN…), DB_*_USER (policy: DB usernames are secrets), or values that look like credentials.
Review the printed key list before running without --dry-run.
"""
import argparse, os, re, subprocess, sys

SECRET_NAME = re.compile(r'(_KEY$|_KEY_|_SECRET|PASSWORD|_TOKEN$|_TOKEN_|_DSN|PRIVATE_KEY|API_KEY|_PASS$)')
NAME_EXEMPT = re.compile(r'(_METHOD$|_TTL$|_TYPE_|_ID$|_IDS$|_HOST$|_PORT$|_NAME$|_URL$|_EMAIL$|_DIRECTORY$|_NAMESPACE$|_GROUP$|_DATABASE$|_SENTINELS$|_LOCATION$|_TABLES$|_MODE$|_CHARSET$|_VERSION$|_DRIVER$|_ENDPOINT$|_LABEL_ID$)')
TOKEN_PREFIX = re.compile(r'^(sk-[A-Za-z0-9_-]{8,}|AKIA[0-9A-Z]{12,}|ghp_[A-Za-z0-9]{20,}|gh[ousr]_[A-Za-z0-9]{20,}|xox[abp]-[A-Za-z0-9-]{10,}|AIza[0-9A-Za-z_-]{30,}|-----BEGIN )')
USERPASS_IN_URL = re.compile(r'://[^/@\s:]+:[^/@\s]+@')
HEX_BLOB = re.compile(r'^[0-9a-fA-F]{32,}$')
B64ISH = re.compile(r'^[A-Za-z0-9+/=_-]{40,}$')
KEY_RE = re.compile(r'^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$')


def parse_env(path):
    with open(path, encoding="utf-8") as fh:
        lines = fh.read().splitlines()
    values, i, n = {}, 0, len(lines)
    while i < n:
        raw = lines[i]; i += 1
        if not raw.strip() or raw.lstrip().startswith("#"):
            continue
        m = KEY_RE.match(raw)
        if not m:
            continue
        key, rest = m.group(1), m.group(2)
        if rest[:1] in ('"', "'"):
            q = rest[0]; body = rest[1:]
            if body.endswith(q) and len(body) >= 1:
                val = body[:-1]
            else:
                parts = [body]
                while i < n:
                    nxt = lines[i]; i += 1
                    if nxt.endswith(q):
                        parts.append(nxt[:-1]); break
                    parts.append(nxt)
                val = "\n".join(parts)
        else:
            val = rest.split(" #", 1)[0].rstrip()
        values[key] = val
    return values


def is_secret(key, val):
    if val == "" or val.startswith("op://"):
        return False
    if re.match(r'^DB_.*_USER$', key):
        return True
    if SECRET_NAME.search(key) and not NAME_EXEMPT.search(key):
        return True
    return bool(TOKEN_PREFIX.search(val) or USERPASS_IN_URL.search(val) or HEX_BLOB.match(val)
                or (B64ISH.match(val) and re.search(r'\d', val) and re.search(r'[A-Z+/=]', val)))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--env", required=True)
    ap.add_argument("--vault", required=True)
    ap.add_argument("--item", required=True)
    ap.add_argument("--account", default=os.environ.get("OP_ACCOUNT"))
    ap.add_argument("--keys", help="comma-separated keys to import")
    ap.add_argument("--auto", action="store_true", help="pick keys by the secret name/value rule")
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args()
    if not (a.keys or a.auto):
        sys.exit("give --keys A,B,C or --auto")
    env = parse_env(a.env)
    keys = [k.strip() for k in a.keys.split(",")] if a.keys else [k for k, v in env.items() if is_secret(k, v)]
    present = [k for k in keys if env.get(k, "") != "" and not env[k].startswith("op://")]
    skipped = [k for k in keys if k not in present]
    print(f"env: {a.env}\nvault: {a.vault}\nitem: {a.item}\naccount: {a.account or '(default)'}")
    print(f"\nwill store {len(present)} field(s) (values never shown):")
    for k in present: print(f"  [ok]    {k}{'  [multi-line]' if chr(10) in env[k] else ''}")
    for k in skipped: print(f"  [skip]  {k}  (absent, empty or already op://)")
    if a.dry_run:
        print("\n--dry-run: nothing written."); return
    op = ["op"] + (["--account", a.account] if a.account else [])
    exists = subprocess.run(op + ["item", "get", a.item, "--vault", a.vault, "--format", "json"],
                            capture_output=True, text=True).returncode == 0
    fields = [f"{k}[password]={env[k]}" for k in present]
    cmd = op + (["item", "edit", a.item, "--vault", a.vault] if exists else
                ["item", "create", "--category", "Secure Note", "--vault", a.vault, "--title", a.item]) + fields
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        sys.stderr.write(r.stderr); sys.exit(f"op failed (exit {r.returncode})")
    print(f"\nItem {'updated' if exists else 'created'}: op://{a.vault}/{a.item}  ({len(fields)} fields)")


if __name__ == "__main__":
    main()
