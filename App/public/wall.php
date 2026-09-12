<?php

declare(strict_types=1);

require __DIR__ . '/../src/Session.php';
require __DIR__ . '/../src/VideoQuery.php';

use MyStash\Session;
use MyStash\VideoQuery;

Session::requireLogin();

$index = Session::refreshIndex();
$categories = $index['categories'] ?? [];
$creators = $index['creators'] ?? [];

$query = VideoQuery::fromRequest($_GET);
$videos = $query->apply($index['videos'] ?? []);
$total = count($index['videos'] ?? []);

function formatLength(int $seconds): string
{
    return $seconds >= 3600
        ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
        : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}

// A→Z / Z→A for the title sorts, 0→9 / 9→0 for the numeric ones.
$sortIcon = match ($query->sort) {
    'title_asc' => 'sort-az',
    'title_desc' => 'sort-za',
    default => str_ends_with($query->sort, '_asc') ? 'sort-09' : 'sort-90',
};

$navActive = 'videos';
$headerActions = '<button class="icon-btn" id="upload-toggle" aria-expanded="false" aria-controls="upload-panel">'
    . '<img class="btn-icon" src="assets/img/icons/upload.png" alt="">Upload</button>'
    . '<button class="icon-btn" id="filters-toggle" aria-expanded="true" aria-controls="filter-panel">'
    . '<img class="btn-icon" id="filters-chevron" src="assets/img/icons/chevron-up.png" alt="">Filters</button>';
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

<?php require __DIR__ . '/../views/header.php'; ?>

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
    <form method="get" action="wall.php" id="filter-form">
      <input type="hidden" name="sort" value="<?= htmlspecialchars($query->sort, ENT_QUOTES) ?>">

      <div class="filter-section">
        <h4>Videos</h4>
        <div class="filter-row">
          <label>Longer than <output id="len-min-out"><?= $query->minMinutes ?>m</output></label>
          <input type="range" id="len-min" name="len_min" min="0" max="<?= VideoQuery::MAX_LENGTH_MINUTES ?>" step="1" value="<?= $query->minMinutes ?>">
        </div>
        <div class="filter-row">
          <label>Shorter than <output id="len-max-out"><?= $query->maxMinutes ?>m</output></label>
          <input type="range" id="len-max" name="len_max" min="0" max="<?= VideoQuery::MAX_LENGTH_MINUTES ?>" step="1" value="<?= $query->maxMinutes ?>">
        </div>
      </div>

      <div class="filter-section">
        <h4>Categories</h4>
        <?php foreach ($categories as $name => $color): ?>
          <div class="filter-row">
            <label style="font-weight:normal; justify-content:flex-start; gap:8px;">
              <input type="checkbox" name="category[]" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                     <?= $query->hasCategory($name) ? 'checked' : '' ?>>
              <span class="dot" style="display:inline-block; width:8px; height:8px; border-radius:50%; background:<?= htmlspecialchars($color, ENT_QUOTES) ?>"></span>
              <?= htmlspecialchars($name, ENT_QUOTES) ?>
            </label>
          </div>
        <?php endforeach; ?>
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

      <button type="submit" class="btn" style="width:100%;">Apply Filters</button>
      <a class="btn secondary" style="width:100%; margin-top:8px; display:block; text-align:center; box-sizing:border-box;" href="wall.php">Reset Filters</a>
    </form>
  </aside>

  <main class="container">
    <div class="wall-toolbar">
      <span class="wall-count">
        <?= count($videos) ?> of <?= $total ?> video<?= $total === 1 ? '' : 's' ?>
        <?= $query->isFiltered() ? ' (filtered)' : '' ?>
      </span>
      <form method="get" action="wall.php" class="sort-form">
        <?php foreach ($query->categories as $name): ?>
          <input type="hidden" name="category[]" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="len_min" value="<?= $query->minMinutes ?>">
        <input type="hidden" name="len_max" value="<?= $query->maxMinutes ?>">
        <label for="sort"><img class="btn-icon" src="assets/img/icons/<?= $sortIcon ?>.png" alt="">Sort</label>
        <select id="sort" name="sort" onchange="this.form.submit()">
          <?php foreach (VideoQuery::SORTS as $value => $label): ?>
            <option value="<?= $value ?>" <?= $value === $query->sort ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn secondary" style="width:auto; padding:6px 14px;">Go</button></noscript>
      </form>
    </div>

    <div class="video-grid">

      <?php foreach ($videos as $video): ?>
        <a class="video-card" href="video.php?id=<?= urlencode($video['id']) ?>"
           style="--tile-a:<?= htmlspecialchars($video['tile_gradient'][0] ?? '#333', ENT_QUOTES) ?>; --tile-b:<?= htmlspecialchars($video['tile_gradient'][1] ?? '#161616', ENT_QUOTES) ?>;">
          <div class="thumb">
            <div class="thumb-gradient"></div>
            <img class="thumb-image" src="media.php?id=<?= urlencode($video['id']) ?>&type=thumb" alt="" loading="lazy" onerror="this.style.display='none'">
            <video class="thumb-preview" muted loop playsinline preload="none" src="media.php?id=<?= urlencode($video['id']) ?>&type=preview"></video>
            <?php if (!empty($video['quality'])): ?>
              <span class="badge quality"><?= htmlspecialchars($video['quality'], ENT_QUOTES) ?></span>
            <?php endif; ?>
            <span class="badge duration"><?= formatLength((int) $video['length_seconds']) ?></span>
            <div class="preview-progress"></div>
          </div>
          <div class="tile-title"><?= htmlspecialchars($video['title'], ENT_QUOTES) ?></div>
          <div class="tile-creator"><?= htmlspecialchars($video['creator'], ENT_QUOTES) ?></div>
          <div class="tile-stats">
            <?= formatLength((int) $video['length_seconds']) ?> • <?= (int) $video['views'] ?> views<?php
              if (!empty($video['categories'])): ?> • <?= htmlspecialchars(implode(', ', $video['categories']), ENT_QUOTES) ?><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>

      <?php if ($videos === []): ?>
        <p class="hint">
          <?= $query->isFiltered() ? 'No videos match these filters.' : 'No videos yet — upload one to get started.' ?>
        </p>
      <?php endif; ?>

    </div>
  </main>
