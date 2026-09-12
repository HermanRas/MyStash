<?php

declare(strict_types=1);

namespace MyStash;

/**
 * A video's creators.
 *
 * A video references creators by display name, and may reference more than one
 * (`"creators": ["Alex R.", "Jamie K."]` on both the index entry and the
 * per-video metadata). Every video has at least one: emptying the list falls
 * back to `default`, which is the creator ingestion assigns and the one creator
 * that cannot be deleted.
 *
 * Entries written before multi-creator support carry a single `creator` string
 * instead; reads go through self::of() so those keep working until the
 * migration rewrites them.
 */
final class VideoCreators
{
    public const DEFAULT_CREATOR = 'default';

    /**
     * The creators of a video (index entry or metadata), in order, never empty.
     *
     * @return list<string>
     */
    public static function of(array $video): array
    {
        $names = $video['creators'] ?? null;

        if (!is_array($names)) {
            // Legacy single-creator entry.
            $names = isset($video['creator']) ? [$video['creator']] : [];
        }

        $names = array_values(array_unique(array_filter(
            array_map('strval', $names),
            static fn(string $name) => $name !== '',
        )));

        return $names !== [] ? $names : [self::DEFAULT_CREATOR];
    }

    public static function has(array $video, string $name): bool
    {
        return in_array($name, self::of($video), true);
    }

    public static function label(array $video): string
    {
        return implode(', ', self::of($video));
    }

    /**
     * Returns $video with its creator list replaced, normalised and with the
     * legacy single-creator field dropped.
     */
    public static function set(array $video, array $names): array
    {
        $video['creators'] = self::of(['creators' => $names]);
        unset($video['creator']);

        return $video;
    }

    /**
     * Repoints every video from one creator name to another (a rename).
     */
    public static function rename(array &$index, string $from, string $to): void
    {
        foreach ($index['videos'] ?? [] as &$video) {
            $names = self::of($video);
            if (in_array($from, $names, true)) {
                $video = self::set($video, array_map(
                    static fn(string $name) => $name === $from ? $to : $name,
                    $names,
                ));
            }
        }
        unset($video);
    }

    /**
     * Drops a creator from every video. A video left with nobody falls back to
     * `default` rather than being orphaned (see self::of()).
     */
    public static function remove(array &$index, string $name): void
    {
        foreach ($index['videos'] ?? [] as &$video) {
            if (self::has($video, $name)) {
                $video = self::set($video, array_values(array_diff(self::of($video), [$name])));
            }
        }
        unset($video);
    }

    /**
     * How many videos a creator appears on.
     */
    public static function videoCount(array $index, string $name): int
    {
        return count(array_filter(
            $index['videos'] ?? [],
            static fn(array $video) => self::has($video, $name),
        ));
    }
}
