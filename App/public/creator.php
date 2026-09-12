<?php

declare(strict_types=1);

require __DIR__ . '/../src/Session.php';

use MyStash\Session;

Session::requireLogin();

$index = Session::index();
$creators = $index['creators'] ?? [];
$categories = $index['categories'] ?? [];
$videos = $index['videos'] ?? [];

$editing = $_GET['edit'] ?? null;
$editCreator = $editing !== null ? ($creators[$editing] ?? null) : null;

function videoCount(array $videos, string $creatorName): int
{
    return count(array_filter($videos, static fn($v) => $v['creator'] === $creatorName));
}
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

<header class="site-header">
  <a class="brand" href="wall.php">
    <img src="assets/img/icon.png" alt="MyStash">
    MyStash
  </a>
  <div class="search-bar">
    <input type="text" placeholder="Search videos, creators, categories…">
  </div>
  <div class="header-actions">
    <a class="icon-btn" href="creator.php?edit=">+ Add Creator</a>
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

<nav class="category-bar">
  <a class="pill" href="wall.php">All Videos</a>
  <span class="pill active">Creators</span>
</nav>

<h1 class="page-title">Creators</h1>

<main class="container">
  <div class="creator-grid">
    <?php foreach ($creators as $name => $creator): ?>
      <a class="creator-card" href="creator.php?edit=<?= urlencode($name) ?>">
        <div class="creator-avatar"></div>
        <div class="creator-name">
          <?= htmlspecialchars($name, ENT_QUOTES) ?>
          <?php if (!empty($creator['verified'])): ?><span class="verified">✓</span><?php endif; ?>
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
      <form action="creator_save.php" method="post">
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
          <label><input type="checkbox" name="verified" <?= !empty($editCreator['verified']) ? 'checked' : '' ?>> Verified</label>
        </div>
        <button type="submit" class="btn" style="width:auto; padding:8px 20px;">Save Changes</button>
      </form>
      <?php if ($editCreator && $editing !== 'default'): ?>
        <form action="creator_delete.php" method="post" style="display:inline;" onsubmit="return confirm('Delete this creator? Their videos will be reassigned to default.');">
          <input type="hidden" name="name" value="<?= htmlspecialchars($editing, ENT_QUOTES) ?>">
          <button type="submit" class="btn secondary" style="width:auto; padding:8px 20px;">Delete Creator</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="section-title">Categories</div>
  <div class="card" style="max-width:480px;">
    <?php foreach ($categories as $name => $color): ?>
      <div class="tag" style="margin-right:8px;">
        <span class="dot" style="background:<?= htmlspecialchars($color, ENT_QUOTES) ?>"></span>
        <?= htmlspecialchars($name, ENT_QUOTES) ?>
        <form action="category_delete.php" method="post" style="display:inline;" onsubmit="return confirm('Delete category &quot;<?= htmlspecialchars($name, ENT_QUOTES) ?>&quot;? It will be removed from all videos.');">
          <input type="hidden" name="name" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
          <button type="submit" style="background:none; border:none; color:var(--text-muted); cursor:pointer; padding:0 0 0 4px;">✕</button>
        </form>
      </div>
    <?php endforeach; ?>

    <form action="category_save.php" method="post" style="margin-top:16px; display:flex; gap:12px; align-items:flex-end;">
      <div class="field" style="margin-bottom:0; flex:1;">
        <label for="cat-name">New category</label>
        <input type="text" id="cat-name" name="name" required>
      </div>
      <div class="field" style="margin-bottom:0;">
        <label for="cat-color">Color</label>
        <input type="color" id="cat-color" name="color" value="#ffa31a" style="height:38px; width:60px; padding:2px; background:var(--surface-alt); border:1px solid var(--border); border-radius:6px;">
      </div>
      <button type="submit" class="btn" style="width:auto; padding:8px 20px;">Add</button>
    </form>
  </div>
</main>

</body>
</html>
