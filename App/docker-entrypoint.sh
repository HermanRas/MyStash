#!/usr/bin/env bash
# Starts php-fpm and nginx in one container, and makes the container's life
# depend on both of them.
#
# The failure mode worth guarding against is half a container: nginx still
# answering on 8080 while php-fpm is dead, so every page is a 502 and Docker
# reports the service as up. `wait -n` returns as soon as *either* process
# exits, and the container then exits too — which is what `restart:
# unless-stopped` needs in order to mean anything.
set -uo pipefail

php-fpm --nodaemonize &
FPM=$!

nginx -g 'daemon off;' &
NGINX=$!

# Stop the other one on the way out, whether that is a crash or a `docker
# compose stop` — which signals this process, not the children.
#
# SIGQUIT is in the list because the php:8.3-fpm base image declares
# STOPSIGNAL SIGQUIT, so that, not SIGTERM, is what `docker compose stop`
# actually sends. Without it the shutdown is a ten-second wait for Docker's
# grace period to expire followed by SIGKILL — which is exactly the mid-write
# kill that Docs/DEPLOY.md §7 tells you to avoid before a backup.
shutdown() {
  kill -QUIT "$FPM" "$NGINX" 2>/dev/null
  wait "$FPM" "$NGINX" 2>/dev/null
  exit 0
}
trap shutdown QUIT TERM INT

wait -n
STATUS=$?
echo "mystash: php-fpm or nginx exited ($STATUS) — stopping the container" >&2
kill -QUIT "$FPM" "$NGINX" 2>/dev/null
exit "$STATUS"
