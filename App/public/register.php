<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/User.php';

use MyStash\Datastore;
use MyStash\Session;
use MyStash\User;

Session::start();

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $users = new User();

    if (!User::isValidName($username)) {
        $error = 'Username must be letters and numbers only — no spaces or symbols.';
    } elseif ($users->exists($username)) {
        $error = 'That username is already taken.';
    } elseif ($password === '') {
        $error = 'Password cannot be empty.';
    } elseif ($password !== $confirm) {
        $error = 'The passwords do not match.';
    } elseif (!$users->create($username, $password)) {
        $error = 'Could not create the stash. Please try again.';
    } else {
        // The password is the key, so log straight in with what we already have.
        $index = (new Datastore())->loadIndex($username, $password);
        if ($index !== null) {
            Session::login($username, $password, $index);
            header('Location: wall.php');
            exit;
        }

        $error = 'Stash created, but sign-in failed. Please log in.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — Create Stash</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="form-page">
  <div class="form-card">
    <div class="brand">
      <img src="assets/img/icon.png" alt="MyStash">
      MyStash
    </div>

    <?php if ($error !== null): ?>
      <p class="hint" style="color:#ff6b6b;"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <form action="register.php" method="post">
      <div class="field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" autocomplete="username"
               value="<?= htmlspecialchars($username, ENT_QUOTES) ?>"
               pattern="[A-Za-z0-9]+" maxlength="32" required
               title="Letters and numbers only">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required>
      </div>
      <div class="field">
        <label for="confirm-password">Confirm password</label>
        <input type="password" id="confirm-password" name="confirm_password" autocomplete="new-password" required>
      </div>
      <button type="submit" class="btn">Create Stash</button>
    </form>

    <p class="hint">
      Your password is the encryption key for everything you store — it is never
      saved anywhere, so if you lose it your stash cannot be recovered by anyone,
      including you.
    </p>
    <p class="hint"><a href="login.html" style="color:var(--accent);">Already have a stash? Log in</a></p>
  </div>
</div>
</body>
</html>
