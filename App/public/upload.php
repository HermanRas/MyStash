<?php

declare(strict_types=1);

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/VideoEncoder.php';
require __DIR__ . '/../src/Datastore.php';
require __DIR__ . '/../src/VideoIngest.php';
require __DIR__ . '/../src/Session.php';

use MyStash\Datastore;
use MyStash\Session;
use MyStash\VideoIngest;

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wall.php');
    exit;
}

$file = $_FILES['video'] ?? null;
if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
    header('Location: wall.php?upload_error=1');
    exit;
}

$previewAt = isset($_POST['preview_at']) && $_POST['preview_at'] !== ''
    ? (float) $_POST['preview_at']
    : null;

$index = Session::refreshIndex();

$entry = (new VideoIngest())->ingest(
    Session::user(),
    Session::password(),
    $file['tmp_name'],
    $file['name'],
    $index,
    $previewAt,
);

$index['videos'][] = $entry;

(new Datastore())->saveIndex(Session::user(), Session::password(), $index);
Session::setIndex($index);

header('Location: wall.php');
