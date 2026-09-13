<?php

declare(strict_types=1);

/**
 * Formatting shared by the pages that render video rows.
 *
 * This was two identical copies — one in wall.php, one in video.php — until
 * the playlist screens wanted a third. Two copies of four lines is a shrug;
 * three is the point at which they start drifting, and a duration that reads
 * differently on the wall than on the playlist it came from is exactly the
 * kind of difference nobody reports and everybody notices.
 */

if (!function_exists('formatLength')) {
    /**
     * A duration as mm:ss, or h:mm:ss once it runs past an hour.
     */
    function formatLength(int $seconds): string
    {
        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
