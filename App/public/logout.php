<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';

use MyStash\Session;

Session::logout();

header('Location: login.html');
