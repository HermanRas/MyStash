<?php

declare(strict_types=1);

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/VideoEncoder.php';
require __DIR__ . '/../src/VideoQuality.php';
require __DIR__ . '/../src/User.php';

use MyStash\Crypto7z;
use MyStash\User;
use MyStash\VideoEncoder;
use MyStash\VideoQuality;

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
step('ffmpeg builds the timelapse preview clip', $encoder->buildPreviewClip($fixture, $previewClip));

$fixtureDuration = $encoder->durationSeconds($fixture) ?? 0.0;
$clipDuration = $encoder->durationSeconds($previewClip) ?? 0.0;
step(
    sprintf(
        'preview clip skims the whole video (source %.1fs -> preview %.1fs)',
        $fixtureDuration,
        $clipDuration,
    ),
    $clipDuration > 0 && $clipDuration < $fixtureDuration,
);

// Derived tags (Docs/SPECIFICATIONS.md §2.3) — pure logic, no ffmpeg needed.
$qualityCases = [
    [4320, '8K'], [2160, '4K'], [1440, '2K'], [1080, 'Full HD'],
    [720, 'HD'],
    // 360p and 480p are both just standard definition — no per-height badge.
    [480, 'SD'], [360, 'SD'], [240, 'SD'],
    // Between tiers a video takes the lower one.
    [1439, 'Full HD'], [719, 'SD'], [null, null],
];

foreach ($qualityCases as [$height, $expected]) {
    step(
        sprintf('quality tag for height %s is %s', $height ?? 'unknown', $expected ?? 'none'),
        VideoQuality::tagForHeight($height) === $expected,
    );
}

step('mp4/hevc counts as converted', VideoQuality::isNotConverted('mp4', 'hevc') === false);
step('mp4/h264 counts as not converted', VideoQuality::isNotConverted('mp4', 'h264') === true);
step('mov/hevc counts as not converted', VideoQuality::isNotConverted('mov', 'hevc') === true);

$fileHeight = $encoder->videoHeight($fixture);
step(
    sprintf('ffprobe reads the fixture height (%s) and it tags as %s', $fileHeight ?? 'null', VideoQuality::tagForHeight($fileHeight) ?? 'none'),
    $fileHeight !== null,
);

step(
    'a 23-character password is rejected, 24 accepted',
    !User::isValidPassword(str_repeat('a', 23)) && User::isValidPassword(str_repeat('a', 24)),
);

echo PHP_EOL . "All smoke tests passed." . PHP_EOL;
