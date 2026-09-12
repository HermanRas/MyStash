<?php

declare(strict_types=1);

require __DIR__ . '/../src/Crypto7z.php';
require __DIR__ . '/../src/Datastore.php';
require __DIR__ . '/../src/Session.php';

use MyStash\Crypto7z;
use MyStash\Datastore;
use MyStash\Session;

/**
 * Decrypt-on-the-fly media endpoint (Docs/PLAN.md Phase 4.0): serves the
 * preview thumbnail, preview clip, or full video for a given video ID.
 * Nothing is ever written back out decrypted to persistent disk — the
 * archive is extracted to tmpfs, streamed (with HTTP Range support for
 * video/mp4 seeking), then wiped.
 */

Session::requireLogin();

$id = (string) ($_GET['id'] ?? '');
$type = (string) ($_GET['type'] ?? '');

$suffixes = [
    'thumb' => ['jpg.preview.enc', 'image/jpeg'],
    'preview' => ['mp4.preview.enc', 'video/mp4'],
    'video' => ['mp4.enc', 'video/mp4'],
];

if ($id === '' || !isset($suffixes[$type])) {
    http_response_code(400);
    exit;
}

[$suffix, $contentType] = $suffixes[$type];
$archivePath = Datastore::videoDir(Session::user(), $id) . "/{$id}.{$suffix}";

if (!file_exists($archivePath)) {
    http_response_code(404);
    exit;
}

$workDir = Datastore::tmpfsWorkDir('media');
register_shutdown_function(static fn() => Datastore::wipe($workDir));

$crypto = new Crypto7z();
if (!$crypto->extract($archivePath, $workDir, Session::password())) {
    http_response_code(403);
    exit;
}

$files = glob("{$workDir}/*");
$plainPath = $files[0] ?? null;

if ($plainPath === null || !is_file($plainPath)) {
    http_response_code(500);
    exit;
}

$size = filesize($plainPath);
$start = 0;
$end = $size - 1;
$status = 200;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $start = $m[1] === '' ? $size - (int) $m[2] : (int) $m[1];
    $end = $m[2] !== '' && $m[1] !== '' ? (int) $m[2] : $size - 1;
    $start = max(0, $start);
    $end = min($size - 1, $end);
    $status = 206;
}

http_response_code($status);
header("Content-Type: {$contentType}");
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
if ($status === 206) {
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

$fh = fopen($plainPath, 'rb');
fseek($fh, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($fh)) {
    $chunk = fread($fh, min(1024 * 1024, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($fh);
