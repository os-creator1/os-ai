<?php

namespace App\Library\Geo;

/**
 * Suggests real communities near a confirmed base location, from grounded
 * geographic data only — never invented names and never a static list reused
 * for every business.
 *
 * How a suggestion is chosen:
 *  - the base place is resolved in the dataset by name + country (+ US state /
 *    Canadian province), by stable GeoNames id; an unresolvable base yields NO
 *    suggestions (the owner adds areas by hand) instead of a guess;
 *  - candidates are other places in the SAME country, ranked mainly by
 *    distance, nudged toward larger communities so a real city beats an
 *    obscure hamlet at the same range;
 *  - the search widens in steps (25 km → 140 km) only until enough
 *    meaningful places (20,000+ people, relaxing to 5,000+ in sparse regions)
 *    have been found;
 *  - neighbourhoods and districts of a larger place (Westpark inside Irvine,
 *    Annex inside Toronto — the extract does not label them) are skipped by
 *    a size/proximity test, so suggestions are communities a customer would
 *    actually name as a service area;
 *  - the base itself is always first, and anything already on the owner's
 *    list (by id or by name) is skipped, which is also how "suggest more"
 *    pages through further neighbours.
 */
class NearbyPlaceSuggester
{
    /** @var array<int, int> search radii, km */
    private const RADII = [25, 40, 60, 90, 140];

    private const MEANINGFUL_POPULATION = 20_000;

    private const MINIMUM_POPULATION = 5_000;

    public function __construct(private readonly GeoPlaceIndex $index)
    {
    }

    public function resolveBase(string $city, ?string $region, string $countryCode): ?GeoPlace
    {
        return $this->index->find($city, $region, $countryCode);
    }

    /**
     * @param  array<int, int>  $excludeGeonameIds  places already chosen
     * @param  array<int, string>  $excludeNames  free-text entries already on the list (matched by folded city name)
     * @return array<int, array{geoname_id: int, name: string, label: string, region: ?string, country_code: string, latitude: float, longitude: float, distance_km: float}>
     */
    public function suggest(GeoPlace $base, int $limit = 10, array $excludeGeonameIds = [], array $excludeNames = []): array
    {
        $excludedIds = array_fill_keys($excludeGeonameIds, true);
        $excludedNames = [];
        foreach ($excludeNames as $name) {
            // "Irvine, CA" and "Irvine" both exclude Irvine.
            $excludedNames[GeoText::fold(explode(',', $name)[0])] = true;
        }

        $isExcluded = fn (GeoPlace $p) => isset($excludedIds[$p->geonameId]) || isset($excludedNames[GeoText::fold($p->name)]);

        $picked = [];

        if (! $isExcluded($base)) {
            $picked[] = [$base, 0.0];
        }

        // The base itself counts as a "larger place" when judging districts, even if the owner already listed it.
        $universe = [[$base, 0.0]];

        $candidates = [];
        foreach ($this->index->country($base->countryCode) as $row) {
            if ($row[0] === $base->geonameId) {
                continue;
            }
            $place = GeoPlaceIndex::place($base->countryCode, $row);
            if ($isExcluded($place)) {
                continue;
            }
            $entry = [$place, $base->distanceKmTo($place)];
            $candidates[] = $entry;
            $universe[] = $entry;
        }

        foreach ([self::MEANINGFUL_POPULATION, self::MINIMUM_POPULATION] as $floor) {
            foreach (self::RADII as $radius) {
                $pool = array_values(array_filter($candidates, fn (array $c) => $c[1] <= $radius && $c[0]->population >= $floor));

                if (count($pool) + count($picked) < $limit) {
                    continue;
                }

                $chosen = $this->takeCommunities($pool, $universe, $limit - count($picked));

                if (count($picked) + count($chosen) >= $limit) {
                    return $this->present(array_merge($picked, $chosen));
                }
            }
        }

        // Sparse country or region: the real communities that do exist in range, however few.
        $pool = array_values(array_filter($candidates, fn (array $c) => $c[1] <= end(self::RADII) && $c[0]->population >= self::MINIMUM_POPULATION));

        return $this->present(array_merge($picked, $this->takeCommunities($pool, $universe, $limit - count($picked))));
    }

    /**
     * Best-ranked entries of `$pool` that are communities in their own right,
     * not districts of a bigger place. Checked lazily in rank order, so only
     * as many candidates are tested as are needed to fill `$wanted`.
     *
     * @param  array<int, array{0: GeoPlace, 1: float}>  $pool
     * @param  array<int, array{0: GeoPlace, 1: float}>  $all  every candidate in the country, as the "larger place" universe
     * @return array<int, array{0: GeoPlace, 1: float}>
     */
    private function takeCommunities(array $pool, array $all, int $wanted): array
    {
        usort($pool, fn (array $a, array $b) => $this->rank($a) <=> $this->rank($b) ?: $a[0]->geonameId <=> $b[0]->geonameId);

        $taken = [];
        foreach ($pool as $candidate) {
            if (count($taken) >= $wanted) {
                break;
            }
            if (! $this->isDistrictOfLargerPlace($candidate[0], $all)) {
                $taken[] = $candidate;
            }
        }

        return $taken;
    }

    /**
     * A place is treated as a district when a clearly larger place (at least
     * twice its population) is close enough to contain it, where "close
     * enough" grows with the larger place's size (~0.35 km per √1,000 people:
     * ≈ 5.6 km for Irvine, ≈ 18 km for Toronto).
     *
     * @param  array<int, array{0: GeoPlace, 1: float}>  $all
     */
    private function isDistrictOfLargerPlace(GeoPlace $place, array $all): bool
    {
        foreach ($all as [$other]) {
            if ($other->population < $place->population * 2 || abs($other->latitude - $place->latitude) > 0.5) {
                continue;
            }

            if ($place->distanceKmTo($other) <= 0.35 * sqrt($other->population / 1000)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{0: GeoPlace, 1: float}>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function present(array $entries): array
    {
        return array_map(fn (array $c) => $c[0]->toArray() + ['distance_km' => round($c[1], 1)], $entries);
    }

    /** Lower is better: distance, discounted (never more than ~45%) for larger places. */
    private function rank(array $candidate): float
    {
        [$place, $distance] = $candidate;
        $sizeBonus = min(3.0, max(0.0, log10(max($place->population, 1) / self::MINIMUM_POPULATION)));

        return $distance * (1 - 0.15 * $sizeBonus);
    }
}
