<?php

declare(strict_types=1);

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/VideoEncoder.php';

use MyStash\Crypto7z;
use MyStash\VideoEncoder;

function step(string $label, bool $ok): void
{
    echo ($ok ? "[PASS] " : "[FAIL] ") . $label . PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}

$fixture = __DIR__ . '/../Data/TestUser/videos/1.mp4';
if (!file_exists($fixture)) {
    fwrite(STDERR, "Fixture not found: {$fixture}\n");
    exit(1);
}

$workDir = sys_get_temp_dir() . '/mystash_smoke_' . uniqid();
mkdir($workDir, 0700, true);

$archive = $workDir . '/1.mp4.7z';
$extractDir = $workDir . '/extracted';
$password = 'smoke-test-password-' . bin2hex(random_bytes(4));

$crypto = new Crypto7z();

step('encrypt fixture into .7z archive', $crypto->encrypt($fixture, $archive, $password));
step('archive file exists', file_exists($archive));

$wrongPasswordExtractDir = $workDir . '/wrong';
step(
    'extraction fails with wrong password',
    $crypto->extract($archive, $wrongPasswordExtractDir, 'not-the-right-password') === false,
);

step('extract archive with correct password', $crypto->extract($archive, $extractDir, $password));

$extractedFile = $extractDir . '/' . basename($fixture);
step('extracted file exists', file_exists($extractedFile));
step(
    'extracted file matches original (byte-for-byte)',
    file_exists($extractedFile) && hash_file('sha256', $fixture) === hash_file('sha256', $extractedFile),
);

$encoder = new VideoEncoder();

$codec = $encoder->videoCodec($fixture);
step("ffprobe reads a video codec from fixture (got: " . ($codec ?? 'null') . ")", $codec !== null);

$converted = $workDir . '/1_converted.mp4';
step('ffmpeg converts fixture to MP4/H.265', $encoder->convertToMp4Hevc($fixture, $converted));
step('converted file is now MP4/HEVC', $encoder->isAlreadyMp4Hevc($converted));

$previewImage = $workDir . '/1_preview.jpg';
step('ffmpeg extracts a preview frame at 15s', $encoder->extractFrame($fixture, $previewImage, 15.0));

$previewClip = $workDir . '/1_preview.mp4';
step('ffmpeg builds a short preview clip', $encoder->buildPreviewClip($fixture, $previewClip));

echo PHP_EOL . "All smoke tests passed." . PHP_EOL;
