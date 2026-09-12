<?php

declare(strict_types=1);

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/VideoEncoder.php';
require __DIR__ . '/../src/VideoQuality.php';
require __DIR__ . '/../src/User.php';
require __DIR__ . '/../src/VideoQuery.php';

use MyStash\Crypto7z;
use MyStash\User;
use MyStash\VideoEncoder;
use MyStash\VideoQuality;
use MyStash\VideoQuery;

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

// Search (Docs/PLAN.md 5.1) — pure logic over index entries, no stash needed.
$library = [
    ['id' => '1', 'title' => 'Skateboarding at dusk', 'creators' => ['Jamie K.'],
     'categories' => ['Sport', 'Outdoors'], 'length_seconds' => 60, 'views' => 0, 'uploaded_at' => '2026-01-01'],
    ['id' => '2', 'title' => 'Office slip and fall', 'creators' => ['default', 'Alex R.'],
     'categories' => ['Personal'], 'length_seconds' => 62, 'views' => 3, 'uploaded_at' => '2026-02-01'],
    ['id' => '3', 'title' => 'Beach day', 'creators' => ['Alex R.'],
     'categories' => ['Outdoors'], 'length_seconds' => 120, 'views' => 9, 'uploaded_at' => '2026-03-01'],
];

$search = static function (string $term) use ($library): array {
    $found = VideoQuery::fromRequest(['q' => $term])->apply($library);

    return array_column($found, 'id');
};

$searchCases = [
    // field it should match on   => term, expected ids
    'title' => ['skateboarding', ['1']],
    'title, case-insensitively' => ['SKATEBOARDING', ['1']],
    'a partial word in a title' => ['skat', ['1']],
    'a creator name' => ['Alex', ['3', '2']],
    'a creator who is not the first credited' => ['Alex R.', ['3', '2']],
    'a category tag' => ['outdoors', ['3', '1']],
    'nothing when there is no match' => ['zzz', []],
];

foreach ($searchCases as $label => [$term, $expected]) {
    $got = $search($term);
    step(
        sprintf('search matches on %s ("%s" -> %s)', $label, $term, implode(',', $got) ?: 'none'),
        $got === $expected,
    );
}

// Several words must all match, but each may match a different field — this is
// what makes "jamie sport" a useful query rather than an impossible one.
step(
    'every term must match, across different fields ("jamie sport")',
    $search('jamie sport') === ['1'],
);
step(
    'a term that matches nothing rules the video out ("jamie beach")',
    $search('jamie beach') === [],
);

step('an empty search returns everything', count($search('')) === 3);
step('a whitespace-only search returns everything', count($search('   ')) === 3);

step(
    'search composes with the other filters',
    array_column(
        VideoQuery::fromRequest(['q' => 'alex', 'category' => ['Outdoors']])->apply($library),
        'id',
    ) === ['3'],
);

step(
    'search composes with sort (views min->max)',
    array_column(
        VideoQuery::fromRequest(['q' => 'alex', 'sort' => 'views_asc'])->apply($library),
        'id',
    ) === ['2', '3'],
);

$parsed = VideoQuery::fromRequest(['q' => "  Jamie   K.  \n "]);
step(
    sprintf('the term is trimmed and its whitespace collapsed ("%s")', $parsed->search),
    $parsed->search === 'Jamie K.' && $parsed->searchTerms() === ['Jamie', 'K.'],
);

step(
    'an over-long term is truncated, not rejected',
    mb_strlen(VideoQuery::fromRequest(['q' => str_repeat('x', 500)])->search) === VideoQuery::MAX_SEARCH_LENGTH,
);

// `?q[]=x` would otherwise reach the matcher as the string "Array".
step(
    'an array q is ignored rather than searched for "Array"',
    VideoQuery::fromRequest(['q' => ['x']])->search === '',
);

step('a search counts as a filter', VideoQuery::fromRequest(['q' => 'x'])->isFiltered());
step('a bare wall is not filtered', !VideoQuery::fromRequest([])->isFiltered());

// Legacy entries store a single `creator` string rather than a `creators` list.
step(
    'search finds a legacy single-creator entry',
    array_column(
        VideoQuery::fromRequest(['q' => 'morgan'])->apply([
            ['id' => '9', 'title' => 'Old entry', 'creator' => 'Morgan P.',
             'categories' => [], 'length_seconds' => 10, 'views' => 0],
        ]),
        'id',
    ) === ['9'],
);

echo PHP_EOL . "All smoke tests passed." . PHP_EOL;
