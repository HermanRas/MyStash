<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/VideoCreators.php';

/**
 * The Creators screen's search + sort state (Docs/PLAN.md 5.8, 5.9), read from
 * the query string and applied to the index's creator list.
 *
 * The same shape as VideoQuery, and for the same reasons: it runs on the server
 * against the already-decrypted index (never opening a creator's own archive),
 * and it lives in the URL so a search or a sort order can be linked to and
 * survives a round trip through the page.
 *
 * The header search box is deliberately NOT this — that one searches *videos*
 * (by title, creator or category) and always lands on the wall. This is the
 * Creators screen's own box, for finding a person rather than their videos.
 */
final class CreatorQuery
{
    /**
     * Sort options, in menu order: value => label.
     */
    public const SORTS = [
        'name_asc' => 'Name: A → Z',
        'name_desc' => 'Name: Z → A',
        'videos_desc' => 'Videos: max → min',
        'videos_asc' => 'Videos: min → max',
        'age_asc' => 'Age: young → old',
        'age_desc' => 'Age: old → young',
    ];

    public const DEFAULT_SORT = 'name_asc';

    /** Matches VideoQuery, so both boxes behave the same way. */
    public const MAX_SEARCH_LENGTH = 100;

    private function __construct(
        public readonly string $search,
        public readonly string $sort,
    ) {
    }

    public static function fromRequest(array $query): self
    {
        // `q[]=x` would otherwise reach the search as the string "Array".
        $search = is_array($query['q'] ?? null) ? '' : (string) ($query['q'] ?? '');
        $search = mb_substr(trim(preg_replace('/\s+/u', ' ', $search) ?? ''), 0, self::MAX_SEARCH_LENGTH);

        $sort = (string) ($query['sort'] ?? self::DEFAULT_SORT);
        if (!isset(self::SORTS[$sort])) {
            $sort = self::DEFAULT_SORT;
        }

        return new self($search, $sort);
    }

    public function isFiltered(): bool
    {
        return $this->search !== '';
    }

    /**
     * @return list<string>
     */
    public function searchTerms(): array
    {
        return $this->search === '' ? [] : explode(' ', $this->search);
    }

    /**
     * True when a creator matches the search: every term appears somewhere in
     * their name, bio or gender. Case-insensitive and on substrings, the same
     * rule the video search uses.
     */
    public function matches(string $name, array $creator): bool
    {
        $terms = $this->searchTerms();

        if ($terms === []) {
            return true;
        }

        $haystack = implode("\n", [
            $name,
            (string) ($creator['bio'] ?? ''),
            (string) ($creator['gender'] ?? ''),
        ]);

        foreach ($terms as $term) {
            if (mb_stripos($haystack, $term) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filters and sorts the index's creator map, keeping it keyed by name
     * (which is what the grid renders from).
     *
     * @param array<string, array> $creators name => summary
     * @param list<array> $videos index entries, for the video-count sort
     * @return array<string, array>
     */
    public function apply(array $creators, array $videos): array
    {
        $creators = array_filter(
            $creators,
            fn(array $creator, string $name) => $this->matches($name, $creator),
            ARRAY_FILTER_USE_BOTH,
        );

        $counts = [];
        foreach (array_keys($creators) as $name) {
            $counts[$name] = VideoCreators::videoCount(['videos' => $videos], $name);
        }

        uksort($creators, function (string $a, string $b) use ($creators, $counts): int {
            // A creator with no age set has nothing to sort on, so they sort
            // last either way rather than counting as age zero.
            $age = static function (string $name) use ($creators): ?int {
                $value = $creators[$name]['age'] ?? null;

                return ($value === null || $value === '') ? null : (int) $value;
            };

            return match ($this->sort) {
                'name_desc' => strcasecmp($b, $a),
                'videos_asc' => $counts[$a] <=> $counts[$b] ?: strcasecmp($a, $b),
                'videos_desc' => $counts[$b] <=> $counts[$a] ?: strcasecmp($a, $b),
                'age_asc', 'age_desc' => self::compareAges(
                    $age($a),
                    $age($b),
                    $this->sort === 'age_desc',
                ) ?: strcasecmp($a, $b),
                default => strcasecmp($a, $b),
            };
        });

        return $creators;
    }

    /**
     * Orders two possibly-missing ages, keeping the unknown ones at the end
     * whichever direction is asked for.
     */
    private static function compareAges(?int $a, ?int $b, bool $descending): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 1 : 0) <=> ($b === null ? 1 : 0);
        }

        return $descending ? $b <=> $a : $a <=> $b;
    }
}
