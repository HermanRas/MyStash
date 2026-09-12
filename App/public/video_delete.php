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

$index['videos'] = array_values(array_filter(
    $index['videos'],
    static fn($v) => $v['id'] !== $id,
));

$videoDir = Datastore::videoDir(Session::user(), $id);
if (is_dir($videoDir)) {
    Datastore::wipe($videoDir);
}

Session::setIndex($index);
(new Datastore())->saveIndex(Session::user(), Session::password(), $index);

header('Location: wall.php');
