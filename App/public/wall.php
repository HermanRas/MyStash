<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/VideoQuery.php';
require_once __DIR__ . '/../src/VideoCreators.php';

use MyStash\Session;
use MyStash\VideoCreators;
use MyStash\VideoQuery;

Session::requireLogin();

$index = Session::refreshIndex();
$categories = $index['categories'] ?? [];
$creators = $index['creators'] ?? [];

$query = VideoQuery::fromRequest($_GET);
$videos = $query->apply($index['videos'] ?? [], $creators);
$total = count($index['videos'] ?? []);

// Gender is a free-text field on the creator form, so the filter offers the
// values actually in use rather than a fixed list that might match nobody.
// Compared case-insensitively, but offered with the spelling the user typed.
$genders = [];
foreach ($creators as $creator) {
    $gender = trim((string) ($creator['gender'] ?? ''));
    if ($gender !== '' && !isset($genders[mb_strtolower($gender)])) {
        $genders[mb_strtolower($gender)] = $gender;
    }
}
ksort($genders);

require_once __DIR__ . '/../views/format.php';

// A→Z / Z→A for the title sorts, 0→9 / 9→0 for the numeric ones.
$sortIcon = match ($query->sort) {
    'title_asc' => 'sort-az',
    'title_desc' => 'sort-za',
    default => str_ends_with($query->sort, '_asc') ? 'sort-09' : 'sort-90',
};

/**
 * The wall's URL with one different sort, keeping every filter and the search
 * term exactly as they are — which is what makes a sorted, filtered wall a
 * link somebody can keep.
 */
$sortHref = static function (string $sort) use ($query): string {
    return 'wall.php?' . http_build_query([
        ...array_filter([
            'q' => $query->search,
            'gender' => $query->gender,
        ], static fn(string $value) => $value !== ''),
        'category' => $query->categories,
        'creator' => $query->creators,
        'len_min' => $query->minMinutes,
        'len_max' => $query->maxMinutes,
        'age_min' => $query->minAge,
        'age_max' => $query->maxAge,
        'sort' => $sort,
    ]);
};

$navActive = 'videos';
$searchTerm = $query->search;
// Icon only, with the label as the tooltip and as the accessible name — the
// two words were costing header width that the search box makes better use of.
$headerActions = '<button class="icon-btn square" id="upload-toggle" title="Upload" aria-label="Upload"'
    . ' aria-expanded="false" aria-controls="upload-panel">'
    . '<img class="btn-icon" src="assets/img/icons/upload.png" alt=""></button>'
    /* A funnel names the button; the chevron that used to sit here named the
       gesture instead, and said "Filters" nowhere. The open/closed state it
       used to carry moves onto the button itself, which lights up amber while
       the panel is showing. */
    . '<button class="icon-btn square active" id="filters-toggle" title="Filters" aria-label="Filters"'
    . ' aria-expanded="true" aria-controls="filter-panel">'
    . '<img class="btn-icon" src="assets/img/icons/filter.png" alt=""></button>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — Wall</title>
<link rel="icon" href="assets/img/icon.png" type="image/png">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php require __DIR__ . '/../views/header.php'; ?>

<?php
// A refused upload comes back here with its reason. The panel is opened for
// it: the message belongs beside the form that produced it, and a notice on
// a collapsed panel is a notice nobody reads.
$uploadError = (string) ($_GET['upload_error'] ?? '');

