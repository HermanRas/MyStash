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

    public function durationSeconds(string $inputPath): ?float
    {
        $command = [
            $this->ffprobe,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $inputPath,
        ];

        [$exitCode, $stdout] = $this->run($command);

        if ($exitCode !== 0 || trim($stdout) === '') {
            return null;
        }

        return (float) trim($stdout);
    }

    public function videoHeight(string $inputPath): ?int
    {
        $command = [
            $this->ffprobe,
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=height',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $inputPath,
        ];

        [$exitCode, $stdout] = $this->run($command);

        if ($exitCode !== 0 || trim($stdout) === '') {
            return null;
        }

        return (int) trim($stdout);
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
     * Builds the silent preview clip: a timelapse that samples one frame every
     * $intervalSeconds across the whole video and plays them back at
     * $playbackFps, so hovering skims the entire video rather than showing one
     * continuous moment (Docs/SPECIFICATIONS.md §2.3).
     */
    public function buildPreviewClip(
        string $inputPath,
        string $outputPath,
        float $intervalSeconds = 15.0,
        int $playbackFps = 4,
    ): bool {
        // Done in two passes — sample the stills, then join them at the
        // playback rate. Re-timing in a single pass (setpts/-r) makes ffmpeg
        // drop most of the sampled frames.
        $framesDir = dirname($outputPath) . '/preview_frames_' . bin2hex(random_bytes(4));
        if (!mkdir($framesDir, 0700, true)) {
            return false;
        }

        try {
            $sampled = $this->run([
                $this->ffmpeg,
                '-y',
                '-i', $inputPath,
                '-vf', sprintf('fps=1/%s,scale=480:-2', $this->formatInterval($intervalSeconds)),
                '-an',
                "{$framesDir}/frame_%04d.jpg",
            ]);

            if ($sampled[0] !== 0 || glob("{$framesDir}/frame_*.jpg") === []) {
                return false;
            }

            return $this->run([
                $this->ffmpeg,
                '-y',
                '-framerate', (string) $playbackFps,
                '-i', "{$framesDir}/frame_%04d.jpg",
                '-an',
                '-c:v', 'libx264',
                '-crf', '30',
                '-pix_fmt', 'yuv420p',
                $outputPath,
            ])[0] === 0;
        } finally {
            array_map('unlink', glob("{$framesDir}/*") ?: []);
            rmdir($framesDir);
        }
    }

    private function formatInterval(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');
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
