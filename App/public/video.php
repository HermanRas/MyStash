<?php

declare(strict_types=1);

require __DIR__ . '/../src/Session.php';

use MyStash\Session;

Session::requireLogin();

$index = Session::index();
$videos = $index['videos'] ?? [];
$creators = $index['creators'] ?? [];
$categories = $index['categories'] ?? [];

$id = (string) ($_GET['id'] ?? '');
$videoIndexPos = null;
foreach ($videos as $pos => $v) {
    if ($v['id'] === $id) {
        $videoIndexPos = $pos;
        break;
    }
}

if ($videoIndexPos === null) {
    header('Location: wall.php');
    exit;
}

$video = $videos[$videoIndexPos];
$editing = isset($_GET['edit']);

function formatLength(int $seconds): string
{
    return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function formatTimestamp(int $seconds): string
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
    <div class="player">▶ Video player placeholder — file loads only on play</div>

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
      <?php foreach ($video['categories'] as $cat): ?>
        <span class="tag">
          <span class="dot" style="background:<?= htmlspecialchars($categories[$cat] ?? '#888', ENT_QUOTES) ?>"></span>
          <?= htmlspecialchars($cat, ENT_QUOTES) ?>
        </span>
      <?php endforeach; ?>
      <?php foreach ($video['tags'] ?? [] as $tagIndex => $tag): ?>
        <span class="tag">
          <span class="dot" style="background:<?= htmlspecialchars($tag['color'] ?? '#5599ff', ENT_QUOTES) ?>"></span>
          <?= htmlspecialchars($tag['label'], ENT_QUOTES) ?>
          <span class="ts"><?= formatTimestamp((int) $tag['timestamp_seconds']) ?></span>
        </span>
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
          <div class="field">
            <label>Categories</label>
            <?php foreach (array_keys($categories) as $cat): ?>
              <label style="display:inline-flex; align-items:center; gap:4px; margin-right:12px; font-size:13px; font-weight:normal;">
                <input type="checkbox" name="categories[]" value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>" <?= in_array($cat, $video['categories'], true) ? 'checked' : '' ?>>
                <?= htmlspecialchars($cat, ENT_QUOTES) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="btn" style="width:auto; padding:8px 20px;">Save Changes</button>
          <a class="btn secondary" style="width:auto; padding:8px 20px; display:inline-block;" href="video.php?id=<?= urlencode($id) ?>">Cancel</a>
        </form>
      </div>

      <div class="section-title">Timestamp Tags</div>
      <div class="card" style="max-width:560px;">
        <?php foreach ($video['tags'] ?? [] as $tag): ?>
          <div class="tag" style="margin-bottom:8px;">
            <span class="dot" style="background:<?= htmlspecialchars($tag['color'] ?? '#5599ff', ENT_QUOTES) ?>"></span>
            <?= htmlspecialchars($tag['label'], ENT_QUOTES) ?>
            <span class="ts"><?= formatTimestamp((int) $tag['timestamp_seconds']) ?></span>
            <form action="video_tag_delete.php" method="post" style="display:inline;">
              <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
              <input type="hidden" name="label" value="<?= htmlspecialchars($tag['label'], ENT_QUOTES) ?>">
              <input type="hidden" name="timestamp_seconds" value="<?= (int) $tag['timestamp_seconds'] ?>">
              <button type="submit" style="background:none; border:none; color:var(--text-muted); cursor:pointer; padding:0 0 0 4px;">✕</button>
            </form>
          </div>
        <?php endforeach; ?>

        <form action="video_tag_add.php" method="post" style="display:flex; gap:12px; align-items:flex-end; margin-top:12px;">
          <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
          <div class="field" style="margin-bottom:0; flex:1;">
            <label for="tag-label">Label</label>
            <input type="text" id="tag-label" name="label" required>
          </div>
          <div class="field" style="margin-bottom:0; width:120px;">
            <label for="tag-ts">Timestamp (s)</label>
            <input type="number" id="tag-ts" name="timestamp_seconds" min="0" value="0" required>
          </div>
          <button type="submit" class="btn" style="width:auto; padding:8px 20px;">Add Tag</button>
        </form>
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
        <div class="creator-meta"><?= htmlspecialchars($creators[$video['creator']]['bio'] ?? 'no bio set', ENT_QUOTES) ?></div>
      </div>
    </div>
  </aside>
</main>

</body>
</html>
