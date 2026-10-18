<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\GoogleBusinessProfile\GoogleLocationHealth;
use App\Enums\Seo\SeoCitationDisplayState;
use App\Enums\Seo\SeoCitationStatus;
use App\Enums\Seo\SeoNapFieldResult;
use App\Library\Seo\SeoCitationLocationSection;
use App\Library\Seo\SeoCitationManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoCitation;
use App\Models\SeoCitationDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * Citations / Google row / NAP repairs from the SEO V1 final audit: honest
 * Google state and age, "Needs setup" kept apart from "Needs attention", counts
 * that come from the rows the list shows, the authorization edges of custom
 * directories, a Business-wide phone that is not claimed for a secondary
 * Location, and copy that does not overstate. Fixture business: industry photo
 * booth, phone +15550101234, website https://example.test, storefront at
 * "12 High Street, Springfield, IL, 62701, US".
 */
class SeoCitationsAuditRepairTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bypassCitationEntitlementForTest();
    }

    /** @return array{0: \App\Models\Customer, 1: Business, 2: \App\Models\Workspace, 3: BusinessLocation} */
    private function tenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->publicStorefront($business, 'Main Storefront');
        $this->authenticateAsSeoCustomer($customer);

        return [$customer, $business, $workspace, $location];
    }

    private function page(\App\Models\Workspace $workspace, Business $business, ?BusinessLocation $location = null): string
    {
        return $this->get($this->citationsUrl($workspace, $business) . ($location ? '?location=' . $location->uid : ''))->assertOk()->getContent();
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

    private function setIndustry(Business $business, string $industry): Business
    {
        DB::table('businesses')->where('id', $business->id)->update(['industry' => $industry]);

        return $business->fresh();
    }

    private function addCustom($workspace, Business $business, BusinessLocation $location, array $overrides = [])
    {
        $url = route('customer.workspaces.businesses.seo.citations.custom.store', [$workspace->uid, $business->uid, $location->uid]);

        return $this->from($this->citationsUrl($workspace, $business))->post($url, array_merge($this->citationInput([
            'listed_name' => 'Acme Photo Booth',
        ]), ['name' => 'Local Wedding Blog', 'claim_url' => 'https://blog.example-weddings.com/vendors', 'location_scope' => 'all'], $overrides));
    }

    private function putCitation($workspace, Business $business, BusinessLocation $location, string $key, array $overrides = [])
    {
        return $this->from($this->citationsUrl($workspace, $business))
            ->put($this->citationUpdateUrl($workspace, $business, (string) $location->uid, $key), $this->citationInput($overrides));
    }

    private function applicability($workspace, Business $business, BusinessLocation $location, string $key, bool $applicable)
    {
        return $this->from($this->citationsUrl($workspace, $business))
            ->post(route('customer.workspaces.businesses.seo.citations.applicability', [$workspace->uid, $business->uid, $location->uid, $key]), ['applicable' => $applicable ? 1 : 0]);
    }

    private function renameCustom($workspace, Business $business, BusinessLocation $location, string $key, string $name)
    {
        return $this->from($this->citationsUrl($workspace, $business))
            ->put(route('customer.workspaces.businesses.seo.citations.custom.update', [$workspace->uid, $business->uid, $location->uid, $key]), ['name' => $name, 'claim_url' => '']);
    }

    private function archiveCustom($workspace, Business $business, BusinessLocation $location, string $key)
    {
        return $this->from($this->citationsUrl($workspace, $business))
            ->post(route('customer.workspaces.businesses.seo.citations.custom.archive', [$workspace->uid, $business->uid, $location->uid, $key]));
    }

    // -----------------------------------------------------------------
    // B2 — only an ACTIVE Google connection is "Connected"
    // -----------------------------------------------------------------

    /** @return array<string, array{0: GoogleConnectionState}> */
    public static function nonActiveConnectionStates(): array
    {
        return [
            'pending' => [GoogleConnectionState::Pending],
            'disconnected' => [GoogleConnectionState::Disconnected],
            'revoked' => [GoogleConnectionState::Revoked],
        ];
    }

    #[DataProvider('nonActiveConnectionStates')]
    public function test_a_bound_location_on_a_non_active_connection_is_never_connected_complete_or_checked_automatically(GoogleConnectionState $state): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business, ['state' => $state]), true, ['title' => $business->name]);

        $section = $this->section($customer, $workspace, $business, $location);
        $summary = $section->summary();

        $this->assertSame(SeoCitationLocationSection::GOOGLE_CONNECTION_LOST, $section->googleState());
        $this->assertFalse($section->googleCheckedAutomatically());
        $this->assertNull($section->googleNap, 'No Google comparison is shown while the connection is not active.');
        $this->assertSame(0, $summary['completed'], 'A connection that is not active is not complete.');
        $this->assertSame(0, $summary['essentialDone']);
        $this->assertSame(1, $summary['attention'], 'It needs reconnecting.');
        $this->assertSame('Reconnect', collect($section->attentionItems())->firstWhere('name', 'Google Business Profile')['action']);

        $html = $this->page($workspace, $business, $location);
        $this->assertStringContainsString('data-google-state="connection_lost"', $html);
        $this->assertDoesNotMatchRegularExpression('/data-role="google-state">.*?Connected/s', $html);
        $this->assertStringNotContainsString('Checked automatically', $html);
        $this->assertStringContainsString('Reconnect', $html);
    }

    public function test_an_active_connection_is_connected_and_checked_automatically(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['title' => $business->name]);

        $section = $this->section($customer, $workspace, $business, $location);

        $this->assertSame(SeoCitationLocationSection::GOOGLE_CONNECTED, $section->googleState());
        $this->assertTrue($section->googleCheckedAutomatically());
        $this->assertSame(1, $section->summary()['completed']);
    }

    // -----------------------------------------------------------------
    // Listing health: a suspended / conflicting profile is not a finished Essential listing
    // -----------------------------------------------------------------

    /** @return array<string, array{0: GoogleLocationHealth, 1: string}> */
    public static function googleHealthProblems(): array
    {
        return [
            'suspended' => [GoogleLocationHealth::Suspended, 'Google has suspended this listing.'],
            'disabled' => [GoogleLocationHealth::Disabled, 'Google has disabled this listing.'],
            'ownership conflict' => [GoogleLocationHealth::OwnershipConflict, 'Google reports an ownership conflict on this listing.'],
            'duplicate' => [GoogleLocationHealth::Duplicate, 'Google reports this as a duplicate listing.'],
            'unverified' => [GoogleLocationHealth::Unverified, 'This Google listing is not verified.'],
            'verification pending' => [GoogleLocationHealth::VerificationPending, 'Verification of this Google listing is still pending.'],
        ];
    }

    #[DataProvider('googleHealthProblems')]
    public function test_a_google_listing_with_a_health_fault_needs_attention_and_is_not_a_finished_essential(GoogleLocationHealth $health, string $words): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['title' => $business->name], $health);

        $section = $this->section($customer, $workspace, $business, $location);
        $summary = $section->summary();
        $item = collect($section->attentionItems())->firstWhere('name', 'Google Business Profile');

        $this->assertSame($words, $section->googleHealthProblem());
        $this->assertSame(0, $summary['completed']);
        $this->assertSame(0, $summary['essentialDone']);
        $this->assertSame(1, $summary['attention']);
        $this->assertSame(1, $item['priority']);
        $this->assertStringContainsString($words, $item['message']);

        $html = $this->page($workspace, $business, $location);
        $this->assertMatchesRegularExpression('/data-role="google-row"[^>]*data-attention="1"/', $html);
        $this->assertMatchesRegularExpression('/data-role="google-state">.*?Needs attention/s', $html);
        $this->assertStringContainsString($words, $html);
        $this->assertStringContainsString('data-action="google"', $html, 'It is in "What to do next", linking to Google Business Profile.');
    }

    /** @return array<string, array{0: GoogleLocationHealth}> */
    public static function googleHealthFine(): array
    {
        return [
            'verified' => [GoogleLocationHealth::Verified],
            'unknown' => [GoogleLocationHealth::Unknown],
            'awaiting Google review' => [GoogleLocationHealth::AwaitingReview],
        ];
    }

    #[DataProvider('googleHealthFine')]
    public function test_a_healthy_or_unknown_google_listing_stays_a_finished_essential(GoogleLocationHealth $health): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['title' => $business->name], $health);

        $section = $this->section($customer, $workspace, $business, $location);

        $this->assertNull($section->googleHealthProblem());
        $this->assertSame(1, $section->summary()['completed']);
        $this->assertSame(0, $section->summary()['attention']);
    }

    public function test_stale_health_alone_is_a_note_not_a_problem(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $binding = $this->bindGoogleLocation($business, $location, $this->activeConnection($business), false, [], GoogleLocationHealth::Verified);
        $binding->forceFill(['last_synced_at' => now()->subDays(60)])->save();

        $section = $this->section($customer, $workspace, $business, $location);

        $this->assertTrue($section->google->healthIsStale);
        $this->assertSame(0, $section->summary()['attention']);
        $this->assertSame(1, $section->summary()['completed']);
    }

    // -----------------------------------------------------------------
    // B3 — Google health says how old it is
    // -----------------------------------------------------------------

    public function test_stale_google_health_shows_as_of_and_an_out_of_date_note_without_a_provider_call(): void
    {
        $fake = $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $binding = $this->bindGoogleLocation($business, $location, $this->activeConnection($business), false, [], GoogleLocationHealth::Verified);
        $syncedAt = now()->subDays(40);
        $binding->forceFill(['last_synced_at' => $syncedAt])->save();

        $status = $this->section($customer, $workspace, $business, $location)->google;
        $this->assertTrue($status->healthIsStale);
        $this->assertSame($syncedAt->toDateString(), $status->healthAsOf->toDateString());

        $html = $this->page($workspace, $business, $location);
        $this->assertMatchesRegularExpression('/data-role="google-health">Verified\s*<span[^>]*data-role="google-health-as-of">as of ' . preg_quote($syncedAt->format('M j, Y'), '/') . '</', $html);
        $this->assertStringContainsString('data-role="google-stale"', $html);
        $this->assertStringContainsString('Google data may be out of date', $html);
        $this->assertSame([], $fake->calls, 'Rendering the page never asks Google anything.');
    }

    public function test_fresh_google_health_has_an_age_and_no_out_of_date_note(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $binding = $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['title' => $business->name]);
        $binding->forceFill(['last_synced_at' => now()->subDay()])->save();

        $this->assertFalse($this->section($customer, $workspace, $business, $location)->google->healthIsStale);

        $html = $this->page($workspace, $business, $location);
        $this->assertStringContainsString('data-role="google-health-as-of"', $html);
        $this->assertStringNotContainsString('data-role="google-stale"', $html);
    }

    public function test_health_synced_beyond_the_policy_ceiling_is_stale_even_beside_a_fresh_mirror(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $binding = $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['title' => $business->name]);
        $binding->forceFill(['last_synced_at' => now()->subDays(31)])->save();

        $this->assertTrue($this->section($customer, $workspace, $business, $location)->google->healthIsStale);
    }

    // -----------------------------------------------------------------
    // B5 — "Needs setup" is not "Needs attention"; counts come from the list's rows
    // -----------------------------------------------------------------

    public function test_an_untouched_directory_needs_setup_and_is_never_counted_as_attention(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();

        $section = $this->section($customer, $workspace, $business, $location);
        $summary = $section->summary();
        $row = collect($section->rows)->first(fn ($r) => $r->directory->key === 'facebook_pages');

        $this->assertSame(SeoCitationDisplayState::NotStarted, $row->displayState());
        $this->assertTrue($row->needsSetup());
        $this->assertFalse($row->needsAttention());
        $this->assertSame(0, $summary['attention'], 'Nothing is wrong yet.');
        $this->assertSame(15, $summary['needsSetup'], '10 core + 5 Photo Booth directories are still to do.');
        $this->assertContains('facebook_pages', array_map(fn ($i) => $i['row']?->directory->key, $section->attentionItems()), 'It is still a next step.');
    }

    public function test_the_summary_counts_equal_the_rows_the_list_flags(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $name = $business->name;

        $this->makeCitation($business, $location, $this->directory('bing_places'), ['status' => SeoCitationStatus::NeedsCorrection->value]);
        $this->makeCitation($business, $location, $this->directory('yelp'), ['status' => SeoCitationStatus::Listed->value, 'listed_name' => $name, 'listed_phone' => '+19998887777']);
        $this->makeCitation($business, $location, $this->directory('apple_business'), ['status' => SeoCitationStatus::InProgress->value]);
        $this->makeCitation($business, $location, $this->directory('facebook_pages'), ['status' => SeoCitationStatus::Listed->value, 'listed_name' => $name, 'listed_phone' => '+15550101234', 'listed_address' => '12 High Street, Springfield, IL, 62701, US']);
        $this->makeCitation($business, $location, $this->directory('bbb'), ['status' => SeoCitationStatus::Listed->value, 'listed_name' => $name, 'last_verified_at' => now()->subDays(200)->toDateString()]);
        $this->makeCitation($business, $location, $this->directory('mapquest'), ['status' => SeoCitationStatus::NotApplicable->value]);

        $summary = $this->section($customer, $workspace, $business, $location)->summary();
        $html = $this->page($workspace, $business, $location);

        // Real problems: the correction flag, the phone that differs, the listing due a review.
        $this->assertSame(3, $summary['attention']);
        $this->assertSame(10, $summary['needsSetup']);
        $this->assertSame($summary['attention'], preg_match_all('/data-role="citation-row"[^>]*data-attention="1"/', $html), 'The count is the number of rows the list flags.');
        $this->assertSame($summary['needsSetup'], preg_match_all('/data-role="citation-row"[^>]*data-setup-needed="1"/', $html));
        $this->assertSame(1, preg_match('/data-stat="attention">(.*?)<\/div>/s', $html, $stat));
        $this->assertSame('Needs attention 3 Differs, marked for correction, or due a review', trim(preg_replace('/\s+/', ' ', strip_tags($stat[1]))));
        $this->assertMatchesRegularExpression('/data-progress="setup">10</', $html);
        $this->assertStringContainsString('data-filter="setup"', $html);
    }

    public function test_a_connected_google_row_with_a_difference_is_counted_as_attention_exactly_where_the_list_lists_it(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        // The default mirror's title is not the business name.
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business));

        $section = $this->section($customer, $workspace, $business, $location);
        $summary = $section->summary();

        $this->assertSame(1, $summary['completed'], 'Connected is complete…');
        $this->assertSame(1, $summary['attention'], '…and a shown difference is still attention.');
        $this->assertSame('Review', collect($section->attentionItems())->firstWhere('name', 'Google Business Profile')['action']);
        $this->assertMatchesRegularExpression('/data-role="google-row"[^>]*data-attention="1"/', $this->page($workspace, $business, $location));
    }

    public function test_google_not_linked_is_neutral_not_an_attention_item_and_not_setup_work(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();

        $section = $this->section($customer, $workspace, $business, $location);

        $this->assertSame(SeoCitationLocationSection::GOOGLE_NOT_LINKED, $section->googleState());
        $this->assertFalse($section->googleNeedsAttention());
        $this->assertNull(collect($section->attentionItems())->firstWhere('name', 'Google Business Profile'));
        $this->assertSame(0, $section->summary()['attention']);
    }

    // -----------------------------------------------------------------
    // B1 on the page — the same address / number written differently is consistent
    // -----------------------------------------------------------------

    /** A Chicago storefront: the main Location by default, a secondary one when $primary is false. */
    private function mainStreetLocation(Business $business, bool $primary = true): BusinessLocation
    {
        $address = [
            'address_line_1' => '123 Main St',
            'city' => 'Chicago',
            'region' => 'IL',
            'postal_code' => '60601',
            'country_code' => 'US',
        ];

        return $primary
            ? $this->makePrimary($this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, $address + ['name' => 'Chicago']))
            : $this->extraLocation($business, 'Chicago', $address + ['service_mode' => \App\Enums\Business\BusinessServiceMode::Storefront, 'public_address' => true]);
    }

    /** `is_primary` is not mass-assignable (the fixtures' flag is ignored), so set it the way the app does: on the row. */
    private function makePrimary(BusinessLocation $location): BusinessLocation
    {
        DB::table('business_locations')->where('id', $location->id)->update(['is_primary' => true]);

        return $location->fresh();
    }

    public function test_a_directory_that_writes_the_address_and_phone_differently_still_matches(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $location = $this->mainStreetLocation($business);
        DB::table('businesses')->where('id', $business->id)->update(['country_code' => 'US']);
        $this->makeCitation($business, $location, $this->directory('bing_places'), [
            'status' => SeoCitationStatus::Listed->value,
            'listed_name' => $business->name,
            'listed_phone' => '(555) 010-1234',
            'listed_address' => '123 Main Street, Chicago, Illinois 60601, USA',
            'last_verified_at' => now()->toDateString(),
        ]);

        $row = collect($this->section($customer, $workspace, $business, $location)->rows)->first(fn ($r) => $r->directory->key === 'bing_places');

        $this->assertSame(SeoNapFieldResult::Consistent, $row->nap['phone']);
        $this->assertSame(SeoNapFieldResult::Consistent, $row->nap['address']);
        $this->assertSame(SeoCitationDisplayState::Accurate, $row->displayState());
        $this->assertSame([], $row->napTally()['differing']);
    }

    public function test_an_address_that_cannot_be_verified_is_neither_a_mismatch_nor_called_accurate(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $location = $this->mainStreetLocation($business);
        $this->makeCitation($business, $location, $this->directory('bing_places'), [
            'status' => SeoCitationStatus::Listed->value,
            'listed_name' => $business->name,
            'listed_address' => '123 Main St, Suite 200, Chicago, IL 60601',
        ]);

        $row = collect($this->section($customer, $workspace, $business, $location)->rows)->first(fn ($r) => $r->directory->key === 'bing_places');

        $this->assertSame(SeoNapFieldResult::NotComparable, $row->nap['address']);
        $this->assertSame(['address'], $row->unverifiedFields());
        $this->assertSame(SeoCitationDisplayState::Listed, $row->displayState(), 'Unable to verify is not "Accurate" and not "Needs attention".');
        $this->assertStringContainsString('could not be verified automatically', $row->helperText());
        $this->assertFalse($row->needsAttention());
    }

    // -----------------------------------------------------------------
    // B13 — the Business phone is not claimed for a secondary Location
    // -----------------------------------------------------------------

    public function test_a_secondary_location_is_not_given_a_false_phone_mismatch(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace] = $this->tenant();
        $primary = $this->makePrimary($this->createLocation($business, true));
        $secondary = $this->mainStreetLocation($business, false);

        foreach ([$primary, $secondary] as $location) {
            $this->makeCitation($business, $location, $this->directory('bing_places'), [
                'status' => SeoCitationStatus::Listed->value,
                'listed_name' => $business->name,
                'listed_phone' => '+14155550000',
            ]);
        }

        $onPrimary = collect($this->section($customer, $workspace, $business, $primary)->rows)->first(fn ($r) => $r->directory->key === 'bing_places');
        $onSecondary = collect($this->section($customer, $workspace, $business, $secondary)->rows)->first(fn ($r) => $r->directory->key === 'bing_places');

        $this->assertSame(SeoNapFieldResult::Mismatch, $onPrimary->nap['phone'], 'The main Location carries the Business phone.');
        $this->assertSame(SeoNapFieldResult::NotComparable, $onSecondary->nap['phone'], 'A secondary Location may list its own number.');
        $this->assertNotContains('phone', $onSecondary->napTally()['differing']);
        $this->assertSame([], $onSecondary->unverifiedFields(), 'A phone that is deliberately not compared is not "could not be verified".');

        $html = $this->page($workspace, $business, $secondary);
        $this->assertStringContainsString('Business phone', $html);
        $this->assertStringContainsString('data-role="phone-not-compared"', $html);
        $this->assertStringNotContainsString('data-role="phone-not-compared"', $this->page($workspace, $business, $primary));
    }

    public function test_a_secondary_locations_google_phone_is_not_compared_with_the_business_phone(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace] = $this->tenant();
        $this->makePrimary($this->createLocation($business, true));
        $secondary = $this->mainStreetLocation($business, false);
        $this->bindGoogleLocation($business, $secondary, $this->activeConnection($business), true, [
            'title' => $business->name, 'phone_primary' => '+14155550000', 'website_uri' => 'https://example.test',
        ]);

        $nap = $this->section($customer, $workspace, $business, $secondary)->googleNap;

        $this->assertSame(SeoNapFieldResult::NotComparable, $nap['phone']);
        $this->assertSame(SeoNapFieldResult::Consistent, $nap['name']);
    }

    // -----------------------------------------------------------------
    // B11 — the Google row names the details a count refers to
    // -----------------------------------------------------------------

    public function test_the_google_row_names_which_details_differ_and_the_count_matches_the_names(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        // Name, phone and website agree; the city and country do not.
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, [
            'title' => $business->name, 'phone_primary' => '+15550101234', 'website_uri' => 'https://example.test',
            'locality' => 'Elsewhere', 'region_code' => 'GB',
        ]);

        $section = $this->section($customer, $workspace, $business, $location);
        $html = $this->page($workspace, $business, $location);

        $this->assertSame(['City', 'Country'], $section->googleOtherDifferences());
        $this->assertFalse($section->googleNeedsAttention(), 'These are not NAP details.');
        $this->assertMatchesRegularExpression('/data-role="google-mismatch-count">2 details differ from Google</', $html);
        $this->assertMatchesRegularExpression('/data-role="google-differing-fields">Differs: City, Country\./', $html);
        $this->assertSame(3, preg_match_all('/data-google-field="[a-z]+" data-result="consistent"/', $html), 'The three shown details all match, and the row says which others differ.');
    }

    public function test_a_shown_difference_is_named_and_counted_once(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['title' => 'Another Name', 'phone_primary' => '+15550101234', 'website_uri' => 'https://example.test']);

        $html = $this->page($workspace, $business, $location);

        $this->assertMatchesRegularExpression('/data-role="google-mismatch-count">1 detail differs from Google</', $html);
        // Name is already named by its own "Name differs" line, so "Differs:" lists only what the three lines cannot.
        $this->assertMatchesRegularExpression('/data-google-field="name" data-result="mismatch"/', $html);
        $this->assertStringNotContainsString('data-role="google-differing-fields"', $html);
    }

    // -----------------------------------------------------------------
    // B6 — authorization edges of directories
    // -----------------------------------------------------------------

    public function test_a_forged_post_cannot_write_a_platform_directory_the_page_does_not_offer(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->setIndustry($business, 'professional_services');

        // Active and in the catalog, but a Photo Booth pick that this Business is not offered.
        $this->putCitation($workspace, $business, $location, 'weddingwire_the_knot')->assertNotFound();
        $this->applicability($workspace, $business, $location, 'weddingwire_the_knot', false)->assertNotFound();
        $this->assertSame(0, SeoCitation::query()->count());

        $this->putCitation($workspace, $business, $location, 'bing_places')->assertSessionHasNoErrors();
        $this->assertSame(1, SeoCitation::query()->count(), 'A core directory is still writable.');
    }

    public function test_a_disabled_niche_recommendation_cannot_be_written_by_a_forged_post(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->putCitation($workspace, $business, $location, 'bark')->assertSessionHasNoErrors();

        \App\Models\SeoNicheCitationRecommendation::query()->whereHas('directory', fn ($q) => $q->where('key', 'bark'))->update(['is_enabled' => false]);

        $this->putCitation($workspace, $business, $location, 'bark')->assertNotFound();
    }

    public function test_another_businesses_custom_directory_is_a_404_on_every_write_route(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->addCustom($workspace, $business, $location, ['name' => 'Private Directory'])->assertSessionHasNoErrors();
        $custom = SeoCitationDirectory::query()->where('business_id', $business->id)->sole();

        [$otherCustomer, $otherBusiness, $otherWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $otherLocation = $this->publicStorefront($otherBusiness, 'Other');
        $this->authenticateAsSeoCustomer($otherCustomer);

        $this->putCitation($otherWorkspace, $otherBusiness, $otherLocation, $custom->key)->assertNotFound();
        $this->applicability($otherWorkspace, $otherBusiness, $otherLocation, $custom->key, false)->assertNotFound();
        $this->renameCustom($otherWorkspace, $otherBusiness, $otherLocation, $custom->key, 'Hijack')->assertNotFound();
        $this->archiveCustom($otherWorkspace, $otherBusiness, $otherLocation, $custom->key)->assertNotFound();

        $fresh = $custom->fresh();
        $this->assertSame('Private Directory', $fresh->name);
        $this->assertTrue($fresh->is_active);
        $this->assertSame(1, SeoCitation::query()->count());
    }

    /**
     * @return array{0: \App\Models\Customer, 1: Business, 2: \App\Models\Workspace, 3: BusinessLocation, 4: BusinessLocation, 5: \App\Models\Customer}
     */
    private function withRestrictedMember(): array
    {
        [$owner, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->publicStorefront($business, 'Hidden Branch');
        $member = $this->selectedScopeMember($workspace, [$granted]);

        return [$owner, $business, $workspace, $granted, $hidden, $member];
    }

    public function test_a_user_restricted_to_one_location_cannot_rename_or_archive_a_directory_shared_by_all_locations(): void
    {
        [$owner, $business, $workspace, $granted, , $member] = $this->withRestrictedMember();
        $this->addCustom($workspace, $business, $granted, ['name' => 'Shared Blog', 'location_scope' => 'all'])->assertSessionHasNoErrors();
        $shared = SeoCitationDirectory::query()->where('business_id', $business->id)->sole();
        $this->assertNull($shared->business_location_id);

        $this->authenticateAsSeoCustomer($member);
        $bag = 'citation_' . $granted->uid . '_' . $shared->key;

        $this->renameCustom($workspace, $business, $granted, $shared->key, 'Renamed By Restricted')->assertSessionHasErrors('directory', null, $bag);
        $this->archiveCustom($workspace, $business, $granted, $shared->key)->assertSessionHasErrors('directory', null, $bag);

        $fresh = $shared->fresh();
        $this->assertSame('Shared Blog', $fresh->name);
        $this->assertTrue($fresh->is_active);

        // The page does not even offer the edit controls for it.
        $html = $this->page($workspace, $business, $granted);
        $this->assertStringContainsString('data-role="drawer-shared-readonly"', $html);
        $this->assertStringNotContainsString('data-role="drawer-custom-edit"', $html);

        // The owner, who reaches every Location, can.
        $this->authenticateAsSeoCustomer($owner);
        $this->renameCustom($workspace, $business, $granted, $shared->key, 'Renamed By Owner')->assertSessionHasNoErrors();
        $this->assertSame('Renamed By Owner', $shared->fresh()->name);
    }

    public function test_a_restricted_user_can_manage_a_directory_scoped_to_their_own_location_but_cannot_add_one_for_all_locations(): void
    {
        [, $business, $workspace, $granted, , $member] = $this->withRestrictedMember();
        $this->authenticateAsSeoCustomer($member);

        $this->addCustom($workspace, $business, $granted, ['name' => 'For Everyone', 'location_scope' => 'all'])
            ->assertSessionHasErrors('location_scope', null, 'citation_' . $granted->uid . '_new_custom');
        $this->assertSame(0, SeoCitationDirectory::query()->where('business_id', $business->id)->count());

        $this->addCustom($workspace, $business, $granted, ['name' => 'Mine Only', 'location_scope' => 'this'])->assertSessionHasNoErrors();
        $mine = SeoCitationDirectory::query()->where('business_id', $business->id)->sole();
        $this->assertSame($granted->id, (int) $mine->business_location_id);

        $this->renameCustom($workspace, $business, $granted, $mine->key, 'Mine Renamed')->assertSessionHasNoErrors();
        $this->assertSame('Mine Renamed', $mine->fresh()->name);

        $html = $this->page($workspace, $business, $granted);
        $this->assertStringNotContainsString('<option value="all"', $html, 'A restricted user is not offered "All my locations".');
    }

    public function test_the_custom_directory_ceiling_counts_only_what_the_actor_can_see(): void
    {
        [$owner, $business, $workspace, $granted, $hidden, $member] = $this->withRestrictedMember();
        config(['seo.citations.max_custom_directories' => 2]);

        $this->addCustom($workspace, $business, $hidden, ['name' => 'Hidden A', 'location_scope' => 'this'])->assertSessionHasNoErrors();
        $this->addCustom($workspace, $business, $hidden, ['name' => 'Hidden B', 'location_scope' => 'this'])->assertSessionHasNoErrors();
        $this->addCustom($workspace, $business, $hidden, ['name' => 'Hidden C', 'location_scope' => 'this'])
            ->assertSessionHasErrors('name', null, 'citation_' . $hidden->uid . '_new_custom');

        // The restricted user sees none of those, so the ceiling says nothing about them.
        $this->authenticateAsSeoCustomer($member);
        $this->addCustom($workspace, $business, $granted, ['name' => 'Visible One', 'location_scope' => 'this'])->assertSessionHasNoErrors();
        $this->addCustom($workspace, $business, $granted, ['name' => 'Visible Two', 'location_scope' => 'this'])->assertSessionHasNoErrors();
        $this->addCustom($workspace, $business, $granted, ['name' => 'Visible Three', 'location_scope' => 'this'])
            ->assertSessionHasErrors('name', null, 'citation_' . $granted->uid . '_new_custom');
    }

    public function test_adding_a_custom_directory_locks_the_business_row_before_it_writes(): void
    {
        [$customer, $business, , $location] = $this->tenant();

        $queries = array_map('strtolower', $this->capturedQueries(fn () => app(SeoCitationManager::class)->createCustomDirectory(
            (int) $customer->user_id,
            $business,
            (string) $location->uid,
            $this->citationInput(['name' => 'Locked Add', 'location_scope' => 'all']),
        )));

        $lock = $directoryInsert = $citationInsert = null;

        foreach ($queries as $index => $sql) {
            if ($lock === null && str_contains($sql, 'from `businesses`') && str_contains($sql, 'for update')) {
                $lock = $index;
            }
            if ($directoryInsert === null && str_starts_with($sql, 'insert into `seo_citation_directories`')) {
                $directoryInsert = $index;
            }
            if ($citationInsert === null && str_starts_with($sql, 'insert into `seo_citations`')) {
                $citationInsert = $index;
            }
        }

        $this->assertNotNull($lock, 'The Business row is locked.');
        $this->assertNotNull($directoryInsert);
        $this->assertNotNull($citationInsert);
        $this->assertLessThan($directoryInsert, $lock, 'The lock comes before the ceiling check and the write.');
        $this->assertLessThan($citationInsert, $directoryInsert);
    }

    public function test_a_failure_saving_the_first_citation_leaves_no_orphan_directory(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $armed = true;
        SeoCitation::creating(function () use (&$armed): void {
            if ($armed) {
                throw new \RuntimeException('simulated failure');
            }
        });

        try {
            app(SeoCitationManager::class)->createCustomDirectory((int) $customer->user_id, $business, (string) $location->uid, $this->citationInput(['name' => 'Orphan?', 'location_scope' => 'all']));
            $this->fail('The simulated failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        } finally {
            $armed = false;
        }

        $this->assertSame(0, SeoCitationDirectory::query()->where('business_id', $business->id)->count(), 'The directory is rolled back with the citation.');
        $this->assertSame(0, SeoCitation::query()->count());
    }

    // -----------------------------------------------------------------
    // B10 — copy and defaults that do not overstate
    // -----------------------------------------------------------------

    public function test_the_page_does_not_claim_business_os_tracks_the_directories_for_you(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $html = $this->page($workspace, $business, $location);

        $this->assertStringNotContainsString('Business OS tracks for you', $html);
        $this->assertStringContainsString('does not read from the directories', $html);
    }

    public function test_a_new_custom_directory_does_not_default_to_listed(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $html = $this->page($workspace, $business, $location);
        $select = $this->between($html, '<select id="custom-status"', '</select>');

        $this->assertMatchesRegularExpression('/<option value="not_started"\s+selected/', $select);
        $this->assertDoesNotMatchRegularExpression('/<option value="listed"\s+selected/', $select);
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "[{$start}] not found.");
        $to = strpos($html, $end, $from);

        return substr($html, $from, ($to === false ? strlen($html) : $to) - $from);
    }
}
