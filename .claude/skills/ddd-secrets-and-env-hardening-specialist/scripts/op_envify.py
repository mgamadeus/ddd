#!/usr/bin/env python3
"""
Rewrite an env file IN PLACE: replace the VALUES of the given keys with 1Password
references  KEY=op://$OP_VAULT/<ITEM>/KEY  and leave everything else untouched.
A plaintext backup is written first (default: <env>_backup) — that backup becomes
the working file for rotation; delete it when the item is the only source.

Usage:
  op_envify.py --env .env --item env_secrets-myapp_backend --keys APP_SECRET,DB_DEFAULT_CONNECTION_PASSWORD,...
  op_envify.py --env .env --item ... --keys ... --dry-run
  op_envify.py --env .env --item ... --keys ... --vault-ref AUT-MYAPP   # literal vault instead of $OP_VAULT
"""
import argparse, os, re, shutil, sys

KEY_RE = re.compile(r'^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$', re.S)


def transform(lines, keys, item, vault_ref):
    out, switched, empty, i, n = [], [], [], 0, len(lines)
    while i < n:
        line = lines[i]; i += 1
        m = KEY_RE.match(line)
        if not m or m.group(1) not in keys:
            out.append(line); continue
        key, rest = m.group(1), m.group(2)
        if rest.strip().strip('"').strip("'") == "" or rest.strip().startswith("op://"):
            out.append(line); empty.append(key); continue
        if rest[:1] in ('"', "'"):                      # skip continuation lines of a multi-line value
            q = rest[0]; closed = len(rest) >= 2 and rest.rstrip("\n").endswith(q)
            while not closed and i < n:
                closed = lines[i].rstrip("\n").endswith(q); i += 1
        out.append(f"{key}=op://{vault_ref}/{item}/{key}\n"); switched.append(key)
    return out, switched, empty


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--env", required=True)
    ap.add_argument("--item", required=True)
    ap.add_argument("--keys", required=True, help="comma-separated keys to switch to references")
    ap.add_argument("--vault-ref", default="$OP_VAULT", help="vault in the reference (default: $OP_VAULT, resolved from the process env)")
    ap.add_argument("--backup", default=None)
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args()
    keys = {k.strip() for k in a.keys.split(",")}
    backup = a.backup or a.env + "_backup"
    with open(a.env, encoding="utf-8") as fh:
        lines = fh.readlines()
    out, switched, empty = transform(lines, keys, a.item, a.vault_ref)
    for k in switched: print(f"  [ref]   {k}")
    for k in empty: print(f"  [as-is] {k}  (empty or already a reference)")
    for k in sorted(keys - set(switched) - set(empty)): print(f"  [none]  {k}  (not in file)")
    if a.dry_run:
        print("--dry-run: nothing written."); return
    if os.path.exists(backup):
        sys.exit(f"backup exists: {backup} — move it away first")
    shutil.copy2(a.env, backup)
    tmp = a.env + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        fh.writelines(out)
    os.replace(tmp, a.env)
    print(f"backup (plaintext!): {backup}\nrewrote {a.env}: {len(switched)} reference(s). Needs OP_VAULT in the process env unless --vault-ref is literal.")


if __name__ == "__main__":
    main()
