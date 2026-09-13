<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/VideoCategories.php';
require_once __DIR__ . '/../src/CreatorStore.php';
require_once __DIR__ . '/../src/VideoCreators.php';
require_once __DIR__ . '/../src/Jobs.php';

use MyStash\CreatorStore;
use MyStash\Jobs;
use MyStash\Session;
use MyStash\VideoCategories;
use MyStash\VideoCreators;

Session::requireLogin();

$index = Session::refreshIndex();
$videos = $index['videos'] ?? [];
$creators = $index['creators'] ?? [];
$categories = $index['categories'] ?? [];

$id = (string) ($_GET['id'] ?? '');

$video = null;
foreach ($videos as $v) {
    if ($v['id'] === $id) {
        $video = $v;
        break;
    }
}

if ($video === null) {
    header('Location: wall.php');
    exit;
}

// A conversion runs in a detached worker (Docs/PLAN.md 4.28), so this page has
// to ask how the last one for this video is getting on rather than assume.
$convertJob = Jobs::read(Jobs::id('convert', Session::user(), $id));
$converting = $convertJob !== null && $convertJob['state'] === Jobs::RUNNING;

$editing = isset($_GET['edit']);
$assignments = (new VideoCategories())->load(Session::user(), Session::password(), $id);

$videoCreators = VideoCreators::of($video);

function creatorAvatarUrl(array $creators, string $name): ?string
{
    $id = (string) ($creators[$name]['id'] ?? '');

    return CreatorStore::hasProfileImage(Session::user(), $id)
        ? 'media.php?type=avatar&creator=' . urlencode($id)
        : null;
}

function formatLength(int $seconds): string
{
    return $seconds >= 3600
        ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
        : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}

$navActive = 'videos';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — <?= htmlspecialchars($video['title'], ENT_QUOTES) ?></title>
<link rel="icon" href="assets/img/icon.png" type="image/png">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php require __DIR__ . '/../views/header.php'; ?>

