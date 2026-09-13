#!/usr/bin/env bash
# 7.0.2 — deleting a stash, end to end through the Profile screen.
#
# Creates its own throwaway stash and expects the test to destroy it. The
# cleanup is a safety net for a failed run, not the normal path.
set -uo pipefail
cd "$(dirname "$0")/.."

PROBE="DelProbe$(openssl rand -hex 3)"
PASS="probe-delete-password-aaaaaaaa"

cleanup() {
  # Only fires if the test failed before deleting it.
  if [ -d "App/Data/${PROBE}" ]; then
    rm -rf "App/Data/${PROBE}"
    echo "cleaned up leftover stash ${PROBE} (the test did not delete it)"
  fi
}
trap cleanup EXIT

docker compose exec -T -u www-data php php -r '
require "/app/src/User.php";
exit((new MyStash\User())->create($argv[1], $argv[2]) ? 0 : 1);
' "${PROBE}" "${PASS}" || { echo "FAIL: could not create the throwaway stash"; exit 1; }

echo "created throwaway stash ${PROBE}"

docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T \
  -e NODE_PATH=/opt/pwlib/node_modules \
  -e PROBE_USER="${PROBE}" -e PROBE_PASSWORD="${PASS}" \
  playwright node /work/check_stash_delete.js
