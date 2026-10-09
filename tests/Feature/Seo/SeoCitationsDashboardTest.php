<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\Seo\SeoCitationStatus;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoCitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * Citations dashboard redesign — what the page SHOWS, derived only from stored
 * rows, the read-time NAP comparison and the GBP read model. The write path,
 * link safety and ACL rules stay covered by SeoCitationsTest /
 * SeoCitationsBoundaryTest; this file pins the dashboard behaviour:
 * canonical profile, summary cards, per-field comparison, honest status
 * vocabulary, Google row, one-Location-at-a-time isolation.
 *
 * Fixture business: name from the tenant fixture, phone +15550101234, website
 * https://example.test. The public storefront's canonical address is
 * "12 High Street, Springfield, IL, 62701, US".
 */
class SeoCitationsDashboardTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

    private const ADDRESS = '12 High Street, Springfield, IL, 62701, US';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bypassCitationEntitlementForTest();
    }

    /**
     * @return array{0: \App\Models\Customer, 1: Business, 2: \App\Models\Workspace, 3: BusinessLocation}
     */
    private function tenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->publicStorefront($business, 'Main Storefront');

        $this->authenticateAsSeoCustomer($customer);

        return [$customer, $business, $workspace, $location];
    }

    private function page(\App\Models\Workspace $workspace, Business $business, ?BusinessLocation $location = null): string
    {
        $url = $this->citationsUrl($workspace, $business) . ($location ? '?location=' . $location->uid : '');

        return $this->get($url)->assertOk()->getContent();
    }

    /** The data-state of one directory row. */
    private function rowState(string $html, string $directory): string
    {
        $this->assertSame(1, preg_match('/data-role="citation-row" data-directory="' . $directory . '" data-status="[a-z_]+" data-state="([a-z_]+)"/', $html, $m), "Row for [{$directory}] not found.");

        return $m[1];
    }

    /** One directory's ROW markup (not its drawer). */
    private function rowHtml(string $html, string $directory): string
    {
        $start = strpos($html, 'data-role="citation-row" data-directory="' . $directory . '"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, 'data-role="citation-drawer"', $start) - $start);
    }

    /** The data-result of one field in one directory's ROW (not its drawer). */
    private function rowFieldResult(string $html, string $directory, string $field): string
    {
        $start = strpos($html, 'data-role="citation-row" data-directory="' . $directory . '"');
        $this->assertNotFalse($start);
        $row = substr($html, $start, strpos($html, 'data-role="citation-drawer"', $start) - $start);
        $this->assertSame(1, preg_match('/data-field="' . $field . '" data-result="([a-z_]+)"/', $row, $m));

        return $m[1];
    }

    private function stat(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('/data-stat="' . $key . '">(.*?)<\/div>/s', $html, $m), "Stat [{$key}] not found.");

        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }

    // -----------------------------------------------------------------
    // Canonical business profile
    // -----------------------------------------------------------------

    public function test_the_canonical_business_profile_is_shown_for_the_selected_location(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $html = $this->page($workspace, $business, $location);

        $this->assertStringContainsString('data-section="business-profile"', $html);
        $this->assertStringContainsString('Business information', $html);
        $this->assertMatchesRegularExpression('/data-canonical="name">\s*' . preg_quote($business->name, '/') . '/', $html);
        $this->assertMatchesRegularExpression('/data-canonical="phone">\s*\+15550101234/', $html);
        $this->assertMatchesRegularExpression('/data-canonical="address">\s*' . preg_quote(self::ADDRESS, '/') . '/', $html);
        $this->assertStringContainsString('https://example.test', $html);
    }

    public function test_the_header_subtitle_and_manual_tracking_note_are_honest(): void
    {
        [, $business, $workspace] = $this->tenant();

        $html = $this->page($workspace, $business);

        $this->assertStringContainsString('Keep your business information accurate and consistent across the places customers search.', $html);
        $this->assertStringContainsString('Manually tracked', $html);

        // Nothing overstates automation.
        foreach (['Synced', 'Live', 'Real-time', 'Realtime', 'Automatically monitored', 'Auto-synced'] as $claim) {
            $this->assertStringNotContainsString($claim, $html, "[{$claim}] would overstate what manual tracking does.");
        }
    }

    // -----------------------------------------------------------------
    // Missing values are "Not checked", never a mismatch
    // -----------------------------------------------------------------

    public function test_a_directory_with_no_recorded_values_is_not_started_and_every_field_is_not_checked(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $html = $this->page($workspace, $business, $location);

        foreach (['bing_places', 'facebook_pages'] as $directory) {
            $this->assertSame('not_started', $this->rowState($html, $directory));

            // Nothing recorded: the row says so once instead of repeating "Not checked" per field.
            $this->assertStringContainsString('data-role="nap-empty"', $this->rowHtml($html, $directory));
        }

        $this->assertStringContainsString('Not checked', $html);
        $this->assertStringContainsString('No listing details recorded yet.', $html);
        $this->assertStringNotContainsString('data-result="mismatch"', $html);
    }

    public function test_missing_values_are_never_marked_as_a_mismatch(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        // Only the name is recorded, and it matches.
        $this->makeCitation($business, $location, null, ['listed_name' => $business->name]);

        $html = $this->page($workspace, $business, $location);

        $this->assertSame('consistent', $this->rowFieldResult($html, 'bing_places', 'name'));
        $this->assertSame('unchecked', $this->rowFieldResult($html, 'bing_places', 'phone'));
        $this->assertSame('unchecked', $this->rowFieldResult($html, 'bing_places', 'address'));
        $this->assertSame('listed', $this->rowState($html, 'bing_places'), 'Partly checked is "Listed", not "Needs attention".');
        $this->assertStringNotContainsString('data-result="mismatch"', $html);
        // 1 of the 1 field that has BOTH values; the two unchecked fields are not in the denominator.
        $this->assertStringContainsString('1 of 1 match', $this->stat($html, 'nap-consistency'));
    }

    // -----------------------------------------------------------------
    // Known values and actual mismatch detection
    // -----------------------------------------------------------------

    public function test_known_listing_values_are_rendered_and_compared_per_field(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, null, [
            'listed_name' => $business->name,
            'listed_phone' => '+1 (555) 010-1234',
            'listed_address' => '99 Wrong Road, Elsewhere',
        ]);

        $html = $this->page($workspace, $business, $location);

        $this->assertSame('consistent', $this->rowFieldResult($html, 'bing_places', 'name'));
        $this->assertSame('consistent', $this->rowFieldResult($html, 'bing_places', 'phone'), 'Phone formatting must not cause a false mismatch.');
        $this->assertSame('mismatch', $this->rowFieldResult($html, 'bing_places', 'address'));
        $this->assertStringContainsString('99 Wrong Road, Elsewhere', $html);
        $this->assertStringContainsString('+1 (555) 010-1234', $html);
        $this->assertSame('needs_attention', $this->rowState($html, 'bing_places'));
        $this->assertStringContainsString('Address differs from your business profile.', $html);
        $this->assertStringContainsString('2 of 3', $this->stat($html, 'nap-consistency'));
    }

    public function test_a_phone_mismatch_is_detected_and_the_stored_status_is_not_changed(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, null, [
            'status' => SeoCitationStatus::Listed->value,
            'listed_name' => $business->name,
            'listed_phone' => '+19998887777',
        ]);

        $html = $this->page($workspace, $business, $location);

        $this->assertSame('mismatch', $this->rowFieldResult($html, 'bing_places', 'phone'));
        $this->assertSame('needs_attention', $this->rowState($html, 'bing_places'));
        $this->assertStringContainsString('Phone differs from your business profile.', $html);
        $this->assertSame(SeoCitationStatus::Listed, SeoCitation::query()->sole()->status, 'The comparison is display only.');
    }

    public function test_every_comparable_field_matching_is_accurate(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, null, [
            'status' => SeoCitationStatus::Listed->value,
            'listed_name' => $business->name,
            'listed_phone' => '+15550101234',
            'listed_address' => self::ADDRESS,
            'last_verified_at' => '2026-09-01',
        ]);

        $html = $this->page($workspace, $business, $location);

        $this->assertSame('accurate', $this->rowState($html, 'bing_places'));
        $this->assertStringContainsString('Sep 1, 2026', $html);
        $this->assertStringContainsString('3 of 3', $this->stat($html, 'nap-consistency'));
    }

    public function test_summary_cards_count_only_stored_rows(): void
    {
        $this->bindFakeGoogleClient();
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, $this->directory('bing_places'), [
            'listed_name' => $business->name,
            'listed_phone' => '+15550101234',
            'listed_address' => self::ADDRESS,
        ]);

        $html = $this->page($workspace, $business, $location);

        // Bing is listed and accurate; every other directory is untouched and
        // Google is not linked. The actor holds the GBP permission, so Google is
        // a tracked listing too.
        // 15 offered directories (10 core + 5 Photo Booth) + the Google row.
        $this->assertSame('Listings tracked 16 Directories for this location', $this->stat($html, 'tracked'));
        $this->assertSame('Completed 1 of 16 Listed with details recorded, or connected', $this->stat($html, 'linked'));
        // Untouched directories are NOT problems: "Needs attention" counts real
        // problems only, and "Needs setup" counts the unfinished work.
        $this->assertSame('Needs attention 0 Differs, marked for correction, or due a review', $this->stat($html, 'attention'));
        $this->assertMatchesRegularExpression('/data-progress="setup">14</', $html);
    }

    // -----------------------------------------------------------------
    // Google Business Profile
    // -----------------------------------------------------------------

    public function test_google_not_linked_shows_connect_actions_but_is_neutral_and_never_an_attention_item(): void
    {
        $this->bindFakeGoogleClient();
        [, $business, $workspace, $location] = $this->tenant();

        $html = $this->page($workspace, $business, $location);

        $this->assertStringContainsString('data-google-state="not_linked"', $html);
        $this->assertStringContainsString('Connect Google Business Profile', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]), $html);
        // A Business may have no Google listing and cannot mark the row not applicable, so it
        // is a neutral "Not linked": never a "What to do next" item and never counted as attention.
        $this->assertStringNotContainsString('data-action="google"', $html);
        $this->assertMatchesRegularExpression('/data-role="google-row"[^>]*data-attention="0"/', $html);
        $this->assertMatchesRegularExpression('/data-role="google-state">.*?Not linked/s', $html);
    }

    public function test_google_connected_shows_connected_and_no_google_action_item(): void
    {
        $this->bindFakeGoogleClient();
        [, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['title' => $business->name]);

        $html = $this->page($workspace, $business, $location);

        $this->assertStringContainsString('data-google-state="connected"', $html);
        $this->assertMatchesRegularExpression('/data-role="google-state">.*?Connected/s', $html);
        $this->assertStringNotContainsString('data-action="google"', $html);
        $this->assertStringNotContainsString('data-role="header-connect-google"', $html);
        $this->assertSame(0, SeoCitation::query()->count(), 'The Google row is synthetic and never stored.');
    }

    public function test_a_lost_google_connection_is_a_reconnect_not_a_connected_state(): void
    {
        $this->bindFakeGoogleClient();
        [, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business, ['state' => GoogleConnectionState::Revoked]));

        $html = $this->page($workspace, $business, $location);

        $this->assertStringContainsString('data-google-state="connection_lost"', $html);
        $this->assertStringContainsString('Reconnect', $html);
    }

    public function test_google_shows_no_fabricated_metrics(): void
    {
        $this->bindFakeGoogleClient();
        [, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business));

        $html = strtolower($this->page($workspace, $business, $location));

        foreach (['impressions', 'ranking', 'rank #', 'review count', 'visibility score', 'listing health'] as $invented) {
            $this->assertStringNotContainsString($invented, $html);
        }
    }

    // -----------------------------------------------------------------
    // Listing URL / claim URL actions
    // -----------------------------------------------------------------

    public function test_a_known_listing_url_gets_an_open_listing_action_and_an_unsafe_one_does_not(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, $this->directory('bing_places'), ['listing_url' => 'https://www.example-directory.com/biz/acme']);
        // Created directly (not through the writer): an unsafe stored value must still never render.
        $this->makeCitation($business, $location, $this->directory('facebook_pages'), ['listing_url' => 'javascript:alert(1)']);

        $html = $this->page($workspace, $business, $location);

        $this->assertSame(1, substr_count($html, 'data-role="listing-link"'));
        $this->assertMatchesRegularExpression('/<a [^>]*href="https:\/\/www\.example-directory\.com\/biz\/acme"[^>]*target="_blank"[^>]*rel="noopener noreferrer nofollow"[^>]*data-role="listing-link"/', $html);
        $this->assertStringContainsString('Open listing', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        // Claim/update is the directory's own page, separate from the listing link.
        // 8 core + 5 Photo Booth directories carry a verified claim link.
        $this->assertSame(13, substr_count($html, 'data-role="drawer-claim-link"'));
        $this->assertStringContainsString('Claim or update', $html);
    }

    // -----------------------------------------------------------------
    // Drawer
    // -----------------------------------------------------------------

    public function test_each_directory_has_a_drawer_whose_form_posts_to_the_one_write_route(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $html = $this->page($workspace, $business, $location);

        foreach (['bing_places', 'facebook_pages'] as $directory) {
            $this->assertStringContainsString('id="citation-drawer-' . $directory . '"', $html);
            $this->assertStringContainsString('data-bs-target="#citation-drawer-' . $directory . '"', $html);
            $this->assertStringContainsString(
                'action="' . $this->citationUpdateUrl($workspace, $business, (string) $location->uid, $directory) . '"',
                $html,
            );
        }

        $this->assertStringContainsString('Save details', $html);
        $this->assertStringNotContainsString('<summary class="text-caption">Edit', $html, 'The tiny Edit toggle is gone.');
    }

    public function test_a_rejected_save_reopens_that_directorys_drawer_with_its_error(): void
    {
        [, $business, $workspace] = $this->tenant();
        $private = $this->privateLocation($business);

        $back = $this->citationsUrl($workspace, $business) . '?location=' . $private->uid;

        $this->from($back)
            ->put($this->citationUpdateUrl($workspace, $business, (string) $private->uid, 'bing_places'), $this->citationInput(['listed_address' => '99 Secret Lane', 'listed_name' => 'Rejected Name']))
            ->assertRedirect($back)
            ->assertSessionHasErrors('listed_address', null, 'citation_' . $private->uid . '_bing_places');

        $html = $this->get($back)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-open-on-load="1"'), 'Only the failed directory\'s drawer reopens.');
        $this->assertMatchesRegularExpression('/id="citation-drawer-bing_places"[^>]*data-open-on-load/', $html);
        $this->assertStringContainsString('data-role="citation-error"', $html);
        $this->assertStringContainsString('Rejected Name', $html, 'The rejected non-address input is kept for correction.');
        $this->assertStringNotContainsString('99 Secret Lane', $html, 'A refused address is never echoed.');
    }

    // -----------------------------------------------------------------
    // Locations: one at a time, nothing combined, fail closed
    // -----------------------------------------------------------------

    public function test_the_selected_location_is_the_only_one_whose_data_is_rendered(): void
    {
        [, $business, $workspace, $first] = $this->tenant();
        $second = $this->publicStorefront($business, 'Second Branch');
        $this->makeCitation($business, $first, null, ['listed_name' => 'First-Branch-Listing', 'notes' => 'first-branch-note']);
        $this->makeCitation($business, $second, null, ['listed_name' => 'Second-Branch-Listing', 'notes' => 'second-branch-note']);

        $secondHtml = $this->page($workspace, $business, $second);
        $this->assertStringContainsString('Second-Branch-Listing', $secondHtml);
        $this->assertStringNotContainsString('First-Branch-Listing', $secondHtml);
        $this->assertStringNotContainsString('first-branch-note', $secondHtml);
        $this->assertStringContainsString('data-location="' . $second->uid . '"', $secondHtml);
        $this->assertStringNotContainsString('data-location="' . $first->uid . '"', $secondHtml);

        $firstHtml = $this->page($workspace, $business, $first);
        $this->assertStringContainsString('First-Branch-Listing', $firstHtml);
        $this->assertStringNotContainsString('Second-Branch-Listing', $firstHtml);
        $this->assertStringNotContainsString('second-branch-note', $firstHtml);
    }

    public function test_the_comparison_source_is_the_selected_locations_canonical_address(): void
    {
        [, $business, $workspace, $first] = $this->tenant();
        $second = $this->extraLocation($business, 'Uptown', [
            'service_mode' => \App\Enums\Business\BusinessServiceMode::Storefront,
            'public_address' => true,
            'address_line_1' => '500 Other Avenue',
            'city' => 'Chicago',
            'region' => 'IL',
            'postal_code' => '60601',
            'country_code' => 'US',
        ]);
        // The same recorded address is consistent for the first Location and a mismatch for the second.
        $this->makeCitation($business, $first, null, ['listed_address' => self::ADDRESS]);
        $this->makeCitation($business, $second, null, ['listed_address' => self::ADDRESS]);

        $this->assertSame('consistent', $this->rowFieldResult($this->page($workspace, $business, $first), 'bing_places', 'address'));

        $secondHtml = $this->page($workspace, $business, $second);
        $this->assertSame('mismatch', $this->rowFieldResult($secondHtml, 'bing_places', 'address'));
        $this->assertStringContainsString('500 Other Avenue, Chicago, IL, 60601, US', $secondHtml);
    }

    public function test_the_default_selection_is_an_accessible_location_and_a_switcher_lists_only_accessible_ones(): void
    {
        [, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->publicStorefront($business, 'Hidden Branch');
        $this->makeCitation($business, $hidden, null, ['listed_name' => 'Hidden-Listing', 'notes' => 'hidden-note']);

        $this->authenticateAsSeoCustomer($this->selectedScopeMember($workspace, [$granted]));

        $html = $this->page($workspace, $business);

        $this->assertStringContainsString('data-location="' . $granted->uid . '"', $html);
        $this->assertStringNotContainsString('Hidden Branch', $html);
        $this->assertStringNotContainsString($hidden->uid, $html);
        $this->assertStringNotContainsString('Hidden-Listing', $html);
        $this->assertStringNotContainsString('data-role="location-switcher"', $html, 'One accessible Location: no switcher.');
    }

    public function test_a_forged_or_inaccessible_location_parameter_is_a_404_and_leaks_nothing(): void
    {
        [, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->publicStorefront($business, 'Hidden Branch');
        $this->makeCitation($business, $hidden, null, ['listed_name' => 'Hidden-Listing']);
        [, $foreignBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $foreign = $this->publicStorefront($foreignBusiness, 'Foreign Branch');

        $this->authenticateAsSeoCustomer($this->selectedScopeMember($workspace, [$granted]));
        $base = $this->citationsUrl($workspace, $business);

        foreach ([(string) Str::uuid(), (string) $hidden->uid, (string) $foreign->uid, 'not-a-uid', '1'] as $forged) {
            $response = $this->get($base . '?location=' . $forged);
            $response->assertNotFound();
            $this->assertStringNotContainsString('Hidden-Listing', $response->getContent());
        }

        $this->get($base . '?location[]=' . $granted->uid)->assertNotFound();
    }

    public function test_a_foreign_business_is_still_a_404_with_a_valid_looking_location(): void
    {
        [, $business, $workspace] = $this->tenant();
        [, $foreignBusiness, $foreignWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $foreign = $this->publicStorefront($foreignBusiness, 'Foreign Branch');

        $this->get($this->citationsUrl($foreignWorkspace, $foreignBusiness) . '?location=' . $foreign->uid)->assertNotFound();
        $this->get($this->citationsUrl($workspace, $foreignBusiness) . '?location=' . $foreign->uid)->assertNotFound();
    }

    public function test_a_business_with_several_locations_renders_a_switcher_with_the_selection_marked(): void
    {
        [, $business, $workspace, $first] = $this->tenant();
        $second = $this->publicStorefront($business, 'Second Branch');

        $html = $this->page($workspace, $business, $second);

        $this->assertStringContainsString('data-role="location-switcher"', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $second->uid . '" selected>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="' . $first->uid . '" selected>/', $html);
    }

    // -----------------------------------------------------------------
    // Empty state
    // -----------------------------------------------------------------

    public function test_a_member_with_no_accessible_location_sees_a_polished_empty_state(): void
    {
        [, $business, $workspace] = $this->tenant();
        $this->authenticateAsSeoCustomer($this->selectedScopeMember($workspace, []));

        $html = $this->page($workspace, $business);

        $this->assertStringContainsString('data-role="citation-empty"', $html);
        $this->assertStringContainsString('Track how your business information appears across important directories.', $html);
        $this->assertStringNotContainsString('data-role="citation-summary"', $html);
        $this->assertStringNotContainsString('data-role="citation-row"', $html);
    }

    // -----------------------------------------------------------------
    // Read-only: rendering writes nothing
    // -----------------------------------------------------------------

    public function test_rendering_the_dashboard_writes_nothing(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, null, ['listed_name' => 'Totally Different Name']);
        $before = $this->dbFingerprint(['seo_citations', 'businesses', 'business_locations']);

        $this->page($workspace, $business, $location);

        $this->assertSame($before, $this->dbFingerprint(['seo_citations', 'businesses', 'business_locations']));
    }
}
