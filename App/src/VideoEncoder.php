<?php

declare(strict_types=1);

namespace MyStash;

/**
 * Wraps `ffmpeg`/`ffprobe` for format checks and MP4/H.265 conversion.
 */
final class VideoEncoder
{
    public function __construct(
        private string $ffmpeg = 'ffmpeg',
        private string $ffprobe = 'ffprobe',
    ) {
    }

    /**
     * Returns the video codec name (e.g. "h264", "hevc") for $inputPath.
     */
    public function videoCodec(string $inputPath): ?string
    {
        $command = [
            $this->ffprobe,
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=codec_name',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $inputPath,
        ];

        [$exitCode, $stdout] = $this->run($command);

        if ($exitCode !== 0) {
            return null;
        }

        $codec = trim($stdout);

        return $codec !== '' ? $codec : null;
    }

    public function isAlreadyMp4Hevc(string $inputPath): bool
    {
        return strtolower((string) pathinfo($inputPath, PATHINFO_EXTENSION)) === 'mp4'
            && $this->videoCodec($inputPath) === 'hevc';
    }

    /**
     * Converts $inputPath to MP4/H.265 at $outputPath.
     */
    public function convertToMp4Hevc(string $inputPath, string $outputPath): bool
    {
        $command = [
            $this->ffmpeg,
            '-y',
            '-i', $inputPath,
            '-c:v', 'libx265',
            '-c:a', 'aac',
            $outputPath,
        ];

        return $this->run($command)[0] === 0;
    }

    /**
     * Extracts a single frame at $timestampSeconds as a JPEG.
     */
    public function extractFrame(string $inputPath, string $outputPath, float $timestampSeconds = 15.0): bool
    {
        $command = [
            $this->ffmpeg,
            '-y',
            '-ss', (string) $timestampSeconds,
            '-i', $inputPath,
            '-frames:v', '1',
            $outputPath,
        ];

        return $this->run($command)[0] === 0;
    }

    /**
     * Builds a short, silent, low-bitrate preview clip starting at $startSeconds.
     */
    public function buildPreviewClip(
        string $inputPath,
        string $outputPath,
        float $startSeconds = 15.0,
        float $durationSeconds = 4.0,
    ): bool {
        $command = [
            $this->ffmpeg,
            '-y',
            '-ss', (string) $startSeconds,
            '-i', $inputPath,
            '-t', (string) $durationSeconds,
            '-an',
            '-c:v', 'libx264',
            '-crf', '30',
            $outputPath,
        ];

        return $this->run($command)[0] === 0;
    }

    /**
     * @return array{0: int, 1: string} [exitCode, stdout]
     */
    private function run(array $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start ffmpeg/ffprobe process');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, $stdout];
    }
}
