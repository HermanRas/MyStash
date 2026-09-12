<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/User.php';

use MyStash\Session;
use MyStash\User;

Session::requireLogin();

$index = Session::refreshIndex();
$videoCount = count($index['videos'] ?? []);
$creatorCount = count($index['creators'] ?? []);

// Set by password_change.php on the way back here.
$status = (string) ($_GET['changed'] ?? '');
$error = (string) ($_GET['error'] ?? '');

$errors = [
    'mismatch' => 'The two new passwords do not match.',
    'short' => 'The new password must be at least ' . User::MIN_PASSWORD_LENGTH . ' characters.',
    'wrong' => 'That is not your current password.',
    'same' => 'The new password is the same as your current one.',
];

$navActive = '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — Profile</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php require __DIR__ . '/../views/header.php'; ?>

<h1 class="page-title">Profile</h1>

<main class="container profile-layout">
  <div class="card">
    <div class="field">
      <label for="p-username">Username</label>
      <input type="text" id="p-username" value="<?= htmlspecialchars(Session::user(), ENT_QUOTES) ?>" disabled>
      <p class="hint" style="margin:6px 0 0;">
        A stash is its directory and its password — there is no account record to
        rename, so the username is fixed once created.
      </p>
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Stash</label>
      <p class="hint" style="margin:0;">
        <?= $videoCount ?> video<?= $videoCount === 1 ? '' : 's' ?>,
        <?= $creatorCount ?> creator<?= $creatorCount === 1 ? '' : 's' ?>.
      </p>
    </div>
  </div>

  <div class="section-title">Change Password</div>

  <?php if ($status !== ''): ?>
    <p class="notice ok">
      Password changed — <?= (int) $status ?> archive<?= (int) $status === 1 ? '' : 's' ?>
      re-encrypted. This session is already using the new password.
    </p>
  <?php elseif ($error !== ''): ?>
    <p class="notice bad">
      <?= htmlspecialchars($errors[$error] ?? 'The password could not be changed — nothing was altered.', ENT_QUOTES) ?>
    </p>
  <?php endif; ?>

  <div class="card">
    <form action="password_change.php" method="post" id="password-form">
      <div class="field">
        <label for="current-password">Current password</label>
        <input type="password" id="current-password" name="current_password" autocomplete="current-password" required>
      </div>
      <div class="field">
        <label for="new-password">New password</label>
        <input type="password" id="new-password" name="new_password" autocomplete="new-password"
               minlength="<?= User::MIN_PASSWORD_LENGTH ?>" required>
      </div>
      <div class="field">
        <label for="confirm-password">Confirm new password</label>
        <input type="password" id="confirm-password" name="confirm_password" autocomplete="new-password"
               minlength="<?= User::MIN_PASSWORD_LENGTH ?>" required>
        <p class="hint" id="match-hint" style="margin:6px 0 0;" hidden>The two new passwords do not match.</p>
      </div>
      <button type="submit" class="btn block">Re-encrypt Stash with New Password</button>
    </form>

    <p class="hint">
      Your password <em>is</em> the encryption key, so changing it re-encrypts every
      archive in your stash<?php if ($videoCount > 0): ?>, all <?= $videoCount ?>
      video<?= $videoCount === 1 ? '' : 's' ?> included<?php endif ?>. On a large
      stash this takes a while; leave the page open until it finishes. Each archive
      keeps its previous bytes as <code>.enc.old</code> until the whole run succeeds,
      and the index is rewritten last, so an interrupted run leaves your current
      password still working.
    </p>
    <p class="hint">
      It cannot be reset. There is no record of it anywhere — forget it and the stash
      is unreadable.
    </p>
  </div>
</main>

<script>
  // Catch the mismatch here rather than after re-encrypting anything. The
  // server checks it too; this just saves a pointless round trip.
  const form = document.getElementById('password-form');
  const next = document.getElementById('new-password');
  const again = document.getElementById('confirm-password');
  const hint = document.getElementById('match-hint');

  const compare = () => {
    const mismatched = again.value !== '' && next.value !== again.value;
    hint.hidden = !mismatched;
    again.setCustomValidity(mismatched ? 'The two new passwords do not match.' : '');
  };

  next.addEventListener('input', compare);
  again.addEventListener('input', compare);

  form.addEventListener('submit', (event) => {
    compare();
    if (!form.checkValidity()) {
      event.preventDefault();
      form.reportValidity();
      return;
    }
    // The re-key is synchronous and can run for a while; say so, and make it
    // impossible to fire a second one over the top of the first.
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = 'Re-encrypting your stash…';
  });
</script>

</body>
</html>
