<?php

namespace Tests\Feature\Seo\Rank;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Seo\Rank\Provider\FakeSeoRankProvider;
use App\Library\Seo\Rank\Provider\SeoRankProviderException;
use App\Library\Seo\Rank\SeoRankLocationCatalog;
use App\Models\Business;
use App\Models\SeoKeyword;
use App\Models\SeoRankLocation;
use App\Models\SeoRankTarget;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\TestCase;

/**
 * The Start tracking city picker: US + Lithuanian cities from the cached
 * provider catalogue, honest empty/unavailable/not-initialised states, and an
 * Add keyword path that never traps the customer.
 *
 * Location codes below are TEST FIXTURES for the fake provider, not production
 * data; production codes always come from `seo:rank-sync-locations`.
 */
class SeoRankCityPickerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;

    private const VILNIUS = 9900001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    private function u(string $name, Workspace $workspace, Business $business, ?string $uid = null): string
    {
        return route('customer.workspaces.businesses.seo.' . $name, array_filter([$workspace->uid, $business->uid, $uid]));
    }

    /** @return array{0: \App\Models\Customer, 1: Business, 2: Workspace} */
    private function tenant(): array
    {
        return $this->rankTenant(WorkspacePlanTier::Core);
    }

    private function seedVilnius(): void
    {
        $row = new SeoRankLocation();
        $row->forceFill([
            'provider' => 'dataforseo',
            'location_code' => self::VILNIUS,
            'location_name' => 'Vilnius,Vilnius County,Lithuania',
            'country_iso' => 'LT',
            'location_type' => 'City',
        ])->save();
    }

    private function search(Workspace $w, Business $b, string $q)
    {
        return $this->getJson($this->u('keywords.rank-locations', $w, $b) . '?q=' . urlencode($q));
    }

    public function test_chicago_and_vilnius_autocomplete_with_their_provider_codes(): void
    {
        [$owner, $business, $workspace] = $this->tenant();
        $this->seedVilnius();
        $this->authenticateAsSeoCustomer($owner);
        Http::fake();

        $this->search($workspace, $business, 'Chicago')->assertOk()->assertExactJson([
            'locations' => [['code' => self::CHICAGO, 'label' => 'Chicago, Illinois, United States']],
            'status' => 'ok',
        ]);
        $this->search($workspace, $business, 'vilnius')->assertOk()->assertExactJson([
            'locations' => [['code' => self::VILNIUS, 'label' => 'Vilnius, Vilnius County, Lithuania']],
            'status' => 'ok',
        ]);

        Http::assertNothingSent();
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
    }

    public function test_unsupported_countries_and_types_never_appear_or_validate(): void
    {
        [$owner, $business, $workspace] = $this->tenant();
        foreach ([[9900010, 'Riga,Riga,Latvia', 'LV', 'City'], [9900011, 'Vilnius County,Lithuania', 'LT', 'County']] as [$code, $name, $iso, $type]) {
            (new SeoRankLocation())->forceFill(['provider' => 'dataforseo', 'location_code' => $code, 'location_name' => $name, 'country_iso' => $iso, 'location_type' => $type])->save();
        }
        $this->seedVilnius();
        $this->authenticateAsSeoCustomer($owner);

        $this->assertSame([], $this->search($workspace, $business, 'Riga')->json('locations'));
        $this->assertSame(['Vilnius, Vilnius County, Lithuania'], collect($this->search($workspace, $business, 'Vilnius')->json('locations'))->pluck('label')->all());
        $this->assertNull(app(SeoRankLocationCatalog::class)->find(9900010));
        $this->assertNull(app(SeoRankLocationCatalog::class)->find(9900011));
    }

    public function test_empty_result_distinguishes_no_match_partial_catalogue_and_not_initialised(): void
    {
        [$owner, $business, $workspace] = $this->tenant();
        $this->authenticateAsSeoCustomer($owner);

        // US cached, Lithuania not: an empty result may only mean "still loading".
        $this->search($workspace, $business, 'Vilnius')->assertOk()->assertExactJson(['locations' => [], 'status' => 'partial']);

        // Everything cached: a plain no-match.
        $this->seedVilnius();
        $this->search($workspace, $business, 'Zzzz')->assertOk()->assertExactJson(['locations' => [], 'status' => 'ok']);

        // Nothing cached at all.
        SeoRankLocation::query()->delete();
        $this->search($workspace, $business, 'Chicago')->assertOk()->assertExactJson(['locations' => [], 'status' => 'not_initialized']);
        $this->assertFalse(app(SeoRankLocationCatalog::class)->isPopulated());
    }

    public function test_the_picker_renders_inline_feedback_a_reason_and_a_disabled_start_button(): void
    {
        [$owner, $business, $workspace] = $this->tenant();
        $this->authenticateAsSeoCustomer($owner);
        $this->post($this->u('keywords.store', $workspace, $business), ['phrase' => 'first keyword', 'track_rank' => '0']);

        $html = $this->get($this->u('keywords.index', $workspace, $business))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-role="rank-location-confirm" disabled>Start tracking</', $html);
        $this->assertStringContainsString('Start tracking turns on once you pick a city from the list.', $html);
        $this->assertStringContainsString('data-role="rank-location-hint" role="status"', $html);
        $this->assertStringContainsString('e.g. Chicago or Vilnius', $html);
        foreach (['No matching city.', 'Locations are temporarily unavailable.', 'Locations are not initialized yet.'] as $copy) {
            $this->assertStringContainsString($copy, $html);
        }
        // Add keyword escape hatch inside the dialog (mobile modal never a dead end).
        $this->assertStringContainsString('data-role="rank-location-skip"', $html);
        $this->assertStringContainsString('Add keyword without tracking', $html);
        $this->assertStringContainsString('data-bs-dismiss="modal">Cancel', $html);
    }

    public function test_choosing_vilnius_sends_its_code_and_starts_tracking(): void
    {
        [$owner, $business, $workspace] = $this->tenant();
        $this->seedVilnius();
        $this->authenticateAsSeoCustomer($owner);
        FakeSeoRankProvider::$completeImmediately = false;

        $keyword = $this->keyword($owner, $business, 'dentist vilnius');
        $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => self::VILNIUS])
            ->assertSessionHas('status', 'success');

        $target = SeoRankTarget::query()->where('seo_keyword_id', $keyword->id)->firstOrFail();
        $this->assertTrue($target->isTracking());
        $this->assertSame(self::VILNIUS, (int) $target->search_location_code);
    }

    public function test_add_keyword_with_a_valid_city_starts_tracking(): void
    {
        [$owner, $business, $workspace] = $this->tenant();
        $this->authenticateAsSeoCustomer($owner);
        FakeSeoRankProvider::$completeImmediately = false;

        $this->post($this->u('keywords.store', $workspace, $business), ['phrase' => 'photo booth chicago', 'track_rank' => '1', 'search_location_code' => (string) self::CHICAGO])
            ->assertSessionHas('message', 'Keyword added and rank tracking started. The first check is on its way.');
        $this->assertSame(1, SeoRankTarget::query()->count());
    }

    public function test_add_keyword_without_tracking_succeeds_when_city_catalogue_is_empty(): void
    {
        [$owner, $business, $workspace] = $this->tenant();
        $this->authenticateAsSeoCustomer($owner);

        // Separate test transaction: no active rank target references the
        // location catalogue, whose FK is RESTRICT in production.
        SeoRankLocation::query()->delete();
        $this->assertFalse(app(SeoRankLocationCatalog::class)->isPopulated());

        $this->post($this->u('keywords.store', $workspace, $business), ['phrase' => 'plain keyword', 'track_rank' => '0'])
            ->assertSessionHas('message', 'Keyword added.');
        $this->post($this->u('keywords.store', $workspace, $business), ['phrase' => 'untracked keyword', 'track_rank' => '1', 'search_location_code' => (string) self::CHICAGO])
            ->assertSessionHas('status', 'success');

        $this->assertNotNull(SeoKeyword::query()->where('phrase', 'plain keyword')->first());
        $this->assertNotNull(SeoKeyword::query()->where('phrase', 'untracked keyword')->first());
        $this->assertSame(0, SeoRankTarget::query()->count());
    }

    public function test_invalid_codes_are_rejected_for_both_countries_without_spend(): void
    {
        [$owner, $business, $workspace] = $this->tenant();
        (new SeoRankLocation())->forceFill(['provider' => 'dataforseo', 'location_code' => 9900011, 'location_name' => 'Vilnius County,Lithuania', 'country_iso' => 'LT', 'location_type' => 'County'])->save();
        $keyword = $this->keyword($owner, $business);
        $this->authenticateAsSeoCustomer($owner);

        foreach ([123456789, 9900011] as $code) {
            $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => $code])
                ->assertSessionHas('message', 'Choose a search location from the list.');
        }

        $this->assertSame(0, SeoRankTarget::query()->count());
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
    }

    public function test_sync_loads_us_and_lithuanian_cities_by_the_rows_own_country(): void
    {
        SeoRankLocation::query()->delete();
        FakeSeoRankProvider::$locations = [
            ['code' => 1016367, 'name' => 'Chicago,Illinois,United States', 'parent_code' => null, 'country_iso' => 'US', 'type' => 'City'],
            ['code' => self::VILNIUS, 'name' => 'Vilnius,Vilnius County,Lithuania', 'parent_code' => null, 'country_iso' => 'LT', 'type' => 'City'],
            ['code' => 9900012, 'name' => 'Kaunas,Kaunas County,Lithuania', 'parent_code' => null, 'country_iso' => 'LT', 'type' => 'Municipality'],
            ['code' => 9900013, 'name' => 'Kaunas County,Lithuania', 'parent_code' => null, 'country_iso' => 'LT', 'type' => 'County'],
            ['code' => 9900014, 'name' => 'Riga,Latvia', 'parent_code' => null, 'country_iso' => 'LV', 'type' => 'City'],
        ];
        $catalog = app(SeoRankLocationCatalog::class);

        $this->assertSame(['US', 'LT'], $catalog->missingCountries());
        $this->assertSame(3, $catalog->sync());
        $this->assertSame([], $catalog->missingCountries());
        $this->assertSame(['LT', 'LT', 'US'], SeoRankLocation::query()->orderBy('country_iso')->orderBy('location_code')->pluck('country_iso')->all());
    }

    public function test_the_if_missing_command_calls_the_provider_only_when_a_country_is_missing(): void
    {
        FakeSeoRankProvider::$locations = [
            ['code' => self::VILNIUS, 'name' => 'Vilnius,Vilnius County,Lithuania', 'parent_code' => null, 'country_iso' => 'LT', 'type' => 'City'],
        ];

        $this->assertSame(['LT'], app(SeoRankLocationCatalog::class)->missingCountries());
        $this->assertSame(0, Artisan::call('seo:rank-sync-locations', ['--if-missing' => true]));
        $this->assertSame([], app(SeoRankLocationCatalog::class)->missingCountries());
        $this->assertNotNull(app(SeoRankLocationCatalog::class)->find(self::VILNIUS));

        // Everything cached: nothing to do.
        FakeSeoRankProvider::$locations = [];
        $this->assertSame(0, Artisan::call('seo:rank-sync-locations', ['--if-missing' => true]));
        $this->assertStringContainsString('nothing to do', Artisan::output());
    }
}
