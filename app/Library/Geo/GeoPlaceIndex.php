<?php

namespace App\Library\Geo;

use Illuminate\Support\Facades\Cache;

/**
 * Read-only index over the bundled GeoNames "cities5000" extract (populated
 * places of 5,000+ people, CC BY 4.0 — see resources/data/geo/ATTRIBUTION.md).
 * It ships with the application: no network call, no paid API, no per-request
 * cost. Each country's rows are parsed once and cached (cache store + process
 * memory), so a suggestion lookup is a scan of a few thousand in-memory rows.
 */
class GeoPlaceIndex
{
    public const DATASET = 'data/geo/cities5000.tsv.gz';

    private const CACHE_VERSION = 'v1';

    private const CACHE_TTL_SECONDS = 2_592_000; // 30 days: the data only changes with a deploy.

    /** @var array<string, array<int, array{0: int, 1: string, 2: string, 3: float, 4: float, 5: int, 6: string}>> */
    private array $memo = [];

    /** @var array<string, array<int, array<int, mixed>>>|null */
    private ?array $allByCountry = null;

    /**
     * All places of a country as [geonameId, name, admin1, lat, lon, population, foldedName].
     *
     * @return array<int, array{0: int, 1: string, 2: string, 3: float, 4: float, 5: int, 6: string}>
     */
    public function country(string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);

        if (isset($this->memo[$countryCode])) {
            return $this->memo[$countryCode];
        }

        return $this->memo[$countryCode] = Cache::remember(
            'geo.cities5000.' . self::CACHE_VERSION . '.' . $countryCode,
            self::CACHE_TTL_SECONDS,
            fn () => $this->load()[$countryCode] ?? [],
        );
    }

    public function has(string $countryCode): bool
    {
        return $this->country($countryCode) !== [];
    }

    /**
     * The most populous place with this name in the country (and the given
     * US state / Canadian province when one is supplied and recognisable).
     * Returns null rather than guessing: an unrecognised name, or a name that
     * exists only in a different state, is "not found".
     */
    public function find(string $name, ?string $region, string $countryCode): ?GeoPlace
    {
        $countryCode = strtoupper($countryCode);
        $needle = GeoText::fold($name);

        if ($needle === '' || $countryCode === '') {
            return null;
        }

        $admin1 = GeoRegions::regionsAreMatchable($countryCode) ? GeoRegions::toAdmin1($countryCode, $region) : null;

        // A region the owner typed but we cannot interpret must not silently widen the match to the whole country.
        if (GeoRegions::regionsAreMatchable($countryCode) && trim((string) $region) !== '' && $admin1 === null) {
            return null;
        }

        $best = null;

        foreach ($this->country($countryCode) as $row) {
            if ($row[6] !== $needle || ($admin1 !== null && $row[2] !== $admin1)) {
                continue;
            }

            if ($best === null || $row[5] > $best[5]) {
                $best = $row;
            }
        }

        return $best === null ? null : self::place($countryCode, $best);
    }

    /**
     * @param  array{0: int, 1: string, 2: string, 3: float, 4: float, 5: int, 6: string}  $row
     */
    public static function place(string $countryCode, array $row): GeoPlace
    {
        return new GeoPlace($row[0], $row[1], $countryCode, $row[2], $row[3], $row[4], $row[5]);
    }

    /**
     * @return array<string, array<int, array{0: int, 1: string, 2: string, 3: float, 4: float, 5: int, 6: string}>>
     */
    private function load(): array
    {
        if ($this->allByCountry !== null) {
            return $this->allByCountry;
        }

        $path = resource_path(self::DATASET);
        $handle = is_file($path) ? gzopen($path, 'rb') : false;

        if ($handle === false) {
            return $this->allByCountry = [];
        }

        $byCountry = [];

        while (($line = gzgets($handle)) !== false) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $f = explode("\t", rtrim($line, "\r\n"));
            if (count($f) < 7) {
                continue;
            }

            $byCountry[$f[2]][] = [(int) $f[0], $f[1], $f[3], (float) $f[4], (float) $f[5], (int) $f[6], GeoText::fold($f[1])];
        }

        gzclose($handle);

        return $this->allByCountry = $byCountry;
    }
}
