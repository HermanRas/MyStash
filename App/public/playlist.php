<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Playlists.php';
require_once __DIR__ . '/../src/VideoCreators.php';
require_once __DIR__ . '/../views/format.php';

use MyStash\Playlists;
use MyStash\Session;
use MyStash\VideoCreators;

/**
 * Playlists (Docs/PLAN.md 5.4).
 *
 * Two screens in one file, the way creator.php holds both the directory and
 * the edit form: no `id` is the grid of playlist cards, `?id=N` is the one
 * playlist, open for editing.
 *
 * The grid deliberately shows only the first few videos of each list
 * (Playlists::CARD_PREVIEW_COUNT). A card is a way of recognising a playlist,
 * not of reading it — the whole thing is one click away, and a card that grew
 * with its playlist would push the next one off the screen.
 */

Session::requireLogin();

$index = Session::refreshIndex();
$playlists = Playlists::all($index);
$videos = $index['videos'] ?? [];

$id = (string) ($_GET['id'] ?? '');
$editing = $id !== '' ? Playlists::find($index, $id) : null;

// A bookmark to a deleted playlist lands back on the grid rather than on an
// empty edit screen for something that no longer exists.
if ($id !== '' && $editing === null) {
    header('Location: playlist.php');
    exit;
}

$navActive = 'playlists';

/** The search text a video matches on — the same three fields VideoQuery uses. */
$searchable = static function (array $video): string {
    return strtolower(implode(' ', array_merge(
        [(string) ($video['title'] ?? '')],
        VideoCreators::of($video),
        array_map('strval', $video['categories'] ?? []),
    )));
};

