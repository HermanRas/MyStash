<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/Crypto7z.php';
require_once __DIR__ . '/Datastore.php';
require_once __DIR__ . '/VideoEncoder.php';

/**
 * Setting and changing a video's preview image (Docs/PLAN.md 3.9).
 *
 * Until now the preview frame was chosen once, at upload time, from a
 * timestamp — and that was the end of it. If the frame landed on a black
 * fade or a blurred pan, the tile wore it for good. This adds the two ways
 * out the plan asks for: pick a different timestamp, or supply your own
 * image, both of them afterwards from the video's edit screen.
 *
 * Three things this has to get right:
 *
 *  - **Never overwrite the only copy.** The preview lives in exactly one
 *    place, `{ID}.jpg.preview.enc`, so it goes through `Crypto7z::replace()`
 *    (4.29) rather than `encrypt()` — the new image is written beside the old
 *    one, verified, and only then swapped in. A failed re-preview leaves the
 *    tile exactly as it was.
 *
 *  - **Store what the filename claims.** Whatever is uploaded — PNG, WebP,
 *    whatever the phone produced — is re-encoded to JPEG, because the archive
 *    is named `.jpg.preview.enc` and `media.php` serves it as `image/jpeg`.
 *    The same rule the creator avatars follow (4.10).
 *
 *  - **Never serve back the bytes that were uploaded.** What is stored is
 *    ffmpeg's re-encode of the image, not the file the browser sent. An
 *    upload that is not really an image fails at that step and is refused;
 *    one that is an image *and also* something else arrives as a plain JPEG
 *    of its pixels, with whatever was hiding in the container gone.
 */
final class VideoPreview
{
    /**
     * Uploaded images are capped at this on the longest edge.
     *
     * Larger than the 512px the creator avatars use: an avatar renders as a
     * ~64px circle, but this is the wall tile's thumbnail *and* the poster
     * behind the watch page's player, so it wants to survive being shown
     * large. A frame grabbed from the video is whatever the video's own
     * resolution is, and this keeps a supplied image in the same range
     * without storing an untouched 6000px photo to be scaled down on every
     * wall render.
     *
     * Public because ingestion applies the same cap to a picture supplied at
     * upload time, and two copies of this number would drift apart.
     */
    public const MAX_EDGE = 1280;

    public function __construct(
        private VideoEncoder $encoder = new VideoEncoder(),
        private Crypto7z $crypto = new Crypto7z(),
        private Datastore $datastore = new Datastore(),
    ) {
    }

    /**
     * Replaces the preview with a frame taken from the video itself.
     *
     * This is the expensive one: the frame has to come from the video, and the
     * video is encrypted, so it is extracted to tmpfs first. That is the same
     * work `media.php` does to play it, so it is not a new cost — but it is
     * the reason this is worth saying out loud rather than looking like a
     * cheap metadata edit.
     *
     * @return array{ok: bool, message: string}
     */
    public function fromTimestamp(string $user, string $password, string $id, float $seconds): array
    {
        if ($seconds < 0 || !is_finite($seconds)) {
            return ['ok' => false, 'message' => 'That is not a valid timestamp.'];
        }

        $videoArchive = Datastore::videoDir($user, $id) . "/{$id}.mp4.enc";

        if (!is_file($videoArchive)) {
            return ['ok' => false, 'message' => 'There is no video file to take a frame from.'];
        }

        $workDir = Datastore::tmpfsWorkDir('preview');

        try {
            if (!$this->crypto->extract($videoArchive, $workDir, $password)) {
                return ['ok' => false, 'message' => 'The video could not be opened.'];
            }

            $video = glob("{$workDir}/*")[0] ?? null;

            if ($video === null) {
                return ['ok' => false, 'message' => 'The video could not be opened.'];
            }

            // Asking for a frame past the end leaves ffmpeg with nothing to
            // write, which surfaces as a confusing "could not be captured".
            // Say the true thing instead.
            $duration = $this->encoder->durationSeconds($video);

            if ($duration !== null && $seconds > $duration) {
                return [
                    'ok' => false,
                    'message' => sprintf('This video is only %d seconds long.', (int) round($duration)),
                ];
            }

            $framePath = "{$workDir}/preview.jpg";

            if (!$this->encoder->extractFrame($video, $framePath, $seconds)) {
                return ['ok' => false, 'message' => 'No frame could be captured at that point.'];
            }

            return $this->store($user, $password, $id, $framePath, $seconds);
        } finally {
            Datastore::wipe($workDir);
        }
    }

    /**
     * Replaces the preview with an image the user supplied.
     *
     * @return array{ok: bool, message: string}
     */
    public function fromUpload(string $user, string $password, string $id, string $uploadedTmpPath): array
    {
        $workDir = Datastore::tmpfsWorkDir('preview');

        try {
            $staged = "{$workDir}/upload";

            if (!move_uploaded_file($uploadedTmpPath, $staged) && !copy($uploadedTmpPath, $staged)) {
                return ['ok' => false, 'message' => 'The image could not be read.'];
            }

            $jpgPath = "{$workDir}/preview.jpg";

            // This doubles as the validation: if ffmpeg cannot decode it as an
            // image, it is not one, and nothing is stored.
            if (!$this->encoder->convertImage($staged, $jpgPath, self::MAX_EDGE)) {
                return ['ok' => false, 'message' => 'That file is not an image this can read.'];
            }

            // null, not a number: the preview no longer corresponds to any
            // point in the video, and recording the timestamp it *used* to be
            // taken from would be a lie the edit screen then displays.
            return $this->store($user, $password, $id, $jpgPath, null);
        } finally {
            Datastore::wipe($workDir);
        }
    }

    /**
     * Encrypts the prepared JPEG over the existing preview archive and records
     * where it came from.
     *
     * @return array{ok: bool, message: string}
     */
    private function store(
        string $user,
        string $password,
        string $id,
        string $jpgPath,
        ?float $capturedAt,
    ): array {
        $archive = Datastore::videoDir($user, $id) . "/{$id}.jpg.preview.enc";

        // replace(), never encrypt(): encrypt() removes its destination before
        // writing, so a failure part way through would leave the video with no
        // preview at all rather than the one it already had (4.29).
        if (!$this->crypto->replace($jpgPath, $archive, $password)) {
            return ['ok' => false, 'message' => 'The new preview could not be saved. The old one is unchanged.'];
        }

        // Bookkeeping only. If this fails the preview is already correctly
        // stored, so the user is not told the operation failed — the worst
        // case is an edit screen showing a stale capture point.
        $metadata = $this->datastore->loadVideoMetadata($user, $password, $id);

        if ($metadata !== null) {
            $metadata['preview_capture_seconds'] = $capturedAt;
            $this->datastore->saveVideoMetadata($user, $password, $id, $metadata);
        }

        return [
            'ok' => true,
            'message' => $capturedAt === null
                ? 'Preview image updated.'
                : sprintf('Preview taken from %s.', self::formatTimestamp($capturedAt)),
        ];
    }

    public static function formatTimestamp(float $seconds): string
    {
        $whole = (int) round($seconds);

        return $whole >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($whole, 3600), intdiv($whole % 3600, 60), $whole % 60)
            : sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
    }
}
