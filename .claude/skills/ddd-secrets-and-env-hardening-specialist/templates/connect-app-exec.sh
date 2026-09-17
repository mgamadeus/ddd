#!/bin/sh
set -eu

# The supervising CLI keeps its token; the application does not inherit it.
unset OP_CONNECT_TOKEN OP_CONNECT_HOST OP_CONNECT_TOKEN_FILE OP_ENV_FILE

# Preserve the official PHP image initialization and argument handling.
# PHP-FPM's master retains its normal identity; its pool configuration controls
# worker privileges. Protect the mounted token against the worker UID.
exec docker-php-entrypoint "$@"
