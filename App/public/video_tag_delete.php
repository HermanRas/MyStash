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
$label = (string) ($_POST['label'] ?? '');
$timestamp = (int) ($_POST['timestamp_seconds'] ?? -1);

$index = Session::index();

foreach ($index['videos'] as &$video) {
    if ($video['id'] === $id) {
        $video['tags'] = array_values(array_filter(
            $video['tags'] ?? [],
            static fn($t) => !($t['label'] === $label && (int) $t['timestamp_seconds'] === $timestamp),
        ));
        break;
    }
}
unset($video);

Session::setIndex($index);
(new Datastore())->saveIndex(Session::user(), Session::password(), $index);

header('Location: video.php?id=' . urlencode($id) . '&edit=1');
