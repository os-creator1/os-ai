<?php

namespace App\Library\Seo;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Support\Collection;

/**
 * SEO V1 final — turns the Business's Niche Blueprint SEO strategy
 * (BlueprintConfigReader::seoStrategy(), the SEO module's one read seam) into a
 * short list of SUGGESTED keywords the owner may add.
 *
 * It SUGGESTS and nothing else. It never creates, changes or archives a
 * keyword, never calls a provider or AI, and never writes: adding a suggestion
 * is an ordinary POST to the keyword create endpoint, which keeps every
 * tenancy, permission, Location-access and ceiling rule SeoKeywordManager
 * already enforces. It is also separate from rank tracking: a suggestion is a
 * free SEO keyword, never a paid rank-tracked slot.
 *
 * HONEST BY CONSTRUCTION — NO SPAM PERMUTATIONS.
 *   - A pattern's `{city}` is filled ONLY with a city the actor's own active
 *     Locations really carry (the Location's city and its service-area cities),
 *     and `{service}` ONLY with the Business's own active service names. A
 *     pattern with any other placeholder, or whose placeholder has nothing real
 *     to fill it, is skipped — never filled with a guess.
 *   - A pattern with no placeholder is suggested as written.
 *   - At most MAX_FILLS_PER_PATTERN fills per pattern and MAX_SUGGESTIONS in
 *     total, taken round-robin across patterns so one pattern cannot crowd out
 *     the rest.
 *   - Anything the Business already has — active OR archived, in any scope —
 *     is dropped, compared by SeoPhraseNormalizer (the one normalization).
 *   - Phrases carrying a search operator, or too long, are dropped.
 *   - Nothing is suggested once the active-keyword ceiling is reached.
 */
final class SeoKeywordSuggester
{
    public const MAX_SUGGESTIONS = 10;

    public const MAX_FILLS_PER_PATTERN = 3;

    /** Longest city or service name used to fill a pattern. */
    private const MAX_FILL_LENGTH = 60;

    private const PLACEHOLDERS = ['city', 'service'];

    public function __construct(
        private readonly BlueprintConfigReader $blueprint,
        private readonly SeoConfig $config,
    ) {
    }

