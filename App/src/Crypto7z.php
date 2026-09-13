<?php

declare(strict_types=1);

namespace MyStash;

/**
 * Wraps the `7z` CLI for password-based AES-256 encryption only.
 * Compression is disabled (-mx=0 / store) since inputs (mp4, jpg, json)
 * are already compressed or small — recompressing wastes CPU and adds
 * decrypt-and-load latency for no size benefit.
 */
final class Crypto7z
{
    public function __construct(private string $binary = '7z')
    {
    }

    /**
     * Encrypt $sourcePath into a new .7z archive at $archivePath using $password.
     * $sourcePath may be a file or a directory.
     */
    public function encrypt(string $sourcePath, string $archivePath, string $password): bool
    {
        if (!file_exists($sourcePath)) {
            throw new \InvalidArgumentException("Source path does not exist: {$sourcePath}");
        }

        if (file_exists($archivePath)) {
            unlink($archivePath);
        }

        $command = [
            $this->binary,
            'a',                    // add to archive
            '-t7z',                 // 7z format
            '-mx=0',                // store only, no compression
            '-mhe=on',              // encrypt headers (hides filenames too)
            '-p' . $password,       // password (no space, single argv token)
            '-y',                   // assume yes on prompts
            $archivePath,
            $sourcePath,
        ];

        return $this->run($command) === 0;
    }

    /**
     * Replaces a live archive with a freshly encrypted $sourceFile, without
     * ever leaving the old one missing or half-written (Docs/PLAN.md 4.29).
     *
     * encrypt() deletes its destination before writing it, so aiming it
     * straight at an archive that is the only copy of a video opens a window
     * where that video does not exist at all — and if the write then fails,
     * returns false unnoticed, or is interrupted, it never comes back. That is
     * the bug this method exists to make unavailable.
     *
     * So: write beside the archive, prove the new file really opens and holds
     * the same bytes, and only then swap it in — keeping the previous bytes as
     * `.enc.old` until the swap has succeeded, and putting them back if
     * anything goes wrong. The same write-verify-rotate shape Rekey uses.
     *
     * $sourceFile must be a single file (every caller re-encrypts one), so the
     * verification can compare it byte for byte.
     */
    public function replace(string $sourceFile, string $archivePath, string $password): bool
    {
        if (!is_file($sourceFile)) {
            return false;
        }

        // The pending name is unique per call: two requests saving the same
        // archive at once would otherwise write over each other's half-built
        // file and swap in a mixture. (They can still race on the swap itself
        // — that is what the lock in 6.6 is for.)
        $pending = $archivePath . '.new.' . bin2hex(random_bytes(4));
        $backup = $archivePath . '.old';

        if (!$this->encrypt($sourceFile, $pending, $password)) {
            @unlink($pending);

            return false;
        }

        // An encrypt that reported success can still have produced something
        // that will not open — a full disk truncates happily. Checking now
        // costs one extraction; not checking costs the video.
        if (!$this->holdsSameBytes($pending, $sourceFile, $password)) {
            @unlink($pending);

            return false;
        }

        if (file_exists($archivePath) && !rename($archivePath, $backup)) {
            @unlink($pending);

            return false;
        }

        if (!rename($pending, $archivePath)) {
            // Put the original back before giving up.
            if (file_exists($backup)) {
                rename($backup, $archivePath);
            }
            @unlink($pending);

            return false;
        }

        if (file_exists($backup)) {
            unlink($backup);
        }

        return true;
    }

    /**
     * True when $archivePath decrypts to exactly one file identical to
     * $expectedFile. Used to prove a replacement archive before it is trusted
     * with the only copy of something.
     */
    private function holdsSameBytes(string $archivePath, string $expectedFile, string $password): bool
    {
        $scratch = $this->scratchDir();

        try {
            if (!$this->extract($archivePath, $scratch, $password)) {
                return false;
            }

            $extracted = glob("{$scratch}/*") ?: [];

            if (count($extracted) !== 1 || !is_file($extracted[0])) {
                return false;
            }

            return filesize($extracted[0]) === filesize($expectedFile)
                && hash_file('sha256', $extracted[0]) === hash_file('sha256', $expectedFile);
        } finally {
            array_map('unlink', glob("{$scratch}/*") ?: []);
            rmdir($scratch);
        }
    }

    /**
     * A private tmpfs directory for verification plaintext.
     *
     * This deliberately does not call Datastore::tmpfsWorkDir(): Datastore
     * depends on this class, and the dependency should not run both ways.
     */
    private function scratchDir(): string
    {
        $root = '/dev/shm/mystash-verify';

        if (!is_dir($root)) {
            mkdir($root, 0700, true);
        }

        $dir = $root . '/' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);

        return $dir;
    }

    /**
     * Extract $archivePath (password-protected) into $destinationDir.
     * Returns false if the password is wrong or the archive is invalid —
     * this is also how login verification works.
     */
    public function extract(string $archivePath, string $destinationDir, string $password): bool
    {
        if (!file_exists($archivePath)) {
            return false;
        }

        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0700, true);
        }

        $command = [
            $this->binary,
            'x',                     // extract with full paths
            '-p' . $password,
            '-y',
            '-o' . $destinationDir,
            $archivePath,
        ];

        return $this->run($command) === 0;
    }

    /**
     * Runs a command via proc_open with an argv array (never a shell string),
     * so the password can never be interpreted by a shell.
     */
    private function run(array $command): int
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start 7z process');
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
