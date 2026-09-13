<?php

declare(strict_types=1);

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/VideoEncoder.php';
require __DIR__ . '/../src/VideoQuality.php';
require __DIR__ . '/../src/User.php';
require __DIR__ . '/../src/VideoQuery.php';
require __DIR__ . '/../src/CreatorQuery.php';
require __DIR__ . '/../src/Rekey.php';

use MyStash\CreatorQuery;
use MyStash\Datastore;
use MyStash\Rekey;
use MyStash\Crypto7z;
use MyStash\User;
use MyStash\VideoEncoder;
use MyStash\VideoCreators;
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

// Crypto7z::replace() — the one that must never lose the file it is replacing
// (Docs/PLAN.md 4.29). encrypt() deletes its destination before writing the
// replacement; these check that replace() never leaves that hole.
$live = $workDir . '/live.7z';
$other = $workDir . '/other.txt';
file_put_contents($other, 'the replacement contents');

step('replace() creates an archive that did not exist yet',
    $crypto->replace($fixture, $live, $password) && file_exists($live));

step('replace() swaps in the new contents',
    $crypto->replace($other, $live, $password));

$swapped = $workDir . '/swapped';
$crypto->extract($live, $swapped, $password);
step('the swapped archive holds the new file, not the old one',
    file_exists("{$swapped}/other.txt") && !file_exists("{$swapped}/1.mp4"));

$litter = static fn(string $path): array => glob("{$path}.new*") ?: [];
step('a successful replace leaves no .new or .old litter',
    $litter($live) === [] && !file_exists("{$live}.old"));

// The invariant the whole item exists for: a failed replace must leave the
// original archive exactly as it was, still readable.
$before = hash_file('sha256', $live);

step('replace() refuses a source that is not a file',
    $crypto->replace($workDir . '/no-such-file', $live, $password) === false);
step('...and the original archive is untouched',
    hash_file('sha256', $live) === $before);

// The failure that actually loses videos: the encrypt itself fails after the
// destination has been cleared. A 7z that always exits non-zero reproduces it
// without having to fill a disk.
$failing = new Crypto7z('/bin/false');
step('replace() reports a failed encrypt instead of ignoring it',
    $failing->replace($other, $live, $password) === false);
step('...and the original archive is still there, byte for byte',
    file_exists($live) && hash_file('sha256', $live) === $before);

step('a failed replace leaves no .new or .old litter',
    $litter($live) === [] && !file_exists("{$live}.old"));

step('the original still opens after the failures',
    $crypto->extract($live, $workDir . '/still', $password));

// encrypt() is the unsafe one by design — this is what replace() exists to
// avoid, and stating it here keeps the difference from being forgotten.
$doomed = $workDir . '/doomed.7z';
$crypto->encrypt($other, $doomed, $password);
$failing->encrypt($other, $doomed, $password);
step('encrypt() destroys its destination when it fails (hence replace())',
    !file_exists($doomed));

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

// Creator search and sort (Docs/PLAN.md 5.8, 5.9) — again pure logic.
$people = [
    'default' => ['id' => '1', 'bio' => 'booping'],
    'Alex R.' => ['id' => '2', 'bio' => 'skate filmer', 'age' => 31, 'gender' => 'Non-binary'],
    'Jamie K.' => ['id' => '3', 'bio' => '', 'age' => 24, 'gender' => 'Female'],
    'Morgan P.' => ['id' => '4', 'bio' => 'drone pilot', 'gender' => 'Male'],
];

// default is on both videos, Alex on one, Jamie on one, Morgan on none.
$clips = [
    ['id' => '1', 'creators' => ['default', 'Alex R.']],
    ['id' => '2', 'creators' => ['default', 'Jamie K.']],
];

$creatorSearch = static fn(string $term): array => array_keys(
    CreatorQuery::fromRequest(['q' => $term])->apply($people, $clips),
);

step('creator search matches a name', $creatorSearch('jamie') === ['Jamie K.']);
step('creator search matches a bio', $creatorSearch('drone') === ['Morgan P.']);
step('creator search matches a gender', $creatorSearch('female') === ['Jamie K.']);
step('creator search is case-insensitive', $creatorSearch('ALEX') === ['Alex R.']);
step('creator search matches a partial word', $creatorSearch('skat') === ['Alex R.']);
step('creator search returns nothing when nothing matches', $creatorSearch('zzz') === []);
step('an empty creator search returns everyone', count($creatorSearch('')) === 4);
step(
    'every creator term must match ("skate filmer" vs "skate drone")',
    $creatorSearch('skate filmer') === ['Alex R.'] && $creatorSearch('skate drone') === [],
);

