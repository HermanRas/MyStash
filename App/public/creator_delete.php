<?php

declare(strict_types=1);

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/Datastore.php';
require __DIR__ . '/../src/Session.php';

use MyStash\Datastore;
use MyStash\Session;

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: creator.php');
    exit;
}

$name = (string) ($_POST['name'] ?? '');

if ($name === '' || $name === 'default') {
    header('Location: creator.php');
    exit;
}

$index = Session::refreshIndex();

unset($index['creators'][$name]);

foreach ($index['videos'] as &$video) {
    if ($video['creator'] === $name) {
        $video['creator'] = 'default';
    }
}
unset($video);

Session::setIndex($index);
(new Datastore())->saveIndex(Session::user(), Session::password(), $index);

header('Location: creator.php');