// The smaller of the two limits PHP applies, in bytes, so the progress card
// can refuse an oversized file before sending it rather than after. They are
// both 2G in App/php.ini today, but reading them beats hard-coding a number
// that stops being true the moment php.ini is edited.
$toBytes = static function (string $value): int {
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (int) $value;

    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
};
$uploadLimit = min(
    array_filter([
        $toBytes((string) ini_get('upload_max_filesize')),
        $toBytes((string) ini_get('post_max_size')),
    ]) ?: [0],
);
?>
<div class="upload-panel<?= $uploadError !== '' ? ' open' : '' ?>" id="upload-panel">
  <?php /* Always rendered, so upload.js has somewhere to put a failure it
           discovers itself (an aborted transfer, a file over the limit) without
           building the element on the fly. */ ?>
  <p class="upload-error"<?= $uploadError === '' ? ' hidden' : '' ?>><?= htmlspecialchars($uploadError, ENT_QUOTES) ?></p>
  <form action="upload.php" method="post" enctype="multipart/form-data">
    <div class="field">
      <label for="video-file">Video file</label>
      <input type="file" id="video-file" name="video" accept="video/*" required>
    </div>
    <div class="field">
      <label for="preview-at">Preview capture time, seconds (optional — defaults to 15s)</label>
      <input type="number" id="preview-at" name="preview_at" min="0" step="1" placeholder="15">
    </div>
    <?php /* 3.9 — a picture of your own instead of a frame from the video.
             Attaching one wins over the capture time above: someone who chose
             an image meant it. Either can be changed afterwards from the
             video's edit screen, which is the half that was missing entirely. */ ?>
    <div class="field">
      <label for="preview-image">Preview image (optional — overrides the capture time)</label>
      <input type="file" id="preview-image" name="preview_image" accept="image/*">
    </div>
    <button type="submit" class="btn">Upload</button>
  </form>

  <?php /* Hidden until a transfer starts, and only ever shown by upload.js —
           with JS off the form posts straight to upload.php as it always has
           and this card is never revealed. Same .progress/.progress-bar as the
           conversion job card on the watch page: one progress bar in the app,
           not two that drifted apart. */ ?>
  <div class="card upload-progress" id="upload-progress" hidden
       data-max-bytes="<?= $uploadLimit ?>">
    <div class="section-title" style="margin-top:0;" id="upload-message">Uploading</div>
    <div class="progress"><div class="progress-bar" id="upload-bar"></div></div>
    <p class="hint job-message" id="upload-detail"></p>
    <button type="button" class="btn secondary small" id="upload-cancel">Cancel</button>
  </div>
</div>

