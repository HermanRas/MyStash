<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/Crypto7z.php';
require_once __DIR__ . '/Datastore.php';
require_once __DIR__ . '/User.php';

/**
 * Re-encrypts an entire stash with a new password
 * (Docs/SPECIFICATIONS.md §2.6, Docs/PLAN.md 6.1).
 *
 * Because the password *is* the key, changing it means rewriting every archive
 * the user owns — there is no password record to update, and nothing cheaper
 * that would work.
 *
 * Two ordering rules make an interrupted run survivable:
 *
 *  - every archive keeps its previous bytes as `.enc.old` until the whole run
 *    succeeds, so a failure part-way can put back what it replaced;
 *  - the index is rewritten LAST. While it still opens with the old password,
 *    the old password is still the stash's password, and the run can simply be
 *    retried. If the index went first, a failure would leave a stash whose
 *    index says one thing and whose media says another.
 *
 * The work itself is CPU-bound 7zip, and it runs over every video, so this can
 * take a while on a large stash. It is deliberately synchronous: the password
 * the caller holds is only valid until this returns.
 */
final class Rekey
{
    public function __construct(
        private Datastore $datastore = new Datastore(),
        private Crypto7z $crypto = new Crypto7z(),
    ) {
    }

    /**
     * Rewrites every archive under the user's directory from $oldPassword to
     * $newPassword.
     *
     * Never throws and never exits — callers get a result they can render,
     * whether that is a CLI script or a web request.
     *
     * @param callable(string):void|null $onProgress called with each archive's
     *        basename as it is rewritten
     * @return array{ok: bool, message: string, rewritten: int}
     */
    public function run(
        string $user,
        string $oldPassword,
        string $newPassword,
        ?callable $onProgress = null,
    ): array {
        if (!User::isValidPassword($newPassword)) {
            return $this->fail('The new password must be at least ' . User::MIN_PASSWORD_LENGTH . ' characters.');
        }

        if ($newPassword === $oldPassword) {
            return $this->fail('The new password is the same as the current one — nothing to do.');
        }

        // The authoritative check that the caller knows the current password:
        // a wrong one simply fails to decrypt. There is nothing else to compare
        // against, because no password is ever stored.
        if ($this->datastore->loadIndex($user, $oldPassword) === null) {
            return $this->fail("Cannot decrypt {$user}'s stash with the current password — nothing changed.");
        }

        $archives = $this->archivesFor($user);

        if ($archives === []) {
            return $this->fail("Found no encrypted archives for {$user} — nothing changed.");
        }

        $rewritten = [];

        foreach ($archives as $archive) {
            $workDir = Datastore::tmpfsWorkDir('rekey');

            try {
                if (!$this->crypto->extract($archive, $workDir, $oldPassword)) {
                    return $this->abort($rewritten, 'Failed to decrypt ' . basename($archive));
                }

                $plain = glob("{$workDir}/*")[0] ?? null;
                if ($plain === null) {
                    return $this->abort($rewritten, basename($archive) . ' decrypted to nothing');
                }

                rename($archive, "{$archive}.old");

                if (!$this->crypto->encrypt($plain, $archive, $newPassword)) {
                    rename("{$archive}.old", $archive);

                    return $this->abort($rewritten, 'Failed to re-encrypt ' . basename($archive));
                }

                $rewritten[] = $archive;

                if ($onProgress !== null) {
                    $onProgress(basename($archive));
                }
            } finally {
                // The plaintext only ever existed in tmpfs, and not past here.
                Datastore::wipe($workDir);
            }
        }

        // Everything is on the new key: the safety copies are now the only
        // thing still readable with the old one, so they go.
        foreach ($rewritten as $archive) {
            unlink("{$archive}.old");
        }

        return [
            'ok' => true,
            'message' => 'Re-encrypted ' . count($rewritten) . ' archive(s).',
            'rewritten' => count($rewritten),
        ];
    }

    /**
     * Every `.enc` archive the user owns, index last.
     *
     * @return list<string>
     */
    private function archivesFor(string $user): array
    {
        $root = Datastore::userDir($user);

        if (!is_dir($root)) {
            return [];
        }

        $archives = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.enc')) {
                $archives[] = $file->getPathname();
            }
        }

        $index = Datastore::indexArchivePath($user);
        usort($archives, static fn($a, $b) => ($a === $index ? 1 : 0) <=> ($b === $index ? 1 : 0));

        return $archives;
    }

    /**
     * Puts back every archive rewritten so far, so a failed run leaves the
     * stash exactly as it was on the old password rather than half-converted.
     *
     * @param list<string> $rewritten
     * @return array{ok: bool, message: string, rewritten: int}
     */
    private function abort(array $rewritten, string $reason): array
    {
        $restored = 0;

        foreach ($rewritten as $archive) {
            if (file_exists("{$archive}.old") && rename("{$archive}.old", $archive)) {
                $restored++;
            }
        }

        return $this->fail(
            $reason . ' — rolled back ' . $restored . ' of ' . count($rewritten)
            . ' archive(s) already rewritten. Your current password still works.',
        );
    }

    /**
     * @return array{ok: bool, message: string, rewritten: int}
     */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'rewritten' => 0];
    }
}
