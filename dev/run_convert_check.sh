#!/usr/bin/env bash
# 4.29 — conversion must never lose the video it is converting.
#
# Drives the real video_convert.php endpoint over HTTP, against a throwaway
# stash this script creates and deletes. It never touches a real user: a
# conversion rewrites the video's archive, which is not something to do to
# someone's stash to prove a point.
set -uo pipefail
cd "$(dirname "$0")/.."

PROBE="ConvProbe$(openssl rand -hex 3)"
PASS="probe-convert-password-aaaaaaaa"
JAR="/tmp/${PROBE}.cookies"
fails=0

check() { if [ "$2" = "0" ]; then echo "PASS: $1"; else echo "FAIL: $1"; fails=$((fails+1)); fi; }

cleanup() {
  rm -rf "App/Data/${PROBE}" "$JAR"
  echo "removed throwaway stash ${PROBE}"
  [ "$fails" = "0" ] && echo "run_convert_check: all passed" || echo "run_convert_check: ${fails} failed"
  exit "$fails"
}
trap cleanup EXIT

# --- a stash with one real, unconverted video -----------------------------
docker compose exec -T -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/User.php";
require_once "/app/src/VideoEncoder.php";
require_once "/app/src/VideoCategories.php";
require_once "/app/src/VideoIngest.php";
require_once "/app/src/Datastore.php";
$u = getenv("U"); $p = getenv("P");
if (!(new MyStash\User())->create($u, $p)) { fwrite(STDERR, "create failed\n"); exit(1); }
$store = new MyStash\Datastore();
$index = $store->loadIndex($u, $p);
// A .mov container makes it "Not Converted" whatever its codec, so the
// convert button has real work to do.
copy("/app/Data/TestUser/videos/1.mp4", "/dev/shm/probe-src.mov");
$entry = (new MyStash\VideoIngest())->ingest($u, $p, "/dev/shm/probe-src.mov", "probe.mov", $index);
$index["videos"][] = $entry;
$store->saveIndex($u, $p, $index);
echo "ingested video {$entry["id"]}, not_converted=", var_export($entry["not_converted"], true), "\n";
' || { echo "FAIL: could not build the throwaway stash"; fails=1; exit 1; }

# The bytes we must still have afterwards, whatever happens.
BEFORE=$(docker compose exec -T -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/Crypto7z.php";
$c = new MyStash\Crypto7z();
$c->extract("/app/Data/" . getenv("U") . "/videos/Video1/1.mp4.enc", "/dev/shm/before", getenv("P"));
echo hash_file("sha256", glob("/dev/shm/before/*")[0]);
array_map("unlink", glob("/dev/shm/before/*")); rmdir("/dev/shm/before");
')
echo "  original video sha256: ${BEFORE:0:16}…"

# --- convert it through the endpoint a user would press -------------------
curl -s -c "$JAR" -o /dev/null -d "username=${PROBE}&password=${PASS}" http://localhost:8080/login.php
LOCATION=$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' -d "id=1" http://localhost:8080/video_convert.php)
echo "  convert redirected to: ${LOCATION##*/}"
case "$LOCATION" in *convert_error*) check "the conversion succeeds" 1;; *) check "the conversion succeeds" 0;; esac

# --- the video must still be there, and now be MP4/H.265 ------------------
docker compose exec -T -e U="$PROBE" -e P="$PASS" -e B="$BEFORE" app php -r '
require_once "/app/src/Crypto7z.php";
require_once "/app/src/VideoEncoder.php";
require_once "/app/src/Datastore.php";
$u = getenv("U"); $p = getenv("P");
$dir = "/app/Data/{$u}/videos/Video1";
$c = new MyStash\Crypto7z();
$ok = $c->extract("{$dir}/1.mp4.enc", "/dev/shm/after", $p);
$file = $ok ? (glob("/dev/shm/after/*")[0] ?? null) : null;
printf("%s: the video archive still decrypts\n", $file !== null ? "PASS" : "FAIL");
if ($file !== null) {
    $enc = new MyStash\VideoEncoder();
    printf("%s: it is now MP4/H.265 (%s)\n",
        $enc->isAlreadyMp4Hevc($file) ? "PASS" : "FAIL", $enc->videoCodec($file) ?? "?");
    printf("%s: the bytes changed, so it really was re-encoded\n",
        hash_file("sha256", $file) !== getenv("B") ? "PASS" : "FAIL");
    printf("  converted size: %d bytes\n", filesize($file));
}
$litter = array_merge(glob("{$dir}/*.old"), glob("{$dir}/*.new"));
printf("%s: no .old or .new copies left behind (%s)\n",
    $litter === [] ? "PASS" : "FAIL", $litter === [] ? "none" : implode(", ", array_map("basename", $litter)));
$index = (new MyStash\Datastore())->loadIndex($u, $p);
printf("%s: the index still opens and no longer says Not Converted\n",
    ($index !== null && empty($index["videos"][0]["not_converted"])) ? "PASS" : "FAIL");
if (is_dir("/dev/shm/after")) { array_map("unlink", glob("/dev/shm/after/*")); rmdir("/dev/shm/after"); }
' | tee /tmp/${PROBE}.out
fails=$((fails + $(grep -c '^FAIL' /tmp/${PROBE}.out)))
rm -f /tmp/${PROBE}.out
