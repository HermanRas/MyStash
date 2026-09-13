#!/usr/bin/env bash
# Retakes every screenshot in the README, then copies them into Docs/Assets.
#
# Expects the demo stash from dev/seed_sample_data.sh. It is read-only apart
# from the last two shots, which start a real conversion — so the stash it runs
# against should be the demo one, not a real library.
set -uo pipefail
cd "$(dirname "$0")/.."

DC="docker compose -f docker-compose.yml -f docker-compose.dev.yml"

$DC exec -T -e NODE_PATH=/opt/pwlib/node_modules playwright node /work/shot_readme.js
status=$?

if [ "$status" = "0" ]; then
  mkdir -p Docs/Assets/README
  cp dev/playwright/screenshots/readme/*.png Docs/Assets/README/
  echo "copied $(ls Docs/Assets/README/*.png | wc -l) screenshots into Docs/Assets/README"
fi

exit "$status"
