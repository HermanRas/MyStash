<?php

declare(strict_types=1);

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/Datastore.php';
require __DIR__ . '/../src/Session.php';

use MyStash\Datastore;
use MyStash\Session;

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wall.php');
    exit;
}

$id = (string) ($_POST['id'] ?? '');
$index = Session::index();

foreach ($index['videos'] as &$video) {
    if ($video['id'] === $id) {
        $video['title'] = trim((string) ($_POST['title'] ?? $video['title'])) ?: $video['title'];
        $video['description'] = trim((string) ($_POST['description'] ?? ''));
        $video['creator'] = (string) ($_POST['creator'] ?? $video['creator']);
        $video['categories'] = array_values(array_map('strval', $_POST['categories'] ?? []));
        break;
    }
}
unset($video);

Session::setIndex($index);
(new Datastore())->saveIndex(Session::user(), Session::password(), $index);

header('Location: video.php?id=' . urlencode($id));
