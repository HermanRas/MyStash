#!/usr/bin/env bash
# Lifts the screenshots out of the playwright container so you can look at them.
#
# They are written into a Docker named volume rather than into the repository,
# because the repository is a Google Drive-synced tree and a checkup that
# rewrites forty PNGs should not cost forty uploads. This is the opt-in step
# that brings them back: dev/playwright/screenshots by default (gitignored), or
# any directory given as the first argument.
set -euo pipefail
cd "$(dirname "$0")/.."

DEST="${1:-dev/playwright/screenshots}"
DC="docker compose -f docker-compose.yml -f docker-compose.dev.yml"

mkdir -p "$DEST"
$DC cp playwright:/work/screenshots/. "$DEST/"
echo "copied $(find "$DEST" -name '*.png' | wc -l) screenshots into ${DEST}"
