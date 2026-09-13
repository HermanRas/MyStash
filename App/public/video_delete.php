<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Crypto7z.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Session.php';

use MyStash\Datastore;
use MyStash\Session;

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wall.php');
    exit;
}

$id = (string) ($_POST['id'] ?? '');
$index = Session::refreshIndex();

// Only delete a video this stash actually lists.
//
// This used to take the id straight from the post and hand it to
// Datastore::videoDir(), which meant `1/../../../OtherUser/videos/Video1`
// resolved into somebody else's directory — and unlike every other endpoint,
// deleting never needs to decrypt anything, so the encryption that protects
// the rest of the app protected nothing here. One logged-in user could
// permanently destroy another user's videos (7.1). The same check
// video_convert.php already made, for the same reason.
$known = false;

foreach ($index['videos'] ?? [] as $video) {
    if ((string) $video['id'] === $id) {
        $known = true;
        break;
    }
}

if (!Datastore::isValidId($id) || !$known) {
    header('Location: wall.php');
    exit;
}

$index['videos'] = array_values(array_filter(
    $index['videos'],
    static fn($v) => $v['id'] !== $id,
));

$videoDir = Datastore::videoDir(Session::user(), $id);
if (is_dir($videoDir)) {
    Datastore::wipe($videoDir);
}

Session::setIndex($index);
(new Datastore())->saveIndex(Session::user(), Session::password(), $index);

header('Location: wall.php');