<div class="layout">
  <aside class="filter-panel" id="filter-panel">
    <h2 class="filter-title">
      <img class="filter-icon" src="assets/img/icons/filter.png" alt="">Filters
    </h2>

    <form method="get" action="wall.php" id="filter-form">
      <input type="hidden" name="sort" value="<?= htmlspecialchars($query->sort, ENT_QUOTES) ?>">
      <?php /* Narrowing an existing search must not throw the search away. */ ?>
      <input type="hidden" name="q" value="<?= htmlspecialchars($query->search, ENT_QUOTES) ?>">

      <?php /* Native <details> accordions — no JS needed. Categories is the
               one open by default; the others remember nothing between loads
               beyond whether a filter in them is active. */ ?>
      <details class="filter-section" open>
        <summary><span class="filter-summary-label"><img class="filter-icon" src="assets/img/icons/tag.png" alt="">Categories</span></summary>
        <?php foreach ($categories as $name => $color): ?>
          <div class="filter-row">
            <label class="check-row">
              <input type="checkbox" name="category[]" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                     <?= $query->hasCategory($name) ? 'checked' : '' ?>>
              <span class="dot" style="background:<?= htmlspecialchars($color, ENT_QUOTES) ?>"></span>
              <?= htmlspecialchars($name, ENT_QUOTES) ?>
            </label>
          </div>
        <?php endforeach; ?>
        <?php if ($categories === []): ?>
          <p class="hint" style="margin:0;">No categories defined yet.</p>
        <?php endif; ?>
      </details>

      <details class="filter-section" <?= $query->minMinutes > 0 || $query->maxMinutes < VideoQuery::MAX_LENGTH_MINUTES ? 'open' : '' ?>>
        <summary><span class="filter-summary-label"><img class="filter-icon" src="assets/img/icons/videos.png" alt="">Videos</span></summary>
        <div class="filter-row">
          <label>Longer than <output id="len-min-out"><?= $query->minMinutes ?>m</output></label>
          <input type="range" id="len-min" name="len_min" min="0" max="<?= VideoQuery::MAX_LENGTH_MINUTES ?>" step="1" value="<?= $query->minMinutes ?>">
        </div>
        <div class="filter-row">
          <label>Shorter than <output id="len-max-out"><?= $query->maxMinutes ?>m</output></label>
          <input type="range" id="len-max" name="len_max" min="0" max="<?= VideoQuery::MAX_LENGTH_MINUTES ?>" step="1" value="<?= $query->maxMinutes ?>">
        </div>
      </details>

      <details class="filter-section" <?= $query->creators !== [] || $query->filtersByCreatorAttributes() ? 'open' : '' ?>>
        <summary><span class="filter-summary-label"><img class="filter-icon" src="assets/img/icons/creator.png" alt="">Creators</span></summary>
        <?php foreach (array_keys($creators) as $name): ?>
          <div class="filter-row">
            <label class="check-row">
              <input type="checkbox" name="creator[]" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                     <?= $query->hasCreator($name) ? 'checked' : '' ?>>
              <?= htmlspecialchars($name, ENT_QUOTES) ?>
            </label>
          </div>
        <?php endforeach; ?>

        <?php /* Age and gender belong to the creator, not the video: a video
                 matches when any one of the people credited on it does. */ ?>
        <div class="filter-row">
          <label>Age at least <output id="age-min-out"><?= $query->minAge ?></output></label>
          <input type="range" id="age-min" name="age_min" min="<?= VideoQuery::MIN_AGE ?>" max="<?= VideoQuery::MAX_AGE ?>" step="1" value="<?= $query->minAge ?>">
        </div>
        <div class="filter-row">
          <label>Age at most <output id="age-max-out"><?= $query->maxAge ?></output></label>
          <input type="range" id="age-max" name="age_max" min="<?= VideoQuery::MIN_AGE ?>" max="<?= VideoQuery::MAX_AGE ?>" step="1" value="<?= $query->maxAge ?>">
        </div>
        <div class="filter-row">
          <label for="gender">Gender</label>
          <?php if ($genders === [] && $query->gender === ''): ?>
            <p class="hint" style="margin:0;">No creator has a gender set.</p>
          <?php else: ?>
            <select id="gender" name="gender">
              <option value="">Any</option>
              <?php /* Keep an unknown value from the URL selectable, so the
                       filter doesn't silently reset to Any. */ ?>
              <?php foreach (array_unique(array_merge(array_values($genders), $query->gender === '' ? [] : [$query->gender])) as $gender): ?>
                <option value="<?= htmlspecialchars($gender, ENT_QUOTES) ?>"
                        <?= strcasecmp($gender, $query->gender) === 0 ? 'selected' : '' ?>>
                  <?= htmlspecialchars($gender, ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
      </details>

      <?php /* No Apply button — the form submits on change (see below). No
               Reset either: "All Videos" in the top nav is the bare wall URL,
               which is the same thing. */ ?>
      <noscript><button type="submit" class="btn block">Apply Filters</button></noscript>
    </form>
  </aside>

  <main class="container">
    <div class="wall-toolbar">
      <span class="wall-count">
        <?= count($videos) ?> of <?= $total ?> video<?= $total === 1 ? '' : 's' ?>
        <?php if ($query->search !== ''): ?>
          matching <strong class="wall-term"><?= htmlspecialchars($query->search, ENT_QUOTES) ?></strong><?php
            /* The filter panel may be narrowing the search further. */
            ?><?= $query->categories !== [] || $query->creators !== [] || $query->minMinutes > 0 || $query->maxMinutes < VideoQuery::MAX_LENGTH_MINUTES || $query->filtersByCreatorAttributes() ? ', filtered' : '' ?>
        <?php elseif ($query->isFiltered()): ?>
          (filtered)
        <?php endif; ?>
      </span>
      <?php /* A menu rather than a <select>: every option is a real link
               carrying the current filters, so a sort stays linkable, needs no
               JavaScript, and the trigger can be a bare icon like the user
               menu instead of a labelled control eating toolbar width. */ ?>
      <div class="sort-menu" tabindex="0">
        <button type="button" class="icon-btn square sort-trigger"
                title="Sort: <?= htmlspecialchars(VideoQuery::SORTS[$query->sort], ENT_QUOTES) ?>"
                aria-label="Sort: <?= htmlspecialchars(VideoQuery::SORTS[$query->sort], ENT_QUOTES) ?>">
          <img class="btn-icon" src="assets/img/icons/<?= $sortIcon ?>.png" alt="">
        </button>
        <div class="sort-menu-dropdown">
          <?php foreach (VideoQuery::SORTS as $value => $label): ?>
            <a class="<?= $value === $query->sort ? 'active' : '' ?>"
               href="<?= htmlspecialchars($sortHref($value), ENT_QUOTES) ?>"><?= $label ?></a>
          <?php endforeach; ?>
        </div>
      </div>
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
          <div class="tile-creator"><?= htmlspecialchars(VideoCreators::label($video), ENT_QUOTES) ?></div>
          <div class="tile-stats">
            <?= formatLength((int) $video['length_seconds']) ?> • <?= (int) $video['views'] ?> view<?= (int) $video['views'] === 1 ? '' : 's' ?><?php
              if (!empty($video['categories'])): ?> • <?= htmlspecialchars(implode(', ', $video['categories']), ENT_QUOTES) ?><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>

      <?php if ($videos === []): ?>
        <p class="hint">
          <?php if ($query->search !== ''): ?>
            Nothing matches <strong class="wall-term"><?= htmlspecialchars($query->search, ENT_QUOTES) ?></strong>
            in any title, creator or category tag.
            <a href="wall.php" style="color:var(--accent);">Clear the search</a>
          <?php elseif ($query->isFiltered()): ?>
            No videos match these filters.
          <?php else: ?>
            No videos yet — upload one to get started.
          <?php endif; ?>
        </p>
      <?php endif; ?>

    </div>
  </main>
</div>

<script>
  const toggle = document.getElementById('filters-toggle');
  const panel = document.getElementById('filter-panel');
  toggle.addEventListener('click', () => {
    const isClosed = panel.classList.toggle('closed');
    toggle.setAttribute('aria-expanded', String(!isClosed));
    toggle.classList.toggle('active', !isClosed);
  });

  const uploadToggle = document.getElementById('upload-toggle');
  const uploadPanel = document.getElementById('upload-panel');
  uploadToggle.addEventListener('click', () => {
    const isOpen = uploadPanel.classList.toggle('open');
    uploadToggle.setAttribute('aria-expanded', String(isOpen));
  });

  // Filters apply on change rather than behind an Apply button. Sliders fire
  // `change` when released (not on every pixel), so this doesn't reload mid-drag.
  document.getElementById('filter-form').addEventListener('change', (event) => {
    event.currentTarget.submit();
  });

  const maxMinutes = <?= VideoQuery::MAX_LENGTH_MINUTES ?>;
  const maxAge = <?= VideoQuery::MAX_AGE ?>;
  document.querySelectorAll('.filter-panel input[type="range"]').forEach((input) => {
    const output = document.getElementById(input.id + '-out');
    const isAge = input.id.startsWith('age');
    // The top of either slider is an open end, not a hard ceiling: "180m" and
    // "80" would both read as real limits when they mean "no upper limit".
    const open = isAge ? maxAge : maxMinutes;
    const format = (v) => Number(v) >= open && input.id.endsWith('max')
      ? 'any'
      : (isAge ? v : `${v}m`);
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

    const bar = card.querySelector('.preview-progress');
    let timer = null;

    // The bar tracks the clip itself rather than running a fixed animation:
    // preview length is frames/fps and so varies from under a second to
    // minutes, and playback only begins after the debounce below.
    const draw = () => {
      if (!bar) return;
      const duration = video.duration;
      bar.style.width = Number.isFinite(duration) && duration > 0
        ? `${Math.min(100, (video.currentTime / duration) * 100)}%`
        : '0%';
    };

    video.addEventListener('timeupdate', draw);
    video.addEventListener('seeked', draw);

    card.addEventListener('mouseenter', () => {
      timer = setTimeout(() => {
        video.currentTime = 0;
        video.style.display = 'block';
        draw();
        video.play().catch(() => {});
      }, 180);
    });

    card.addEventListener('mouseleave', () => {
      clearTimeout(timer);
      video.pause();
      video.style.display = 'none';
      if (bar) bar.style.width = '0%';
    });
  });
</script>
<script src="assets/upload.js"></script>

</body>
</html>
