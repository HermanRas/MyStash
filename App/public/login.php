<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/LoginThrottle.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/User.php';

use MyStash\Datastore;
use MyStash\LoginThrottle;
use MyStash\Session;
use MyStash\User;

Session::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html');
    exit;
}

// Taken before any work, because the constant-time floor below is measured
// from here (7.3).
$startedAt = microtime(true);

$user = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$address = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

// Checked *before* the decrypt, which is the whole point: past the limit this
// costs a file read instead of spawning 7z, so a flood is cheaper for the
// server to refuse than for the attacker to send.
$retryAfter = LoginThrottle::retryAfter($user, $address);

if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    header('Location: login.html?error=1&wait=' . (int) ceil($retryAfter / 60));
    exit;
}

// The name is used as a path segment, so reject anything but letters/digits
// before it reaches the filesystem.
$index = User::isValidName($user) && $password !== ''
    ? (new Datastore())->loadIndex($user, $password)
    : null;

// Wrong password and unknown username look identical: no further detail given.
if ($index === null) {
    LoginThrottle::recordFailure($user, $address);

    // ...and now they take the same amount of *time*, too. Without this the
    // replies matched but the clock did not: ~130ms when a stash existed and
    // 7z ran, ~16ms when there was nothing to try. That is a username oracle
    // (7.3).
    LoginThrottle::settle($startedAt);

    header('Location: login.html?error=1');
    exit;
}

LoginThrottle::clear($user);

Session::login($user, $password, $index);

header('Location: wall.php');
