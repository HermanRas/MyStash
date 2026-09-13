<?php

declare(strict_types=1);

/**
 * The background worker behind every long job (Docs/PLAN.md 4.28, 6.6).
 *
 * Spawned detached by Jobs::start() with nothing but a job id on its argv. It
 * takes the passwords out of the job's tmpfs key file (deleting it as it
 * reads), does the work, and reports progress into the job record for the
 * browser to poll. It has no session, no output and no request behind it — the
 * user may well have closed the tab, and the job carries on regardless, which
 * is the entire point.
 *
 * Usage: php bin/job_worker.php {job-id}
 */

require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/Crypto7z.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Rekey.php';
require_once __DIR__ . '/../src/VideoCategories.php';
require_once __DIR__ . '/../src/VideoEncoder.php';
require_once __DIR__ . '/../src/VideoQuality.php';

use MyStash\Crypto7z;
use MyStash\Datastore;
use MyStash\Jobs;
use MyStash\Rekey;
use MyStash\VideoCategories;
use MyStash\VideoEncoder;
use MyStash\VideoQuality;

$id = (string) ($argv[1] ?? '');

if ($id === '') {
    fwrite(STDERR, "Usage: php bin/job_worker.php {job-id}\n");
    exit(1);
}

// Taken first, before anything is read: holding the lock is what makes
// Jobs::read() report this job as alive, and failing to take it means another
// worker already has it — in which case this one must do nothing at all.
$lock = Jobs::hold($id);

if ($lock === false) {
    exit(0);
}

$record = Jobs::read($id);
$secrets = Jobs::claimSecrets($id);

if ($record === null || $secrets === null) {
    Jobs::finish($id, false, 'The job was not staged correctly.');
    exit(1);
}

// No time limit and no abort-on-disconnect: there is no client to disconnect.
set_time_limit(0);

Jobs::update($id, ['pid' => getmypid()]);

try {
    match ((string) $record['kind']) {
        'convert' => runConvert($id, $record, $secrets),
        'rekey' => runRekey($id, $record, $secrets),
        default => Jobs::finish($id, false, 'Unknown job type.'),
    };
} catch (\Throwable $error) {
    error_log("MyStash job {$id} crashed: " . $error->getMessage());
    Jobs::finish($id, false, 'The job stopped with an unexpected error. Nothing was lost — try again.');
    exit(1);
}

exit(0);

/**
 * Transcode one video to MP4/H.265 in place (4.28).
 *
 * This is the body that used to run inside the Convert request. It is
 * unchanged in what it does to the stash — including the 4.29 rules: the
 * archive is replaced, never overwritten, and the bookkeeping goes media →
 * per-video metadata → index, so an interruption leaves an index that still
 * describes what is on disk.
 *
 * @param array<string, mixed> $record
 * @param array<string, string> $secrets
 */
