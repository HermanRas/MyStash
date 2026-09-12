<?php

declare(strict_types=1);

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/VideoEncoder.php';
require __DIR__ . '/../src/Datastore.php';
require __DIR__ . '/../src/Session.php';
require __DIR__ . '/../src/VideoQuality.php';
require __DIR__ . '/../src/VideoCategories.php';

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

    $crypto->encrypt($convertedPath, $archivePath, $password);

    // The converted file is still decrypted in tmpfs here, so this is the one
    // moment the real pixel height is cheap to read — recalculate the derived
    // tags from it rather than trusting what the index carried.
    $height = $encoder->videoHeight($convertedPath);

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
    $datastore->saveIndex($user, $password, $index);
} finally {
    Datastore::wipe($workDir);
}

header('Location: video.php?id=' . urlencode($id));