<main class="watch-layout">
  <div>
    <h1 class="watch-title"><?= htmlspecialchars($video['title'], ENT_QUOTES) ?></h1>

    <div class="player" id="player" data-video-src="media.php?id=<?= urlencode($id) ?>&amp;type=video">
      <img src="media.php?id=<?= urlencode($id) ?>&amp;type=thumb" alt="" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'">
      <button type="button" id="player-play" style="position:relative; z-index:1; background:rgba(0,0,0,0.6); border:1px solid var(--border); color:#fff; border-radius:50%; width:64px; height:64px; font-size:20px; cursor:pointer;">▶</button>
    </div>

    <?php if (!$converting && $convertJob !== null && $convertJob['state'] === Jobs::FAILED): ?>
      <p class="hint" style="color:#ff6b6b;">
        <?= htmlspecialchars((string) $convertJob['message'], ENT_QUOTES) ?>
      </p>
    <?php endif; ?>

    <div class="watch-meta">
      <span id="view-count"><?= (int) $video['views'] ?></span>
      <span id="view-label">view<?= (int) $video['views'] === 1 ? '' : 's' ?></span>
      • <?= formatLength((int) $video['length_seconds']) ?>
      <?php if (!empty($video['quality'])): ?> • <?= htmlspecialchars($video['quality'], ENT_QUOTES) ?><?php endif; ?>
      <?php if (!empty($video['not_converted'])): ?>
        • <span style="color:#cc4444;">Not Converted</span>
      <?php endif; ?>
    </div>

    <div>
      <?php foreach ($assignments as $assignment): ?>
        <button type="button" class="tag tag-jump" data-seconds="<?= (int) $assignment['timestamp_seconds'] ?>">
          <span class="dot" style="background:<?= htmlspecialchars($categories[$assignment['name']] ?? '#888', ENT_QUOTES) ?>"></span>
          <?= htmlspecialchars($assignment['name'], ENT_QUOTES) ?>
          <span class="ts"><?= VideoCategories::formatTimestamp((int) $assignment['timestamp_seconds']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>

    <?php /* Creators sit below the categories rather than in a side rail. A
             video may credit more than one, so this is a list. */ ?>
    <div class="section-title">Creator<?= count($videoCreators) === 1 ? '' : 's' ?></div>
    <div class="creator-list">
      <?php foreach ($videoCreators as $creatorName): ?>
        <?php $avatar = creatorAvatarUrl($creators, $creatorName); ?>
        <a class="card creator-strip" href="creator.php?edit=<?= urlencode($creatorName) ?>">
          <div class="creator-avatar">
            <?php if ($avatar !== null): ?>
              <img src="<?= htmlspecialchars($avatar, ENT_QUOTES) ?>" alt="">
            <?php endif; ?>
          </div>
          <div class="creator-strip-text">
            <div class="creator-name"><?= htmlspecialchars($creatorName, ENT_QUOTES) ?></div>
            <div class="creator-meta"><?= htmlspecialchars($creators[$creatorName]['bio'] ?? '', ENT_QUOTES) ?: 'no bio set' ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (!$editing): ?>
      <div class="form-actions">
        <a class="btn secondary" href="video.php?id=<?= urlencode($id) ?>&edit=1">Edit Video</a>

        <?php /* The way back out. Everything in the stash is encrypted with a
                 password 7zip holds, so without this the only way to get a
                 video off the wall again was the command line. */ ?>
        <a class="btn secondary" href="media.php?id=<?= urlencode($id) ?>&amp;type=video&amp;download=1">
          <img class="btn-icon" src="assets/img/icons/download.png" alt="">Download
        </a>

        <?php /* While a conversion is running the button is gone, not merely
                 disabled — a second run over the same archive is refused by the
                 job id anyway, but offering it would be a lie. */ ?>
        <?php if (!empty($video['not_converted']) && !$converting): ?>
          <form action="video_convert.php" method="post">
            <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
            <button type="submit" class="btn">Convert to MP4/H.265</button>
          </form>
        <?php endif; ?>
      </div>

      <?php if ($converting): ?>
        <div class="card job-card indeterminate" id="job-card"
             data-kind="convert" data-target="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
          <div class="section-title" style="margin-top:0;">Converting to MP4/H.265</div>
          <div class="progress"><div class="progress-bar" id="job-bar"></div></div>
          <p class="hint job-message" id="job-message">
            <?= htmlspecialchars((string) $convertJob['message'], ENT_QUOTES) ?>
          </p>
          <p class="hint">
            This runs in the background. You can leave this page, keep browsing,
            or close the tab — it carries on, and the video stays exactly as it
            is until the converted copy has been written and verified.
          </p>
        </div>
      <?php endif; ?>

      <div class="section-title">Description</div>
      <p class="tile-stats" style="font-size:13px; color:#ccc;">
        <?= nl2br(htmlspecialchars($video['description'] ?? '', ENT_QUOTES)) ?: '<em>No description set.</em>' ?>
      </p>
    <?php else: ?>
      <div class="section-title" style="margin-top:20px;">Edit Video</div>
      <div class="card">
        <form action="video_save.php" method="post">
          <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
          <div class="field">
            <label for="v-title">Title</label>
            <input type="text" id="v-title" name="title" value="<?= htmlspecialchars($video['title'], ENT_QUOTES) ?>" required>
          </div>
          <div class="field">
            <label for="v-description">Description</label>
            <input type="text" id="v-description" name="description" value="<?= htmlspecialchars($video['description'] ?? '', ENT_QUOTES) ?>">
          </div>
          <div class="field">
            <label>Creators</label>
            <?php foreach (array_keys($creators) as $name): ?>
              <div class="field inline-check" style="margin-bottom:6px;">
                <label>
                  <input type="checkbox" name="creators[]" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                         <?= in_array($name, $videoCreators, true) ? 'checked' : '' ?>>
                  <?= htmlspecialchars($name, ENT_QUOTES) ?>
                </label>
              </div>
            <?php endforeach; ?>
            <p class="hint" style="margin:6px 0 0;">A video can credit more than one creator. Tick none and it falls back to <code>default</code>.</p>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn">Save Changes</button>
            <a class="btn secondary" href="video.php?id=<?= urlencode($id) ?>">Cancel</a>
          </div>
        </form>
      </div>

      <div class="section-title">Categories</div>
      <div class="card">
        <?php if ($assignments === []): ?>
          <p class="hint" style="margin:0 0 12px;">No categories assigned yet.</p>
        <?php endif; ?>

        <?php foreach ($assignments as $assignment): ?>
          <div class="tag" style="margin-bottom:8px;">
            <span class="dot" style="background:<?= htmlspecialchars($categories[$assignment['name']] ?? '#888', ENT_QUOTES) ?>"></span>
            <?= htmlspecialchars($assignment['name'], ENT_QUOTES) ?>
            <span class="ts"><?= VideoCategories::formatTimestamp((int) $assignment['timestamp_seconds']) ?></span>
            <form action="video_category_delete.php" method="post" style="display:inline;">
              <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
              <input type="hidden" name="name" value="<?= htmlspecialchars($assignment['name'], ENT_QUOTES) ?>">
              <input type="hidden" name="timestamp_seconds" value="<?= (int) $assignment['timestamp_seconds'] ?>">
              <button type="submit" style="background:none; border:none; color:var(--text-muted); cursor:pointer; padding:0 0 0 4px;">✕</button>
            </form>
          </div>
        <?php endforeach; ?>

        <form action="video_category_add.php" method="post" style="display:flex; gap:12px; align-items:flex-end; margin-top:12px;">
          <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
          <div class="field" style="margin-bottom:0; flex:1;">
            <label for="cat-name">Category</label>
            <select id="cat-name" name="name" required>
              <?php foreach (array_keys($categories) as $name): ?>
                <option value="<?= htmlspecialchars($name, ENT_QUOTES) ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="margin-bottom:0; width:130px;">
            <label for="cat-ts">Time (hh:mm:ss)</label>
            <input type="text" id="cat-ts" name="timestamp" value="00:00:00" pattern="[0-9]{1,2}:[0-9]{2}:[0-9]{2}" required>
          </div>
          <button type="submit" class="btn">Add</button>
        </form>
        <p class="hint" style="margin-top:12px;">
          Categories come from the global list (user menu → Manage Categories). The same
          category can be added more than once at different times.
        </p>
      </div>

      <form class="form-actions" action="video_delete.php" method="post" onsubmit="return confirm('Delete this video permanently?');">
        <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
        <button type="submit" class="btn secondary">Delete Video</button>
      </form>
    <?php endif; ?>
  </div>
</main>

<script>
  // The video file is only fetched/decrypted on click, never eagerly.
  const player = document.getElementById('player');

  // A view is recorded the first time playback starts on this page — not on
  // page load, so opening a video without watching it doesn't count.
  let viewRecorded = false;

  function recordView() {
    if (viewRecorded) return;
    viewRecorded = true;

    fetch('video_view.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ id: <?= json_encode($id) ?> }),
    })
      .then((response) => response.ok ? response.json() : null)
      .then((data) => {
        if (!data) return;
        document.getElementById('view-count').textContent = data.views;
        document.getElementById('view-label').textContent = data.views === 1 ? 'view' : 'views';
      })
      .catch(() => {});
  }

  function startPlayback(atSeconds) {
    let video = player.querySelector('video');

    if (!video) {
      video = document.createElement('video');
      video.src = player.dataset.videoSrc;
      video.controls = true;
      video.autoplay = true;
      player.replaceChildren(video);
    }

    if (atSeconds !== undefined) {
      const seek = () => { video.currentTime = atSeconds; };
      // Seeking needs metadata; if it isn't loaded yet, wait for it.
      video.readyState >= 1 ? seek() : video.addEventListener('loadedmetadata', seek, { once: true });
    }

    // Count the view when the browser actually starts playing, so a file that
    // fails to decode isn't counted as watched.
    video.addEventListener('playing', recordView, { once: true });
    video.play().catch(() => {});
  }

  document.getElementById('player-play').addEventListener('click', () => startPlayback());

  // Category chips jump the player to their timestamp.
  document.querySelectorAll('.tag-jump').forEach((chip) => {
    chip.addEventListener('click', () => {
      startPlayback(Number(chip.dataset.seconds));
      player.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  });
</script>
<script src="assets/job.js"></script>

</body>
</html>
