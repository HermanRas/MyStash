<?php

declare(strict_types=1);

namespace MyStash;

/**
 * Starts a PHP session backed by tmpfs (/dev/shm) instead of the default
 * on-disk session save path. The session carries the user's password for
 * the duration of the login (needed to decrypt/re-encrypt on demand) —
 * it must never touch a persistent disk, only RAM-backed storage that is
 * gone on container restart.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $tmpfsPath = '/dev/shm/mystash-sessions';
        if (!is_dir($tmpfsPath)) {
            mkdir($tmpfsPath, 0700, true);
        }

        session_save_path($tmpfsPath);
        session_start();
    }

    public static function isLoggedIn(): bool
    {
        self::start();

        return isset($_SESSION['user'], $_SESSION['password']);
    }

    public static function login(string $user, string $password, array $index): void
    {
        self::start();
        session_regenerate_id(true);

        $_SESSION['user'] = $user;
        $_SESSION['password'] = $password;
        $_SESSION['index'] = $index;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    public static function requireLogin(): void
    {
        self::start();

        if (!self::isLoggedIn()) {
            header('Location: login.html');
            exit;
        }
    }

    public static function user(): string
    {
        return $_SESSION['user'];
    }

    public static function password(): string
    {
        return $_SESSION['password'];
    }

    public static function index(): array
    {
        return $_SESSION['index'];
    }

    public static function setIndex(array $index): void
    {
        $_SESSION['index'] = $index;
    }
}
