#!/usr/bin/env bash
# Runs the 6.1 password-change browser check against a throwaway stash that
# this script creates and removes.
#
# Changing a password re-encrypts every archive, so this must never be pointed
# at a real user. The stash it makes is empty, named PwProbe<random>, and is
# deleted even if the test fails.
set -uo pipefail
cd "$(dirname "$0")/.."

PROBE="PwProbe$(openssl rand -hex 3)"
OLD="probe-old-password-aaaaaaaa"
NEW="probe-new-password-bbbbbbbb"

cleanup() {
  rm -rf "App/Data/${PROBE}"
  echo "removed throwaway stash ${PROBE}"
}
trap cleanup EXIT

docker compose exec -T -u www-data app php -r '
require "/app/src/User.php";
exit((new MyStash\User())->create($argv[1], $argv[2]) ? 0 : 1);
' "${PROBE}" "${OLD}" || { echo "FAIL: could not create the throwaway stash"; exit 1; }

echo "created throwaway stash ${PROBE}"

docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T \
  -e NODE_PATH=/opt/pwlib/node_modules \
  -e PROBE_USER="${PROBE}" -e PROBE_OLD="${OLD}" -e PROBE_NEW="${NEW}" \
  playwright node /work/check_password_change.js
