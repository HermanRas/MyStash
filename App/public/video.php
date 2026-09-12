<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/VideoCategories.php';

use MyStash\Session;
use MyStash\VideoCategories;

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

$editing = isset($_GET['edit']);
$assignments = (new VideoCategories())->load(Session::user(), Session::password(), $id);

function formatLength(int $seconds): string
{
    return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — <?= htmlspecialchars($video['title'], ENT_QUOTES) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header class="site-header">
  <a class="brand" href="wall.php">
    <img src="assets/img/icon.png" alt="MyStash">
    MyStash
  </a>
  <div class="search-bar">
    <input type="text" placeholder="Search videos, creators, categories…">
  </div>
  <div class="header-actions">
    <div class="user-menu" tabindex="0">
      <div class="user-menu-trigger">
        <div class="avatar"></div>
        <?= htmlspecialchars(Session::user(), ENT_QUOTES) ?>
      </div>
      <div class="user-menu-dropdown">
        <a href="creator.php">Manage Creators</a>
        <a href="user.html">Profile &amp; Password</a>
        <a href="logout.php">Log Out</a>
      </div>
    </div>
  </div>
</header>

<main class="watch-layout">
  <div>
    <div class="player" id="player" data-video-src="media.php?id=<?= urlencode($id) ?>&amp;type=video">
      <img src="media.php?id=<?= urlencode($id) ?>&amp;type=thumb" alt="" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'">
      <button type="button" id="player-play" style="position:relative; z-index:1; background:rgba(0,0,0,0.6); border:1px solid var(--border); color:#fff; border-radius:50%; width:64px; height:64px; font-size:20px; cursor:pointer;">▶</button>
    </div>

    <?php if (isset($_GET['convert_error'])): ?>
      <p class="hint" style="color:#ff6b6b;">Conversion failed — no encrypted video file exists for this entry yet (seed/demo data has no real media behind it).</p>
    <?php endif; ?>

    <div class="watch-title"><?= htmlspecialchars($video['title'], ENT_QUOTES) ?></div>
    <div class="watch-meta">
      <?= (int) $video['views'] ?> views • <?= formatLength((int) $video['length_seconds']) ?> •
      Uploaded by <strong><?= htmlspecialchars($video['creator'], ENT_QUOTES) ?></strong>
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

    <?php if (!$editing): ?>
      <a class="btn secondary" style="width:auto; margin-top:16px; padding:8px 20px; display:inline-block;" href="video.php?id=<?= urlencode($id) ?>&edit=1">Edit Video</a>

      <?php if (!empty($video['not_converted'])): ?>
        <form action="video_convert.php" method="post" style="display:inline;">
          <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
          <button type="submit" class="btn" style="width:auto; margin-top:16px; padding:8px 20px;">Convert to MP4/H.265</button>
        </form>
      <?php endif; ?>

      <div class="section-title">Description</div>
      <p class="tile-stats" style="font-size:13px; color:#ccc;">
        <?= nl2br(htmlspecialchars($video['description'] ?? '', ENT_QUOTES)) ?: '<em>No description set.</em>' ?>
      </p>
    <?php else: ?>
      <div class="section-title" style="margin-top:20px;">Edit Video</div>
      <div class="card" style="max-width:560px;">
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
            <label for="v-creator">Creator</label>
            <select id="v-creator" name="creator">
              <?php foreach (array_keys($creators) as $name): ?>
                <option value="<?= htmlspecialchars($name, ENT_QUOTES) ?>" <?= $name === $video['creator'] ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn" style="width:auto; padding:8px 20px;">Save Changes</button>
          <a class="btn secondary" style="width:auto; padding:8px 20px; display:inline-block;" href="video.php?id=<?= urlencode($id) ?>">Cancel</a>
        </form>
      </div>

      <div class="section-title">Categories</div>
      <div class="card" style="max-width:560px;">
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
          <button type="submit" class="btn" style="width:auto; padding:8px 20px;">Add</button>
        </form>
        <p class="hint" style="margin-top:12px;">
          Categories come from the global list (Manage Creators → Categories). The same
          category can be added more than once at different times.
        </p>
      </div>

      <form action="video_delete.php" method="post" style="margin-top:20px;" onsubmit="return confirm('Delete this video permanently?');">
        <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
        <button type="submit" class="btn secondary" style="width:auto; padding:8px 20px;">Delete Video</button>
      </form>
    <?php endif; ?>
  </div>

  <aside>
    <div class="section-title">Creator</div>
    <div class="card" style="display:flex; align-items:center; gap:12px;">
      <div class="creator-avatar" style="width:56px; height:56px; margin:0;"></div>
      <div>
        <div class="creator-name"><?= htmlspecialchars($video['creator'], ENT_QUOTES) ?></div>
        <div class="creator-meta"><?= htmlspecialchars($creators[$video['creator']]['bio'] ?? '', ENT_QUOTES) ?: 'no bio set' ?></div>
      </div>
    </div>
  </aside>
</main>

<script>
  // The video file is only fetched/decrypted on click, never eagerly.
  const player = document.getElementById('player');

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

</body>
</html>
