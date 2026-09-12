<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';

use MyStash\Session;

header('Location: ' . (Session::isLoggedIn() ? 'wall.php' : 'login.html'));
