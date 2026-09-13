#!/usr/bin/env bash
# Slices a generated icon sheet into the transparent PNGs the UI loads
# (Docs/SPECIFICATIONS.md §4.0).
#
# The sheets come out of Gemini as a 4x4 grid on a flat background — black on
# the first sheet, white on icons_v2.png, which turned out not to matter: the
# amber keys off either one just as cleanly, so a sheet does not need
# regenerating for its background alone.
#
# Three passes per cell:
#   1. crop the 256x256 cell out of the 1024x1024 sheet;
#   2. find the icon's real bounds inside it, so every icon ends up trimmed to
#      its own ink rather than to whatever padding the generator left — without
#      this, icons render at wildly different visual weights for the same
#      --icon-size;
#   3. key the background out and normalise the longest edge to 96px.
#
# ffmpeg lives in the php container, so the work happens there; Docs/ is not
# mounted into it, hence the copy in.
set -euo pipefail
cd "$(dirname "$0")/.."

SHEET="${1:-Docs/Assets/icons_v2.png}"
KEY="${2:-0xFFFFFF}"          # 0x000000 for a black sheet
OUT="App/public/assets/img/icons"

# Cell names, left to right and top to bottom. An empty name skips the cell,
# which is what the mostly-blank v3 sheet needs.
NAMES=(
  upload   download  creator   sort-az
  sort-za  sort-09   sort-90   tag
  user     login     logout    register
  videos   expand    collapse  playlist
)

if [ "$(basename "$SHEET")" = "icons_v3.png" ]; then
  NAMES=(filter video-delete creator-delete "" "" "" "" "" "" "" "" "" "" "" "" "")
fi

docker compose cp "$SHEET" php:/tmp/sheet.png >/dev/null
echo "slicing $(basename "$SHEET") (keying ${KEY})"

for i in "${!NAMES[@]}"; do
  name="${NAMES[$i]}"
  [ -z "$name" ] && continue

  x=$(( (i % 4) * 256 ))
  y=$(( (i / 4) * 256 ))

  docker compose exec -T php ffmpeg -v error -y -i /tmp/sheet.png \
    -vf "crop=256:256:${x}:${y}" /tmp/cell.png

  # The icon's own bounds. cropdetect looks for *dark* borders, so a light
  # sheet has to be negated first and a dark one must not be — negating a
  # black sheet made it report the whole cell every time, which silently
  # produced icons at a quarter of the intended size.
  #
  # It also needs more than one frame before it will report anything, which is
  # what the loop is for.
  PRE=""
  [ "$KEY" = "0xFFFFFF" ] && PRE="negate,"

  BOX=$(docker compose exec -T php sh -c \
    "ffmpeg -v info -loop 1 -t 0.4 -i /tmp/cell.png -vf '${PRE}cropdetect=limit=0.08:round=2:reset=0' -f null - 2>&1 | grep -o 'crop=[0-9:]*' | tail -1")
  BOX="${BOX#crop=}"
  BOX="$(printf '%s' "$BOX" | tr -d '\r')"

  if [ -z "$BOX" ]; then
    echo "  SKIP ${name}: nothing found in that cell"
    continue
  fi

  docker compose exec -T php ffmpeg -v error -y -i /tmp/cell.png \
    -vf "crop=${BOX},colorkey=${KEY}:0.18:0.02,scale='if(gt(iw,ih),96,-1)':'if(gt(iw,ih),-1,96)'" \
    "/tmp/icon-${name}.png"

  docker compose cp "php:/tmp/icon-${name}.png" "${OUT}/${name}.png" >/dev/null
  echo "  ${name}.png  (from ${BOX})"
done

echo "done — $(ls -1 ${OUT} | wc -l) icons in ${OUT}"
