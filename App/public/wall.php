<?php

declare(strict_types=1);

require __DIR__ . '/../src/Session.php';

use MyStash\Session;

Session::requireLogin();

$index = Session::index();
$videos = $index['videos'] ?? [];
$categories = $index['categories'] ?? [];
$creators = $index['creators'] ?? [];

function formatLength(int $seconds): string
{
    return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function creatorLabel(array $creators, string $name): string
{
    $verified = ($creators[$name]['verified'] ?? false) ? ' ✓' : '';

    return htmlspecialchars($name, ENT_QUOTES) . $verified;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — Wall</title>
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
    <button class="icon-btn" id="upload-toggle" aria-expanded="false" aria-controls="upload-panel">+ Upload</button>
    <button class="icon-btn" id="filters-toggle" aria-expanded="false" aria-controls="filter-panel">Filters</button>
    <div class="user-menu" tabindex="0">
      <div class="user-menu-trigger">
        <div class="avatar"></div>
        <?= htmlspecialchars(Session::user(), ENT_QUOTES) ?>
      </div>
      <div class="user-menu-dropdown">
        <a href="creator.html">Manage Creators</a>
        <a href="user.html">Profile &amp; Password</a>
        <a href="logout.php">Log Out</a>
      </div>
    </div>
  </div>
</header>

<nav class="category-bar">
  <span class="pill active">All</span>
  <span class="pill">Most Recent</span>
  <span class="pill">Not Converted</span>
  <a class="pill" href="creator.html">Creators</a>
  <?php foreach ($categories as $name => $color): ?>
    <?php if (in_array($name, ['Most Recent', 'Not Converted'], true)) continue; ?>
    <span class="pill"><?= htmlspecialchars($name, ENT_QUOTES) ?></span>
  <?php endforeach; ?>
</nav>

<div class="upload-panel" id="upload-panel">
  <form action="upload.php" method="post" enctype="multipart/form-data">
    <div class="field">
      <label for="video-file">Video file</label>
      <input type="file" id="video-file" name="video" accept="video/*" required>
    </div>
    <div class="field">
      <label for="preview-at">Preview capture time, seconds (optional — defaults to 15s)</label>
      <input type="number" id="preview-at" name="preview_at" min="0" step="1" placeholder="15">
    </div>
    <button type="submit" class="btn" style="width:auto; padding:8px 24px;">Upload</button>
  </form>
</div>

<div class="layout">
  <aside class="filter-panel" id="filter-panel">
    <div class="filter-section">
      <h4>Videos</h4>
      <div class="filter-row">
        <label>Longer than <output id="len-min-out">0m</output></label>
        <input type="range" id="len-min" min="0" max="60" step="1" value="0">
      </div>
      <div class="filter-row">
        <label>Shorter than <output id="len-max-out">60m</output></label>
        <input type="range" id="len-max" min="0" max="60" step="1" value="60">
      </div>
    </div>

    <div class="filter-section">
      <h4>Creators</h4>
      <div class="filter-row">
        <label>Age at least <output id="age-min-out">18</output></label>
        <input type="range" id="age-min" min="18" max="80" step="1" value="18">
      </div>
      <div class="filter-row">
        <label>Age at most <output id="age-max-out">80</output></label>
        <input type="range" id="age-max" min="18" max="80" step="1" value="80">
      </div>
      <div class="filter-row">
        <label>Gender</label>
        <select>
          <option value="">Any</option>
          <option>Female</option>
          <option>Male</option>
          <option>Non-binary</option>
        </select>
      </div>
    </div>

    <button class="btn secondary" style="width:100%;">Reset Filters</button>
  </aside>

  <main class="container">
    <div class="video-grid">

      <?php foreach ($videos as $video): ?>
        <a class="video-card" href="video.html?id=<?= urlencode($video['id']) ?>"
           style="--tile-a:<?= htmlspecialchars($video['tile_gradient'][0] ?? '#333', ENT_QUOTES) ?>; --tile-b:<?= htmlspecialchars($video['tile_gradient'][1] ?? '#161616', ENT_QUOTES) ?>;">
          <div class="thumb">
            <div class="thumb-gradient"></div>
            <?php if (!empty($video['quality'])): ?>
              <span class="badge quality"><?= htmlspecialchars($video['quality'], ENT_QUOTES) ?></span>
            <?php endif; ?>
            <span class="badge duration"><?= formatLength((int) $video['length_seconds']) ?></span>
            <div class="preview-progress"></div>
          </div>
          <div class="tile-title"><?= htmlspecialchars($video['title'], ENT_QUOTES) ?></div>
          <div class="tile-creator"><?= creatorLabel($creators, $video['creator']) ?></div>
          <div class="tile-stats">
            <?= (int) $video['views'] ?> views • <?= htmlspecialchars(implode(', ', $video['categories']), ENT_QUOTES) ?>
          </div>
        </a>
      <?php endforeach; ?>

    </div>
  </main>
</div>

<script>
  const toggle = document.getElementById('filters-toggle');
  const panel = document.getElementById('filter-panel');
  toggle.addEventListener('click', () => {
    const isOpen = panel.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(isOpen));
  });

  const uploadToggle = document.getElementById('upload-toggle');
  const uploadPanel = document.getElementById('upload-panel');
  uploadToggle.addEventListener('click', () => {
    const isOpen = uploadPanel.classList.toggle('open');
    uploadToggle.setAttribute('aria-expanded', String(isOpen));
  });

  document.querySelectorAll('.filter-panel input[type="range"]').forEach((input) => {
    const output = document.getElementById(input.id + '-out');
    const isAge = input.id.startsWith('age');
    const format = (v) => isAge ? v : `${v}m`;
    output.textContent = format(input.value);
    input.addEventListener('input', () => {
      output.textContent = format(input.value);
    });
  });
</script>

</body>
</html>
