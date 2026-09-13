# Sourced by the dev check scripts: guarantees a real video to upload.
#
# These scripts used to copy App/Data/TestUser/videos/1.mp4 — a raw file that
# lived inside a user's stash, which meant every check broke the moment that
# stash was cleared, and none of them worked on a fresh clone at all. The
# fixture is generated instead, once, and kept outside App/Data where it is not
# mistakeable for someone's library.
#
# Paths, because the scripts need both: FIXTURE from the host, /app/tests/
# from inside the containers.
FIXTURE="App/tests/fixture.mp4"
FIXTURE_IN_CONTAINER="/app/tests/fixture.mp4"

ensure_fixture() {
  [ -f "$FIXTURE" ] && return 0

  echo "generating $FIXTURE (once)"
  docker compose exec -T php sh -c "
    ffmpeg -v error -y -f lavfi -i testsrc=size=640x360:rate=25:duration=20 \
      -f lavfi -i sine=frequency=440:duration=20 \
      -c:v libx264 -preset veryfast -c:a aac -pix_fmt yuv420p -shortest \
      ${FIXTURE_IN_CONTAINER}" </dev/null || return 1

  [ -f "$FIXTURE" ]
}
