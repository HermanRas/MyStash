<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/CreatorStore.php';
require_once __DIR__ . '/../src/VideoCreators.php';

use MyStash\CreatorStore;
use MyStash\Session;
use MyStash\VideoCreators;

Session::requireLogin();

$index = Session::refreshIndex();
$creators = $index['creators'] ?? [];
$categories = $index['categories'] ?? [];
$videos = $index['videos'] ?? [];

$editing = $_GET['edit'] ?? null;
// Full details come from the creator's own encrypted record; the index copy is
// only the denormalized summary the grid renders from.
$editCreator = ($editing !== null && $editing !== '')
    ? (new CreatorStore())->load(Session::user(), Session::password(), $index, $editing)
    : null;

function videoCount(array $videos, string $creatorName): int
{
    return VideoCreators::videoCount(['videos' => $videos], $creatorName);
}

function avatarUrl(array $creator): ?string
{
    $id = (string) ($creator['id'] ?? '');

    return CreatorStore::hasProfileImage(Session::user(), $id)
        ? 'media.php?type=avatar&creator=' . urlencode($id)
        : null;
}

$navActive = 'creators';
$headerActions = '<a class="icon-btn" href="creator.php?edit="><img class="btn-icon" src="assets/img/icons/creator.png" alt="">Add Creator</a>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — Creators</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php require __DIR__ . '/../views/header.php'; ?>

<h1 class="page-title">Creators</h1>

<main class="container">
  <div class="creator-grid">
    <?php foreach ($creators as $name => $creator): ?>
      <a class="creator-card" href="creator.php?edit=<?= urlencode($name) ?>">
        <?php $avatar = avatarUrl($creator); ?>
        <div class="creator-avatar">
          <?php if ($avatar !== null): ?>
            <img src="<?= htmlspecialchars($avatar, ENT_QUOTES) ?>" alt="">
          <?php endif; ?>
        </div>
        <div class="creator-name">
          <?= htmlspecialchars($name, ENT_QUOTES) ?>
        </div>
        <div class="creator-meta">
          <?= videoCount($videos, $name) ?> video<?= videoCount($videos, $name) === 1 ? '' : 's' ?>
          <?php if (!empty($creator['age'])): ?> • Age <?= (int) $creator['age'] ?><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($editing !== null): ?>
    <div class="section-title"><?= $editCreator ? 'Edit Creator' : 'Add Creator' ?></div>
    <div class="card" style="max-width:480px;">
      <form id="creator-form" action="creator_save.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="original_name" value="<?= htmlspecialchars($editing, ENT_QUOTES) ?>">
        <div class="field">
          <label for="c-name">Display name</label>
          <input type="text" id="c-name" name="name" value="<?= htmlspecialchars($editCreator['name'] ?? '', ENT_QUOTES) ?>" required>
        </div>
        <div class="field">
          <label for="c-age">Age</label>
          <input type="number" id="c-age" name="age" min="18" max="120" value="<?= htmlspecialchars((string) ($editCreator['age'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field">
          <label for="c-gender">Gender</label>
          <input type="text" id="c-gender" name="gender" value="<?= htmlspecialchars($editCreator['gender'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="field">
          <label for="c-bio">Short bio</label>
          <input type="text" id="c-bio" name="bio" value="<?= htmlspecialchars($editCreator['bio'] ?? '', ENT_QUOTES) ?>" placeholder="Short bio…">
        </div>
        <div class="field">
          <label for="c-profile">Profile picture</label>
          <?php $editAvatar = $editCreator !== null ? avatarUrl($editCreator) : null; ?>
          <?php if ($editAvatar !== null): ?>
            <div class="creator-avatar" style="width:72px; height:72px; margin:0 0 8px;">
              <img src="<?= htmlspecialchars($editAvatar, ENT_QUOTES) ?>" alt="">
            </div>
          <?php endif; ?>
          <input type="file" id="c-profile" name="profile" accept="image/*">
          <p class="hint" style="margin:6px 0 0;">Stored encrypted alongside the creator's record. Leave empty to keep the current picture.</p>
        </div>
      </form>

      <?php if ($editCreator && $editing !== 'default'): ?>
        <form id="creator-delete-form" action="creator_delete.php" method="post"
              onsubmit="return confirm('Delete this creator? Their videos will be reassigned to default.');">
          <input type="hidden" name="name" value="<?= htmlspecialchars($editing, ENT_QUOTES) ?>">
        </form>
      <?php endif; ?>

      <?php /* Both buttons sit outside their forms and target them by id, so
               they can share one row (forms cannot be nested). */ ?>
      <div class="form-actions">
        <button type="submit" form="creator-form" class="btn">Save Changes</button>
        <?php if ($editCreator && $editing !== 'default'): ?>
          <button type="submit" form="creator-delete-form" class="btn secondary">Delete Creator</button>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

</main>

</body>
</html>
