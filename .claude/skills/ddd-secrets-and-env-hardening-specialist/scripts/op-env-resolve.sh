# 1Password: resolve the op:// references of $OP_ENV_FILE into THIS shell.
#
# Sourced from /etc/bash.bashrc (interactive: docker exec -it … bash) and usable
# as BASH_ENV for non-interactive shells (crons, init scripts):
#   docker exec -e BASH_ENV=/usr/local/bin/op-env-resolve.sh <c> bash -c '…'
# Works for root and www-data. Idempotent (OP_ENV_RESOLVED). Never prints values.
# Non-fatal: if Connect is unreachable the shell still runs, with a warning on stderr.
if [ -z "${OP_ENV_RESOLVED:-}" ] && [ -n "${OP_ENV_FILE:-}" ] && command -v op >/dev/null 2>&1; then
  _op_token_file="${OP_CONNECT_TOKEN_FILE:-/run/secrets/op_connect_token}"
  if [ -r "$_op_token_file" ]; then
    # op needs a config dir owned by the current user; the container default (/tmp/op) is root's.
    if [ ! -w "${OP_CONFIG_DIR:-/nonexistent}" ] || [ ! -O "${OP_CONFIG_DIR:-/nonexistent}" ]; then
      export OP_CONFIG_DIR="/tmp/op-uid-$(id -u)"
    fi
    # secrets only: feed op run just the op:// lines, never the whole .env (APP_ENV etc. stay with Dotenv)
    _op_refs="$(mktemp)"; grep -E '^[[:space:]]*(export[[:space:]]+)?[A-Za-z_][A-Za-z0-9_]*=.*op://' "$OP_ENV_FILE" > "$_op_refs"
    _op_exports="$(OP_CONNECT_TOKEN="$(cat "$_op_token_file")" op run --no-masking --env-file="$_op_refs" -- sh -c "export -p" 2>/dev/null \
      | grep "^export " \
      | grep -Ev "^export (PWD|OLDPWD|SHLVL|_|HOME|PATH|TERM|HOSTNAME|OP_CONNECT_TOKEN)=")"
    if [ -n "$_op_exports" ]; then
      eval "$_op_exports"
      export OP_ENV_RESOLVED=1
      case "$-" in *i*) echo "[1Password] secrets resolved: $(printf "%s\n" "$_op_exports" | grep -c "^export ") vars from the op:// lines of $OP_ENV_FILE";; esac
    else
      echo "[1Password] WARNING: could not resolve $OP_ENV_FILE (Connect unreachable? config dir?) - php would see op:// placeholders" >&2
    fi
    rm -f "$_op_refs"; unset _op_exports _op_refs
  fi
  unset _op_token_file
fi
# Interactive shells only: start in the app directory (= where the reference file lives)
case "$-" in *i*) [ ! -f bin/console ] && [ -n "${OP_ENV_FILE:-}" ] && [ -d "$(dirname "$OP_ENV_FILE")" ] && cd "$(dirname "$OP_ENV_FILE")";; esac
