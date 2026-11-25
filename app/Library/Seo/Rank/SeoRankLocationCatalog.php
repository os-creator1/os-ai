<?php

namespace App\Library\Seo\Rank;

use App\Library\Seo\Rank\Provider\SeoRankProvider;
use App\Models\SeoRankLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Local cache of the provider's FREE location catalogue (seo_rank_locations) and
 * the only way a search geography becomes a paid request: a typed string is
 * never sent anywhere, it must resolve to a cached row. Supported geographies
 * are US, Canadian and Lithuanian cities (SUPPORTED), English.
 *
 * search()/find() are pure local queries. sync() is the one provider call (a
 * free endpoint) and is run by the scheduled command, never by a web request.
 */
class SeoRankLocationCatalog
{
    /**
     * Supported country (ISO alpha-2) => provider location types accepted as a
     * "city". Lithuania also accepts Municipality because the provider models
     * some Lithuanian towns (e.g. Vilnius) at that level. Codes are never
     * listed here: they always come from the provider's own free catalogue.
     *
     * @var array<string, list<string>>
     */
    public const SUPPORTED = [
        'US' => ['City'],
        // Canada (e.g. Toronto, Ontario; Mississauga; Vaughan) is a City in the provider's own catalogue.
        'CA' => ['City'],
        'LT' => ['City', 'Municipality'],
    ];
    public const LANGUAGE = 'en';

    /** The compact form used in the dialog hint: "US, Canada and Lithuania". */
    public static function supportedCountriesShortLabel(): string
    {
        $names = array_map(fn (string $c) => $c === 'US' ? 'US' : (self::COUNTRY_NAMES[$c] ?? $c), self::supportedCountries());
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' and ' . $last;
    }

    /** @return list<string> */
    public static function supportedCountries(): array
    {
        return array_keys(self::SUPPORTED);
    }

    /** Display names for the supported countries, for user-facing wording only (never for lookups). */
    public const COUNTRY_NAMES = ['US' => 'United States', 'CA' => 'Canada', 'LT' => 'Lithuania'];

    /** "United States, Canada or Lithuania" - derived from SUPPORTED so UI copy cannot drift from it. */
    public static function supportedCountriesLabel(): string
    {
        $names = array_map(fn (string $c) => self::COUNTRY_NAMES[$c] ?? $c, self::supportedCountries());
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' or ' . $last;
    }

    /** @return list<string> */
    public static function supportedTypes(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::SUPPORTED))));
    }

    /** Restrict a query to supported (country, type) pairs. */
    private function supported(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('provider', $this->provider->key())->where(function ($q) {
            foreach (self::SUPPORTED as $country => $types) {
                $q->orWhere(fn ($w) => $w->where('country_iso', $country)->whereIn('location_type', $types));
            }
        });
    }

    public function __construct(private readonly SeoRankProvider $provider)
    {
    }

    /** @return Collection<int, SeoRankLocation> */
    public function search(string $query, int $limit = 10): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return collect();
        }

        $escaped = addcslashes($query, '\\%_');

        return $this->supported(SeoRankLocation::query())
            ->where('location_name', 'like', $escaped . '%')
            // City name first ("Biloxi" before "Biloxi Beach": a space sorts before the comma that follows a bare city name).
            ->orderByRaw("SUBSTRING_INDEX(location_name, ',', 1)")
            ->orderBy('location_name')
            ->limit(max(1, min(25, $limit)))
            ->get();
    }

    /**
     * The one cached city a keyword phrase names, or null. "photo booth rental biloxi" -> Biloxi, Mississippi.
     * Tries the phrase's trailing 1-3 words (longest first), also without a trailing two-letter state/province
     * token ("biloxi ms"), and answers only when exactly one supported city has that exact name: an ambiguous
     * name ("springfield") yields no hint rather than a guess.
     */
    public function suggestFromPhrase(string $phrase): ?SeoRankLocation
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}\'\-]+/u', mb_strtolower($phrase)) ?: []));
        $candidates = [];

        for ($n = min(3, count($words)); $n >= 1; $n--) {
            $tail = array_slice($words, -$n);
            $candidates[] = implode(' ', $tail);

            if ($n > 1 && mb_strlen(end($tail)) === 2) {
                $candidates[] = implode(' ', array_slice($tail, 0, -1));
            }
        }

        foreach (array_unique($candidates) as $name) {
            if (mb_strlen($name) < 3) {
                continue;
            }

            $matches = $this->supported(SeoRankLocation::query())
                ->where('location_name', 'like', addcslashes($name, '\\%_') . ',%')
                ->limit(2)
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    /** A supported, cached location by provider code, or null. */
    public function find(int $locationCode): ?SeoRankLocation
    {
        return $this->supported(SeoRankLocation::query())
            ->where('location_code', $locationCode)
            ->first();
    }

    /** True when at least one supported city is cached. */
    public function isPopulated(): bool
    {
        return $this->supported(SeoRankLocation::query())->exists();
    }

    /**
     * Supported countries with no cached city yet (local query only).
     *
     * @return list<string>
     */
    public function missingCountries(): array
    {
        $present = $this->supported(SeoRankLocation::query())->distinct()->pluck('country_iso')->map(fn ($c) => strtoupper((string) $c))->all();

        return array_values(array_diff(self::supportedCountries(), $present));
    }

    /** "Chicago,Illinois,United States" -> "Chicago, Illinois, United States". */
    public static function label(string $locationName): string
    {
        return implode(', ', array_map('trim', explode(',', $locationName)));
    }

    /**
     * Upsert the supported subset of the free catalogue for every supported
     * country. Returns rows written. A country that fails does not stop the
     * others; the first failure is rethrown once all have been attempted.
     *
     * @throws \App\Library\Seo\Rank\Provider\SeoRankProviderException
     */
    public function sync(): int
    {
        $total = 0;
        $failure = null;

        foreach (self::supportedCountries() as $country) {
            try {
                $total += $this->syncCountry($country);
            } catch (\App\Library\Seo\Rank\Provider\SeoRankProviderException $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $total;
    }

    /**
     * One free provider call for one supported country.
     *
     * @throws \App\Library\Seo\Rank\Provider\SeoRankProviderException
     */
    public function syncCountry(string $country): int
    {
        $country = strtoupper($country);
        $types = self::SUPPORTED[$country] ?? null;

        if ($types === null) {
            throw new \InvalidArgumentException('Unsupported rank location country.');
        }

        $rows = [];
        $now = now();

        foreach ($this->provider->locations($country) as $location) {
            // Trust the row's own country metadata, never the endpoint we asked:
            // a missing or other-country row is skipped, not stamped.
            if (! in_array($location['type'], $types, true)
                || strtoupper((string) ($location['country_iso'] ?? '')) !== $country) {
                continue;
            }

            $rows[] = [
                'provider' => $this->provider->key(),
                'location_code' => $location['code'],
                'location_name' => $location['name'],
                'parent_code' => $location['parent_code'],
                'country_iso' => $country,
                'location_type' => $location['type'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                SeoRankLocation::query()->upsert(
                    $chunk,
                    ['provider', 'location_code'],
                    ['location_name', 'parent_code', 'country_iso', 'location_type', 'updated_at'],
                );
            }
        });

        return count($rows);
    }
}
