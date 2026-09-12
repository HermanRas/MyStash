<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Crypto7z.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/CreatorStore.php';

use MyStash\CreatorStore;
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

// Drops the index entry, wipes the creator's encrypted record and profile
// picture, and reassigns their videos to `default`.
(new CreatorStore())->delete(Session::user(), $index, $name);

Session::setIndex($index);
(new Datastore())->saveIndex(Session::user(), Session::password(), $index);

header('Location: creator.php');
