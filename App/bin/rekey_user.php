<?php

declare(strict_types=1);

/**
 * Re-encrypts an entire stash with a new password (Docs/SPECIFICATIONS.md §2.6).
 *
 * Because the password *is* the key, changing it means rewriting every archive
 * the user owns. Order matters: all media and creator archives are rewritten
 * first, and {user}.json.enc last — so an interrupted run leaves the index (and
 * therefore the old password) still valid to retry with. Each archive keeps its
 * previous bytes as `.enc.old` until the whole run succeeds.
 *
 * Usage (inside the container):
 *   php bin/rekey_user.php {user} {old password} {new password}
 */

require_once __DIR__ . '/../src/Crypto7z.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/User.php';

use MyStash\Crypto7z;
use MyStash\Datastore;
use MyStash\User;

[$user, $oldPassword, $newPassword] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];

if ($user === '' || $oldPassword === '' || $newPassword === '') {
    fwrite(STDERR, "Usage: php bin/rekey_user.php {user} {old password} {new password}\n");
    exit(1);
}

if (!User::isValidPassword($newPassword)) {
    fwrite(STDERR, 'New password must be at least ' . User::MIN_PASSWORD_LENGTH . " characters.\n");
    exit(1);
}

$root = __DIR__ . '/../Data/' . $user;
$datastore = new Datastore();
$crypto = new Crypto7z();

if ($datastore->loadIndex($user, $oldPassword) === null) {
    fwrite(STDERR, "Cannot decrypt {$user}'s index with the old password — nothing changed.\n");
    exit(1);
}

$indexArchive = "{$root}/videos/{$user}.json.enc";
$archives = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getPathname(), '.enc')) {
        $archives[] = $file->getPathname();
    }
}

// Index last: while it still opens with the old password, the run is retryable.
usort($archives, static fn($a, $b) => ($a === $indexArchive ? 1 : 0) <=> ($b === $indexArchive ? 1 : 0));

$rewritten = [];

foreach ($archives as $archive) {
    $workDir = Datastore::tmpfsWorkDir('rekey');

    try {
        if (!$crypto->extract($archive, $workDir, $oldPassword)) {
            fwrite(STDERR, "Failed to decrypt {$archive} — aborting, no further changes.\n");
            exit(1);
        }

        $plain = glob("{$workDir}/*")[0] ?? null;
        if ($plain === null) {
            fwrite(STDERR, "Archive {$archive} decrypted to nothing — aborting.\n");
            exit(1);
        }

        rename($archive, "{$archive}.old");

        if (!$crypto->encrypt($plain, $archive, $newPassword)) {
            rename("{$archive}.old", $archive);
            fwrite(STDERR, "Failed to re-encrypt {$archive} — restored the original, aborting.\n");
            exit(1);
        }

        $rewritten[] = $archive;
        echo "re-encrypted " . basename($archive) . "\n";
    } finally {
        Datastore::wipe($workDir);
    }
}

foreach ($rewritten as $archive) {
    unlink("{$archive}.old");
}

echo "\nRe-encrypted " . count($rewritten) . " archive(s) for {$user}.\n";
exit(0);
