#!/usr/bin/env bash
# Generates the twelve sample clips the demo stash is built from.
#
# The four files in SampleData are ffmpeg test patterns — colour bars with
# "Frame 132/150" burned into them — which is exactly right for testing
# ingestion and exactly wrong for a README: the wall is the first thing anyone
# sees and it would be a grid of rainbow stripes.
#
# So these are synthesised instead: slow-moving light, grain and a vignette,
# in twelve palettes. Nothing here is footage of anything — it is abstract by
# construction, which is the honest option for sample data in a repository
# about a *private* library. What it does demonstrate is real: every clip is a
# genuine video file, the four resolutions exercise all four quality tags, and
# four of them are deliberately left in a codec the app considers unconverted
# so the "Not Converted" tag and the convert button have something to act on.
#
# Usage: dev/make_sample_clips.sh [output-dir]
set -uo pipefail
cd "$(dirname "$0")/.."

OUT="${1:-${MYSTASH_SAMPLE_DIR:-dev/sample}/clips}"
mkdir -p "$OUT"

DC="docker compose"

# name|WxH|style|duration|codec|c0|c1|c2
#   style A: soft light through a lens — a drifting gradient, blurred noise
#   style B: distant lights — cellular automata, scaled up and bloomed
#   style C: organic texture — a mandelbrot field used as a light map
CLIPS='
01-harbour-road|1920x1080|A|12|hevc|0x0b1d2e|0xd98f3a|0x3a1f3d
02-rain-window|1280x720|C|9|hevc|0x0d1b26|0x4a7fa5|0x12222c
03-kitchen-table|854x480|A|7|h264|0x241408|0xc98a4b|0x3a2415
04-long-way-around|3840x2160|A|11|hevc|0x07201f|0xe0a34a|0x123033
05-lenses-lie|1920x1080|B|8|hevc|0x140d1e|0x2b1f45|0x0d0a14
06-milo-carrier|1280x720|A|6|vp9|0x2a1a20|0xd8a0a8|0x3d2a32
07-sunday-league|854x480|C|10|hevc|0x101a12|0x5f8f4a|0x1c2a1e
08-two-cats|3840x2160|A|9|hevc|0x1c1320|0xc9789a|0x2a1c30
09-borrowed-kitchen|1280x720|A|13|hevc|0x1e1710|0xe8c98f|0x2c2218
10-night-buses|1920x1080|B|14|hevc|0x06101c|0x1b3a5c|0x0a0f18
11-training-week-six|854x480|C|6|h264|0x1a1a1c|0x7a7f88|0x24262a
12-cat-on-the-script|3840x2160|B|8|vp9|0x1a0f06|0x3d2410|0x0f0a06
'

# Read into an array first, never `echo | while read`: the docker exec inside
# the loop reads stdin, swallows the rest of the list and the loop ends after
# one clip.
mapfile -t ROWS < <(echo "$CLIPS" | grep .)

for row in "${ROWS[@]}"; do
  IFS='|' read -r name size style dur codec c0 c1 c2 <<< "$row"
  w="${size%x*}"; h="${size#*x}"

  case "$codec" in
    hevc) enc='-c:v libx265 -crf 30 -preset veryfast -tag:v hvc1' ;;
    h264) enc='-c:v libx264 -crf 26 -preset veryfast' ;;
    vp9)  enc='-c:v libvpx-vp9 -crf 40 -b:v 0 -deadline realtime -cpu-used 6' ;;
  esac

  # The gradient is the base in every style; what differs is what is blended
  # over it. Speeds are deliberately tiny — this should drift, not pulse.
  base="gradients=s=${w}x${h}:c0=${c0}:c1=${c1}:c2=${c2}:nb_colors=3:speed=0.01:d=${dur}:r=25"

  case "$style" in
    A) second="perlin=size=${w}x${h}:rate=25"
       graph="[1:v]format=gray,gblur=sigma=18,format=yuv420p[t];[0:v][t]blend=all_mode=softlight:all_opacity=0.55" ;;
    B) second="life=s=$((w/8))x$((h/8)):mold=10:r=25:ratio=0.08:death_color=0x000000:life_color=0xffcf7a"
       graph="[1:v]scale=${w}:${h}:flags=neighbor,gblur=sigma=$((h/120+4)),format=yuv420p[t];[0:v][t]blend=all_mode=screen:all_opacity=0.7" ;;
    C) second="mandelbrot=s=$((w/2))x$((h/2)):rate=25:maxiter=200:start_scale=2.2:end_scale=0.9"
       graph="[1:v]scale=${w}:${h},format=gray,gblur=sigma=12,format=yuv420p[t];[0:v][t]blend=all_mode=overlay:all_opacity=0.5" ;;
  esac

  echo "  ${name} (${size}, ${codec}, ${dur}s)"
  $DC exec -T php sh -c "
    ffmpeg -v error -y -f lavfi -i '${base}' -f lavfi -i '${second}' \
      -filter_complex '${graph},vignette=PI/4.2,noise=alls=5:allf=t,eq=contrast=1.1:saturation=1.15,format=yuv420p[v]' \
      -map '[v]' ${enc} -an -t ${dur} /dev/shm/clip.mp4" </dev/null 2>/dev/null \
    || { echo "    FAILED"; continue; }
  $DC exec -T php cat /dev/shm/clip.mp4 </dev/null > "${OUT}/${name}.mp4"
done

$DC exec -T php rm -f /dev/shm/clip.mp4
echo
ls -la "$OUT"
