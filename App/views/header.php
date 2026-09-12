<?php

declare(strict_types=1);

/**
 * Shared site header and top navigation (Docs/SPECIFICATIONS.md §4.2).
 *
 * Callers set $navActive to 'videos', 'creators' or 'categories' before
 * including this, and may set $headerActions to extra HTML for the right-hand
 * button group.
 *
 * There is exactly one search box on the site, and it is this one. It searches
 * whatever the page you are on is a list of: videos everywhere (landing on the
 * wall), creators while you are on the Creators screen. A page steers it by
 * setting $searchTerm (so the box still shows what was searched for after the
 * results load), and optionally $searchAction, $searchPlaceholder,
 * $searchClearHref and $searchHidden.
 *
 * The nav carries exactly three pills. Category names deliberately do NOT
 * appear here: they live in the wall's left filter panel, and having both was
 * two ways to do the same thing. "All Videos" links to the bare wall URL, so
 * it doubles as the reset for whatever filters are applied.
 */

// The search box is on every page, and it is a wall query — so the header
// needs VideoQuery whether or not the including page uses it.
require_once __DIR__ . '/../src/VideoQuery.php';

use MyStash\Session;
use MyStash\VideoQuery;

$navActive = $navActive ?? '';
$headerActions = $headerActions ?? '';
$searchTerm = $searchTerm ?? '';
$searchAction = $searchAction ?? 'wall.php';
$searchPlaceholder = $searchPlaceholder ?? 'Search videos, creators, categories…';
$searchClearHref = $searchClearHref ?? $searchAction;
// Extra state the search must not throw away (the Creators screen's sort).
$searchHidden = $searchHidden ?? [];

// Icons are sliced from the generated sheet in Docs/Assets/site_icons.png.
$navPills = [
    'videos' => ['All Videos', 'wall.php', 'videos'],
    'creators' => ['Creators', 'creator.php', 'creator'],
    'categories' => ['Categories', 'category.php', 'tag'],
];
?>
<header class="site-header">
  <a class="brand" href="wall.php">
    <img src="assets/img/mark.png" alt="MyStash">
    MyStash
  </a>

  <?php /* The site's one search box. On the Creators screen it searches
           creators in place; everywhere else it searches videos and lands on
           the wall, carrying no filters with it — narrowing down afterwards is
           what the filter panel is for (which does carry the term through). */ ?>
  <form class="search-bar" method="get" action="<?= htmlspecialchars($searchAction, ENT_QUOTES) ?>" role="search">
    <?php foreach ($searchHidden as $name => $value): ?>
      <input type="hidden" name="<?= htmlspecialchars((string) $name, ENT_QUOTES) ?>"
             value="<?= htmlspecialchars((string) $value, ENT_QUOTES) ?>">
    <?php endforeach; ?>
    <input type="text" name="q" aria-label="Search"
           maxlength="<?= VideoQuery::MAX_SEARCH_LENGTH ?>"
           value="<?= htmlspecialchars($searchTerm, ENT_QUOTES) ?>"
           placeholder="<?= htmlspecialchars($searchPlaceholder, ENT_QUOTES) ?>">
    <?php if ($searchTerm !== ''): ?>
      <a class="search-clear" href="<?= htmlspecialchars($searchClearHref, ENT_QUOTES) ?>" aria-label="Clear search">&times;</a>
    <?php endif; ?>
  </form>

  <div class="header-actions">
    <?= $headerActions ?>
    <div class="user-menu" tabindex="0">
      <div class="user-menu-trigger">
        <div class="avatar"><img src="assets/img/icons/user.png" alt=""></div>
        <?= htmlspecialchars(Session::user(), ENT_QUOTES) ?>
      </div>
      <div class="user-menu-dropdown">
        <a href="creator.php"><img class="menu-icon" src="assets/img/icons/creator.png" alt="">Manage Creators</a>
        <a href="category.php"><img class="menu-icon" src="assets/img/icons/tag.png" alt="">Manage Categories</a>
        <a href="user.php"><img class="menu-icon" src="assets/img/icons/user.png" alt="">Profile &amp; Password</a>
        <a href="logout.php"><img class="menu-icon" src="assets/img/icons/logout.png" alt="">Log Out</a>
      </div>
    </div>
  </div>
</header>

<nav class="category-bar">
  <?php foreach ($navPills as $key => [$label, $href, $icon]): ?>
    <a class="pill<?= $key === $navActive ? ' active' : '' ?>" href="<?= $href ?>">
      <img class="pill-icon" src="assets/img/icons/<?= $icon ?>.png" alt=""><?= $label ?>
    </a>
  <?php endforeach; ?>
</nav>
