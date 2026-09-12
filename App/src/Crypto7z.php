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
