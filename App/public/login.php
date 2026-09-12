<?php

declare(strict_types=1);

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/Datastore.php';
require __DIR__ . '/../src/Session.php';

use MyStash\Datastore;
use MyStash\Session;

Session::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html');
    exit;
}

$user = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

$index = $user !== '' && $password !== ''
    ? (new Datastore())->loadIndex($user, $password)
    : null;

// Wrong password and unknown username look identical: no further detail given.
if ($index === null) {
    header('Location: login.html?error=1');
    exit;
}

Session::login($user, $password, $index);

header('Location: wall.php');