</div>

<script>
  const toggle = document.getElementById('filters-toggle');
  const panel = document.getElementById('filter-panel');
  const chevron = document.getElementById('filters-chevron');
  toggle.addEventListener('click', () => {
    const isClosed = panel.classList.toggle('closed');
    toggle.setAttribute('aria-expanded', String(!isClosed));
    chevron.src = `assets/img/icons/chevron-${isClosed ? 'down' : 'up'}.png`;
  });

  const uploadToggle = document.getElementById('upload-toggle');
  const uploadPanel = document.getElementById('upload-panel');
  uploadToggle.addEventListener('click', () => {
    const isOpen = uploadPanel.classList.toggle('open');
    uploadToggle.setAttribute('aria-expanded', String(isOpen));
  });

  const maxMinutes = <?= VideoQuery::MAX_LENGTH_MINUTES ?>;
  document.querySelectorAll('.filter-panel input[type="range"]').forEach((input) => {
    const output = document.getElementById(input.id + '-out');
    const isAge = input.id.startsWith('age');
    // The top of the length slider is an open end, not a hard ceiling.
    const format = (v) => isAge ? v : (Number(v) >= maxMinutes ? 'any' : `${v}m`);
    output.textContent = format(input.value);
    input.addEventListener('input', () => {
      output.textContent = format(input.value);
    });
  });

  // Hover-to-preview (Docs/SPECIFICATIONS.md §4.4): debounce before playing
  // the preview clip, cancel and reset instantly on mouseleave.
  document.querySelectorAll('.video-card').forEach((card) => {
    const video = card.querySelector('.thumb-preview');
    if (!video) return;

    let timer = null;

    card.addEventListener('mouseenter', () => {
      timer = setTimeout(() => {
        video.currentTime = 0;
        video.style.display = 'block';
        video.play().catch(() => {});
      }, 180);
    });

    card.addEventListener('mouseleave', () => {
      clearTimeout(timer);
      video.pause();
      video.style.display = 'none';
    });
  });
</script>

</body>
</html>