$creatorSort = static fn(string $sort): array => array_keys(
    CreatorQuery::fromRequest(['sort' => $sort])->apply($people, $clips),
);

step(
    'creators sort by name A→Z by default',
    $creatorSort('name_asc') === ['Alex R.', 'default', 'Jamie K.', 'Morgan P.'],
);
step(
    'name Z→A is the exact reverse',
    $creatorSort('name_desc') === array_reverse($creatorSort('name_asc')),
);
step(
    'creators sort by video count, max→min',
    $creatorSort('videos_desc') === ['default', 'Alex R.', 'Jamie K.', 'Morgan P.'],
);
step(
    'creators sort by video count, min→max',
    $creatorSort('videos_asc') === ['Morgan P.', 'Alex R.', 'Jamie K.', 'default'],
);
step(
    'creators sort by age, young→old, with unknown ages last',
    $creatorSort('age_asc') === ['Jamie K.', 'Alex R.', 'default', 'Morgan P.'],
);
step(
    'creators sort by age, old→young, with unknown ages still last',
    $creatorSort('age_desc') === ['Alex R.', 'Jamie K.', 'default', 'Morgan P.'],
);

// "o" matches default (bio "booping"), Alex (gender "Non-binary") and Morgan
// (name), but not Jamie — so this checks the sort really is applied to a
// filtered set rather than to everyone.
step(
    'creator search and sort compose',
    array_keys(CreatorQuery::fromRequest(['q' => 'o', 'sort' => 'videos_desc'])->apply($people, $clips))
        === ['default', 'Alex R.', 'Morgan P.'],
);

step('an unknown creator sort falls back to the default',
    CreatorQuery::fromRequest(['sort' => 'nonsense'])->sort === CreatorQuery::DEFAULT_SORT);
step('an array q is ignored on the creator search',
    CreatorQuery::fromRequest(['q' => ['x']])->search === '');
step('a creator search counts as filtered',
    CreatorQuery::fromRequest(['q' => 'x'])->isFiltered() && !CreatorQuery::fromRequest([])->isFiltered());

// Creator view counts (Docs/PLAN.md 4.11). $library credits Jamie on video 1
// (0 views), Alex on videos 2 and 3 (3 + 9), and default on video 2 (3).
$creatorViews = static fn(string $name): int => VideoCreators::viewCount(['videos' => $library], $name);

step('a creator\'s views are the sum of their videos\' views', $creatorViews('Alex R.') === 12);
step('a co-credited video counts in full for each creator', $creatorViews('default') === 3);
step('an unwatched creator has no views', $creatorViews('Jamie K.') === 0);
step('a creator with no videos has no views', $creatorViews('Morgan P.') === 0);
step('view counts read legacy single-creator entries',
    VideoCreators::viewCount(['videos' => [['creator' => 'Morgan P.', 'views' => 4]]], 'Morgan P.') === 4);

// The same view counts drive the Creators screen's views sort.
$viewSort = static fn(string $sort): array => array_keys(
    CreatorQuery::fromRequest(['sort' => $sort])->apply($people, $library),
);
step('creators sort by views, max→min',
    $viewSort('views_desc') === ['Alex R.', 'default', 'Jamie K.', 'Morgan P.']);
step('creators sort by views, min→max, ties broken by name',
    $viewSort('views_asc') === ['Jamie K.', 'Morgan P.', 'default', 'Alex R.']);

// Creator age/gender filters on the wall (Docs/PLAN.md 5.2). These are
// attributes of the *creator*, so the video list alone cannot answer them —
// $people is passed alongside.
$byCreator = static fn(array $params): array => array_column(
    VideoQuery::fromRequest($params)->apply($library, $people),
    'id',
);

step('an untouched age slider filters nothing', count($byCreator([])) === 3);
step('a minimum age keeps only videos credited to someone that old',
    $byCreator(['age_min' => 30]) === ['3', '2']);
step('a maximum age keeps only videos credited to someone that young',
    $byCreator(['age_max' => 25]) === ['1']);
step('an age range excludes everyone outside it',
    $byCreator(['age_min' => 26, 'age_max' => 29]) === []);
step('a gender filter matches a creator\'s gender',
    $byCreator(['gender' => 'Female']) === ['1']);
