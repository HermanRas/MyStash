<?php

declare(strict_types=1);

namespace MyStash;

/**
 * Playlists — named, ordered collections of videos (Docs/PLAN.md 5.4).
 *
 * Playlists live in the index, beside categories and creators, as a list:
 *
 *   "playlists": [
 *     {"id": "1", "name": "Watch Later", "videos": ["7","3","9"],
 *      "created_at": "…", "updated_at": "…"}
 *   ]
 *
 * Two things about that shape are deliberate, and both are departures from how
 * categories and creators are stored:
 *
 *  - **Keyed by id, not by name.** Categories and creators are keyed by their
 *    display name because that name *is* the reference a video holds, which is
 *    why renaming a creator has to walk every video and repoint it
 *    (VideoCreators::rename()). Nothing references a playlist by name, so
 *    there is nothing to repoint: a rename here is one field on one record.
 *
 *  - **Membership is stored once, on the playlist.** A video carries no
 *    playlist field at all. The alternative — a list on each side — is two
 *    copies of one fact, and the ordering has to live on the playlist anyway,
 *    so the video's copy could only ever be the one that goes stale. "Which
 *    playlists is this video in?" is a scan of a handful of short arrays
 *    (self::containing()), which is cheaper than the bug.
 *
 * The order of the `videos` array IS the playlist order — that is the whole
 * point of a playlist, and it is what dragging a row on the edit screen
 * rewrites.
 */
final class Playlists
{
    /** Long enough to be descriptive, short enough to render in a card. */
    public const MAX_NAME_LENGTH = 60;

    /**
     * How many videos a card on the playlists screen shows before it stops.
     * Public so the view and its tests cannot disagree about it.
     */
    public const CARD_PREVIEW_COUNT = 3;

    /**
     * @return list<array{id: string, name: string, videos: list<string>, created_at: string, updated_at: string}>
     */
    public static function all(array $index): array
    {
        $playlists = $index['playlists'] ?? [];

        if (!is_array($playlists)) {
            return [];
        }

        return array_values(array_map(self::normalise(...), array_filter($playlists, 'is_array')));
    }

    public static function find(array $index, string $id): ?array
    {
        foreach (self::all($index) as $playlist) {
            if ($playlist['id'] === $id) {
                return $playlist;
            }
        }

        return null;
    }

    /**
     * The ids of every playlist holding this video.
     *
     * @return list<string>
     */
    public static function containing(array $index, string $videoId): array
    {
        $ids = [];

        foreach (self::all($index) as $playlist) {
            if (in_array($videoId, $playlist['videos'], true)) {
                $ids[] = $playlist['id'];
            }
        }

        return $ids;
    }

    public static function isValidName(string $name): bool
    {
        $name = trim($name);

        return $name !== '' && mb_strlen($name) <= self::MAX_NAME_LENGTH;
    }

