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
 * are US and Lithuanian cities (SUPPORTED), English.
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
        'LT' => ['City', 'Municipality'],
    ];
    public const LANGUAGE = 'en';

    /** @return list<string> */
    public static function supportedCountries(): array
    {
        return array_keys(self::SUPPORTED);
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
            ->orderBy('location_name')
            ->limit(max(1, min(25, $limit)))
            ->get();
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