step('a gender filter is case-insensitive',
    $byCreator(['gender' => 'non-binary']) === ['3', '2']);
step('a gender nobody\'s videos carry matches nothing',
    $byCreator(['gender' => 'Male']) === []);

// A video credited to several people matches if *any* of them does: video 2 is
// Alex (31) and default (no age), and an age filter must not rule it out on
// default's account.
step('one matching creator is enough on a co-credited video',
    in_array('2', $byCreator(['age_min' => 30]), true));

// ...but an unrecorded age is not evidence of being in the range asked for.
step('a creator with no age set fails an active age filter',
    array_column(VideoQuery::fromRequest(['age_min' => 19])->apply(
        [['id' => '9', 'title' => 'x', 'creators' => ['default'], 'categories' => [], 'length_seconds' => 5, 'views' => 0]],
        $people,
    ), 'id') === []);

step('age and gender compose with the other filters',
    $byCreator(['age_min' => 30, 'category' => ['Outdoors']]) === ['3']);

step('an age filter counts as a filter',
    VideoQuery::fromRequest(['age_min' => 25])->isFiltered()
        && VideoQuery::fromRequest(['gender' => 'Female'])->isFiltered()
        && !VideoQuery::fromRequest(['age_min' => VideoQuery::MIN_AGE, 'age_max' => VideoQuery::MAX_AGE])->isFiltered());

$ages = VideoQuery::fromRequest(['age_min' => 40, 'age_max' => 20]);
step('an upside-down age range is corrected rather than matching nothing',
    $ages->minAge === 40 && $ages->maxAge === 40);
step('an out-of-range age is clamped to the slider',
    VideoQuery::fromRequest(['age_min' => 3, 'age_max' => 900])->minAge === VideoQuery::MIN_AGE
        && VideoQuery::fromRequest(['age_min' => 3, 'age_max' => 900])->maxAge === VideoQuery::MAX_AGE);
step('an array gender is ignored rather than filtered for "Array"',
    VideoQuery::fromRequest(['gender' => ['x']])->gender === '');

// Password change / re-keying (Docs/PLAN.md 6.1). Runs against a throwaway
// stash of its own — never the caller's — and removes it again at the end.
$probe = 'RekeyProbe' . bin2hex(random_bytes(3));
$oldKey = 'probe-old-password-aaaaaaaa';
$newKey = 'probe-new-password-bbbbbbbb';

$users = new User();
step("create a throwaway stash ({$probe})", $users->create($probe, $oldKey));

$store = new Datastore();
$store->saveVideoMetadata($probe, $oldKey, '1', ['id' => '1', 'title' => 'Probe clip']);

$rekey = new Rekey();

step(
    'a too-short new password is refused',
    $rekey->run($probe, $oldKey, 'short')['ok'] === false,
);
step(
    'reusing the current password is refused',
    $rekey->run($probe, $oldKey, $oldKey)['ok'] === false,
);
step(
    'a wrong current password is refused',
    $rekey->run($probe, 'not-the-current-password-x', $newKey)['ok'] === false,
);
step(
    'the stash still opens with the old password after those refusals',
    $store->loadIndex($probe, $oldKey) !== null,
);

$result = $rekey->run($probe, $oldKey, $newKey);
step("re-key succeeds ({$result['message']})", $result['ok']);
step('it rewrote more than just the index', $result['rewritten'] >= 2);

step('the stash opens with the new password', $store->loadIndex($probe, $newKey) !== null);
step('the stash no longer opens with the old one', $store->loadIndex($probe, $oldKey) === null);
step(
    'per-video metadata came across too, not just the index',
    ($store->loadVideoMetadata($probe, $newKey, '1')['title'] ?? null) === 'Probe clip',
);

$leftovers = [];
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(Datastore::userDir($probe), FilesystemIterator::SKIP_DOTS),
);
foreach ($walk as $file) {
    if ($file->isFile() && str_ends_with($file->getPathname(), '.enc.old')) {
        $leftovers[] = $file->getFilename();
    }
}
step(
    'no .enc.old safety copies are left behind (' . (implode(', ', $leftovers) ?: 'none') . ')',
    $leftovers === [],
);

Datastore::wipe(Datastore::userDir($probe));
step('the throwaway stash is gone', !is_dir(Datastore::userDir($probe)));

echo PHP_EOL . "All smoke tests passed." . PHP_EOL;
