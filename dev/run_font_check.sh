#!/usr/bin/env bash
# The display face is served by this app and by nothing else.
#
# Read-only: it signs in to TestUser and looks at pages. Nothing is created,
# so there is no throwaway stash to clean up.
set -uo pipefail
cd "$(dirname "$0")/.."

DC="docker compose -f docker-compose.yml -f docker-compose.dev.yml"

$DC exec -T -e NODE_PATH=/opt/pwlib/node_modules playwright node /work/check_font.js
fails=$?

[ "$fails" = "0" ] && echo "run_font_check: all passed" || echo "run_font_check: failed"
exit "$fails"
