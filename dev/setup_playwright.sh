#!/usr/bin/env bash
# One-time (per machine) setup for the playwright container.
#
# The mcr.microsoft.com/playwright image ships the *browsers* at /ms-playwright
# but not the playwright npm package, so every dev/run_*_check.sh runs its
# script with NODE_PATH=/opt/pwlib/node_modules and something has to put the
# library there. That somewhere used to be a bind mount inside the repository,
# which meant node_modules was uploaded to Google Drive and synced to every
# other machine; it is a Docker named volume now, so this script is what fills
# it after a fresh clone or a `docker compose down -v`.
#
# The version is pinned to the image's, so the library finds the browsers the
# image already carries and downloads nothing.
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION="1.47.0"
DC="docker compose -f docker-compose.yml -f docker-compose.dev.yml"

$DC up -d playwright

if $DC exec -T playwright test -d /opt/pwlib/node_modules/playwright; then
  echo "playwright ${VERSION} is already installed in the volume"
  exit 0
fi

$DC exec -T playwright npm install --prefix /opt/pwlib --no-fund --no-audit \
  "playwright@${VERSION}"

$DC exec -T -e NODE_PATH=/opt/pwlib/node_modules playwright \
  node -e 'require("playwright").chromium.launch().then(b => b.close()).then(() => console.log("chromium launches"))'
