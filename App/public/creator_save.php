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

$originalName = (string) ($_POST['original_name'] ?? '');
$name = trim((string) ($_POST['name'] ?? ''));

if ($name === '') {
    header('Location: creator.php');
    exit;
}

$index = Session::index();
$creators = $index['creators'] ?? [];

$record = [
    'name' => $name,
    'age' => $_POST['age'] !== '' ? (int) $_POST['age'] : null,
    'gender' => trim((string) ($_POST['gender'] ?? '')) ?: null,
    'bio' => trim((string) ($_POST['bio'] ?? '')),
    'verified' => isset($_POST['verified']),
];

// Renaming: move the key and repoint every video that referenced the old name.
if ($originalName !== '' && $originalName !== $name) {
    unset($creators[$originalName]);

    foreach ($index['videos'] as &$video) {
        if ($video['creator'] === $originalName) {
            $video['creator'] = $name;
        }
    }
    unset($video);
}

$creators[$name] = $record;
$index['creators'] = $creators;

Session::setIndex($index);
(new Datastore())->saveIndex(Session::user(), Session::password(), $index);

header('Location: creator.php');
