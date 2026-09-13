<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/User.php';

use MyStash\Jobs;
use MyStash\Session;
use MyStash\User;

/**
 * Changes the stash password (Docs/PLAN.md 6.1).
 *
 * The password *is* the encryption key, so this is not a field update — it
 * re-encrypts every archive the user owns (App/src/Rekey.php). That runs as a
 * background job (6.6), so this endpoint only validates and starts it.
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

// Re-encrypting every archive is CPU-bound 7zip work over the whole stash, so
// it runs as a detached background job rather than inside this request
// (Docs/PLAN.md 6.6). The browser used to sit on a pending request for minutes
// with no way to tell whether it had finished; now the Profile page polls
// job_status.php and shows "re-encrypted 14 of 37".
//
// The passwords go to the worker through the job's tmpfs key file, which it
// deletes as it reads — never on its argv, where `ps` would show them.
$started = Jobs::start(
    'rekey',
    Session::user(),
    '',
    ['old_password' => $current, 'new_password' => $new],
    ['message' => 'Starting…'],
);

if (!$started['ok']) {
    $back('error=running');
}

// No Session::setPassword() here, and deliberately no attempt to hand the new
// password over when the job finishes. The session holding a password the
// archives are not yet on is the dangerous state (6.5), and the job outlives
// this request anyway. The honest ending is to sign the user out when it
// completes and have them log back in with the new password, which proves it
// worked. Session::requireLogin() keeps every other page out of reach until
// then, so nothing can write under the old key mid-run.
$back('rekeying=1');
