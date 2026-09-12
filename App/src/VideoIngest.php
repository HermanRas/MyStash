<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/VideoQuality.php';

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
     * @return array the new video's index summary entry
     */
    public function ingest(
        string $user,
        string $password,
        string $uploadedTmpPath,
        string $originalFilename,
        array $index,
        ?float $previewTimestamp = null,
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
            $this->encoder->extractFrame($originalPath, $previewImagePath, $previewAt);

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

            $metadata = [
                'id' => $id,
                'title' => pathinfo($originalFilename, PATHINFO_FILENAME),
                'description' => '',
                'creator' => 'default',
                'length_seconds' => (int) round($duration),
                'views' => 0,
                'format' => $ext,
                'codec' => $codec,
                'height' => $height,
                'quality' => $quality,
                'not_converted' => $notConverted,
                'uploaded_at' => date('c'),
                'categories' => $assignments,
                'preview_capture_seconds' => $previewAt,
            ];
            $metadataPath = "{$workDir}/{$id}.json";
            file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $videoDir = Datastore::videoDir($user, $id);
            mkdir($videoDir, 0700, true);

            $this->crypto->encrypt($originalPath, "{$videoDir}/{$id}.mp4.enc", $password);
            $this->crypto->encrypt($previewClipPath, "{$videoDir}/{$id}.mp4.preview.enc", $password);
            $this->crypto->encrypt($previewImagePath, "{$videoDir}/{$id}.jpg.preview.enc", $password);
            $this->crypto->encrypt($metadataPath, "{$videoDir}/{$id}.json.enc", $password);

            return [
                'id' => $id,
                'title' => $metadata['title'],
                'description' => '',
                'creator' => 'default',
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
