<?php

declare(strict_types=1);

namespace MyStash;

/**
 * Derived video tags: the quality badge and the "not converted" flag.
 *
 * Both are *calculated*, never typed in — they describe the file, so letting a
 * user edit them would only let them lie about it. They are recomputed from the
 * stored technical facts (pixel height, container, codec) every time a video is
 * saved, ingested or converted. See Docs/SPECIFICATIONS.md §2.3.
 */
final class VideoQuality
{
    /**
     * Standard tiers, keyed on vertical pixel count (progressive scan), highest
     * first. A video is tagged with the highest tier its height reaches.
     *
     *   4320p  8K UHD      7680 × 4320
     *   2160p  4K UHD      3840 × 2160
     *   1440p  2K / QHD    2560 × 1440
     *   1080p  Full HD     1920 × 1080
     *    720p  HD          1280 ×  720   (the minimum for "high definition")
     *
     * Below 720p there is no separate badge per height: 360p and 480p are both
     * simply standard definition, so both are tagged `SD`.
     *
     * @var list<array{0: int, 1: string}>
     */
    private const TIERS = [
        [4320, '8K'],
        [2160, '4K'],
        [1440, '2K'],
        [1080, 'Full HD'],
        [720, 'HD'],
        [0, 'SD'],
    ];

    /**
     * The quality badge for a given pixel height, or null if the height is
     * unknown (nothing to claim, so the tile shows no badge).
     */
    public static function tagForHeight(?int $height): ?string
    {
        if ($height === null || $height <= 0) {
            return null;
        }

        foreach (self::TIERS as [$minimum, $tag]) {
            if ($height >= $minimum) {
                return $tag;
            }
        }

        return 'SD';
    }

    /**
     * True when the stored file is not yet MP4/H.265, which is what drives the
     * "Not Converted" tag (Docs/SPECIFICATIONS.md §2.4).
     */
    public static function isNotConverted(?string $format, ?string $codec): bool
    {
        return !(strtolower((string) $format) === 'mp4' && strtolower((string) $codec) === 'hevc');
    }

    /**
     * Applies both derived fields to a video record (index entry or per-video
     * metadata) and returns it. The single place either value is ever set.
     */
    public static function apply(array $video): array
    {
        $video['quality'] = self::tagForHeight(isset($video['height']) ? (int) $video['height'] : null);
        $video['not_converted'] = self::isNotConverted($video['format'] ?? null, $video['codec'] ?? null);

        return $video;
    }
}
