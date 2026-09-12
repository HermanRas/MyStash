<?php

declare(strict_types=1);

/**
 * Dev-only seed script: creates App/Data/TestUser/videos/TestUser.json.enc
 * plus a per-video Video{ID}/{ID}.json.enc for each sample entry, so login,
 * the wall, and category editing all have real data to work against.
 *
 * These demo entries carry no actual media files — only metadata — so
 * playback/conversion for them is expected to report "no encrypted video file".
 *
 * Usage (inside the container): php bin/seed_testuser.php [password]
 * Default password: testpass123
 */

require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/VideoCategories.php';

use MyStash\Datastore;

$password = $argv[1] ?? 'testpass123';
$user = 'TestUser';

$categories = [
    'Personal' => '#ffa31a',
    'Highlights' => '#5cb85c',
    'Not Converted' => '#cc4444',
    'Most Recent' => '#5599ff',
];

$creators = [
    'default' => ['name' => 'default', 'age' => null, 'gender' => null, 'bio' => '', 'verified' => false],
    'Alex R.' => ['name' => 'Alex R.', 'age' => 29, 'gender' => 'Female', 'bio' => '', 'verified' => true],
    'Jamie K.' => ['name' => 'Jamie K.', 'age' => 34, 'gender' => 'Male', 'bio' => '', 'verified' => true],
];

// [id, title, creator, length, views, format, codec, notConverted, quality,
//  gradient, category assignments]
$samples = [
    ['1', 'Evening Session — Full Walkthrough', 'default', 860, 128, 'mp4', 'h264', true, 'HD', ['#3a3a3a', '#161616'],
        [['name' => 'Personal', 'timestamp_seconds' => 0], ['name' => 'Personal', 'timestamp_seconds' => 225]]],
    ['2', 'Studio Test Clip 01', 'Alex R.', 342, 34, 'mp4', 'hevc', false, '4K', ['#4a3a2a', '#1a1410'],
        [['name' => 'Highlights', 'timestamp_seconds' => 0]]],
    ['3', 'Behind the Scenes — Raw Footage', 'default', 1325, 9, 'mov', 'h264', true, null, ['#2a3a3a', '#101a1a'],
        [['name' => 'Not Converted', 'timestamp_seconds' => 0]]],
    ['4', 'Quick Recap Reel', 'Jamie K.', 491, 210, 'mp4', 'hevc', false, 'HD', ['#3a2a3a', '#1a101a'],
        [['name' => 'Highlights', 'timestamp_seconds' => 0], ['name' => 'Personal', 'timestamp_seconds' => 120]]],
    ['5', 'Long-Form Interview Draft', 'default', 1907, 17, 'mp4', 'hevc', false, 'HD', ['#3a3a2a', '#181810'],
        [['name' => 'Most Recent', 'timestamp_seconds' => 0]]],
    ['6', 'Preview Clip Sample', 'Alex R.', 178, 5, 'mp4', 'hevc', false, 'HD', ['#2a2a3a', '#10101a'],
        [['name' => 'Personal', 'timestamp_seconds' => 0]]],
];

$datastore = new Datastore();

// Merge into whatever is already there: real uploads, creators and categories
// the user created must survive a re-seed — only the demo rows are rewritten.
$existing = $datastore->loadIndex($user, $password) ?? ['categories' => [], 'creators' => [], 'videos' => []];
$demoIds = array_column($samples, 0);

$categories = [...$categories, ...$existing['categories']];
$creators = [...$creators, ...$existing['creators']];
$videos = array_values(array_filter(
    $existing['videos'],
    static fn($v) => !in_array($v['id'], $demoIds, true),
));

foreach ($samples as [$id, $title, $creator, $length, $views, $format, $codec, $notConverted, $quality, $gradient, $assignments]) {
    $videos[] = [
        'id' => $id,
        'title' => $title,
        'description' => '',
        'creator' => $creator,
        'length_seconds' => $length,
        'views' => $views,
        'format' => $format,
        'codec' => $codec,
        'not_converted' => $notConverted,
        'categories' => Datastore::categoryNames($assignments),
        'quality' => $quality,
        'tile_gradient' => $gradient,
    ];

    $datastore->saveVideoMetadata($user, $password, $id, [
        'id' => $id,
        'title' => $title,
        'description' => '',
        'creator' => $creator,
        'length_seconds' => $length,
        'views' => $views,
        'format' => $format,
        'codec' => $codec,
        'not_converted' => $notConverted,
        'uploaded_at' => date('c'),
        'categories' => $assignments,
        'preview_capture_seconds' => 15,
    ]);
}

$ok = $datastore->saveIndex($user, $password, [
    'categories' => $categories,
    'creators' => $creators,
    'videos' => $videos,
]);

if ($ok) {
    $kept = count($videos) - count($samples);
    echo "Seeded " . count($samples) . " demo videos (+ metadata files) for {$user}"
        . ($kept > 0 ? ", keeping {$kept} existing video(s)" : '') . ".\n";
    exit(0);
}

fwrite(STDERR, "Failed to seed index.\n");
exit(1);