function runConvert(string $id, array $record, array $secrets): void
{
    $user = (string) $record['user'];
    $videoId = (string) $record['target'];
    $password = (string) $secrets['password'];

    $archivePath = Datastore::videoDir($user, $videoId) . "/{$videoId}.mp4.enc";

    if (!file_exists($archivePath)) {
        Jobs::finish($id, false, 'There is no encrypted video file behind this entry to convert.');

        return;
    }

    $crypto = new Crypto7z();
    $encoder = new VideoEncoder();
    $workDir = Datastore::tmpfsWorkDir('convert');

    try {
        Jobs::update($id, ['message' => 'Decrypting…', 'percent' => 0]);

        $extractDir = "{$workDir}/extract";

        if (!$crypto->extract($archivePath, $extractDir, $password)) {
            Jobs::finish($id, false, 'Could not decrypt the video.');

            return;
        }

        $originalPath = (glob("{$extractDir}/*") ?: [])[0] ?? null;

        if ($originalPath === null) {
            Jobs::finish($id, false, 'The archive decrypted to nothing.');

            return;
        }

        Jobs::update($id, ['message' => 'Converting to MP4/H.265…']);

        $convertedPath = "{$workDir}/{$videoId}.mp4";

        // The percentage is ffmpeg's own position in the video, reported
        // through -progress. Written straight into the job record, which is
        // what the watch page is polling.
        $onProgress = static function (float $done, float $total) use ($id): void {
            Jobs::update($id, [
                'done' => (int) round($done),
                'total' => (int) round($total),
                'percent' => $total > 0 ? min(99, (int) round($done / $total * 100)) : 0,
            ]);
        };

        if (!$encoder->convertToMp4Hevc($originalPath, $convertedPath, $onProgress)) {
            // Either ffmpeg failed or it stopped making progress and was
            // killed. Either way nothing has touched the stash yet.
            Jobs::finish($id, false, 'The conversion failed or stalled. The original video is untouched.');

            return;
        }

        Jobs::update($id, ['message' => 'Re-encrypting…', 'percent' => 99]);

        // Never encrypt straight over the live archive — replace() writes
        // beside it, proves the new archive opens and holds the same bytes,
        // and only then swaps it in (Docs/PLAN.md 4.29).
        if (!$crypto->replace($convertedPath, $archivePath, $password)) {
            error_log("MyStash convert: failed to replace {$archivePath} for {$user}");
            Jobs::finish($id, false, 'Could not save the converted video. The original is untouched — try again.');

            return;
        }

        // The converted file is still decrypted in tmpfs here, so this is the
        // one moment the real pixel height is cheap to read.
        $height = $encoder->videoHeight($convertedPath);

        $datastore = new Datastore();
        $index = $datastore->loadIndex($user, $password);

        if ($index === null) {
            // The media is converted and safe; only the labels will be wrong.
            error_log("MyStash convert: converted {$videoId} for {$user} but could not open the index");
            Jobs::finish($id, false, 'The video converted, but its index entry could not be updated.');

            return;
        }

        foreach ($index['videos'] as &$video) {
            if ($video['id'] === $videoId) {
                $video['format'] = 'mp4';
                $video['codec'] = 'hevc';
                $video['height'] = $height;
                $video = VideoQuality::apply($video);
                break;
            }
        }
        unset($video);

        // "Not Converted" is a real category assignment in the video's own
        // metadata, which is authoritative — drop it there and let the index
        // copy be derived from what remains.
        $categories = new VideoCategories();
        $assignments = array_values(array_filter(
            $categories->load($user, $password, $videoId),
            static fn($assignment) => $assignment['name'] !== 'Not Converted',
        ));
        $categories->save($user, $password, $videoId, $assignments, $index);

        $metadata = $datastore->loadVideoMetadata($user, $password, $videoId);

        if ($metadata !== null) {
            $datastore->saveVideoMetadata($user, $password, $videoId, VideoQuality::apply([
                ...$metadata,
                'format' => 'mp4',
                'codec' => 'hevc',
                'height' => $height,
            ]));
        }

        if (!$datastore->saveIndex($user, $password, $index)) {
            error_log("MyStash convert: converted {$videoId} for {$user} but could not save the index");
            Jobs::finish($id, false, 'The video converted, but its index entry could not be saved.');

            return;
        }

        Jobs::finish($id, true, 'Converted to MP4/H.265.');
    } finally {
        Datastore::wipe($workDir);
    }
}

/**
 * Re-encrypt the whole stash under a new password (6.6).
 *
 * All of the re-keying itself stays in Rekey — the ordering rules, the `.old`
 * safety copies, the rollback. This only reports how far along it is.
 *
 * @param array<string, mixed> $record
 * @param array<string, string> $secrets
 */
function runRekey(string $id, array $record, array $secrets): void
{
    $user = (string) $record['user'];

    Jobs::update($id, ['message' => 'Checking your current password…']);

    $result = (new Rekey())->run(
        $user,
        (string) $secrets['old_password'],
        (string) $secrets['new_password'],
        static function (int $done, int $total, string $name) use ($id): void {
            Jobs::update($id, [
                'done' => $done,
                'total' => $total,
                'percent' => $total > 0 ? min(99, (int) round($done / $total * 100)) : 0,
                'message' => "Re-encrypted {$done} of {$total} archives",
            ]);
        },
    );

    if (!$result['ok']) {
        error_log("MyStash re-key failed for {$user}: " . $result['message']);
        Jobs::finish($id, false, $result['message']);

        return;
    }

    Jobs::finish($id, true, $result['message'] . ' Sign in again with your new password.');
}
