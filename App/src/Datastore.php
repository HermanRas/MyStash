<?php

declare(strict_types=1);

namespace MyStash;

/**
 * Reads/writes the per-user encrypted video index ({user}.json.enc).
 *
 * See Docs/SPECIFICATIONS.md §3 for the on-disk datastore layout and §2.1
 * for the login flow this class implements the decrypt/verify step of.
 *
 * Index schema (decrypted JSON):
 * {
 *   "categories": { "<name>": "<hex color>" },
 *   "creators":   { "<name>": {"name","age","gender","bio","verified"} },
 *   "videos": [
 *     {
 *       "id", "title", "creator", "length_seconds", "views",
 *       "format", "codec", "not_converted", "categories": ["..."],
 *       "quality", "tile_gradient": ["#a","#b"],
 *       "tags": [{"label","color","timestamp_seconds"}]
 *     }
 *   ]
 * }
 *
 * Per-video metadata schema (Video{ID}/{ID}.json.enc — created during ingestion
 * in Phase 3, not yet written by this class): the fuller record for a single
 * video, of which the index above keeps only a denormalized summary for the
 * wall grid.
 * {
 *   "id", "title", "description", "creator", "length_seconds",
 *   "views", "format", "codec", "not_converted",
 *   "uploaded_at", "categories": ["..."],
 *   "tags": [{"label","color","timestamp_seconds"}],
 *   "preview_capture_seconds"
 * }
 */
final class Datastore
{
    private const DATA_ROOT = __DIR__ . '/../Data';
    private const TMPFS_ROOT = '/dev/shm/mystash-extract';

    public function __construct(private Crypto7z $crypto = new Crypto7z())
    {
    }

    public function userExists(string $user): bool
    {
        return is_dir(self::DATA_ROOT . '/' . $user);
    }

    private function indexArchivePath(string $user): string
    {
        return self::DATA_ROOT . "/{$user}/videos/{$user}.json.enc";
    }

    public static function videoDir(string $user, string $id): string
    {
        return self::DATA_ROOT . "/{$user}/videos/Video{$id}";
    }

    /**
     * One more than the highest existing numeric video ID in the index.
     */
    public static function nextVideoId(array $index): string
    {
        $max = 0;
        foreach ($index['videos'] ?? [] as $video) {
            $max = max($max, (int) $video['id']);
        }

        return (string) ($max + 1);
    }

    /**
     * Creates a fresh, empty tmpfs working directory under a caller-chosen
     * namespace (e.g. "ingest") for staging plaintext before encryption.
     * Callers must clean up with self::wipe() when done.
     */
    public static function tmpfsWorkDir(string $namespace): string
    {
        $root = "/dev/shm/mystash-{$namespace}";
        if (!is_dir($root)) {
            mkdir($root, 0700, true);
        }

        $dir = $root . '/' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);

        return $dir;
    }

    /**
     * Attempts to decrypt and parse the user's video index with the given
     * password. Returns null on any failure (unknown user, wrong password,
     * missing/corrupt archive) — this is also how login verification works,
     * since a wrong password simply fails to extract.
     */
    public function loadIndex(string $user, string $password): ?array
    {
        if (!$this->userExists($user)) {
            return null;
        }

        $archive = $this->indexArchivePath($user);
        if (!file_exists($archive)) {
            return null;
        }

        if (!is_dir(self::TMPFS_ROOT)) {
            mkdir(self::TMPFS_ROOT, 0700, true);
        }

        $extractDir = self::TMPFS_ROOT . '/' . bin2hex(random_bytes(8));

        try {
            if (!$this->crypto->extract($archive, $extractDir, $password)) {
                return null;
            }

            $jsonPath = $extractDir . '/' . basename($archive, '.enc');
            if (!file_exists($jsonPath)) {
                return null;
            }

            $json = file_get_contents($jsonPath);
            $data = json_decode($json, true);

            return is_array($data) ? $data : null;
        } finally {
            self::wipe($extractDir);
        }
    }

    /**
     * Re-encrypts $index back into the user's {user}.json.enc with $password.
     */
    public function saveIndex(string $user, string $password, array $index): bool
    {
        if (!is_dir(self::TMPFS_ROOT)) {
            mkdir(self::TMPFS_ROOT, 0700, true);
        }

        $workDir = self::TMPFS_ROOT . '/' . bin2hex(random_bytes(8));
        mkdir($workDir, 0700, true);

        $plainName = "{$user}.json";
        $plainPath = "{$workDir}/{$plainName}";

        try {
            file_put_contents($plainPath, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $videosDir = self::DATA_ROOT . "/{$user}/videos";
            if (!is_dir($videosDir)) {
                mkdir($videosDir, 0700, true);
            }

            return $this->crypto->encrypt($plainPath, $this->indexArchivePath($user), $password);
        } finally {
            self::wipe($workDir);
        }
    }

    public static function wipe(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
