<?php

namespace Tests\Feature\Seo\Rank;

use App\Library\Seo\Rank\Provider\FakeSeoRankProvider;
use App\Library\Seo\Rank\SeoRankException;
use App\Library\Seo\Rank\SeoRankLocationCatalog;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankLocation;
use App\Models\SeoRankObservation;
use App\Models\SeoRankProviderLedger;
use App\Models\SeoRankTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\TestCase;

/**
 * The cached provider location catalogue: a typed string can only become a
 * paid request by resolving to a supported (US, City) cached row.
 */
class SeoRankLocationCatalogTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    private function catalog(): SeoRankLocationCatalog
    {
        return app(SeoRankLocationCatalog::class);
    }

    private function seedRow(int $code, string $name, string $country = 'US', string $type = 'City', string $provider = 'dataforseo'): SeoRankLocation
    {
        $location = new SeoRankLocation();
        $location->forceFill([
            'provider' => $provider,
            'location_code' => $code,
            'location_name' => $name,
            'country_iso' => $country,
            'location_type' => $type,
        ])->save();

        return $location;
    }

    /** @return list<string> */
    private function names(string $query, int $limit = 10): array
    {
        return $this->catalog()->search($query, $limit)->pluck('location_name')->all();
    }

    // ---------------------------------------------------------------
    // search
    // ---------------------------------------------------------------

    public function test_search_matches_by_prefix_and_is_case_insensitive(): void
    {
        $this->assertSame(['Chicago,Illinois,United States'], $this->names('Chic'));
        $this->assertSame(['Chicago,Illinois,United States'], $this->names('chicago'));
        $this->assertSame(['Naperville,Illinois,United States'], $this->names('Naper'));
        $this->assertSame([], $this->names('ville'), 'Only a prefix matches, never a substring.');
        $this->assertSame([], $this->names('Illinois'));
    }

    public function test_search_needs_at_least_two_characters_after_trimming(): void
    {
        $this->assertCount(0, $this->catalog()->search(''));
        $this->assertCount(0, $this->catalog()->search('c'));
        $this->assertCount(0, $this->catalog()->search('   c   '));
        $this->assertCount(0, $this->catalog()->search('   '));
        $this->assertCount(1, $this->catalog()->search('ch'));
        $this->assertCount(1, $this->catalog()->search('  ch  '));
    }

    public function test_search_returns_only_supported_us_city_rows_of_this_provider(): void
    {
        $this->seedRow(1, 'Chicago Heights,Illinois,United States');
        $this->seedRow(2, 'Chicago State,Illinois,United States', 'US', 'State');
        $this->seedRow(3, 'Chicago,Ontario,Canada', 'CA');
        $this->seedRow(4, 'Chicago Neighborhood,Illinois,United States', 'US', 'Neighborhood');
        $this->seedRow(5, 'Chicago Elsewhere,Illinois,United States', 'US', 'City', 'someotherprovider');

        $this->assertSame([
            'Chicago Heights,Illinois,United States',
            'Chicago,Illinois,United States',
        ], $this->names('Chicago'));
    }

    public function test_search_results_are_ordered_by_name_and_limited(): void
    {
        foreach (range(1, 30) as $n) {
            $this->seedRow(100 + $n, sprintf('Springfield %02d,Illinois,United States', $n));
        }

        $default = $this->names('Spring');
        $this->assertCount(10, $default);
        $this->assertSame('Springfield 01,Illinois,United States', $default[0]);
        $this->assertSame('Springfield 10,Illinois,United States', $default[9]);

        $this->assertCount(3, $this->names('Spring', 3));
        $this->assertCount(1, $this->names('Spring', 0), 'The limit never drops below one.');
        $this->assertCount(1, $this->names('Spring', -5));
        $this->assertCount(25, $this->names('Spring', 100), 'The limit never exceeds 25.');
    }

    public function test_search_treats_like_wildcards_as_literal_characters(): void
    {
        $this->assertCount(0, $this->catalog()->search('Ch%'));
        $this->assertCount(0, $this->catalog()->search('%'));
        $this->assertCount(0, $this->catalog()->search('%%'));
        $this->assertCount(0, $this->catalog()->search('_hicago'));
        $this->assertCount(0, $this->catalog()->search('C_icago'));
        $this->assertCount(0, $this->catalog()->search('\\'));

        $this->seedRow(900, '100%_Real,Illinois,United States');
        $this->assertSame(['100%_Real,Illinois,United States'], $this->names('100%'));
    }

    public function test_search_never_calls_the_provider(): void
    {
        $this->catalog()->search('Chicago');
        $this->catalog()->find(self::CHICAGO);

        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
        $this->assertSame(0, FakeSeoRankProvider::$fetchCalls);
    }

    // ---------------------------------------------------------------
    // find / label / isPopulated
    // ---------------------------------------------------------------

    public function test_find_returns_a_supported_cached_location_by_code(): void
    {
        $found = $this->catalog()->find(self::CHICAGO);

        $this->assertNotNull($found);
        $this->assertSame(self::CHICAGO, (int) $found->location_code);
        $this->assertSame('Chicago,Illinois,United States', $found->location_name);
    }

    public function test_find_rejects_unsupported_type_country_provider_and_unknown_codes(): void
    {
        $this->seedRow(2001, 'Illinois,United States', 'US', 'State');
        $this->seedRow(2002, 'Toronto,Ontario,Canada', 'CA', 'City');
        $this->seedRow(2003, 'Somewhere,Illinois,United States', 'US', 'City', 'someotherprovider');
        $this->seedRow(2004, 'Downtown,Illinois,United States', 'US', 'Neighborhood');

        foreach ([2001, 2002, 2003, 2004, 987654321, 0, -1] as $code) {
            $this->assertNull($this->catalog()->find($code), 'code ' . $code);
        }
    }

    public function test_label_makes_the_provider_name_readable(): void
    {
        $this->assertSame('Chicago, Illinois, United States', SeoRankLocationCatalog::label('Chicago,Illinois,United States'));
        $this->assertSame('Chicago, Illinois, United States', SeoRankLocationCatalog::label('Chicago , Illinois ,  United States'));
        $this->assertSame('Single', SeoRankLocationCatalog::label('Single'));
        $this->assertSame('', SeoRankLocationCatalog::label(''));
    }

    public function test_is_populated_reflects_the_cache(): void
    {
        $this->assertTrue($this->catalog()->isPopulated());

        SeoRankLocation::query()->delete();

        $this->assertFalse($this->catalog()->isPopulated());
    }

    // ---------------------------------------------------------------
    // sync
    // ---------------------------------------------------------------

    /** @return list<array{code: int, name: string, parent_code: int|null, country_iso: string, type: string}> */
    private function providerCatalogue(): array
    {
        return [
            ['code' => 3001, 'name' => 'Aurora,Illinois,United States', 'parent_code' => 21144, 'country_iso' => 'US', 'type' => 'City'],
            ['code' => 3002, 'name' => 'Joliet,Illinois,United States', 'parent_code' => 21144, 'country_iso' => 'US', 'type' => 'City'],
            ['code' => 21144, 'name' => 'Illinois,United States', 'parent_code' => 2840, 'country_iso' => 'US', 'type' => 'State'],
            ['code' => 2840, 'name' => 'United States', 'parent_code' => null, 'country_iso' => 'US', 'type' => 'Country'],
            ['code' => 3003, 'name' => 'Hood,Illinois,United States', 'parent_code' => 3001, 'country_iso' => 'US', 'type' => 'Neighborhood'],
            ['code' => 9001, 'name' => 'Toronto,Ontario,Canada', 'parent_code' => null, 'country_iso' => 'CA', 'type' => 'City'],
        ];
    }

    public function test_sync_upserts_only_city_rows_of_the_supported_country(): void
    {
        SeoRankLocation::query()->delete();
        FakeSeoRankProvider::$locations = $this->providerCatalogue();

        $written = $this->catalog()->sync();

        $this->assertSame(2, $written);
        $this->assertSame(2, SeoRankLocation::query()->count());
        $this->assertSame([3001, 3002], SeoRankLocation::query()->orderBy('location_code')->pluck('location_code')->map(fn ($c) => (int) $c)->all());

        $aurora = SeoRankLocation::query()->where('location_code', 3001)->firstOrFail();
        $this->assertSame('dataforseo', $aurora->provider);
        $this->assertSame('US', $aurora->country_iso);
        $this->assertSame('City', $aurora->location_type);
        $this->assertSame(21144, (int) $aurora->parent_code);
        $this->assertSame('Aurora,Illinois,United States', $aurora->location_name);
    }

    public function test_sync_is_idempotent_and_updates_changed_names(): void
    {
        SeoRankLocation::query()->delete();
        FakeSeoRankProvider::$locations = $this->providerCatalogue();

        $this->assertSame(2, $this->catalog()->sync());
        $firstIds = SeoRankLocation::query()->orderBy('id')->pluck('id')->all();

        $this->assertSame(2, $this->catalog()->sync());
        $this->assertSame(2, SeoRankLocation::query()->count());
        $this->assertSame($firstIds, SeoRankLocation::query()->orderBy('id')->pluck('id')->all(), 'Rows are updated in place, never duplicated.');

        $changed = $this->providerCatalogue();
        $changed[0]['name'] = 'Aurora City,Illinois,United States';
        FakeSeoRankProvider::$locations = $changed;

        $this->assertSame(2, $this->catalog()->sync());
        $this->assertSame(2, SeoRankLocation::query()->count());
        $this->assertSame('Aurora City,Illinois,United States', SeoRankLocation::query()->where('location_code', 3001)->value('location_name'));
    }

    public function test_sync_keeps_existing_rows_that_targets_may_reference(): void
    {
        FakeSeoRankProvider::$locations = $this->providerCatalogue();

        $this->catalog()->sync();

        // The seeded fixture cities are untouched: sync never deletes.
        $this->assertNotNull($this->catalog()->find(self::CHICAGO));
        $this->assertNotNull($this->catalog()->find(3001));
    }

    public function test_sync_with_an_empty_provider_catalogue_writes_nothing(): void
    {
        FakeSeoRankProvider::$locations = [];

        $this->assertSame(0, $this->catalog()->sync());
        $this->assertSame(3, SeoRankLocation::query()->count());
    }

    public function test_sync_writes_large_catalogues_in_chunks(): void
    {
        SeoRankLocation::query()->delete();
        $rows = [];

        foreach (range(1, 1200) as $n) {
            $rows[] = ['code' => 50000 + $n, 'name' => "City {$n},Illinois,United States", 'parent_code' => null, 'country_iso' => 'US', 'type' => 'City'];
        }

        FakeSeoRankProvider::$locations = $rows;

        $this->assertSame(1200, $this->catalog()->sync());
        $this->assertSame(1200, SeoRankLocation::query()->count());
        $this->assertSame(1200, $this->catalog()->sync());
        $this->assertSame(1200, SeoRankLocation::query()->count());
    }

    // ---------------------------------------------------------------
    // an invalid location can never become paid activity
    // ---------------------------------------------------------------

    public function test_an_invalid_location_code_is_refused_before_any_run_ledger_or_provider_activity(): void
    {
        [$owner, $business] = $this->rankTenant();
        $keyword = $this->keyword($owner, $business);
        $this->seedRow(2001, 'Illinois,United States', 'US', 'State');
        $this->seedRow(2002, 'Toronto,Ontario,Canada', 'CA', 'City');

        foreach ([987654321, 2001, 2002, 0, -7] as $code) {
            try {
                $this->track($owner, $business, $keyword, $code);
                $this->fail('Location ' . $code . ' must be refused.');
            } catch (SeoRankException $e) {
                $this->assertSame(SeoRankException::INVALID_LOCATION, $e->reason, 'code ' . $code);
                $this->assertSame('Choose a search location from the list.', $e->customerMessage());
            }
        }

        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
        $this->assertSame(0, FakeSeoRankProvider::$fetchCalls);
        $this->assertSame(0, SeoRankTarget::query()->count());
        $this->assertSame(0, SeoRankCheckRun::query()->count());
        $this->assertSame(0, SeoRankProviderLedger::query()->count());
        $this->assertSame(0, SeoRankObservation::query()->count());
    }

    public function test_a_valid_location_code_creates_a_target_and_still_no_provider_activity(): void
    {
        [$owner, $business] = $this->rankTenant();

        $target = $this->track($owner, $business, $this->keyword($owner, $business), self::NAPERVILLE);

        $this->assertSame(self::NAPERVILLE, (int) $target->search_location_code);
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
        $this->assertSame(0, SeoRankCheckRun::query()->count());
        $this->assertSame(0, SeoRankProviderLedger::query()->count());
    }
}
