#!/bin/sh
set -eu

# Opt-in wrapper: Compose must explicitly select this entrypoint.
token_file=${OP_CONNECT_TOKEN_FILE:-/run/secrets/op_connect_token}
references_file=${OP_ENV_FILE:-/run/config/app.env.references}
: "${OP_CONNECT_HOST:?OP_CONNECT_HOST must point to the local Connect API}"
if [ ! -r "$token_file" ] || [ ! -s "$token_file" ]; then
    echo 'FAIL: Connect token file is missing, empty or unreadable.' >&2
    exit 1
fi
if [ ! -r "$references_file" ] || [ ! -s "$references_file" ]; then
    echo 'FAIL: Secret reference template is missing, empty or unreadable.' >&2
    exit 1
fi
# op run must inject SECRETS ONLY. It exports every key of the file it is given as a real env var,
# and real env outranks every .env* file inside PHP — feeding it the whole .env would let the
# committed prod flags (APP_ENV, APP_DEBUG, URLs) override .env.local in dev workspaces.
# So: filter the op:// reference lines out of the app .env into a private references file.
# Everything else stays with Symfony Dotenv (.env < .env.local < ... < real env).
refs_dir=/run/op; mkdir -p "$refs_dir"; chmod 755 "$refs_dir"
refs_only="$refs_dir/app.env.references"
grep -E '^[[:space:]]*(export[[:space:]]+)?[A-Za-z_][A-Za-z0-9_]*=.*op://' "$references_file" > "$refs_only" || true
chmod 644 "$refs_only"
if [ ! -s "$refs_only" ]; then
    echo "FAIL: no op:// references found in $references_file." >&2
    exit 1
fi
echo "INFO: $(wc -l < "$refs_only") op:// reference(s) from $references_file -> $refs_only (secrets only)" >&2

OP_CONNECT_TOKEN=$(cat "$token_file")
if [ -z "$OP_CONNECT_TOKEN" ]; then
    echo 'FAIL: Connect token is empty.' >&2
    exit 1
fi
export OP_CONNECT_TOKEN

# Check local availability only: upstream health must not gate cached reads.
attempt=0
until curl --fail --silent --output /dev/null --connect-timeout 1 --max-time 2 \
    "${OP_CONNECT_HOST%/}/heartbeat"; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 20 ]; then
        echo 'FAIL: Local Connect API unavailable after bounded startup wait.' >&2
        exit 1
    fi
    sleep 1
done

# A heartbeat is not proof of synchronization or token/vault access. Resolve
# every configured reference before allowing the application to start. Local
# cached data is sufficient: do not require a fresh sync with 1password.com.
attempt=1
while :; do
    if timeout --signal=TERM --kill-after=5s 15s \
        op run --env-file="$refs_only" -- /bin/true >/dev/null 2>&1; then
        break
    else
        probe_status=$?
    fi
    if [ "$attempt" -ge 12 ]; then
        echo 'FAIL: Connect cannot resolve required references after bounded readiness wait. Check token scope, references and cache/sync state.' >&2
        exit "$probe_status"
    fi
    if [ "$attempt" -eq 1 ]; then
        echo 'Waiting for Connect to resolve required references...' >&2
    fi
    attempt=$((attempt + 1))
    sleep 2
done

echo 'PASS: 1Password Connect resolved all references; starting PHP.' >&2

if [ "$#" -eq 0 ]; then
    set -- php-fpm -F
fi
# Keep masking enabled. Never apply the pilot's 90-second lifetime limit to PHP.
# Resolve again for the actual child; this final call must also succeed.
exec op run --env-file="$refs_only" -- /usr/local/bin/connect-app-exec.sh "$@"
