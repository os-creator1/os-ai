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
            // City name first, so "Biloxi" lists before "Biloxi Beach" (a bare space would otherwise sort before the comma).
            ->orderByRaw("SUBSTRING_INDEX(location_name, ',', 1)")
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

    /** US state / DC and Canadian province abbreviations (lowercase) => provider region name. UI hint only, never a lookup key. */
    private const REGION_ABBREVIATIONS = [
        'al' => 'Alabama', 'ak' => 'Alaska', 'az' => 'Arizona', 'ar' => 'Arkansas', 'ca' => 'California', 'co' => 'Colorado',
        'ct' => 'Connecticut', 'de' => 'Delaware', 'dc' => 'District of Columbia', 'fl' => 'Florida', 'ga' => 'Georgia',
        'hi' => 'Hawaii', 'id' => 'Idaho', 'il' => 'Illinois', 'in' => 'Indiana', 'ia' => 'Iowa', 'ks' => 'Kansas',
        'ky' => 'Kentucky', 'la' => 'Louisiana', 'me' => 'Maine', 'md' => 'Maryland', 'ma' => 'Massachusetts',
        'mi' => 'Michigan', 'mn' => 'Minnesota', 'ms' => 'Mississippi', 'mo' => 'Missouri', 'mt' => 'Montana',
        'ne' => 'Nebraska', 'nv' => 'Nevada', 'nh' => 'New Hampshire', 'nj' => 'New Jersey', 'nm' => 'New Mexico',
        'ny' => 'New York', 'nc' => 'North Carolina', 'nd' => 'North Dakota', 'oh' => 'Ohio', 'ok' => 'Oklahoma',
        'or' => 'Oregon', 'pa' => 'Pennsylvania', 'ri' => 'Rhode Island', 'sc' => 'South Carolina', 'sd' => 'South Dakota',
        'tn' => 'Tennessee', 'tx' => 'Texas', 'ut' => 'Utah', 'vt' => 'Vermont', 'va' => 'Virginia', 'wa' => 'Washington',
        'wv' => 'West Virginia', 'wi' => 'Wisconsin', 'wy' => 'Wyoming',
        'ab' => 'Alberta', 'bc' => 'British Columbia', 'mb' => 'Manitoba', 'nb' => 'New Brunswick',
        'nl' => 'Newfoundland and Labrador', 'ns' => 'Nova Scotia', 'on' => 'Ontario', 'pe' => 'Prince Edward Island',
        'qc' => 'Quebec', 'sk' => 'Saskatchewan',
    ];

    /**
     * A supported cached city named at the end of a keyword phrase ("photo booth rental biloxi ms" -> Biloxi,
     * Mississippi), or null. Local lookup only. A trailing state/province abbreviation narrows the match; without
     * one a city name shared by several cached rows is ambiguous and yields null rather than a guess.
     */
    public function suggestFromPhrase(string $phrase): ?SeoRankLocation
    {
        $words = preg_split('/[^\p{L}\p{N}\'.-]+/u', mb_strtolower(trim($phrase)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $region = null;

        if (count($words) > 1 && isset(self::REGION_ABBREVIATIONS[end($words)])) {
            $region = self::REGION_ABBREVIATIONS[array_pop($words)];
        }

        for ($n = min(3, count($words)); $n >= 1; $n--) {
            $city = implode(' ', array_slice($words, -$n));
            $matches = $this->supported(SeoRankLocation::query())
                ->where('location_name', 'like', addcslashes($city, '\\%_') . ',' . ($region !== null ? addcslashes($region, '\\%_') . ',' : '') . '%')
                ->orderBy('location_name')
                ->limit(2)
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }

            if ($matches->count() > 1) {
                return null;
            }
        }

        return null;
    }

    /** "Biloxi,Mississippi,United States" -> "Biloxi, MS" (the provider region abbreviated where it is a US state or Canadian province). */
    public static function shortLabel(string $locationName): string
    {
        $parts = array_map('trim', explode(',', $locationName));
        $abbr = array_search($parts[1] ?? '', self::REGION_ABBREVIATIONS, true);

        return $abbr !== false ? $parts[0] . ', ' . strtoupper((string) $abbr) : self::label($locationName);
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
