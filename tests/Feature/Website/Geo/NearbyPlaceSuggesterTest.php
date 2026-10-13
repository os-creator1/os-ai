<?php

namespace Tests\Feature\Website\Geo;

use App\Library\Geo\GeoPlaceIndex;
use App\Library\Geo\GeoRegions;
use App\Library\Geo\NearbyPlaceSuggester;
use Tests\TestCase;

/**
 * The service-area suggestions are grounded in the bundled GeoNames extract —
 * real communities near a confirmed base, never an invented or static list.
 * No database: the dataset ships with the application.
 */
class NearbyPlaceSuggesterTest extends TestCase
{
    private function suggester(): NearbyPlaceSuggester
    {
        return app(NearbyPlaceSuggester::class);
    }

    /** @return array<int, string> */
    private function names(array $suggestions): array
    {
        return array_column($suggestions, 'name');
    }

    public function test_an_orange_county_base_gets_ten_real_nearby_communities_base_first(): void
    {
        $base = $this->suggester()->resolveBase('Irvine', 'California', 'US');
        $this->assertNotNull($base);
        $this->assertSame('Irvine, CA', $base->label());

        $suggestions = $this->suggester()->suggest($base, 10);
        $names = $this->names($suggestions);

        $this->assertCount(10, $suggestions);
        $this->assertSame('Irvine', $names[0], 'The business\'s own city is always first.');
        $this->assertSame(10, count(array_unique(array_column($suggestions, 'geoname_id'))), 'No duplicates.');

        foreach (['Santa Ana', 'Costa Mesa', 'Newport Beach'] as $expected) {
            $this->assertContains($expected, $names, "{$expected} is a real Orange County neighbour of Irvine.");
        }

        foreach ($suggestions as $suggestion) {
            $this->assertSame('US', $suggestion['country_code']);
            $this->assertSame('CA', $suggestion['region'], 'Never a wrong-state or wrong-country match.');
            $this->assertLessThanOrEqual(140, $suggestion['distance_km'], 'Nearby, not statewide.');
        }
    }

    public function test_districts_of_a_larger_place_are_not_suggested_as_service_areas(): void
    {
        $irvine = $this->names($this->suggester()->suggest($this->suggester()->resolveBase('Irvine', 'CA', 'US'), 10));
        $this->assertNotContains('Westpark', $irvine);
        $this->assertNotContains('Woodbridge', $irvine);

        $toronto = $this->names($this->suggester()->suggest($this->suggester()->resolveBase('Toronto', 'Ontario', 'CA'), 10));
        $this->assertNotContains('Annex', $toronto);
        $this->assertNotContains('Mount Pleasant West', $toronto);
        $this->assertContains('Mississauga', $toronto);
    }

    public function test_the_suggestions_depend_on_the_base_location_not_a_fixed_list(): void
    {
        $irvine = $this->names($this->suggester()->suggest($this->suggester()->resolveBase('Irvine', 'CA', 'US'), 10));
        $naperville = $this->names($this->suggester()->suggest($this->suggester()->resolveBase('Naperville', 'IL', 'US'), 10));

        $this->assertSame([], array_values(array_intersect($irvine, $naperville)));
        $this->assertContains('Aurora', $naperville);
        $this->assertContains('Wheaton', $naperville);
    }

    public function test_a_shared_city_name_resolves_by_state_and_never_to_the_wrong_one(): void
    {
        $missouri = $this->suggester()->resolveBase('Springfield', 'Missouri', 'US');
        $illinois = $this->suggester()->resolveBase('Springfield', 'IL', 'US');

        $this->assertNotNull($missouri);
        $this->assertNotNull($illinois);
        $this->assertNotSame($missouri->geonameId, $illinois->geonameId);
        $this->assertSame('MO', $missouri->regionLabel());
        $this->assertSame('IL', $illinois->regionLabel());

        $this->assertNull($this->suggester()->resolveBase('Irvine', 'Texas', 'US'), 'Irvine, TX is not a real place: not found, not a guess.');
        $this->assertNull($this->suggester()->resolveBase('Irvine', 'Atlantis', 'US'), 'An unrecognised state never widens the match to the whole country.');
    }

    public function test_names_match_regardless_of_case_accents_or_punctuation(): void
    {
        $this->assertSame(
            $this->suggester()->resolveBase('St. Louis', 'MO', 'US')?->geonameId,
            $this->suggester()->resolveBase('st louis', 'missouri', 'us')?->geonameId,
        );
        $this->assertNotNull($this->suggester()->resolveBase('ZURICH', null, 'CH'));
    }

    public function test_an_unknown_place_yields_no_suggestions_rather_than_a_guess(): void
    {
        $this->assertNull($this->suggester()->resolveBase('Nowheresville', 'Kansas', 'US'));
        $this->assertNull($this->suggester()->resolveBase('Irvine', 'California', 'ZZ'));
    }

    public function test_labels_carry_enough_identity_to_avoid_wrong_country_matches(): void
    {
        $bristol = $this->suggester()->resolveBase('Bristol', null, 'GB');
        $this->assertNotNull($bristol);
        $this->assertSame('Bristol, United Kingdom', $bristol->label());
        $this->assertNull(GeoRegions::toAdmin1('GB', 'England'), 'Regions are only interpreted for US states and Canadian provinces.');

        $toronto = $this->suggester()->resolveBase('Toronto', 'ON', 'CA');
        $this->assertSame('Toronto, ON', $toronto->label());
    }

    public function test_already_listed_places_are_skipped_so_suggest_more_pages_through_new_neighbours(): void
    {
        $base = $this->suggester()->resolveBase('Irvine', 'CA', 'US');
        $first = $this->suggester()->suggest($base, 10);

        $second = $this->suggester()->suggest($base, 10, array_column($first, 'geoname_id'));

        $this->assertCount(10, $second);
        $this->assertSame([], array_values(array_intersect(array_column($first, 'geoname_id'), array_column($second, 'geoname_id'))));

        // Free-text entries are skipped too: "Costa Mesa, CA" or just "costa mesa".
        $third = $this->suggester()->suggest($base, 10, [], ['costa mesa', 'Santa Ana, CA']);
        $this->assertNotContains('Costa Mesa', $this->names($third));
        $this->assertNotContains('Santa Ana', $this->names($third));
    }

    public function test_the_dataset_is_the_attributed_geonames_extract(): void
    {
        $this->assertFileExists(resource_path('data/geo/ATTRIBUTION.md'));
        $this->assertStringContainsString('Creative Commons Attribution 4.0', file_get_contents(resource_path('data/geo/ATTRIBUTION.md')));
        $this->assertTrue(app(GeoPlaceIndex::class)->has('US'));
        $this->assertGreaterThan(5000, count(app(GeoPlaceIndex::class)->country('US')));
    }
}
