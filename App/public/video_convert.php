<?php

declare(strict_types=1);

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/VideoEncoder.php';
require __DIR__ . '/../src/Datastore.php';
require __DIR__ . '/../src/Session.php';

use MyStash\Crypto7z;
use MyStash\Datastore;
use MyStash\Session;
use MyStash\VideoEncoder;

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

    $index = Session::index();
    foreach ($index['videos'] as &$video) {
        if ($video['id'] === $id) {
            $video['format'] = 'mp4';
            $video['codec'] = 'hevc';
            $video['not_converted'] = false;
            $video['categories'] = array_values(array_diff($video['categories'], ['Not Converted']));
            break;
        }
    }
    unset($video);

    Session::setIndex($index);
    (new Datastore())->saveIndex($user, $password, $index);
} finally {
    Datastore::wipe($workDir);
}

header('Location: video.php?id=' . urlencode($id));
