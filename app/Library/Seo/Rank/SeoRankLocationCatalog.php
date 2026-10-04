<?php

namespace App\Library\Seo\Rank;

use App\Library\Seo\Rank\Provider\SeoRankProvider;
use App\Models\SeoRankLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Local cache of the provider's FREE location catalogue (seo_rank_locations) and
 * the only way a search geography becomes a paid request: a typed string is
 * never sent anywhere, it must resolve to a cached row. V1 supports United
 * States cities only (Contract: geography policy), English.
 *
 * search()/find() are pure local queries. sync() is the one provider call (a
 * free endpoint) and is run by the scheduled command, never by a web request.
 */
class SeoRankLocationCatalog
{
    public const SUPPORTED_COUNTRY = 'US';
    public const SUPPORTED_TYPE = 'City';
    public const LANGUAGE = 'en';

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

        return SeoRankLocation::query()
            ->where('provider', $this->provider->key())
            ->where('country_iso', self::SUPPORTED_COUNTRY)
            ->where('location_type', self::SUPPORTED_TYPE)
            ->where('location_name', 'like', $escaped . '%')
            ->orderBy('location_name')
            ->limit(max(1, min(25, $limit)))
            ->get();
    }

    /** A supported, cached location by provider code, or null. */
    public function find(int $locationCode): ?SeoRankLocation
    {
        return SeoRankLocation::query()
            ->where('provider', $this->provider->key())
            ->where('country_iso', self::SUPPORTED_COUNTRY)
            ->where('location_type', self::SUPPORTED_TYPE)
            ->where('location_code', $locationCode)
            ->first();
    }

    public function isPopulated(): bool
    {
        return SeoRankLocation::query()->where('provider', $this->provider->key())->exists();
    }

    /** "Chicago,Illinois,United States" -> "Chicago, Illinois, United States". */
    public static function label(string $locationName): string
    {
        return implode(', ', array_map('trim', explode(',', $locationName)));
    }

    /**
     * Upsert the supported subset of the free catalogue. Returns rows written.
     *
     * @throws \App\Library\Seo\Rank\Provider\SeoRankProviderException
     */
    public function sync(): int
    {
        $rows = [];
        $now = now();

        foreach ($this->provider->locations(self::SUPPORTED_COUNTRY) as $location) {
            // Trust the row's own country metadata, never the endpoint we asked:
            // a missing or non-US country is skipped, not stamped as US.
            if ($location['type'] !== self::SUPPORTED_TYPE
                || strtoupper((string) ($location['country_iso'] ?? '')) !== self::SUPPORTED_COUNTRY) {
                continue;
            }

            $rows[] = [
                'provider' => $this->provider->key(),
                'location_code' => $location['code'],
                'location_name' => $location['name'],
                'parent_code' => $location['parent_code'],
                'country_iso' => self::SUPPORTED_COUNTRY,
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
