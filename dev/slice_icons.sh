#!/usr/bin/env bash
# Slices a generated icon sheet into the transparent PNGs the UI loads
# (Docs/SPECIFICATIONS.md §4.0).
#
# The sheets come out of Gemini as a 4x4 grid of flat amber icons. Successive
# revisions have arrived on black, on white, and on a noisy near-black with
# faint grid lines, so nothing here may assume a background colour beyond the
# one it is told to key.
#
# Per cell:
#   1. crop the 256x256 cell, inset a little to drop the grid line;
#   2. key the background out;
#   3. find the icon's bounds FROM THE RESULTING ALPHA, not from the colour
#      image. An earlier version ran cropdetect over the colour cell and was
#      quietly wrong — the sheet's background is not uniform (JPEG-ish noise
#      around #141503), so the luma threshold clipped real ink and shipped a
#      register icon with its lower half missing. The alpha channel after
#      keying is a clean two-value mask, and cropdetect on that cannot
#      disagree with what will actually be drawn;
#   4. crop to those bounds plus a small margin, and normalise the longest
#      edge to 96px so every icon carries the same weight at one --icon-size.
#
# ffmpeg lives in the php container, and Docs/ is not mounted into it, hence
# the copy in.
set -euo pipefail
cd "$(dirname "$0")/.."

SHEET="${1:-Docs/Assets/icons_v2.png}"
KEY="${2:-0x000000}"          # 0xFFFFFF for a white sheet
OUT="App/public/assets/img/icons"

# Enough to swallow the background's noise without reaching the amber. The
# icons' own black cut-outs (the film strips' sprockets and play triangles) go
# transparent too, which is what they should do on a dark UI.
TOL="0.22:0.06"

INSET=6                        # px of grid line to drop on each side
MARGIN=3                       # px of breathing room to add back after trimming

NAMES=(
  upload   download  creator   sort-az
  sort-za  sort-09   sort-90   tag
  user     login     logout    register
  videos   expand    collapse  playlist
)

if [ "$(basename "$SHEET")" = "icons_v3.png" ]; then
  # Names follow the sheet's own prompt, which lists what each cell was asked
  # for (Docs/Assets/gemini_asset prompt.md, "site icons v3").
  NAMES=(
    filter          video-delete  creator-delete   video-save
    video-edit      category-add  image-upload     capture
    cancel          recolour      category-delete  playlist-add
    playlist-delete creator-save  playlist-rename  video-remove
  )
fi

docker compose cp "$SHEET" app:/tmp/sheet.png >/dev/null
echo "slicing $(basename "$SHEET"), keying ${KEY}"

for i in "${!NAMES[@]}"; do
  name="${NAMES[$i]}"
  [ -z "$name" ] && continue

  x=$(( (i % 4) * 256 + INSET ))
  y=$(( (i / 4) * 256 + INSET ))
  side=$(( 256 - INSET * 2 ))

  # 1 + 2: the cell, with the background already gone.
  docker compose exec -T app ffmpeg -v error -y -i /tmp/sheet.png \
    -vf "crop=${side}:${side}:${x}:${y},format=rgba,colorkey=${KEY}:${TOL}" /tmp/keyed.png

  # 3: bounds from the alpha. cropdetect needs more than one frame before it
  # reports, which is what the loop is for.
  BOX=$(docker compose exec -T app sh -c \
    "ffmpeg -v info -loop 1 -t 0.4 -i /tmp/keyed.png -vf 'alphaextract,cropdetect=limit=0:round=2:reset=0' -f null - 2>&1 | grep -o 'crop=[0-9]*:[0-9]*:[0-9]*:[0-9]*' | tail -1")
  BOX="$(printf '%s' "${BOX#crop=}" | tr -d '\r')"

  if [ -z "$BOX" ]; then
    echo "  skip ${name} — nothing opaque in that cell"
    continue
  fi

  IFS=: read -r cw ch cx cy <<< "$BOX"

  # 4: give the trim a margin back, clamped inside the cell, so a detection
  # that is a pixel or two keen still cannot cut ink.
  nx=$(( cx - MARGIN < 0 ? 0 : cx - MARGIN ))
  ny=$(( cy - MARGIN < 0 ? 0 : cy - MARGIN ))
  nw=$(( cw + (cx - nx) + MARGIN ))
  nh=$(( ch + (cy - ny) + MARGIN ))
  [ $(( nx + nw )) -gt "$side" ] && nw=$(( side - nx ))
  [ $(( ny + nh )) -gt "$side" ] && nh=$(( side - ny ))

  docker compose exec -T app ffmpeg -v error -y -i /tmp/keyed.png \
    -vf "crop=${nw}:${nh}:${nx}:${ny},scale='if(gt(iw,ih),96,-1)':'if(gt(iw,ih),-1,96)':flags=lanczos" \
    "/tmp/icon-${name}.png"

  docker compose cp "app:/tmp/icon-${name}.png" "${OUT}/${name}.png" >/dev/null
  printf '  %-16s %s\n' "${name}.png" "${nw}x${nh}+${nx}+${ny}"
done

echo "done — $(ls -1 ${OUT} | wc -l) icons in ${OUT}"
