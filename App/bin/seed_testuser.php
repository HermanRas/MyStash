<?php

declare(strict_types=1);

/**
 * Dev-only seed script: creates App/Data/TestUser/videos/TestUser.json.enc,
 * a per-video Video{ID}/{ID}.json.enc for each sample entry, and a
 * Creator{ID}/{ID}.json.enc for each sample creator — so login, the wall,
 * creator editing and category editing all have real data to work against.
 *
 * These demo entries carry no actual media files — only metadata — so
 * playback/conversion for them is expected to report "no encrypted video file".
 *
 * Usage (inside the container): php bin/seed_testuser.php [password]
 * Default password: DS89HONPtufGDncNUoGfshCg (24 chars — see User::MIN_PASSWORD_LENGTH)
 */

require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/VideoCategories.php';
require_once __DIR__ . '/../src/VideoQuality.php';

use MyStash\Datastore;
use MyStash\VideoQuality;

$password = $argv[1] ?? 'DS89HONPtufGDncNUoGfshCg';
$user = 'TestUser';

$categories = [
    'Personal' => '#ffa31a',
    'Highlights' => '#5cb85c',
    'Not Converted' => '#cc4444',
];

// [id, name, age, gender]
$sampleCreators = [
    ['1', 'default', null, null],
    ['2', 'Alex R.', 29, 'Female'],
    ['3', 'Jamie K.', 34, 'Male'],
];

// [id, title, creators, length, views, format, codec, height, gradient,
//  daysAgo, category assignments] — quality and "not converted" are derived
// from height/format/codec, never seeded directly (see VideoQuality).
$samples = [
    ['1', 'Evening Session — Full Walkthrough', ['default'], 860, 128, 'mp4', 'h264', 1080, ['#3a3a3a', '#161616'], 2,
        [['name' => 'Personal', 'timestamp_seconds' => 0], ['name' => 'Personal', 'timestamp_seconds' => 225]]],
    ['2', 'Studio Test Clip 01', ['Alex R.'], 342, 34, 'mp4', 'hevc', 2160, ['#4a3a2a', '#1a1410'], 9,
        [['name' => 'Highlights', 'timestamp_seconds' => 0]]],
    ['3', 'Behind the Scenes — Raw Footage', ['default', 'Jamie K.'], 1325, 9, 'mov', 'h264', 480, ['#2a3a3a', '#101a1a'], 21,
        [['name' => 'Not Converted', 'timestamp_seconds' => 0]]],
    ['4', 'Quick Recap Reel', ['Jamie K.'], 491, 210, 'mp4', 'hevc', 1440, ['#3a2a3a', '#1a101a'], 4,
        [['name' => 'Highlights', 'timestamp_seconds' => 0], ['name' => 'Personal', 'timestamp_seconds' => 120]]],
    ['5', 'Long-Form Interview Draft', ['default'], 4210, 17, 'mp4', 'hevc', 720, ['#3a3a2a', '#181810'], 1,
        [['name' => 'Personal', 'timestamp_seconds' => 0]]],
    ['6', 'Preview Clip Sample', ['Alex R.', 'default'], 178, 5, 'mp4', 'hevc', 360, ['#2a2a3a', '#10101a'], 40,
        [['name' => 'Personal', 'timestamp_seconds' => 0]]],
];

$datastore = new Datastore();

// Merge into whatever is already there: real uploads, creators and categories
// the user created must survive a re-seed — only the demo rows are rewritten.
$existing = $datastore->loadIndex($user, $password) ?? ['categories' => [], 'creators' => [], 'videos' => []];
$demoIds = array_column($samples, 0);

$categories = [...$categories, ...$existing['categories']];
$videos = array_values(array_filter(
    $existing['videos'],
    static fn($v) => !in_array($v['id'], $demoIds, true),
));

$creators = $existing['creators'];
foreach ($sampleCreators as [$id, $name, $age, $gender]) {
    $record = [
        'id' => $id,
        'name' => $name,
        'age' => $age,
        'gender' => $gender,
        'bio' => '',
        'created_at' => date('c'),
    ];

    $creators[$name] = [...$record, ...($creators[$name] ?? [])];
    $creators[$name]['id'] = $id;

    $datastore->saveCreatorMetadata($user, $password, $id, $record);
}

foreach ($samples as [$id, $title, $videoCreators, $length, $views, $format, $codec, $height, $gradient, $daysAgo, $assignments]) {
    $uploadedAt = date('c', strtotime("-{$daysAgo} days"));

    $entry = VideoQuality::apply([
        'id' => $id,
        'title' => $title,
        'description' => '',
        'creators' => $videoCreators,
        'length_seconds' => $length,
        'views' => $views,
        'format' => $format,
        'codec' => $codec,
        'height' => $height,
        'uploaded_at' => $uploadedAt,
        'categories' => Datastore::categoryNames($assignments),
        'tile_gradient' => $gradient,
    ]);

    $videos[] = $entry;

    $datastore->saveVideoMetadata($user, $password, $id, VideoQuality::apply([
        'id' => $id,
        'title' => $title,
        'description' => '',
        'creators' => $videoCreators,
        'length_seconds' => $length,
        'views' => $views,
        'format' => $format,
        'codec' => $codec,
        'height' => $height,
        'uploaded_at' => $uploadedAt,
        'categories' => $assignments,
        'preview_capture_seconds' => 15,
    ]));
}

$ok = $datastore->saveIndex($user, $password, [
    'categories' => $categories,
    'creators' => $creators,
    'videos' => $videos,
]);

if ($ok) {
    $kept = count($videos) - count($samples);
    echo "Seeded " . count($samples) . " demo videos (+ metadata files) and "
        . count($sampleCreators) . " creators for {$user}"
        . ($kept > 0 ? ", keeping {$kept} existing video(s)" : '') . ".\n";
    exit(0);
}

fwrite(STDERR, "Failed to seed index.\n");
exit(1);
