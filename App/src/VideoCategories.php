<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/Crypto7z.php';
require_once __DIR__ . '/Datastore.php';

/**
 * Category assignments for a video: a reference to a global category (by name)
 * plus the timestamp it points at. The same category may be assigned more than
 * once at different timestamps.
 *
 * The assignments live in the per-video metadata (Video{ID}/{ID}.json.enc);
 * the index keeps only the de-duplicated names so the wall can render and
 * filter without decrypting every video's metadata.
 */
final class VideoCategories
{
    public function __construct(private Datastore $datastore = new Datastore())
    {
    }

    /**
     * Assignments for a video, sorted by timestamp.
     */
    public function load(string $user, string $password, string $id): array
    {
        $metadata = $this->datastore->loadVideoMetadata($user, $password, $id);
        $assignments = $metadata['categories'] ?? [];

        usort($assignments, static fn($a, $b) => $a['timestamp_seconds'] <=> $b['timestamp_seconds']);

        return $assignments;
    }

    /**
     * Writes assignments to the video's metadata and mirrors the unique names
     * into the given index (by reference) so both stay consistent.
     */
    public function save(string $user, string $password, string $id, array $assignments, array &$index): bool
    {
        usort($assignments, static fn($a, $b) => $a['timestamp_seconds'] <=> $b['timestamp_seconds']);

        $metadata = $this->datastore->loadVideoMetadata($user, $password, $id)
            ?? $this->metadataFromIndex($index, $id);

        $metadata['categories'] = $assignments;

        $names = Datastore::categoryNames($assignments);
        foreach ($index['videos'] as &$video) {
            if ($video['id'] === $id) {
                $video['categories'] = $names;
                break;
            }
        }
        unset($video);

        return $this->datastore->saveVideoMetadata($user, $password, $id, $metadata);
    }

    /**
     * Removes a category everywhere it is assigned, across every video.
     * Used when a global category is deleted.
     */
    public function removeCategoryEverywhere(string $user, string $password, string $name, array &$index): void
    {
        foreach ($index['videos'] as &$video) {
            if (!in_array($name, $video['categories'] ?? [], true)) {
                continue;
            }

            $assignments = array_values(array_filter(
                $this->load($user, $password, $video['id']),
                static fn($a) => $a['name'] !== $name,
            ));

            $metadata = $this->datastore->loadVideoMetadata($user, $password, $video['id']);
            if ($metadata !== null) {
                $metadata['categories'] = $assignments;
                $this->datastore->saveVideoMetadata($user, $password, $video['id'], $metadata);
            }

            $video['categories'] = Datastore::categoryNames($assignments);
        }
        unset($video);
    }

    /**
     * Seeds per-video metadata from the index entry, for videos whose metadata
     * archive doesn't exist yet (e.g. entries created before metadata files
     * were written, or demo data).
     */
    private function metadataFromIndex(array $index, string $id): array
    {
        foreach ($index['videos'] as $video) {
            if ($video['id'] === $id) {
                return [
                    'id' => $video['id'],
                    'title' => $video['title'],
                    'description' => $video['description'] ?? '',
                    'creator' => $video['creator'],
                    'length_seconds' => $video['length_seconds'],
                    'views' => $video['views'],
                    'format' => $video['format'],
                    'codec' => $video['codec'],
                    'not_converted' => $video['not_converted'],
                    'uploaded_at' => null,
                    'categories' => [],
                    'preview_capture_seconds' => null,
                ];
            }
        }

        return ['id' => $id, 'categories' => []];
    }

    public static function formatTimestamp(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * Parses "hh:mm:ss" (or "mm:ss", or a plain second count) into seconds.
     */
    public static function parseTimestamp(string $value): int
    {
        $parts = array_reverse(explode(':', trim($value)));
        $seconds = 0;
        $multiplier = 1;

        foreach (array_slice($parts, 0, 3) as $part) {
            $seconds += ((int) $part) * $multiplier;
            $multiplier *= 60;
        }

        return max(0, $seconds);
    }
}
