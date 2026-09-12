<?php

declare(strict_types=1);

/**
 * Dev-only seed script: creates App/Data/TestUser/videos/TestUser.json.enc
 * with sample data matching the Phase 1 wall.html mockup, so Phase 2 login
 * + wall wiring has something real to decrypt and render.
 *
 * Usage (inside the container): php bin/seed_testuser.php [password]
 * Default password: testpass123
 */

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/Datastore.php';

use MyStash\Datastore;

$password = $argv[1] ?? 'testpass123';
$user = 'TestUser';

$index = [
    'categories' => [
        'Personal' => '#ffa31a',
        'Highlights' => '#5cb85c',
        'Not Converted' => '#cc4444',
        'Most Recent' => '#5599ff',
    ],
    'creators' => [
        'default' => ['name' => 'default', 'age' => null, 'gender' => null, 'bio' => '', 'verified' => false],
        'Alex R.' => ['name' => 'Alex R.', 'age' => 29, 'gender' => 'Female', 'bio' => '', 'verified' => true],
        'Jamie K.' => ['name' => 'Jamie K.', 'age' => 34, 'gender' => 'Male', 'bio' => '', 'verified' => true],
    ],
    'videos' => [
        [
            'id' => '1',
            'title' => 'Evening Session — Full Walkthrough',
            'creator' => 'default',
            'length_seconds' => 860,
            'views' => 128,
            'format' => 'mp4',
            'codec' => 'h264',
            'not_converted' => true,
            'categories' => ['Personal'],
            'quality' => 'HD',
            'tile_gradient' => ['#3a3a3a', '#161616'],
            'tags' => [],
        ],
        [
            'id' => '2',
            'title' => 'Studio Test Clip 01',
            'creator' => 'Alex R.',
            'length_seconds' => 342,
            'views' => 34,
            'format' => 'mp4',
            'codec' => 'hevc',
            'not_converted' => false,
            'categories' => ['Highlights'],
            'quality' => '4K',
            'tile_gradient' => ['#4a3a2a', '#1a1410'],
            'tags' => [],
        ],
        [
            'id' => '3',
            'title' => 'Behind the Scenes — Raw Footage',
            'creator' => 'default',
            'length_seconds' => 1325,
            'views' => 9,
            'format' => 'mov',
            'codec' => 'h264',
            'not_converted' => true,
            'categories' => ['Not Converted'],
            'quality' => null,
            'tile_gradient' => ['#2a3a3a', '#101a1a'],
            'tags' => [],
        ],
        [
            'id' => '4',
            'title' => 'Quick Recap Reel',
            'creator' => 'Jamie K.',
            'length_seconds' => 491,
            'views' => 210,
            'format' => 'mp4',
            'codec' => 'hevc',
            'not_converted' => false,
            'categories' => ['Highlights'],
            'quality' => 'HD',
            'tile_gradient' => ['#3a2a3a', '#1a101a'],
            'tags' => [],
        ],
        [
            'id' => '5',
            'title' => 'Long-Form Interview Draft',
            'creator' => 'default',
            'length_seconds' => 1907,
            'views' => 17,
            'format' => 'mp4',
            'codec' => 'hevc',
            'not_converted' => false,
            'categories' => ['Most Recent'],
            'quality' => 'HD',
            'tile_gradient' => ['#3a3a2a', '#181810'],
            'tags' => [],
        ],
        [
            'id' => '6',
            'title' => 'Preview Clip Sample',
            'creator' => 'Alex R.',
            'length_seconds' => 178,
            'views' => 5,
            'format' => 'mp4',
            'codec' => 'hevc',
            'not_converted' => false,
            'categories' => ['Personal'],
            'quality' => 'HD',
            'tile_gradient' => ['#2a2a3a', '#10101a'],
            'tags' => [],
        ],
    ],
];

$datastore = new Datastore();
$ok = $datastore->saveIndex($user, $password, $index);

if ($ok) {
    echo "Seeded {$user}.json.enc with password: {$password}\n";
    exit(0);
}

fwrite(STDERR, "Failed to seed index.\n");
exit(1);
