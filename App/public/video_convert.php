<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Crypto7z.php';
require_once __DIR__ . '/../src/VideoEncoder.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/VideoQuality.php';
require_once __DIR__ . '/../src/VideoCategories.php';

use MyStash\Crypto7z;
use MyStash\Datastore;
use MyStash\Session;
use MyStash\VideoCategories;
use MyStash\VideoEncoder;
use MyStash\VideoQuality;

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wall.php');
    exit;
}

$id = (string) ($_POST['id'] ?? '');
$user = Session::user();
$password = Session::password();

$videoDir = Datastore::videoDir($user, $id);
$archivePath = "{$videoDir}/{$id}.mp4.enc";

if (!file_exists($archivePath)) {
    // Nothing to convert — e.g. a seeded/demo index entry with no real
    // encrypted file behind it yet.
    header('Location: video.php?id=' . urlencode($id) . '&convert_error=1');
    exit;
}

$crypto = new Crypto7z();
$encoder = new VideoEncoder();
$workDir = Datastore::tmpfsWorkDir('convert');

try {
    $extractDir = "{$workDir}/extract";
    if (!$crypto->extract($archivePath, $extractDir, $password)) {
        header('Location: video.php?id=' . urlencode($id) . '&convert_error=1');
        exit;
    }

    $files = glob("{$extractDir}/*");
    $originalPath = $files[0] ?? null;

    if ($originalPath === null) {
        header('Location: video.php?id=' . urlencode($id) . '&convert_error=1');
        exit;
    }

    $convertedPath = "{$workDir}/{$id}.mp4";
    if (!$encoder->convertToMp4Hevc($originalPath, $convertedPath)) {
        header('Location: video.php?id=' . urlencode($id) . '&convert_error=1');
        exit;
    }

    // Never encrypt straight over the live archive: that deletes the only copy
    // of the video before writing its replacement, and a failure or an
    // interruption in that window loses it outright (Docs/PLAN.md 4.29).
    // replace() writes beside it, proves the new archive opens and holds the
    // same bytes, and only then swaps it in.
    if (!$crypto->replace($convertedPath, $archivePath, $password)) {
        // The original is untouched and still readable — the video is exactly
        // as it was, still tagged "Not Converted", and this can be retried.
        error_log("MyStash convert: failed to replace {$archivePath} for {$user}");
        header('Location: video.php?id=' . urlencode($id) . '&convert_error=1');
        exit;
    }

    // The converted file is still decrypted in tmpfs here, so this is the one
    // moment the real pixel height is cheap to read — recalculate the derived
    // tags from it rather than trusting what the index carried.
    $height = $encoder->videoHeight($convertedPath);

    // From here the media on disk is already converted. Everything below is
    // bookkeeping, and it is ordered so that an interruption is survivable:
    // the per-video metadata first, the index last (the same rule as 6.3), so
    // a half-finished run leaves an index that still describes what is
    // actually on disk. Worst case the video is converted but still tagged
    // "Not Converted" — wrong on a label, not lost, and fixed by converting
    // again.
    $index = Session::refreshIndex();
    foreach ($index['videos'] as &$video) {
        if ($video['id'] === $id) {
            $video['format'] = 'mp4';
            $video['codec'] = 'hevc';
            $video['height'] = $height;
            $video = VideoQuality::apply($video);
            break;
        }
    }
    unset($video);

    // "Not Converted" is a real category assignment in the video's own
    // metadata, which is authoritative — drop it there and let the index copy
    // be derived from what remains.
    $categories = new VideoCategories();
    $assignments = array_values(array_filter(
        $categories->load($user, $password, $id),
        static fn($assignment) => $assignment['name'] !== 'Not Converted',
    ));
    $categories->save($user, $password, $id, $assignments, $index);

    $datastore = new Datastore();
    $metadata = $datastore->loadVideoMetadata($user, $password, $id);
    if ($metadata !== null) {
        $datastore->saveVideoMetadata($user, $password, $id, VideoQuality::apply([
            ...$metadata,
            'format' => 'mp4',
            'codec' => 'hevc',
            'height' => $height,
        ]));
    }

    Session::setIndex($index);

    if (!$datastore->saveIndex($user, $password, $index)) {
        // The video converted; only the index entry did not catch up. Say so
        // rather than reporting a success the wall will not show.
        error_log("MyStash convert: converted {$id} for {$user} but could not save the index");
        header('Location: video.php?id=' . urlencode($id) . '&convert_error=1');
        exit;
    }
} finally {
    Datastore::wipe($workDir);
}

header('Location: video.php?id=' . urlencode($id));
