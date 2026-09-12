<?php

declare(strict_types=1);

/**
 * Shared site header and top navigation (Docs/SPECIFICATIONS.md §4.2).
 *
 * Callers set $navActive to 'videos', 'creators' or 'categories' before
 * including this, and may set $headerActions to extra HTML for the right-hand
 * button group.
 *
 * The nav carries exactly three pills. Category names deliberately do NOT
 * appear here: they live in the wall's left filter panel, and having both was
 * two ways to do the same thing. "All Videos" links to the bare wall URL, so
 * it doubles as the reset for whatever filters are applied.
 */

use MyStash\Session;

$navActive = $navActive ?? '';
$headerActions = $headerActions ?? '';

$navPills = [
    'videos' => ['All Videos', 'wall.php'],
    'creators' => ['Creators', 'creator.php'],
    'categories' => ['Categories', 'category.php'],
];
?>
<header class="site-header">
  <a class="brand" href="wall.php">
    <img src="assets/img/icon.png" alt="MyStash">
    MyStash
  </a>

  <div class="search-bar">
    <input type="text" placeholder="Search videos, creators, categories…">
  </div>

  <div class="header-actions">
    <?= $headerActions ?>
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
  <?php foreach ($navPills as $key => [$label, $href]): ?>
    <a class="pill<?= $key === $navActive ? ' active' : '' ?>" href="<?= $href ?>"><?= $label ?></a>
  <?php endforeach; ?>
</nav>
