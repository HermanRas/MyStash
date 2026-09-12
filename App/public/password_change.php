<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Rekey.php';
require_once __DIR__ . '/../src/User.php';

use MyStash\Rekey;
use MyStash\Session;
use MyStash\User;

/**
 * Changes the stash password (Docs/PLAN.md 6.1).
 *
 * The password *is* the encryption key, so this is not a field update — it
 * re-encrypts every archive the user owns (App/src/Rekey.php). The session then
 * adopts the new password so the user stays logged in; without that, the very
 * next page load would find an index its key no longer opens and log them out.
 */

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: user.php');
    exit;
}

$back = static function (string $query): never {
    header('Location: user.php?' . $query);
    exit;
};

$current = (string) ($_POST['current_password'] ?? '');
$new = (string) ($_POST['new_password'] ?? '');
$confirm = (string) ($_POST['confirm_password'] ?? '');

// Cheap checks first — none of these should cost a re-encryption to discover.
if ($new !== $confirm) {
    $back('error=mismatch');
}

if (!User::isValidPassword($new)) {
    $back('error=short');
}

if ($new === $current) {
    $back('error=same');
}

// No password is stored anywhere, so the thing to check against is the one
// this session already proved at login by decrypting the index with it.
// Rekey::run() then re-proves it the only authoritative way — by decrypting —
// before it touches a single archive.
if (!hash_equals(Session::password(), $current)) {
    $back('error=wrong');
}

// Re-encrypting every video is CPU-bound 7zip work and can outrun the default
// time limit on a large stash. The request must not be cut off half way.
//
// This is deliberately synchronous, which is correct but impatient: on a big
// stash the browser sits on a pending request for minutes, and a reverse proxy
// would time it out whatever PHP's own limit says. Moving it to a background
// worker with a progress poll is Docs/PLAN.md 6.6.
set_time_limit(0);
ignore_user_abort(true);

$result = (new Rekey())->run(Session::user(), $current, $new);

if (!$result['ok']) {
    error_log('MyStash password change failed for ' . Session::user() . ': ' . $result['message']);
    $back('error=failed');
}

// Only now, with every archive on the new key.
Session::setPassword($new);
Session::refreshIndex();

$back('changed=' . $result['rewritten']);
