# ---- cron on the Docker host: resolve the env via BASH_ENV, run as www-data ----
*/5 * * * * root docker exec --user www-data -e BASH_ENV=/usr/local/bin/op-env-resolve.sh {{CONTAINER}} bash -c '{{APP_DIR}}/bin/console app:crons:execute' > /dev/null 2>&1

# ---- deploy step (TeamCity), runs against the OLD container before its restart ----
set -e
cd {{APP_DIR_HOST}} && mkdir -p var/cache && chown -R www-data:www-data var && chmod -R 775 var
docker exec -t --user www-data -e BASH_ENV=/usr/local/bin/op-env-resolve.sh {{CONTAINER}} bash -c "cd {{APP_DIR}} && php bin/console cache:clear --env=prod --no-debug"
docker exec -t --user www-data -e BASH_ENV=/usr/local/bin/op-env-resolve.sh {{CONTAINER}} bash -c "cd {{APP_DIR}}/bin && ./console app:generate-doctrine-models-for-entities"
docker restart {{CONTAINER}}
# Do NOT: run a php file from public/ as a build helper (web-reachable), use shell_exec wrappers
# (exit codes lost), or `sed` APP_DEBUG=true->false (\"false\" is truthy; the committed .env is prod-safe).

# ---- supervisord (messenger workers) after the restart ----
# NEVER `service supervisor start`: /usr/sbin/service runs the init script through `env -i`,
# supervisord then has 18 locale vars and nothing else, and every worker crash-loops on the
# op:// placeholders. Call the init script directly from a bash that resolved the env:
docker exec -e BASH_ENV=/usr/local/bin/op-env-resolve.sh {{CONTAINER}} bash -c '/etc/init.d/supervisor start'
# verify: docker exec {{CONTAINER}} supervisorctl status | awk '{print $2}' | sort | uniq -c   -> N RUNNING, uptime growing
# Structural fix: make supervisord the container's main process under connect-entrypoint.sh
# (command: ["supervisord","-n","-c","/etc/supervisor/supervisord.conf"], php-fpm as a program).
