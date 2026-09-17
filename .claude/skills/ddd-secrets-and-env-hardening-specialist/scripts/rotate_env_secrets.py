#!/usr/bin/env python3
"""
Regenerate the app-INTERNAL secrets of a plaintext env file in place (64 hex chars each),
keeping mirrored pairs identical. A timestamped backup of the file is written first.

Default keys (mgamadeus/ddd apps):
  APP_SECRET ENCRYPTION_COOKIE_PASSWORD JWT_HASH_KEY AUTH_JWT_HASH_KEY PASSWORD_HASH AUTH_PASSWORD_HASH_KEY
Default mirror: JWT_HASH_KEY == AUTH_JWT_HASH_KEY (the framework reads both; they MUST match).

!! AUTH_PASSWORD_HASH_KEY: only rotate it when the app hashes passwords with Argon2id/bcrypt
!! (password_hash). If the app uses hash_hmac(..., AUTH_PASSWORD_HASH_KEY) the key is a live
!! pepper — rotating it invalidates EVERY stored password. Check AuthService/Account::verifyPassword first.
!! PASSWORD_HASH salts the Encrypt helper: rotate only if no DB column uses enryptionScope (sic).

Usage:
  rotate_env_secrets.py --file .env_backup [--keys A,B,C] [--mirror JWT_HASH_KEY=AUTH_JWT_HASH_KEY] [--dry-run]
Afterwards: op_import_env_secrets.py --env .env_backup ... (the item is a copy of the file).
"""
import argparse, datetime, re, secrets, shutil

DEFAULT_KEYS = ["APP_SECRET", "ENCRYPTION_COOKIE_PASSWORD", "JWT_HASH_KEY", "AUTH_JWT_HASH_KEY",
                "PASSWORD_HASH", "AUTH_PASSWORD_HASH_KEY"]
DEFAULT_MIRROR = {"AUTH_JWT_HASH_KEY": "JWT_HASH_KEY"}   # target: source


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--file", required=True)
    ap.add_argument("--keys", default=",".join(DEFAULT_KEYS))
    ap.add_argument("--mirror", action="append", default=None, help="TARGET=SOURCE (repeatable)")
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args()
    keys = [k.strip() for k in a.keys.split(",") if k.strip()]
    mirror = dict(m.split("=", 1) for m in a.mirror) if a.mirror else DEFAULT_MIRROR
    new = {k: secrets.token_hex(32) for k in keys if k not in mirror}
    for target, source in mirror.items():
        if target in keys:
            new[target] = new.setdefault(source, secrets.token_hex(32))
    with open(a.file, encoding="utf-8") as fh:
        lines = fh.readlines()
    out, done = [], []
    for line in lines:
        m = re.match(r'^(\s*(?:export\s+)?)([A-Za-z_][A-Za-z0-9_]*)(\s*=\s*)(.*)$', line.rstrip("\n"))
        if m and m.group(2) in new:
            out.append(f"{m.group(1)}{m.group(2)}{m.group(3)}{new[m.group(2)]}\n"); done.append(m.group(2))
        else:
            out.append(line)
    for k in done: print(f"  [new]   {k}{'  (mirror of ' + mirror[k] + ')' if k in mirror else ''}")
    for k in sorted(set(new) - set(done)): print(f"  [none]  {k}  (not in file — add the line first)")
    if a.dry_run:
        print("--dry-run: nothing written."); return
    bak = f"{a.file}.bak.{datetime.datetime.now():%Y%m%d-%H%M%S}"
    shutil.copy2(a.file, bak)
    with open(a.file, "w", encoding="utf-8") as fh:
        fh.writelines(out)
    print(f"backup: {bak}  (holds the OLD values — delete after the new ones are live)\nrewrote {a.file}: {len(done)} key(s).")


if __name__ == "__main__":
    main()
