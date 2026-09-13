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

/** The wall, filtered to one category — what clicking a category opens. */
function wallLink(string $name): string
{
    return 'wall.php?' . http_build_query(['category' => [$name]]);
}

$navActive = 'categories';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — Categories</title>
<link rel="icon" href="assets/img/icon.png" type="image/png">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php require __DIR__ . '/../views/header.php'; ?>

<main class="manage-layout">
  <h1 class="page-title">Categories</h1>

  <div class="card">
    <p class="hint" style="margin-top:0;">
      Categories are global: defined once here, then assigned to videos (with a
      timestamp) from a video's edit screen. They also populate the wall's filter
      panel — click one below to open the wall filtered to it.
    </p>

    <?php if ($categories === []): ?>
      <p class="hint">No categories defined yet.</p>
    <?php endif; ?>

    <table style="width:100%; border-collapse:collapse; margin-bottom:20px;">
      <?php foreach ($categories as $name => $color): ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:10px 0;">
            <a class="tag" style="margin:0; text-decoration:none;" href="<?= htmlspecialchars(wallLink($name), ENT_QUOTES) ?>">
              <span class="dot" style="background:<?= htmlspecialchars($color, ENT_QUOTES) ?>"></span>
              <?= htmlspecialchars($name, ENT_QUOTES) ?>
            </a>
          </td>
          <td style="padding:10px 0; font-size:12px; color:var(--text-muted);">
            on <?= categoryUsage($videos, $name) ?> video<?= categoryUsage($videos, $name) === 1 ? '' : 's' ?>
          </td>
          <td style="padding:10px 0;">
            <form action="category_save.php" method="post" style="display:flex; gap:6px; align-items:center; justify-content:flex-end;">
              <input type="hidden" name="name" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
              <input class="swatch small" type="color" name="color" value="<?= htmlspecialchars($color, ENT_QUOTES) ?>">
              <button type="submit" class="btn secondary small"><img class="btn-icon" src="assets/img/icons/recolour.png" alt="">Recolour</button>
            </form>
          </td>
          <td style="padding:10px 0 10px 8px; text-align:right;">
            <form action="category_delete.php" method="post"
                  onsubmit="return confirm('Remove &quot;<?= htmlspecialchars($name, ENT_QUOTES) ?>&quot; from the global list?\n\nVideos already tagged with it keep their tags — you just won\'t be able to add it to new videos, and it disappears from the filter panel.');">
              <input type="hidden" name="name" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
              <button type="submit" class="btn secondary small"><img class="btn-icon" src="assets/img/icons/category-delete.png" alt="">Remove</button>
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
        <input class="swatch" type="color" id="cat-color" name="color" value="#ffa31a">
      </div>
      <button type="submit" class="btn"><img class="btn-icon" src="assets/img/icons/category-add.png" alt="">Add</button>
    </form>
  </div>
</main>

</body>
</html>