    /**
     * @param  Collection<int, BusinessLocation>  $activeLocations  the ACTOR's accessible, active Locations (already filtered)
     * @param  iterable<\App\Models\SeoKeyword>  $existing  every keyword the actor can see, active and archived
     * @return array<int, SeoKeywordSuggestion>
     */
    public function suggest(Business $business, Collection $activeLocations, iterable $existing, int $activeKeywordCount): array
    {
        $room = $this->config->keywordsMaxActivePerBusiness() - $activeKeywordCount;

        if ($room <= 0) {
            return [];
        }

        $patterns = $this->patterns($this->blueprint->seoStrategy($business));

        if ($patterns === []) {
            return [];
        }

        $taken = [];

        foreach ($existing as $keyword) {
            $taken[SeoPhraseNormalizer::normalize((string) $keyword->phrase)] = true;
        }

        $cities = $this->cities($activeLocations);
        $services = $this->services($business);

        $lists = [];

        foreach ($patterns as $pattern) {
            $candidates = $this->candidates($pattern['pattern'], $pattern['intent'], $cities, $services);

            if ($candidates !== []) {
                $lists[] = $candidates;
            }
        }

        $limit = min(self::MAX_SUGGESTIONS, $room);
        $out = [];

        // Round-robin: the first fill of every pattern, then the second, ...
        for ($round = 0; $round < self::MAX_FILLS_PER_PATTERN && count($out) < $limit; $round++) {
            foreach ($lists as $candidates) {
                if (! isset($candidates[$round])) {
                    continue;
                }

                $suggestion = $candidates[$round];
                $key = SeoPhraseNormalizer::normalize($suggestion->phrase);

                if ($key === '' || isset($taken[$key])) {
                    continue;
                }

                $taken[$key] = true;
                $out[] = $suggestion;

                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $strategy
     * @return array<int, array{pattern: string, intent: string}>
     */
    private function patterns(?array $strategy): array
    {
        $out = [];

        foreach ((is_array($strategy['keyword_patterns'] ?? null) ? $strategy['keyword_patterns'] : []) as $row) {
            if (! is_array($row) || ! is_string($row['pattern'] ?? null) || trim($row['pattern']) === '') {
                continue;
            }

            $out[] = ['pattern' => trim($row['pattern']), 'intent' => (string) ($row['intent'] ?? '')];
        }

        return $out;
    }

    /**
     * Real cities from the actor's active Locations, each tied to the first
     * Location that carries it: the Location's own city, then its service-area
     * cities, in Location order.
     *
     * @param  Collection<int, BusinessLocation>  $activeLocations
     * @return array<int, array{name: string, location_uid: string, location_name: string}>
     */
    private function cities(Collection $activeLocations): array
    {
        $seen = [];
        $cities = [];

        foreach ($activeLocations as $location) {
            $names = array_merge(
                [$location->city],
                is_array($location->service_area_cities) ? $location->service_area_cities : [],
            );

            foreach ($names as $name) {
                $clean = $this->cleanFill($name);

                if ($clean === null || isset($seen[mb_strtolower($clean)])) {
                    continue;
                }

                $seen[mb_strtolower($clean)] = true;
                $cities[] = ['name' => $clean, 'location_uid' => (string) $location->uid, 'location_name' => (string) $location->name];
            }
        }

        return $cities;
    }

    /**
     * The Business's own active service names, in their saved order.
     *
     * @return array<int, string>
     */
    private function services(Business $business): array
    {
        $names = [];

        foreach ($business->services()->where('status', BusinessServiceStatus::Active->value)->orderBy('sort_order')->orderBy('id')->pluck('name') as $name) {
            $clean = $this->cleanFill($name);

            if ($clean !== null && ! in_array($clean, $names, true)) {
                $names[] = $clean;
            }
        }

        return $names;
    }

    /**
     * Every honest fill of one pattern (at most MAX_FILLS_PER_PATTERN), or none
     * when it carries a placeholder this class does not know or cannot fill
     * from real data.
     *
     * @param  array<int, array{name: string, location_uid: string, location_name: string}>  $cities
     * @param  array<int, string>  $services
     * @return array<int, SeoKeywordSuggestion>
     */
    private function candidates(string $pattern, string $intent, array $cities, array $services): array
    {
        preg_match_all('/\{([^{}]*)\}/', $pattern, $found);
        $placeholders = array_values(array_unique($found[1]));

        foreach ($placeholders as $placeholder) {
            if (! in_array($placeholder, self::PLACEHOLDERS, true)) {
                return [];
            }
        }

        $wantsCity = in_array('city', $placeholders, true);
        $wantsService = in_array('service', $placeholders, true);

        if ($wantsCity && $cities === []) {
            return [];
        }

        if ($wantsService && $services === []) {
            return [];
        }

        $fills = [];

        if (! $wantsCity && ! $wantsService) {
            $fills[] = [null, null];
        } elseif ($wantsCity && ! $wantsService) {
            foreach ($cities as $city) {
                $fills[] = [null, $city];
            }
        } elseif ($wantsService && ! $wantsCity) {
            foreach ($services as $service) {
                $fills[] = [$service, null];
            }
        } else {
            foreach ($services as $service) {
                foreach ($cities as $city) {
                    $fills[] = [$service, $city];
                }
            }
        }

        $out = [];

        foreach ($fills as [$service, $city]) {
            $phrase = trim((string) preg_replace('/\s+/u', ' ', str_replace(
                ['{city}', '{service}'],
                [$city['name'] ?? '', $service ?? ''],
                $pattern,
            )));

            if ($phrase === ''
                || mb_strlen($phrase) > SeoKeywordManager::MAX_PHRASE_LENGTH
                || preg_match('/[\p{Cc}\p{Cf}]/u', $phrase) === 1
                || SeoPhraseNormalizer::hasSearchOperator($phrase)) {
                continue;
            }

            $out[] = new SeoKeywordSuggestion($phrase, $intent, $city['location_uid'] ?? null, $city['location_name'] ?? null);

            if (count($out) >= self::MAX_FILLS_PER_PATTERN) {
                break;
            }
        }

        return $out;
    }

    /** A value fit to put inside a keyword: plain, single-line, bounded; or null. */
    private function cleanFill(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($clean === ''
            || mb_strlen($clean) > self::MAX_FILL_LENGTH
            || preg_match('/[\p{Cc}\p{Cf}{}]/u', $clean) === 1
            || SeoPhraseNormalizer::hasSearchOperator($clean)) {
            return null;
        }

        return $clean;
    }
}
