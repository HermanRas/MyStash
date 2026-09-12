<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/User.php';

use MyStash\Datastore;
use MyStash\Session;
use MyStash\User;

Session::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html');
    exit;
}

$user = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

// The name is used as a path segment, so reject anything but letters/digits
// before it reaches the filesystem.
$index = User::isValidName($user) && $password !== ''
    ? (new Datastore())->loadIndex($user, $password)
    : null;

// Wrong password and unknown username look identical: no further detail given.
if ($index === null) {
    header('Location: login.html?error=1');
    exit;
}

Session::login($user, $password, $index);

header('Location: wall.php');