    /**
     * Creates a playlist and returns its id, or null if the name is unusable.
     *
     * Duplicate names are allowed. They are a label, not a key — two playlists
     * called "Later" are a mess the user made and can rename, not a collision
     * the datastore has to refuse.
     */
    public static function create(array &$index, string $name): ?string
    {
        if (!self::isValidName($name)) {
            return null;
        }

        $id = self::nextId($index);
        $now = date('c');

        // The high-water mark, so the next id is not the one just freed by a
        // delete. See nextId().
        $index['playlist_seq'] = (int) $id;

        $playlists = self::all($index);
        $playlists[] = [
            'id' => $id,
            'name' => trim($name),
            'videos' => [],
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $index['playlists'] = $playlists;

        return $id;
    }

    public static function rename(array &$index, string $id, string $name): bool
    {
        if (!self::isValidName($name)) {
            return false;
        }

        return self::mutate($index, $id, static function (array $playlist) use ($name): array {
            $playlist['name'] = trim($name);

            return $playlist;
        });
    }

    public static function delete(array &$index, string $id): bool
    {
        $before = self::all($index);
        $after = array_values(array_filter($before, static fn(array $p) => $p['id'] !== $id));

        $index['playlists'] = $after;

        return count($after) !== count($before);
    }

    /**
     * Replaces a playlist's contents with the given ids, in the given order.
     *
     * Ids not in the index are dropped and duplicates collapse to their first
     * position: this is what both the reorder and the add-videos modal post,
     * and neither is trusted to send something sane.
     */
    public static function setVideos(array &$index, string $id, array $videoIds, array $knownIds): bool
    {
        $clean = [];

        foreach ($videoIds as $videoId) {
            $videoId = (string) $videoId;

            if (in_array($videoId, $knownIds, true) && !in_array($videoId, $clean, true)) {
                $clean[] = $videoId;
            }
        }

        return self::mutate($index, $id, static function (array $playlist) use ($clean): array {
            $playlist['videos'] = $clean;

            return $playlist;
        });
    }

    /**
     * Adds a video to a playlist if absent, removes it if present.
     *
     * Returns the state afterwards: true = the playlist now holds the video.
     * New videos go on the end, because the order is the user's and appending
     * is the only insertion point that does not disturb it.
     */
    public static function toggle(array &$index, string $id, string $videoId): bool
    {
        $nowHolds = false;

        self::mutate($index, $id, static function (array $playlist) use ($videoId, &$nowHolds): array {
            if (in_array($videoId, $playlist['videos'], true)) {
                $playlist['videos'] = array_values(array_diff($playlist['videos'], [$videoId]));
                $nowHolds = false;
            } else {
                $playlist['videos'][] = $videoId;
                $nowHolds = true;
            }

            return $playlist;
        });

        return $nowHolds;
    }

    /**
     * Drops a video from every playlist that held it.
     *
     * Called when the video itself is deleted. Without this a playlist keeps
     * an id whose files are gone, which renders as a gap on the edit screen
     * and, worse, silently changes what "3 videos" means.
     */
    public static function forgetVideo(array &$index, string $videoId): void
    {
        $playlists = self::all($index);

        foreach ($playlists as &$playlist) {
            if (in_array($videoId, $playlist['videos'], true)) {
                $playlist['videos'] = array_values(array_diff($playlist['videos'], [$videoId]));
                $playlist['updated_at'] = date('c');
            }
        }
        unset($playlist);

        $index['playlists'] = $playlists;
    }

    /**
     * The playlist's videos as index entries, in playlist order.
     *
     * An id with no entry behind it is skipped rather than rendered as a hole.
     * forgetVideo() should mean that cannot happen; this is what keeps a stash
     * that predates it, or one edited by an older build, from rendering blanks.
     *
     * @return list<array>
     */
    public static function videosOf(array $index, array $playlist): array
    {
        $byId = [];

        foreach ($index['videos'] ?? [] as $video) {
            $byId[(string) $video['id']] = $video;
        }

        $out = [];

        foreach ($playlist['videos'] as $videoId) {
            if (isset($byId[$videoId])) {
                $out[] = $byId[$videoId];
            }
        }

        return $out;
    }

    /**
     * One more than the highest id ever issued, so ids are never reused.
     *
     * The highest id still *present* is not enough: delete the newest playlist
     * and it would be handed straight back out. Playlist ids appear in URLs,
     * so a bookmark to a deleted playlist should land nowhere, not quietly
     * open whatever was created after it. `playlist_seq` on the index is the
     * high-water mark that survives the delete.
     *
     * It is still maxed against the live records, so a stash whose playlists
     * predate the counter — or one where the counter was lost — cannot issue
     * an id that is already in use.
     */
    private static function nextId(array $index): string
    {
        $highest = (int) ($index['playlist_seq'] ?? 0);

        foreach (self::all($index) as $playlist) {
            $highest = max($highest, (int) $playlist['id']);
        }

        return (string) ($highest + 1);
    }

    private static function mutate(array &$index, string $id, callable $change): bool
    {
        $playlists = self::all($index);
        $found = false;

        foreach ($playlists as $i => $playlist) {
            if ($playlist['id'] === $id) {
                $playlist = $change($playlist);
                $playlist['updated_at'] = date('c');
                $playlists[$i] = $playlist;
                $found = true;
                break;
            }
        }

        if ($found) {
            $index['playlists'] = $playlists;
        }

        return $found;
    }

    /**
     * Fills in anything a record is missing, so a playlist written by an older
     * build (or hand-edited) reads the same as one written today.
     */
    private static function normalise(array $playlist): array
    {
        $videos = $playlist['videos'] ?? [];

        return [
            'id' => (string) ($playlist['id'] ?? '0'),
            'name' => (string) ($playlist['name'] ?? 'Untitled'),
            'videos' => is_array($videos) ? array_values(array_map('strval', $videos)) : [],
            'created_at' => (string) ($playlist['created_at'] ?? ''),
            'updated_at' => (string) ($playlist['updated_at'] ?? ''),
        ];
    }
}
