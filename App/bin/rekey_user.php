<?php

declare(strict_types=1);

/**
 * Re-encrypts an entire stash with a new password (Docs/SPECIFICATIONS.md §2.6).
 *
 * The work itself lives in App/src/Rekey.php, which the Profile screen's
 * password-change form uses too — this is just the command-line way in, for a
 * stash whose owner cannot log in to reach that form.
 *
 * Usage (inside the container):
 *   php bin/rekey_user.php {user} {old password} {new password}
 */

require_once __DIR__ . '/../src/Rekey.php';

use MyStash\Rekey;

[$user, $oldPassword, $newPassword] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];

if ($user === '' || $oldPassword === '' || $newPassword === '') {
    fwrite(STDERR, "Usage: php bin/rekey_user.php {user} {old password} {new password}\n");
    exit(1);
}

$result = (new Rekey())->run(
    $user,
    $oldPassword,
    $newPassword,
    static fn(int $done, int $total, string $name) => print("re-encrypted {$done}/{$total}  {$name}\n"),
);

if (!$result['ok']) {
    fwrite(STDERR, $result['message'] . "\n");
    exit(1);
}

echo "\n" . $result['message'] . "\n";
exit(0);
