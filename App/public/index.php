<?php

declare(strict_types=1);

require __DIR__ . '/../src/Session.php';

use MyStash\Session;

header('Location: ' . (Session::isLoggedIn() ? 'wall.php' : 'login.html'));
