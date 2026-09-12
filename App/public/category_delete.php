<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/VideoCategories.php';

use MyStash\Datastore;
use MyStash\Session;
use MyStash\VideoCategories;

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: creator.php');
    exit;
}

$name = (string) ($_POST['name'] ?? '');

if ($name === '') {
    header('Location: creator.php');
    exit;
}

$index = Session::refreshIndex();

unset($index['categories'][$name]);
(new VideoCategories())->removeCategoryEverywhere(Session::user(), Session::password(), $name, $index);

(new Datastore())->saveIndex(Session::user(), Session::password(), $index);
Session::setIndex($index);

header('Location: creator.php');
