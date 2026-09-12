<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/Crypto7z.php';

/**
 * Reads/writes the per-user encrypted video index ({user}.json.enc) and the
 * per-video metadata files (Video{ID}/{ID}.json.enc).
 *
 * See Docs/SPECIFICATIONS.md §3 for the on-disk datastore layout and §2.1
 * for the login flow this class implements the decrypt/verify step of.
 *
 * Index schema ({user}.json — the global, per-user record):
 * {
 *   "categories": { "<name>": "<hex color>" },   // global category definitions
 *   "creators":   { "<name>": {"id","name","age","gender","bio"} },
 *                                                // DENORMALIZED — see CreatorStore
 *   "videos": [
 *     {
 *       "id", "title", "description", "creators": ["<name>", ...],
 *       "length_seconds", "views",
 *       "format", "codec", "height", "not_converted", "uploaded_at",
 *       "quality", "tile_gradient": ["#a","#b"],
 *       "categories": ["<name>", ...]   // DENORMALIZED, de-duplicated names only
 *     }
 *   ]
 * }
 *
 * `quality` and `not_converted` are DERIVED from height/format/codec and are
 * never user-editable — VideoQuality::apply() is the only thing that sets them.
 *
 * Per-video metadata schema (Video{ID}/{ID}.json — the source of truth for a
 * single video's category assignments):
 * {
 *   "id", "title", "description", "creators": ["<name>", ...],
 *   "length_seconds", "views",
 *   "format", "codec", "height", "quality", "not_converted", "uploaded_at",
 *   "categories": [ {"name": "<global category name>", "timestamp_seconds": 0} ],
 *   "preview_capture_seconds"
 * }
 *
 * A video may carry the same category more than once at different timestamps —
 * each entry is an independent assignment. The index keeps only the unique
 * names so the wall grid and its filters can render without decrypting every
 * video's metadata archive on each page load.
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

    public static function creatorDir(string $user, string $id): string
    {
        return self::DATA_ROOT . "/{$user}/creators/Creator{$id}";
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
     * One more than the highest creator ID already handed out in the index.
     */
    public static function nextCreatorId(array $index): string
    {
        $max = 0;
        foreach ($index['creators'] ?? [] as $creator) {
            $max = max($max, (int) ($creator['id'] ?? 0));
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

        return $this->loadJsonArchive($this->indexArchivePath($user), "{$user}.json", $password);
    }

    /**
     * Re-encrypts $index back into the user's {user}.json.enc with $password.
     */
    public function saveIndex(string $user, string $password, array $index): bool
    {
        $videosDir = self::DATA_ROOT . "/{$user}/videos";
        if (!is_dir($videosDir)) {
            mkdir($videosDir, 0700, true);
        }

        return $this->saveJsonArchive($this->indexArchivePath($user), "{$user}.json", $password, $index);
    }

    public function loadVideoMetadata(string $user, string $password, string $id): ?array
    {
        return $this->loadJsonArchive(self::videoDir($user, $id) . "/{$id}.json.enc", "{$id}.json", $password);
    }

    public function saveVideoMetadata(string $user, string $password, string $id, array $metadata): bool
    {
        $dir = self::videoDir($user, $id);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $this->saveJsonArchive("{$dir}/{$id}.json.enc", "{$id}.json", $password, $metadata);
    }

    public function loadCreatorMetadata(string $user, string $password, string $id): ?array
    {
        return $this->loadJsonArchive(self::creatorDir($user, $id) . "/{$id}.json.enc", "{$id}.json", $password);
    }

    public function saveCreatorMetadata(string $user, string $password, string $id, array $metadata): bool
    {
        $dir = self::creatorDir($user, $id);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $this->saveJsonArchive("{$dir}/{$id}.json.enc", "{$id}.json", $password, $metadata);
    }

    /**
     * The unique category names of a video's assignments, in first-seen order —
     * what the index carries for wall rendering and filtering.
     */
    public static function categoryNames(array $assignments): array
    {
        $names = [];
        foreach ($assignments as $assignment) {
            $name = $assignment['name'] ?? null;
            if ($name !== null && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function loadJsonArchive(string $archivePath, string $plainName, string $password): ?array
    {
        if (!file_exists($archivePath)) {
            return null;
        }

        $extractDir = self::tmpfsWorkDir('extract');

        try {
            if (!$this->crypto->extract($archivePath, $extractDir, $password)) {
                return null;
            }

            $jsonPath = "{$extractDir}/{$plainName}";
            if (!file_exists($jsonPath)) {
                return null;
            }

            $data = json_decode((string) file_get_contents($jsonPath), true);

            return is_array($data) ? $data : null;
        } finally {
            self::wipe($extractDir);
        }
    }

    private function saveJsonArchive(string $archivePath, string $plainName, string $password, array $data): bool
    {
        $workDir = self::tmpfsWorkDir('extract');
        $plainPath = "{$workDir}/{$plainName}";

        try {
            file_put_contents($plainPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->crypto->encrypt($plainPath, $archivePath, $password);
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
