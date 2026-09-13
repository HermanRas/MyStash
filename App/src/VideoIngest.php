<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/VideoQuality.php';
require_once __DIR__ . '/VideoCreators.php';
require_once __DIR__ . '/VideoPreview.php';

/**
 * Upload/ingestion pipeline (Docs/PLAN.md Phase 3, Docs/SPECIFICATIONS.md §2.3):
 * format-checks the upload, generates a preview image + silent preview clip,
 * encrypts everything into the datastore layout, and returns the index
 * summary entry to append to the user's video index.
 */
final class VideoIngest
{
    public function __construct(
        private VideoEncoder $encoder = new VideoEncoder(),
        private Crypto7z $crypto = new Crypto7z(),
    ) {
    }

    /**
     * @param array $index current decrypted index, used only to pick the next ID
     * @param float|null $previewTimestamp user-chosen preview capture point in
     *        seconds; defaults to 15s (clamped to the video's duration)
     * @param string|null $previewImagePathIn a picture the user supplied to use
     *        as the preview instead of a frame from the video (3.9). Wins over
     *        $previewTimestamp when both are given — someone who attached an
     *        image meant it.
     * @return array the new video's index summary entry
     */
    public function ingest(
        string $user,
        string $password,
        string $uploadedTmpPath,
        string $originalFilename,
        array $index,
        ?float $previewTimestamp = null,
        ?string $previewImagePathIn = null,
    ): array {
        $id = Datastore::nextVideoId($index);
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION)) ?: 'mp4';

        $workDir = Datastore::tmpfsWorkDir('ingest');

        try {
            $originalPath = "{$workDir}/original.{$ext}";
            if (!move_uploaded_file($uploadedTmpPath, $originalPath)) {
                // Fall back to copy for non-HTTP-upload callers (e.g. CLI/tests).
                if (!copy($uploadedTmpPath, $originalPath)) {
                    throw new \RuntimeException('Failed to stage uploaded file');
                }
            }

            $codec = $this->encoder->videoCodec($originalPath);
            $notConverted = VideoQuality::isNotConverted($ext, $codec);

            $duration = $this->encoder->durationSeconds($originalPath) ?? 0.0;
            $previewAt = $previewTimestamp ?? min(15.0, max(0.0, $duration - 0.5));

            $previewImagePath = "{$workDir}/preview.jpg";
            $customPreview = false;

            // A supplied picture is re-encoded to JPEG rather than stored as
            // it arrived, so the archive's name describes its contents and
            // nothing but ffmpeg's own output is ever served back (3.9). If it
            // turns out not to be an image, fall through to the frame grab —
            // refusing the whole upload over the thumbnail would be a poor
            // trade for a video that has already been transferred.
            if ($previewImagePathIn !== null) {
                $customPreview = $this->encoder->convertImage(
                    $previewImagePathIn,
                    $previewImagePath,
                    VideoPreview::MAX_EDGE,
                );
            }

            if (!$customPreview) {
                $this->encoder->extractFrame($originalPath, $previewImagePath, $previewAt);
            }

            // The clip is a timelapse over the whole video, so unlike the
            // preview image it doesn't start from the chosen timestamp.
            $previewClipPath = "{$workDir}/preview.mp4";
            $this->encoder->buildPreviewClip($originalPath, $previewClipPath);

            // Both derived tags come from the file itself and are never
            // user-editable — see VideoQuality.
            $height = $this->encoder->videoHeight($originalPath);
            $quality = VideoQuality::tagForHeight($height);

            // Category assignments reference a global category by name and
            // carry the timestamp they point at (see VideoCategories).
            $assignments = $notConverted ? [['name' => 'Not Converted', 'timestamp_seconds' => 0]] : [];
            $categories = Datastore::categoryNames($assignments);

            // Claim the directory before anything is written into it.
            //
            // nextVideoId() reads the *index*, so two uploads that both start
            // before either has saved compute the same id — and the second
            // would then encrypt its files straight over the first's, losing
            // that video outright. mkdir() is the atomic test-and-set that
            // settles it: whoever creates the directory owns the id, and the
            // loser tries the next one. Single-threaded PHP made this
            // unreachable; nginx in front of php-fpm (7.5) does not.
            $videoDir = Datastore::videoDir($user, $id);

            while (!@mkdir($videoDir, 0700, true)) {
                if (!is_dir($videoDir)) {
                    throw new \RuntimeException("Could not create {$videoDir}");
                }

                $id = (string) ((int) $id + 1);
                $videoDir = Datastore::videoDir($user, $id);
            }

            $metadata = [
                'id' => $id,
                'title' => pathinfo($originalFilename, PATHINFO_FILENAME),
                'description' => '',
                'creators' => [VideoCreators::DEFAULT_CREATOR],
                'length_seconds' => (int) round($duration),
                'views' => 0,
                'format' => $ext,
                'codec' => $codec,
                'height' => $height,
                'quality' => $quality,
                'not_converted' => $notConverted,
                'uploaded_at' => date('c'),
                'categories' => $assignments,
                // null when the picture came from the user: it no longer
                // corresponds to any point in the video.
                'preview_capture_seconds' => $customPreview ? null : $previewAt,
            ];
            $metadataPath = "{$workDir}/{$id}.json";
            file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->crypto->encrypt($originalPath, "{$videoDir}/{$id}.mp4.enc", $password);
            $this->crypto->encrypt($previewClipPath, "{$videoDir}/{$id}.mp4.preview.enc", $password);
            $this->crypto->encrypt($previewImagePath, "{$videoDir}/{$id}.jpg.preview.enc", $password);
            $this->crypto->encrypt($metadataPath, "{$videoDir}/{$id}.json.enc", $password);

            return [
                'id' => $id,
                'title' => $metadata['title'],
                'description' => '',
                'creators' => $metadata['creators'],
                'length_seconds' => $metadata['length_seconds'],
                'views' => 0,
                'format' => $ext,
                'codec' => $codec,
                'height' => $height,
                'not_converted' => $notConverted,
                'uploaded_at' => $metadata['uploaded_at'],
                'categories' => $categories,
                'quality' => $quality,
                'tile_gradient' => $this->randomTileGradient(),
            ];
        } finally {
            Datastore::wipe($workDir);
        }
    }

    private function randomTileGradient(): array
    {
        $palettes = [
            ['#3a3a3a', '#161616'],
            ['#4a3a2a', '#1a1410'],
            ['#2a3a3a', '#101a1a'],
            ['#3a2a3a', '#1a101a'],
            ['#3a3a2a', '#181810'],
            ['#2a2a3a', '#10101a'],
        ];

        return $palettes[array_rand($palettes)];
    }
}
