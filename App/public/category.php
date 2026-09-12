<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';

use MyStash\Session;

Session::requireLogin();

$index = Session::refreshIndex();
$categories = $index['categories'] ?? [];
$videos = $index['videos'] ?? [];

function categoryUsage(array $videos, string $name): int
{
    return count(array_filter($videos, static fn($v) => in_array($name, $v['categories'] ?? [], true)));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — Categories</title>
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
        <a href="category.php">Manage Categories</a>
        <a href="user.html">Profile &amp; Password</a>
        <a href="logout.php">Log Out</a>
      </div>
    </div>
  </div>
</header>

<nav class="category-bar">
  <a class="pill" href="wall.php">All Videos</a>
  <a class="pill" href="creator.php">Creators</a>
  <span class="pill active">Categories</span>
</nav>

<h1 class="page-title">Categories</h1>

<main class="container">
  <div class="card" style="max-width:560px;">
    <p class="hint" style="margin-top:0;">
      Categories are global: defined once here, then assigned to videos (with a
      timestamp) from a video's edit screen. They also populate the wall's filter panel.
    </p>

    <?php if ($categories === []): ?>
      <p class="hint">No categories defined yet.</p>
    <?php endif; ?>

    <table style="width:100%; border-collapse:collapse; margin-bottom:20px;">
      <?php foreach ($categories as $name => $color): ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:10px 0;">
            <span class="tag" style="margin:0;">
              <span class="dot" style="background:<?= htmlspecialchars($color, ENT_QUOTES) ?>"></span>
              <?= htmlspecialchars($name, ENT_QUOTES) ?>
            </span>
          </td>
          <td style="padding:10px 0; font-size:12px; color:var(--text-muted);">
            on <?= categoryUsage($videos, $name) ?> video<?= categoryUsage($videos, $name) === 1 ? '' : 's' ?>
          </td>
          <td style="padding:10px 0;">
            <form action="category_save.php" method="post" style="display:flex; gap:6px; align-items:center; justify-content:flex-end;">
              <input type="hidden" name="name" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
              <input type="color" name="color" value="<?= htmlspecialchars($color, ENT_QUOTES) ?>"
                     style="height:30px; width:44px; padding:2px; background:var(--surface-alt); border:1px solid var(--border); border-radius:6px;">
              <button type="submit" class="btn secondary" style="width:auto; padding:5px 12px; font-size:12px;">Recolour</button>
            </form>
          </td>
          <td style="padding:10px 0 10px 8px; text-align:right;">
            <form action="category_delete.php" method="post"
                  onsubmit="return confirm('Remove &quot;<?= htmlspecialchars($name, ENT_QUOTES) ?>&quot; from the global list?\n\nVideos already tagged with it keep their tags — you just won\'t be able to add it to new videos, and it disappears from the filter panel.');">
              <input type="hidden" name="name" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
              <button type="submit" class="btn secondary" style="width:auto; padding:5px 12px; font-size:12px;">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>

    <form action="category_save.php" method="post" style="display:flex; gap:12px; align-items:flex-end;">
      <div class="field" style="margin-bottom:0; flex:1;">
        <label for="cat-name">New category</label>
        <input type="text" id="cat-name" name="name" required>
      </div>
      <div class="field" style="margin-bottom:0;">
        <label for="cat-color">Colour</label>
        <input type="color" id="cat-color" name="color" value="#ffa31a"
               style="height:38px; width:60px; padding:2px; background:var(--surface-alt); border:1px solid var(--border); border-radius:6px;">
      </div>
      <button type="submit" class="btn" style="width:auto; padding:8px 20px;">Add</button>
    </form>
  </div>
</main>

</body>
</html>
