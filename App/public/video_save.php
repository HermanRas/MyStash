<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/VideoCategories.php';
require_once __DIR__ . '/../src/VideoQuality.php';

use MyStash\Datastore;
use MyStash\Session;
use MyStash\VideoQuality;

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wall.php');
    exit;
}

$id = (string) ($_POST['id'] ?? '');
$index = Session::refreshIndex();
$datastore = new Datastore();

// Category assignments are managed separately (video_category_add/delete.php),
// since they carry timestamps and may repeat.
$fields = [];
foreach ($index['videos'] as &$video) {
    if ($video['id'] === $id) {
        $video['title'] = trim((string) ($_POST['title'] ?? '')) ?: $video['title'];
        $video['description'] = trim((string) ($_POST['description'] ?? ''));
        $video['creator'] = (string) ($_POST['creator'] ?? $video['creator']);

        // Quality and "Not Converted" describe the file, not the user's
        // opinion of it: they are recalculated here on every save and are
        // never accepted from the form.
        $video = VideoQuality::apply($video);

        $fields = [
            'title' => $video['title'],
            'description' => $video['description'],
            'creator' => $video['creator'],
            'height' => $video['height'] ?? null,
            'quality' => $video['quality'],
            'not_converted' => $video['not_converted'],
        ];
        break;
    }
}
unset($video);

$metadata = $datastore->loadVideoMetadata(Session::user(), Session::password(), $id);
if ($metadata !== null && $fields !== []) {
    $datastore->saveVideoMetadata(Session::user(), Session::password(), $id, [...$metadata, ...$fields]);
}

$datastore->saveIndex(Session::user(), Session::password(), $index);
Session::setIndex($index);

header('Location: video.php?id=' . urlencode($id));
