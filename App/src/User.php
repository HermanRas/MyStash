<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/Crypto7z.php';
require_once __DIR__ . '/Datastore.php';

/**
 * Creating and validating users.
 *
 * A user is just a datastore directory plus an index archive encrypted with
 * their password — there is no account record anywhere, and no password is
 * ever stored (see Docs/SPECIFICATIONS.md §2.1).
 */
final class User
{
    /**
     * Letters and digits only. Beyond being the chosen naming rule, this is
     * what keeps a username safe to use as a path segment — no separators,
     * no dots, so it cannot traverse out of the data directory.
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9]+$/';

    private const MAX_NAME_LENGTH = 32;

    public function __construct(private Datastore $datastore = new Datastore())
    {
    }

    public static function isValidName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= self::MAX_NAME_LENGTH
            && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    public function exists(string $name): bool
    {
        return self::isValidName($name) && $this->datastore->userExists($name);
    }

    /**
     * Creates the user's datastore and their initial encrypted index.
     * Returns false if the name is invalid or already taken.
     */
    public function create(string $name, string $password): bool
    {
        if (!self::isValidName($name) || $this->exists($name) || $password === '') {
            return false;
        }

        return $this->datastore->saveIndex($name, $password, [
            // "Not Converted" is assigned automatically during ingestion, so a
            // new stash needs it defined up front for the tag to resolve.
            'categories' => ['Not Converted' => '#cc4444'],
            // Uploads default to the "default" creator, so it must exist.
            'creators' => [
                'default' => ['name' => 'default', 'age' => null, 'gender' => null, 'bio' => '', 'verified' => false],
            ],
            'videos' => [],
        ]);
    }
}
