<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/Crypto7z.php';
require_once __DIR__ . '/Datastore.php';

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

    /**
     * Adopts a new password after the stash has been re-keyed under it
     * (Docs/PLAN.md 6.1), so the session keeps working instead of being
     * logged out by refreshIndex() the moment the old key stops opening
     * anything.
     *
     * Only ever call this once the re-key has actually succeeded — a session
     * holding a password the archives are not on is exactly the split-key
     * state refreshIndex() exists to catch.
     */
    public static function setPassword(string $password): void
    {
        self::start();
        // The password changed, so treat it as a fresh authentication.
        session_regenerate_id(true);

        $_SESSION['password'] = $password;
    }

    /**
     * Re-reads the index from disk and refreshes the session copy.
     *
     * Every page and endpoint must start from this rather than the snapshot
     * taken at login: the whole index is written back on each change, so a
     * session working from a stale copy would silently revert changes made in
     * another session (which is exactly how a deleted video reappeared on the
     * wall once).
     *
     * If the index no longer decrypts, the session's password is stale — the
     * stash was re-keyed elsewhere while this session was open. The session is
     * ended rather than allowed to continue: every write re-encrypts with the
     * password the session holds, so carrying on would rewrite the index and
     * creator records under the *old* key and leave the stash split across two
     * passwords, with some archives opening under neither in practice. That is
     * not hypothetical — it happened once, after `bin/rekey_user.php` ran while
     * a browser session was still open.
     */
    public static function refreshIndex(): array
    {
        $index = (new Datastore())->loadIndex(self::user(), self::password());

        if ($index === null) {
            self::logout();
            header('Location: login.html?error=stale');
            exit;
        }

        $_SESSION['index'] = $index;

        return $index;
    }
}
