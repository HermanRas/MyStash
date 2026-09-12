<?php

declare(strict_types=1);

require __DIR__ . '/../src/Session.php';

use MyStash\Session;

Session::logout();

header('Location: login.html');