if ($editing !== null) {
    $rows = Playlists::videosOf($index, $editing);
    $totalSeconds = array_sum(array_map(static fn(array $v) => (int) ($v['length_seconds'] ?? 0), $rows));
    $headerActions = '<button type="button" class="icon-btn" id="add-videos-open">'
        . '<img class="btn-icon" src="assets/img/icons/playlist-add.png" alt="">Add Videos</button>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — <?= $editing !== null ? htmlspecialchars($editing['name'], ENT_QUOTES) : 'Playlists' ?></title>
<link rel="icon" href="assets/img/icon.png" type="image/png">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php require __DIR__ . '/../views/header.php'; ?>

<?php if ($editing === null): ?>

<main class="manage-layout">
  <h1 class="page-title">Playlists</h1>

  <div class="card">
    <p class="hint" style="margin-top:0;">
      A playlist is an ordered set of videos you keep together. Nothing is
      copied — a video can sit on any number of playlists, and removing it from
      one leaves the video itself alone.
    </p>

    <?php if (($_GET['error'] ?? '') === 'name'): ?>
      <p class="hint" style="color:#ff6b6b;">
        A playlist needs a name, of at most <?= Playlists::MAX_NAME_LENGTH ?> characters.
      </p>
    <?php endif; ?>

    <form action="playlist_save.php" method="post" style="display:flex; gap:12px; align-items:flex-end;">
      <div class="field" style="margin-bottom:0; flex:1;">
        <label for="playlist-name">New playlist</label>
        <input type="text" id="playlist-name" name="name"
               maxlength="<?= Playlists::MAX_NAME_LENGTH ?>" required>
      </div>
      <button type="submit" class="btn"><img class="btn-icon" src="assets/img/icons/playlist-add.png" alt="">Create</button>
    </form>
  </div>

  <?php if ($playlists === []): ?>
    <p class="hint">No playlists yet. Create one above, or use the Playlist button on any video.</p>
  <?php endif; ?>

  <div class="playlist-grid">
    <?php foreach ($playlists as $playlist): ?>
      <?php $rows = Playlists::videosOf($index, $playlist); ?>
      <a class="playlist-card" href="playlist.php?id=<?= urlencode($playlist['id']) ?>">
        <?php /* The stack of the first few thumbnails is the card's face: a
                 playlist is recognised by what is on it long before its name
                 is read. */ ?>
        <div class="playlist-thumbs">
          <?php if ($rows === []): ?>
            <div class="playlist-thumb empty">Empty</div>
          <?php endif; ?>

          <?php foreach (array_slice($rows, 0, Playlists::CARD_PREVIEW_COUNT) as $video): ?>
            <div class="playlist-thumb">
              <img src="media.php?id=<?= urlencode((string) $video['id']) ?>&amp;type=thumb" alt=""
                   loading="lazy" onerror="this.style.display='none'">
              <span class="badge duration"><?= formatLength((int) ($video['length_seconds'] ?? 0)) ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="playlist-card-body">
          <div class="playlist-card-name"><?= htmlspecialchars($playlist['name'], ENT_QUOTES) ?></div>
          <div class="playlist-card-meta">
            <?= count($playlist['videos']) ?> video<?= count($playlist['videos']) === 1 ? '' : 's' ?>
            <?php if (count($rows) > Playlists::CARD_PREVIEW_COUNT): ?>
              • <?= count($rows) - Playlists::CARD_PREVIEW_COUNT ?> more
            <?php endif; ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</main>

<?php else: ?>

<main class="manage-layout">
  <p class="hint" style="margin-bottom:8px;">
    <a href="playlist.php" style="color:var(--accent);">&larr; All playlists</a>
  </p>

  <h1 class="page-title"><?= htmlspecialchars($editing['name'], ENT_QUOTES) ?></h1>

  <div class="card">
    <form action="playlist_save.php" method="post" style="display:flex; gap:12px; align-items:flex-end;">
      <input type="hidden" name="id" value="<?= htmlspecialchars($editing['id'], ENT_QUOTES) ?>">
      <div class="field" style="margin-bottom:0; flex:1;">
        <label for="playlist-rename">Playlist name</label>
        <input type="text" id="playlist-rename" name="name"
               maxlength="<?= Playlists::MAX_NAME_LENGTH ?>" required
               value="<?= htmlspecialchars($editing['name'], ENT_QUOTES) ?>">
      </div>
      <button type="submit" class="btn secondary">Rename</button>
    </form>

    <p class="hint">
      <?= count($rows) ?> video<?= count($rows) === 1 ? '' : 's' ?><?php
        if ($totalSeconds > 0): ?> • <?= formatLength($totalSeconds) ?> in total<?php endif; ?>
      — drag a row by its handle to reorder. The order saves as you drop.
    </p>

    <?php if ($rows === []): ?>
      <p class="hint">Nothing on this playlist yet. Use <strong>Add Videos</strong> above.</p>
    <?php endif; ?>

    <ul class="playlist-rows" id="playlist-rows" data-playlist="<?= htmlspecialchars($editing['id'], ENT_QUOTES) ?>">
      <?php foreach ($rows as $video): ?>
        <li class="playlist-row" draggable="true" data-id="<?= htmlspecialchars((string) $video['id'], ENT_QUOTES) ?>">
          <?php /* The drag handle is its own element rather than the whole row
                   being the grip: the row holds a link and a button, and a row
                   that is entirely draggable makes both awkward to press. */ ?>
          <span class="playlist-grip" aria-hidden="true">≡</span>

          <a class="playlist-row-thumb" href="video.php?id=<?= urlencode((string) $video['id']) ?>">
            <img src="media.php?id=<?= urlencode((string) $video['id']) ?>&amp;type=thumb" alt=""
                 loading="lazy" onerror="this.style.display='none'">
            <span class="badge duration"><?= formatLength((int) ($video['length_seconds'] ?? 0)) ?></span>
          </a>

          <div class="playlist-row-body">
            <a class="playlist-row-title" href="video.php?id=<?= urlencode((string) $video['id']) ?>">
              <?= htmlspecialchars((string) $video['title'], ENT_QUOTES) ?>
            </a>
            <div class="playlist-row-meta">
              <?= htmlspecialchars(VideoCreators::label($video), ENT_QUOTES) ?>
              • <?= (int) ($video['views'] ?? 0) ?> view<?= (int) ($video['views'] ?? 0) === 1 ? '' : 's' ?>
            </div>
          </div>

          <button type="button" class="btn secondary small playlist-remove"
                  title="Remove from this playlist">Remove</button>
        </li>
      <?php endforeach; ?>
    </ul>

    <p class="hint playlist-status" id="playlist-status" hidden></p>

    <form action="playlist_delete.php" method="post" style="margin-top:20px;"
          onsubmit="return confirm('Delete the playlist &quot;<?= htmlspecialchars($editing['name'], ENT_QUOTES) ?>&quot;?\n\nThe videos on it are not deleted — only the list itself.');">
      <input type="hidden" name="id" value="<?= htmlspecialchars($editing['id'], ENT_QUOTES) ?>">
      <button type="submit" class="btn secondary small"><img class="btn-icon" src="assets/img/icons/playlist-delete.png" alt="">Delete playlist</button>
    </form>
  </div>
</main>

<?php /* The add-videos modal. Every video is rendered into it up front and the
         search filters them in the browser: the list is already decrypted for
         this page, and a round trip per keystroke would be slower and no more
         correct. It matches on the same three fields VideoQuery searches —
         title, creators, category names — so "search" means one thing on this
         site. */ ?>
<dialog class="modal" id="add-videos">
  <form method="post" action="playlist_videos.php" class="modal-inner">
    <input type="hidden" name="id" value="<?= htmlspecialchars($editing['id'], ENT_QUOTES) ?>">

    <?php /* The videos already on the list are posted first, in their current
             order, so saving the modal appends the newly checked ones rather
             than reordering everything into index order. */ ?>
    <?php foreach ($editing['videos'] as $existingId): ?>
      <input type="hidden" name="videos[]" value="<?= htmlspecialchars($existingId, ENT_QUOTES) ?>">
    <?php endforeach; ?>

    <div class="modal-head">
      <h2>Add videos</h2>
      <button type="button" class="icon-btn square" id="add-videos-close" aria-label="Close"><img class="btn-icon" src="assets/img/icons/close.png" alt=""></button>
    </div>

    <input type="search" class="modal-search" id="add-videos-search"
           placeholder="Search videos, creators, categories…" autocomplete="off">

    <div class="modal-list" id="add-videos-list">
      <?php foreach ($videos as $video): ?>
        <?php $onList = in_array((string) $video['id'], $editing['videos'], true); ?>
        <label class="modal-row<?= $onList ? ' on-list' : '' ?>"
               data-search="<?= htmlspecialchars($searchable($video), ENT_QUOTES) ?>">
          <?php /* Already on the list: shown ticked and disabled, because its
                   membership is carried by the hidden fields above. Removing
                   is what the rows behind this modal are for. */ ?>
          <input type="checkbox" <?= $onList ? 'checked disabled' : '' ?>
                 name="videos[]" value="<?= htmlspecialchars((string) $video['id'], ENT_QUOTES) ?>">
          <?php /* Titles only — no thumbnails. This is a list to scan and tick,
                   not a wall to browse: the pictures made every row three times
                   as tall, so a stash of any size needed scrolling to find what
                   the search box had already narrowed down. */ ?>
          <span class="modal-row-body">
            <span class="modal-row-title"><?= htmlspecialchars((string) $video['title'], ENT_QUOTES) ?></span>
            <?php if ($onList): ?>
              <span class="modal-row-meta">already on this playlist</span>
            <?php endif; ?>
          </span>
        </label>
      <?php endforeach; ?>

      <?php if ($videos === []): ?>
        <p class="hint">There are no videos in this stash yet.</p>
      <?php endif; ?>
    </div>

    <p class="hint" id="add-videos-empty" hidden>Nothing matches that.</p>

    <div class="modal-foot">
      <button type="button" class="btn secondary" id="add-videos-cancel">Cancel</button>
      <button type="submit" class="btn">Add selected</button>
    </div>
  </form>
</dialog>

<script>
(() => {
  const dialog = document.getElementById('add-videos');
  const list = document.getElementById('playlist-rows');
  const status = document.getElementById('playlist-status');

  // --- the add-videos modal -------------------------------------------
  const search = document.getElementById('add-videos-search');
  const empty = document.getElementById('add-videos-empty');

  document.getElementById('add-videos-open').addEventListener('click', () => {
    dialog.showModal();
    search.focus();
  });

  const close = () => dialog.close();
  document.getElementById('add-videos-close').addEventListener('click', close);
  document.getElementById('add-videos-cancel').addEventListener('click', close);

  search.addEventListener('input', () => {
    // Every term has to appear somewhere in the row, which is what
    // VideoQuery::matchesSearch() does server-side.
    const terms = search.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    let shown = 0;

    document.querySelectorAll('#add-videos-list .modal-row').forEach((row) => {
      const hay = row.dataset.search || '';
      const hit = terms.every((t) => hay.includes(t));
      row.hidden = !hit;
      if (hit) shown += 1;
    });

    empty.hidden = shown > 0;
  });

  if (!list) return;

  // --- reordering ------------------------------------------------------
  const say = (text, bad) => {
    status.textContent = text;
    status.style.color = bad ? '#ff6b6b' : 'var(--text-muted)';
    status.hidden = false;
  };

  // The whole order is posted, never a "move X above Y": the server then
  // stores exactly what the user is looking at, with no chance of replaying
  // a move against a list that has changed.
  const save = () => {
    const ids = [...list.querySelectorAll('.playlist-row')].map((row) => row.dataset.id);
    const body = new URLSearchParams();
    body.append('id', list.dataset.playlist);
    ids.forEach((id) => body.append('videos[]', id));

    say('Saving…');

    fetch('playlist_videos.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'fetch' },
      body,
    })
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then(() => say('Order saved.'))
      .catch(() => say('That did not save — reload the page to see the real order.', true));
  };

  let dragged = null;

  list.addEventListener('dragstart', (e) => {
    dragged = e.target.closest('.playlist-row');
    if (!dragged) return;
    dragged.classList.add('dragging');
    // Firefox will not start a drag at all without data on the transfer.
    e.dataTransfer.setData('text/plain', dragged.dataset.id);
    e.dataTransfer.effectAllowed = 'move';
  });

  list.addEventListener('dragover', (e) => {
    if (!dragged) return;
    e.preventDefault();

    const over = e.target.closest('.playlist-row');
    if (!over || over === dragged) return;

    // Insert before or after depending on which half of the row the pointer
    // is in, so a row can be dropped at either end of its neighbour.
    const box = over.getBoundingClientRect();
    const after = (e.clientY - box.top) / box.height > 0.5;
    over.parentNode.insertBefore(dragged, after ? over.nextSibling : over);
  });

  list.addEventListener('dragend', () => {
    if (!dragged) return;
    dragged.classList.remove('dragging');
    dragged = null;
    save();
  });

  // --- removing --------------------------------------------------------
  list.addEventListener('click', (e) => {
    const button = e.target.closest('.playlist-remove');
    if (!button) return;

    const row = button.closest('.playlist-row');
    row.remove();
    save();
  });
})();
</script>

<?php endif; ?>

</body>
</html>
