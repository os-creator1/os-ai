<?php

namespace Tests\Feature\Seo;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoCitationStatus;
use App\Library\Seo\SeoCitationLocationSection;
use App\Library\Seo\SeoCitationManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoCitation;
use App\Models\SeoCitationDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * Citations V1 closure — country-scoped directories are filtered PER LOCATION.
 *
 * Shipped US-scoped directories: data_axle, nextdoor_business, yellow_pages (core) and bark (Photo Booth
 * niche). Everything else is global. The fixture business is photo_booth_service, so bark is recommended.
 */
class SeoCitationsCountryScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

    private const US_ONLY = ['data_axle', 'nextdoor_business', 'yellow_pages', 'bark'];
    private const GLOBAL_SAMPLE = ['apple_business', 'bing_places', 'yelp', 'facebook_pages', 'gigsalad', 'weddingwire_the_knot'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bypassCitationEntitlementForTest();
    }

    /** @return array{0: \App\Models\Customer, 1: Business, 2: \App\Models\Workspace, 3: BusinessLocation} */
    private function tenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $us = $this->publicStorefront($business, 'Chicago');
        $this->authenticateAsSeoCustomer($customer);

        return [$customer, $business, $workspace, $us];
    }

    private function vilnius(Business $business): BusinessLocation
    {
        return $this->extraLocation($business, 'Vilnius', [
            'service_mode' => BusinessServiceMode::Storefront,
            'public_address' => true,
            'address_line_1' => 'Gedimino pr. 1',
            'city' => 'Vilnius',
            'postal_code' => '01103',
            'country_code' => 'LT',
        ]);
    }

    private function section(\App\Models\Customer $customer, \App\Models\Workspace $workspace, Business $business, BusinessLocation $location): SeoCitationLocationSection
    {
        foreach (app(SeoCitationManager::class)->page($workspace, $business->fresh(), $customer->user) as $section) {
            if ($section->location->id === $location->id) {
                return $section;
            }
        }

        $this->fail('Section not found.');
    }

    /** @return array<int, string> */
    private function keys(SeoCitationLocationSection $section): array
    {
        return array_map(fn ($row) => $row->directory->key, $section->rows);
    }

    private function page($workspace, Business $business, BusinessLocation $location): string
    {
        return $this->get($this->citationsUrl($workspace, $business) . '?location=' . $location->uid)->assertOk()->getContent();
    }

    public function test_the_shipped_catalog_has_exactly_the_documented_us_scoped_directories(): void
    {
        $scoped = SeoCitationDirectory::query()->whereNull('business_id')->whereNotNull('country_scope')->pluck('country_scope', 'key')->all();

        $this->assertEquals(array_fill_keys(self::US_ONLY, 'US'), $scoped);
        $this->assertNull($this->directory('weddingwire_the_knot')->country_scope, 'US focus alone does not prove a restriction.');
    }

    public function test_a_us_location_sees_global_and_us_scoped_directories(): void
    {
        [$customer, $business, $workspace, $us] = $this->tenant();

        $keys = $this->keys($this->section($customer, $workspace, $business, $us));

        foreach (array_merge(self::GLOBAL_SAMPLE, self::US_ONLY) as $key) {
            $this->assertContains($key, $keys, "[{$key}] applies in the US.");
        }
    }

    public function test_the_scope_match_is_case_insensitive(): void
    {
        [$customer, $business, $workspace, $us] = $this->tenant();
        DB::table('seo_citation_directories')->where('key', 'data_axle')->update(['country_scope' => 'us']);
        DB::table('business_locations')->where('id', $us->id)->update(['country_code' => 'us']);

        $this->assertContains('data_axle', $this->keys($this->section($customer, $workspace, $business, $us)));
    }

    public function test_a_lithuanian_location_sees_global_but_not_us_scoped_directories(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $lt = $this->vilnius($business);

        $keys = $this->keys($this->section($customer, $workspace, $business, $lt));

        foreach (self::GLOBAL_SAMPLE as $key) {
            $this->assertContains($key, $keys, "[{$key}] is global.");
        }
        foreach (self::US_ONLY as $key) {
            $this->assertNotContains($key, $keys, "[{$key}] is US-only.");
        }

        $html = $this->page($workspace, $business, $lt);
        $this->assertStringNotContainsString('data-directory="data_axle"', $html);
        $this->assertStringNotContainsString('id="citation-drawer-nextdoor_business"', $html);
    }

    public function test_one_business_with_a_us_and_an_lt_location_gets_different_lists(): void
    {
        [$customer, $business, $workspace, $us] = $this->tenant();
        $lt = $this->vilnius($business);

        $usKeys = $this->keys($this->section($customer, $workspace, $business, $us));
        $ltKeys = $this->keys($this->section($customer, $workspace, $business, $lt));

        $this->assertNotEquals($usKeys, $ltKeys);
        $this->assertEqualsCanonicalizing(self::US_ONLY, array_values(array_diff($usKeys, $ltKeys)));
        $this->assertSame([], array_values(array_diff($ltKeys, $usKeys)), 'The LT list is a strict subset here.');

        // The Business's own country plays no part: both Locations are decided by their own country_code.
        DB::table('businesses')->where('id', $business->id)->update(['country_code' => 'LT']);
        $this->assertEquals($usKeys, $this->keys($this->section($customer, $workspace, $business, $us)));
    }

    public function test_a_us_only_niche_recommendation_is_absent_from_an_lt_location(): void
    {
        [$customer, $business, $workspace, $us] = $this->tenant();
        $lt = $this->vilnius($business);

        // bark is a Photo Booth niche recommendation AND US-scoped.
        $this->assertContains('bark', $this->keys($this->section($customer, $workspace, $business, $us)));
        $this->assertNotContains('bark', $this->keys($this->section($customer, $workspace, $business, $lt)), 'A recommendation never overrides country applicability.');

        // Even an Essential override from the niche does not bring it back.
        DB::table('seo_niche_citation_recommendations')->update(['importance' => 'essential']);
        $this->assertNotContains('bark', $this->keys($this->section($customer, $workspace, $business, $lt)));
    }

    public function test_a_scoped_directory_is_not_offered_for_writes_at_a_location_it_does_not_apply_to(): void
    {
        [, $business, $workspace] = $this->tenant();
        $lt = $this->vilnius($business);

        $this->from($this->citationsUrl($workspace, $business))
            ->put($this->citationUpdateUrl($workspace, $business, (string) $lt->uid, 'data_axle'), $this->citationInput())
            ->assertNotFound();
        $this->post(route('customer.workspaces.businesses.seo.citations.applicability', [$workspace->uid, $business->uid, $lt->uid, 'data_axle']), ['applicable' => 0])
            ->assertNotFound();

        $this->assertSame(0, SeoCitation::query()->count());
    }

    public function test_a_citation_for_a_directory_that_no_longer_applies_stays_readable_history_but_is_not_offered(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $lt = $this->vilnius($business);
        $this->makeCitation($business, $lt, $this->directory('data_axle'), [
            'status' => SeoCitationStatus::Listed->value, 'listed_name' => 'History Name', 'notes' => 'kept-note',
        ]);
        $before = $this->dbFingerprint(['seo_citations']);

        $section = $this->section($customer, $workspace, $business, $lt);
        $row = collect($section->rows)->first(fn ($r) => $r->directory->key === 'data_axle');

        $this->assertNotNull($row, 'The record stays readable.');
        $this->assertSame('History Name', $row->listedName);
        $this->assertTrue($row->isHistoryOnly());
        $this->assertFalse($row->writable);
        $this->assertFalse($row->countsTowardProgress());

        // Not counted as progress, attention or a next step.
        $summary = $section->summary();
        $this->assertSame(0, $summary['completed']);
        $this->assertSame(0, $summary['notApplicable']);
        $this->assertNotContains('data_axle', array_map(fn ($i) => $i['row']?->directory->key, $section->attentionItems()));

        $html = $this->page($workspace, $business, $lt);
        $this->assertStringContainsString('data-role="history-badge"', $html);
        $this->assertStringContainsString('kept-note', $html);
        $this->assertStringContainsString('data-role="drawer-readonly"', $html);
        // No edit form and no Record details button for it.
        $this->assertDoesNotMatchRegularExpression('/id="citation-drawer-data_axle".*?data-role="citation-form"/s', $this->drawerOf($html, 'data_axle'));

        $this->from($this->citationsUrl($workspace, $business))
            ->put($this->citationUpdateUrl($workspace, $business, (string) $lt->uid, 'data_axle'), $this->citationInput())->assertNotFound();
        $this->assertSame($before, $this->dbFingerprint(['seo_citations']), 'Nothing was deleted or rewritten.');
    }

    private function drawerOf(string $html, string $key): string
    {
        $start = strpos($html, 'id="citation-drawer-' . $key . '"');
        $this->assertNotFalse($start);
        $next = strpos($html, 'id="citation-drawer-', $start + 10);

        return substr($html, $start, ($next === false ? strlen($html) : $next) - $start);
    }

    public function test_history_also_survives_a_disabled_directory_and_a_removed_recommendation(): void
    {
        [$customer, $business, $workspace, $us] = $this->tenant();
        $this->makeCitation($business, $us, $this->directory('bark'), ['status' => SeoCitationStatus::Listed->value, 'listed_name' => 'Bark Record']);
        $this->makeCitation($business, $us, $this->directory('mapquest'), ['status' => SeoCitationStatus::Listed->value, 'listed_name' => 'Map Record']);
        DB::table('seo_niche_citation_recommendations')->delete();
        DB::table('seo_citation_directories')->where('key', 'mapquest')->update(['is_active' => false]);

        $section = $this->section($customer, $workspace, $business, $us);
        $rows = collect($section->rows)->keyBy(fn ($r) => $r->directory->key);

        foreach (['bark' => 'Bark Record', 'mapquest' => 'Map Record'] as $key => $name) {
            $this->assertSame($name, $rows[$key]->listedName, "[{$key}] history is readable.");
            $this->assertTrue($rows[$key]->isHistoryOnly());
            $this->assertFalse($rows[$key]->writable);
        }
        $this->assertSame(0, $section->summary()['completed']);
    }

    public function test_a_business_custom_directory_is_unaffected_by_country(): void
    {
        [$customer, $business, $workspace, $us] = $this->tenant();
        $lt = $this->vilnius($business);

        $this->from($this->citationsUrl($workspace, $business))
            ->post(route('customer.workspaces.businesses.seo.citations.custom.store', [$workspace->uid, $business->uid, $lt->uid]), array_merge($this->citationInput(), ['name' => 'Baltic Weddings', 'location_scope' => 'all']))
            ->assertSessionHasNoErrors();
        $custom = SeoCitationDirectory::query()->where('business_id', $business->id)->sole();

        // Even a stray US scope on a custom row is ignored.
        DB::table('seo_citation_directories')->where('id', $custom->id)->update(['country_scope' => 'US']);

        foreach ([$us, $lt] as $location) {
            $row = collect($this->section($customer, $workspace, $business, $location)->rows)->first(fn ($r) => $r->directory->key === $custom->key);
            $this->assertNotNull($row, 'Offered at ' . $location->name);
            $this->assertFalse($row->isHistoryOnly());
        }

        $this->from($this->citationsUrl($workspace, $business))
            ->put($this->citationUpdateUrl($workspace, $business, (string) $lt->uid, $custom->key), $this->citationInput(['listed_name' => 'Baltic Weddings']))
            ->assertSessionHasNoErrors();
    }

    public function test_counts_follow_each_locations_own_offered_list_and_nothing_leaks_between_locations(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $us] = $this->tenant();
        $lt = $this->vilnius($business);
        $this->makeCitation($business, $us, $this->directory('data_axle'), ['status' => SeoCitationStatus::Listed->value, 'listed_name' => 'US-Only-Record']);

        $usSummary = $this->section($customer, $workspace, $business, $us)->summary();
        $ltSummary = $this->section($customer, $workspace, $business, $lt)->summary();

        $this->assertSame(count(self::US_ONLY), $usSummary['tracked'] - $ltSummary['tracked'], 'The US Location tracks exactly the US-only directories more.');
        $this->assertSame(1, $usSummary['completed']);
        $this->assertSame(0, $ltSummary['completed']);
        $this->assertSame($usSummary['essentialTotal'], $ltSummary['essentialTotal'], 'Essential sources are all global.');
        $this->assertGreaterThan($ltSummary['recommendedTotal'], $usSummary['recommendedTotal']);

        $this->assertStringNotContainsString('US-Only-Record', $this->page($workspace, $business, $lt));
        $this->assertStringContainsString('US-Only-Record', $this->page($workspace, $business, $us));
    }
}
