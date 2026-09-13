<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Jobs.php';

use MyStash\Jobs;
use MyStash\Session;

/**
 * What a background job is up to, as JSON, for the pages that draw progress
 * bars (Docs/PLAN.md 4.28, 6.6).
 *
 * A user can only ever ask about their *own* jobs: the job id is derived from
 * the session's username, not taken from the request, so there is no id to
 * guess or tamper with.
 */

Session::requireLogin();

$user = Session::user();
$kind = (string) ($_GET['kind'] ?? '');
$target = (string) ($_GET['target'] ?? '');

// Nothing below this line needs the session, and PHP holds an exclusive lock on
// the session file for the whole of a request. Without this, every poll would
// queue behind whatever else the tab is doing — which is precisely the "session
// lockup" that moving to php-fpm is supposed to have cured, and php-fpm alone
// does not cure it.
session_write_close();

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!in_array($kind, ['convert', 'rekey'], true)) {
    http_response_code(400);
    echo json_encode(['state' => 'none', 'message' => 'Unknown job type.']);
    exit;
}

$job = Jobs::read(Jobs::id($kind, $user, $target));

if ($job === null) {
    echo json_encode(['state' => 'none']);
    exit;
}

// Explicitly listed rather than echoing the record: the record is written by a
// worker and read by a browser, and only these fields are meant to cross.
echo json_encode([
    'state' => $job['state'],
    'percent' => (int) ($job['percent'] ?? 0),
    'done' => (int) ($job['done'] ?? 0),
    'total' => (int) ($job['total'] ?? 0),
    'message' => (string) ($job['message'] ?? ''),
    'started_at' => (int) ($job['started_at'] ?? 0),
]);
