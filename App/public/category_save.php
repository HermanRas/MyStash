<?php

declare(strict_types=1);

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/Datastore.php';
require __DIR__ . '/../src/Session.php';

use MyStash\Datastore;
use MyStash\Session;

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: category.php");
    exit;
}

$name = trim((string) ($_POST['name'] ?? ''));
$color = (string) ($_POST['color'] ?? '#ffa31a');

if ($name === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
    header("Location: category.php");
    exit;
}

$index = Session::refreshIndex();
$index['categories'][$name] = $color;

Session::setIndex($index);
(new Datastore())->saveIndex(Session::user(), Session::password(), $index);

header("Location: category.php");
