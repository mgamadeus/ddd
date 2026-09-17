#!/usr/bin/env python3
"""
Refuse literal secrets in a committed .env file.

The committed .env must hold ONLY op:// references (1Password) for anything
secret. Real values belong in the 1Password item; dev-only / ad-hoc values
belong in the gitignored .env.local.

Two independent checks (a line fails if either fires):
  1. NAME rule  — a key that looks like a secret (…_KEY, …_SECRET, …PASSWORD…,
     …_TOKEN, …_DSN…, PRIVATE_KEY, API_KEY) must be empty or start with op://.
     Known non-secret suffixes (_METHOD, _TTL, _TYPE_, _ID, _HOST, _USER, …)
     are exempt.
  2. VALUE rule — any value that LOOKS like a credential fails regardless of
     its key name: known token prefixes (sk-…, AKIA…, ghp_…, AIza…, -----BEGIN),
     user:pass@ inside a URL/DSN, ≥32 hex chars, or a ≥40-char base64-ish blob.

Never prints values. Exit 1 = findings, exit 0 = clean.

Usage:
  check-env-secrets.py --file path/to/.env
  git show :.env | check-env-secrets.py --name .env
"""

import argparse
import re
import sys

SECRET_NAME = re.compile(r'(_KEY$|_KEY_|_SECRET|PASSWORD|_TOKEN$|_TOKEN_|_DSN|PRIVATE_KEY|API_KEY|_PASS$)')
NAME_EXEMPT = re.compile(r'(_METHOD$|_TTL$|_TYPE_|_ID$|_IDS$|_HOST$|_USER$|_PORT$|_NAME$|_URL$|_EMAIL$|_DIRECTORY$|_NAMESPACE$|_GROUP$|_DATABASE$|_SENTINELS$|_LOCATION$|_TABLES$|_MODE$|_CHARSET$|_VERSION$|_DRIVER$|_ENDPOINT$|_LABEL_ID$)')
TOKEN_PREFIX = re.compile(r'^(sk-[A-Za-z0-9_-]{8,}|AKIA[0-9A-Z]{12,}|ghp_[A-Za-z0-9]{20,}|gh[ousr]_[A-Za-z0-9]{20,}|xox[abp]-[A-Za-z0-9-]{10,}|AIza[0-9A-Za-z_-]{30,}|-----BEGIN )')
USERPASS_IN_URL = re.compile(r'://[^/@\s:]+:[^/@\s]+@')
HEX_BLOB = re.compile(r'^[0-9a-fA-F]{32,}$')
B64ISH = re.compile(r'^[A-Za-z0-9+/=_-]{40,}$')
KV = re.compile(r'^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$')


def unquote(v):
    v = v.strip()
    if len(v) >= 2 and v[0] in "\"'" and v[-1] == v[0]:
        return v[1:-1]
    return v.split(" #", 1)[0].strip()


def looks_like_credential(v):
    if TOKEN_PREFIX.search(v):
        return "value has a known token prefix"
    if USERPASS_IN_URL.search(v):
        return "value embeds user:password@ in a URL/DSN"
    if HEX_BLOB.match(v):
        return f"value is a {len(v)}-char hex blob"
    if B64ISH.match(v) and re.search(r'\d', v) and re.search(r'[A-Z+/=]', v):
        return f"value is a {len(v)}-char base64-like blob"
    return None


def check(lines):
    findings = []
    for n, raw in enumerate(lines, 1):
        m = KV.match(raw.rstrip("\n"))
        if not m:
            continue
        key, val = m.group(1), unquote(m.group(2))
        if val == "" or val.startswith("op://"):
            continue
        # Policy: database usernames are secrets (the other half of the credential pair).
        if re.match(r'^DB_.*_USER$', key):
            findings.append((n, key, "DB username must be an op:// reference (policy: DB users are secrets)"))
            continue
        if SECRET_NAME.search(key) and not NAME_EXEMPT.search(key):
            findings.append((n, key, "secret-named key holds a literal value"))
            continue
        why = looks_like_credential(val)
        if why:
            findings.append((n, key, why))
    return findings


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--file", help="file to check (default: stdin)")
    ap.add_argument("--name", default=None, help="display name when reading stdin")
    a = ap.parse_args()
    if a.file:
        with open(a.file, encoding="utf-8", errors="replace") as fh:
            lines = fh.readlines()
        name = a.file
    else:
        lines = sys.stdin.readlines()
        name = a.name or "<stdin>"

    findings = check(lines)
    if not findings:
        print(f"ok: {name} — no literal secrets ({len(lines)} lines)")
        return 0
    print(f"BLOCKED: {name} contains {len(findings)} literal secret(s):")
    for n, key, why in findings:
        print(f"  line {n:>4}  {key:<45} {why}")
    print("\nFix: move the value into the 1Password item and reference it as KEY=op://...,")
    print("     or, for dev-only values, put it in the gitignored .env.local.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
