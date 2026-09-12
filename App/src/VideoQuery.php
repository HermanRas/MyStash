<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/VideoCreators.php';

/**
 * The wall's filter + sort state, read from the query string and applied to
 * the index's video list (Docs/SPECIFICATIONS.md §2.8).
 *
 * Filtering and sorting happen on the server, against the already-decrypted
 * index, so a filtered wall renders exactly the tiles it should rather than
 * hiding rows in the browser — and so a filter can be linked to, which is how
 * clicking a category on the Categories screen opens the wall on that category.
 */
final class VideoQuery
{
    /**
     * Sort options, in menu order: value => label.
     */
    public const SORTS = [
        'uploaded_desc' => 'Uploaded: new → old',
        'uploaded_asc' => 'Uploaded: old → new',
        'title_asc' => 'Title: A → Z',
        'title_desc' => 'Title: Z → A',
        'length_asc' => 'Length: short → long',
        'length_desc' => 'Length: long → short',
        'views_desc' => 'Views: max → min',
        'views_asc' => 'Views: min → max',
    ];

    public const DEFAULT_SORT = 'uploaded_desc';

    /** Upper end of the length slider; at the maximum it means "no limit". */
    public const MAX_LENGTH_MINUTES = 180;

    /**
     * Longest search term accepted. A search runs over the already-decrypted
     * index, so this is not about cost — it just stops a pathological URL from
     * being echoed back into the input.
     */
    public const MAX_SEARCH_LENGTH = 100;

    /**
     * @param list<string> $categories
     * @param list<string> $creators
     */
    private function __construct(
        public readonly array $categories,
        public readonly array $creators,
        public readonly int $minMinutes,
        public readonly int $maxMinutes,
        public readonly string $sort,
        public readonly string $search,
    ) {
    }

    public static function fromRequest(array $query): self
    {
        $names = static fn(string $key): array => array_values(array_filter(
            array_map('strval', (array) ($query[$key] ?? [])),
            static fn(string $name) => $name !== '',
        ));

        $categories = $names('category');
        $creators = $names('creator');

        $min = max(0, (int) ($query['len_min'] ?? 0));
        $max = (int) ($query['len_max'] ?? self::MAX_LENGTH_MINUTES);
        $max = min(self::MAX_LENGTH_MINUTES, max($min, $max));

        $sort = (string) ($query['sort'] ?? self::DEFAULT_SORT);
        if (!isset(self::SORTS[$sort])) {
            $sort = self::DEFAULT_SORT;
        }

        // `q[]=x` would otherwise reach the search as the string "Array".
        $search = is_array($query['q'] ?? null) ? '' : (string) ($query['q'] ?? '');
        $search = mb_substr(trim(preg_replace('/\s+/u', ' ', $search) ?? ''), 0, self::MAX_SEARCH_LENGTH);

        return new self($categories, $creators, $min, $max, $sort, $search);
    }

    public function isFiltered(): bool
    {
        return $this->categories !== []
            || $this->creators !== []
            || $this->search !== ''
            || $this->minMinutes > 0
            || $this->maxMinutes < self::MAX_LENGTH_MINUTES;
    }

    /**
     * Search terms, in the order typed. Several words all have to match, each
     * possibly in a different field — so "jamie skate" finds a skating video by
     * Jamie without either word having to match title and creator at once.
     *
     * @return list<string>
     */
    public function searchTerms(): array
    {
        return $this->search === '' ? [] : explode(' ', $this->search);
    }

    /**
     * True when a video matches the search: every term appears somewhere in its
     * title, one of its creators, or one of its category tags
     * (Docs/PLAN.md 5.1). Matching is case-insensitive and on substrings, so
     * "skat" finds "Skateboarding".
     */
    public function matchesSearch(array $video): bool
    {
        $terms = $this->searchTerms();

        if ($terms === []) {
            return true;
        }

        $haystack = implode("\n", array_merge(
            [(string) ($video['title'] ?? '')],
            VideoCreators::of($video),
            array_map('strval', $video['categories'] ?? []),
        ));

        foreach ($terms as $term) {
            if (mb_stripos($haystack, $term) === false) {
                return false;
            }
        }

        return true;
    }

    public function hasCategory(string $name): bool
    {
        return in_array($name, $this->categories, true);
    }

    public function hasCreator(string $name): bool
    {
        return in_array($name, $this->creators, true);
    }

    /**
     * @param list<array> $videos index entries
     * @return list<array>
     */
    public function apply(array $videos): array
    {
        $videos = array_values(array_filter($videos, function (array $video): bool {
            if (!$this->matchesSearch($video)) {
                return false;
            }

            $minutes = ((int) ($video['length_seconds'] ?? 0)) / 60;

            if ($minutes < $this->minMinutes) {
                return false;
            }

            // The top of the slider is an open end, not a 180-minute ceiling.
            if ($this->maxMinutes < self::MAX_LENGTH_MINUTES && $minutes > $this->maxMinutes) {
                return false;
            }

            if ($this->creators !== []
                && array_intersect($this->creators, VideoCreators::of($video)) === []) {
                return false;
            }

            if ($this->categories === []) {
                return true;
            }

            return array_intersect($this->categories, $video['categories'] ?? []) !== [];
        }));

        usort($videos, $this->comparator());

        return $videos;
    }

    private function comparator(): callable
    {
        return match ($this->sort) {
            'title_asc' => static fn($a, $b) => strcasecmp($a['title'], $b['title']),
            'title_desc' => static fn($a, $b) => strcasecmp($b['title'], $a['title']),
            'length_asc' => static fn($a, $b) => $a['length_seconds'] <=> $b['length_seconds'],
            'length_desc' => static fn($a, $b) => $b['length_seconds'] <=> $a['length_seconds'],
            'views_asc' => static fn($a, $b) => $a['views'] <=> $b['views'],
            'views_desc' => static fn($a, $b) => $b['views'] <=> $a['views'],
            // Entries predating uploaded_at have no date to sort on; they sort
            // oldest either way rather than jumping to the top of the wall.
            'uploaded_asc' => static fn($a, $b) => ($a['uploaded_at'] ?? '') <=> ($b['uploaded_at'] ?? ''),
            default => static fn($a, $b) => ($b['uploaded_at'] ?? '') <=> ($a['uploaded_at'] ?? ''),
        };
    }
}
