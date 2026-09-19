<?php

namespace Tests\Feature\Seo;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoCitationStatus;
use App\Exceptions\Seo\SeoCitationNotFoundException;
use App\Exceptions\Seo\SeoCitationRefusedException;
use App\Library\Seo\SeoCitationManager;
use App\Library\Seo\SeoLinkSafety;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoCitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice 18E (§8.5, §15.E, §16 T-SEO-TEN / T-SEO-LOC) — the
 * Citations surface behind the (bypassed, test-only) entitlement step:
 * the private-address write refusal, link safety, no outbound fetch, no
 * automatic status change, per-Location ACL, and the GBP synthetic row.
 */
class SeoCitationsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function putCitation(\App\Models\Workspace $workspace, Business $business, BusinessLocation $location, string $key = 'bing_places', array $overrides = [])
    {
        $url = $this->citationUpdateUrl($workspace, $business, (string) $location->uid, $key);

        return $this->from($this->citationsUrl($workspace, $business))->put($url, $this->citationInput($overrides));
    }

    private function bag(BusinessLocation $location, string $key = 'bing_places'): string
    {
        return 'citation_' . $location->uid . '_' . $key;
    }

    private function archive(BusinessLocation $location): void
    {
        DB::table('business_locations')->where('id', $location->id)->update([
            'lifecycle_state' => BusinessLocationLifecycleState::Archived->value,
            'archived_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // Reads and the basic write
    // -----------------------------------------------------------------

    public function test_the_page_lists_each_accessible_location_with_the_seeded_directories(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $html = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Main Storefront', $html);
        $this->assertStringContainsString('data-location="' . $location->uid . '"', $html);
        $this->assertStringContainsString('data-directory="bing_places"', $html);
        $this->assertStringContainsString('data-directory="facebook_pages"', $html);
        $this->assertStringContainsString('Bing Places for Business', $html);
    }

    public function test_a_first_save_creates_one_user_asserted_citation(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();

        $this->putCitation($workspace, $business, $location, 'bing_places', ['listed_address' => '12 High Street, Springfield, IL, 62701, US'])
            ->assertRedirect($this->citationsUrl($workspace, $business))
            ->assertSessionHasNoErrors();

        $citation = SeoCitation::query()->sole();

        $this->assertSame($business->id, $citation->business_id);
        $this->assertSame($location->id, $citation->business_location_id);
        $this->assertSame(SeoCitationStatus::Listed, $citation->status);
        $this->assertSame('https://www.example-directory.com/biz/acme', $citation->listing_url);
        $this->assertSame('Acme Plumbing', $citation->listed_name);
        $this->assertSame('12 High Street, Springfield, IL, 62701, US', $citation->listed_address);
        $this->assertSame('2026-09-01', $citation->last_verified_at->format('Y-m-d'));
        $this->assertSame('user_asserted', $citation->verification_source);
        $this->assertSame($customer->user_id, $citation->updated_by_user_id);
    }

    public function test_saving_again_updates_the_same_row_and_never_duplicates(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $this->putCitation($workspace, $business, $location, 'bing_places', ['status' => 'in_progress', 'listed_name' => 'Old']);
        $this->putCitation($workspace, $business, $location, 'bing_places', ['status' => 'listed', 'listed_name' => 'New']);

        $this->assertSame(1, SeoCitation::query()->count());
        $this->assertSame('New', SeoCitation::query()->sole()->listed_name);
        $this->assertSame(SeoCitationStatus::Listed, SeoCitation::query()->sole()->status);
    }

    public function test_the_manager_recovers_from_a_lost_insert_race_by_updating(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $manager = app(SeoCitationManager::class);

        $first = $manager->save($customer->user_id, $business, (string) $location->uid, 'bing_places', $this->citationInput(['listed_name' => 'A']));
        $second = $manager->save($customer->user_id, $business, (string) $location->uid, 'bing_places', $this->citationInput(['listed_name' => 'B']));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SeoCitation::query()->count());
    }

    // -----------------------------------------------------------------
    // Private-address invariant (write refusal)
    // -----------------------------------------------------------------

    public function test_a_listed_address_is_refused_for_a_location_that_does_not_publish_one(): void
    {
        [, $business, $workspace] = $this->tenant();
        $private = $this->privateLocation($business);

        $response = $this->putCitation($workspace, $business, $private, 'bing_places', ['listed_address' => '99 Secret Lane, Springfield']);

        $response->assertSessionHasErrors('listed_address', null, $this->bag($private));
        $this->assertSame(0, SeoCitation::query()->count(), 'A refused write must persist nothing at all — not even the other fields.');
    }

    public function test_the_refused_address_is_never_echoed_into_the_session_or_the_page(): void
    {
        [, $business, $workspace] = $this->tenant();
        $private = $this->privateLocation($business);

        $this->putCitation($workspace, $business, $private, 'bing_places', ['listed_address' => '99 Secret Lane, Springfield']);

        $this->assertNull(session()->getOldInput('listed_address'));
        $this->assertStringNotContainsString('Secret Lane', json_encode(session()->all()));

        $html = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringNotContainsString('Secret Lane', $html);
    }

    /**
     * @return array<string, array{0: bool, 1: \App\Enums\Business\BusinessServiceMode}>
     */
    public static function refusingLocationShapes(): array
    {
        return [
            'public_address off' => [false, \App\Enums\Business\BusinessServiceMode::Storefront],
            'service-area mode' => [true, \App\Enums\Business\BusinessServiceMode::ServiceArea],
            'online mode' => [true, \App\Enums\Business\BusinessServiceMode::Online],
            'hybrid without consent' => [false, \App\Enums\Business\BusinessServiceMode::Hybrid],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusingLocationShapes')]
    public function test_every_shape_the_predicate_denies_is_refused_at_the_manager(bool $public, \App\Enums\Business\BusinessServiceMode $mode): void
    {
        [$customer, $business] = $this->tenant();
        $location = $this->extraLocation($business, 'Denied', ['public_address' => $public, 'service_mode' => $mode]);

        try {
            app(SeoCitationManager::class)->save($customer->user_id, $business, (string) $location->uid, 'bing_places', $this->citationInput(['listed_address' => '1 Any Street']));
            $this->fail('The write must be refused.');
        } catch (SeoCitationRefusedException $e) {
            $this->assertSame('listed_address', $e->field);
            $this->assertStringNotContainsString('Any Street', $e->getMessage());
        }

        $this->assertSame(0, SeoCitation::query()->count());
    }

    public function test_a_hybrid_location_with_consent_may_record_an_address(): void
    {
        [$customer, $business] = $this->tenant();
        $location = $this->extraLocation($business, 'Hybrid', ['public_address' => true, 'service_mode' => \App\Enums\Business\BusinessServiceMode::Hybrid]);

        app(SeoCitationManager::class)->save($customer->user_id, $business, (string) $location->uid, 'bing_places', $this->citationInput(['listed_address' => '1 Any Street']));

        $this->assertSame('1 Any Street', SeoCitation::query()->sole()->listed_address);
    }

    public function test_a_private_location_can_still_be_tracked_without_an_address_and_the_column_stays_null(): void
    {
        [, $business, $workspace] = $this->tenant();
        $private = $this->privateLocation($business);

        $this->putCitation($workspace, $business, $private, 'bing_places', ['listed_address' => ''])->assertSessionHasNoErrors();

        $this->assertNull(SeoCitation::query()->sole()->listed_address);
    }

    public function test_an_address_stored_before_the_location_became_private_is_hidden_and_cleared_on_the_next_write(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, null, ['listed_address' => '12 High Street, Springfield, IL, 62701, US']);

        // Owner turns the address off.
        DB::table('business_locations')->where('id', $location->id)->update(['public_address' => false]);

        $html = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringNotContainsString('12 High Street', $html, 'A stored address must not be rendered once the Location is not permitted to expose it.');

        $this->putCitation($workspace, $business, $location, 'bing_places', ['listed_address' => ''])->assertSessionHasNoErrors();
        $this->assertNull(SeoCitation::query()->sole()->listed_address);
    }

    public function test_the_edit_form_offers_no_address_field_for_a_location_that_does_not_publish_one(): void
    {
        [, $business, $workspace] = $this->tenant();
        $this->privateLocation($business);

        $html = $this->get($this->citationsUrl($workspace, $business))->getContent();

        // The permitted storefront has the field; the private location's
        // section must not. Two Locations x two directories, one with fields.
        $this->assertSame(2, substr_count($html, 'name="listed_address"'), 'Only the permitted Location (2 directories) may offer an address input.');
        $this->assertStringContainsString('data-role="address-withheld"', $html);
    }

    // -----------------------------------------------------------------
    // NAP is display-only: never changes a status
    // -----------------------------------------------------------------

    public function test_a_nap_mismatch_never_changes_a_status_or_writes_anything(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, null, [
            'status' => SeoCitationStatus::Listed->value,
            'listed_name' => 'Totally Different Name',
            'listed_phone' => '+19998887777',
        ]);
        $before = $this->dbFingerprint(['seo_citations']);

        $html = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-field="name" data-result="mismatch"', $html);
        $this->assertStringContainsString('data-field="phone" data-result="mismatch"', $html);
        $this->assertSame(SeoCitationStatus::Listed, SeoCitation::query()->sole()->status);
        $this->assertSame($before, $this->dbFingerprint(['seo_citations']), 'Reading a mismatch must write nothing.');
    }

    public function test_a_save_stores_exactly_the_status_the_user_chose_whatever_the_comparison_says(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        // A mismatching name, saved as "listed": stays listed.
        $this->putCitation($workspace, $business, $location, 'bing_places', ['status' => 'listed', 'listed_name' => 'Wrong Name']);
        $this->assertSame(SeoCitationStatus::Listed, SeoCitation::query()->sole()->status);

        // A perfectly consistent name, saved as "needs_correction": stays that.
        $this->putCitation($workspace, $business, $location, 'bing_places', ['status' => 'needs_correction', 'listed_name' => $business->name]);
        $this->assertSame(SeoCitationStatus::NeedsCorrection, SeoCitation::query()->sole()->status);
    }

    public function test_the_canonical_business_details_changing_never_touches_stored_citations(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, null, ['listed_name' => $business->name, 'status' => SeoCitationStatus::Listed->value]);
        $before = $this->dbFingerprint(['seo_citations']);

        DB::table('businesses')->where('id', $business->id)->update(['name' => 'Renamed Business']);

        $html = $this->get($this->citationsUrl($workspace, $business))->getContent();

        $this->assertStringContainsString('data-field="name" data-result="mismatch"', $html);
        $this->assertSame($before, $this->dbFingerprint(['seo_citations']));
    }

    // -----------------------------------------------------------------
    // No outbound fetch of listing_url — ever
    // -----------------------------------------------------------------

    public function test_no_outbound_http_request_is_made_reading_or_saving_a_listing_url(): void
    {
        Http::fake();
        $google = $this->bindFakeGoogleClient();
        [, $business, $workspace, $location] = $this->tenant();

        $this->putCitation($workspace, $business, $location, 'bing_places', ['listing_url' => 'https://www.example-directory.com/biz/acme']);
        $this->putCitation($workspace, $business, $location, 'facebook_pages', ['listing_url' => 'https://www.facebook.com/acme']);
        $this->get($this->citationsUrl($workspace, $business))->assertOk();

        Http::assertNothingSent();
        $this->assertSame([], $google->calls, 'The page must make no Google provider call either.');
    }

    // -----------------------------------------------------------------
    // Link safety: https only, contracted rel
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeListingUrls(): array
    {
        return [
            'http' => ['http://www.example-directory.com/biz/acme'],
            'javascript' => ['javascript:alert(document.cookie)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'protocol relative' => ['//www.example-directory.com/x'],
            'userinfo' => ['https://www.facebook.com@evil.example/x'],
            'bare host' => ['www.example-directory.com/biz'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeListingUrls')]
    public function test_an_unsafe_listing_url_is_refused_and_nothing_is_stored(string $url): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $this->putCitation($workspace, $business, $location, 'bing_places', ['listing_url' => $url])
            ->assertSessionHasErrors('listing_url', null, $this->bag($location));

        $this->assertSame(0, SeoCitation::query()->count());
    }

    public function test_only_a_safe_https_listing_url_renders_as_a_link_and_carries_the_contracted_rel(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $second = $this->publicStorefront($business, 'Second');

        // One safe row, and three rows an outside writer put in the table.
        $this->makeCitation($business, $location, $this->directory('bing_places'), ['listing_url' => 'https://www.example-directory.com/biz/acme']);
        $this->makeCitation($business, $location, $this->directory('facebook_pages'), ['listing_url' => 'javascript:alert(1)']);
        $this->makeCitation($business, $second, $this->directory('bing_places'), ['listing_url' => 'http://insecure.example/x']);
        $this->makeCitation($business, $second, $this->directory('facebook_pages'), ['listing_url' => 'https://a@evil.example/x']);

        $html = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-role="listing-link"'), 'Only the one safe https listing URL may be rendered as a link.');
        $this->assertStringContainsString('href="https://www.example-directory.com/biz/acme"', $html);

        foreach (['javascript:alert', 'http://insecure.example', 'evil.example'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $html, "[{$unsafe}] must not appear anywhere in the page.");
        }
    }

    public function test_every_external_link_opens_safely_with_the_contracted_rel(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, $this->directory('bing_places'), ['listing_url' => 'https://www.example-directory.com/biz/acme']);

        $html = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();

        preg_match_all('/<a\b[^>]*target="_blank"[^>]*>/i', $html, $matches);

        // 1 listing link + 2 directory claim links.
        $this->assertCount(3, $matches[0]);

        foreach ($matches[0] as $tag) {
            $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $tag);
            $this->assertMatchesRegularExpression('/href="https:\/\//', $tag);
        }
    }

    public function test_user_supplied_text_is_escaped_never_rendered_as_markup(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, null, [
            'listed_name' => '<script>alert("n")</script>',
            'listed_phone' => '"><img src=x onerror=alert(1)>',
            'notes' => '<b onmouseover=alert(2)>x</b>',
        ]);

        $html = $this->get($this->citationsUrl($workspace, $business))->getContent();

        $this->assertStringNotContainsString('<script>alert("n")', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b onmouseover', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // -----------------------------------------------------------------
    // Other write validation
    // -----------------------------------------------------------------

    public function test_invalid_status_and_dates_and_lengths_are_refused(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $this->putCitation($workspace, $business, $location, 'bing_places', ['status' => 'verified'])->assertSessionHasErrors('status');
        $this->putCitation($workspace, $business, $location, 'bing_places', ['last_verified_at' => '01/09/2026'])->assertSessionHasErrors('last_verified_at');
        $this->putCitation($workspace, $business, $location, 'bing_places', ['notes' => str_repeat('x', 501)])->assertSessionHasErrors('notes');
        $this->putCitation($workspace, $business, $location, 'bing_places', ['listed_phone' => str_repeat('1', 51)])->assertSessionHasErrors('listed_phone');

        $this->assertSame(0, SeoCitation::query()->count());
    }

    public function test_a_future_verification_date_is_refused_at_the_manager(): void
    {
        [$customer, $business, , $location] = $this->tenant();

        $this->expectException(SeoCitationRefusedException::class);

        app(SeoCitationManager::class)->save($customer->user_id, $business, (string) $location->uid, 'bing_places', $this->citationInput(['last_verified_at' => now()->addDays(30)->toDateString()]));
    }

    public function test_the_manager_re_enforces_link_safety_itself(): void
    {
        [$customer, $business, , $location] = $this->tenant();

        $this->expectException(SeoCitationRefusedException::class);

        // Bypasses the controller's shape validation entirely.
        app(SeoCitationManager::class)->save($customer->user_id, $business, (string) $location->uid, 'bing_places', $this->citationInput(['listing_url' => 'javascript:alert(1)']));
    }

    // -----------------------------------------------------------------
    // Per-Location ACL
    // -----------------------------------------------------------------

    public function test_a_selected_scope_member_sees_only_the_locations_they_were_granted(): void
    {
        [$owner, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->publicStorefront($business, 'Hidden Branch');
        $this->makeCitation($business, $hidden, null, ['notes' => 'hidden-branch-secret-note', 'listed_name' => 'Hidden Listing Name']);
        $this->makeCitation($business, $granted, null, ['notes' => 'granted-note']);

        $member = $this->selectedScopeMember($workspace, [$granted]);
        $this->authenticateAsSeoCustomer($member);

        $html = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Main Storefront', $html);
        $this->assertStringContainsString('granted-note', $html);
        $this->assertStringNotContainsString('Hidden Branch', $html);
        $this->assertStringNotContainsString($hidden->uid, $html);
        $this->assertStringNotContainsString('hidden-branch-secret-note', $html);
        $this->assertStringNotContainsString('Hidden Listing Name', $html);
    }

    public function test_an_inaccessible_location_is_not_countable_aggregate_after_filter(): void
    {
        [$owner, $business, $workspace, $granted] = $this->tenant();
        $this->publicStorefront($business, 'Second');
        $this->publicStorefront($business, 'Third');

        // Owner sees three Locations; a member granted one sees exactly one.
        $ownerHtml = $this->get($this->citationsUrl($workspace, $business))->getContent();
        $this->assertSame(3, substr_count($ownerHtml, 'data-section="citation-location"'));

        $this->authenticateAsSeoCustomer($this->selectedScopeMember($workspace, [$granted]));
        $memberHtml = $this->get($this->citationsUrl($workspace, $business))->getContent();

        $this->assertSame(1, substr_count($memberHtml, 'data-section="citation-location"'));
        $this->assertSame(2, substr_count($memberHtml, 'data-role="citation-row"'), 'Rows are counted only for the accessible Location (2 directories).');
    }

    public function test_a_member_with_no_granted_location_sees_the_empty_state_not_a_count(): void
    {
        [, $business, $workspace] = $this->tenant();
        $this->authenticateAsSeoCustomer($this->selectedScopeMember($workspace, []));

        $html = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="citation-empty"', $html);
        $this->assertSame(0, substr_count($html, 'data-section="citation-location"'));
    }

    public function test_a_write_to_an_inaccessible_location_is_a_404_and_writes_nothing(): void
    {
        [, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->publicStorefront($business, 'Hidden Branch');

        $this->authenticateAsSeoCustomer($this->selectedScopeMember($workspace, [$granted]));

        $this->putCitation($workspace, $business, $hidden)->assertNotFound();

        $this->assertSame(0, SeoCitation::query()->count());

        // ...and the same member may write the Location they hold.
        $this->putCitation($workspace, $business, $granted)->assertSessionHasNoErrors();
        $this->assertSame(1, SeoCitation::query()->count());
    }

    public function test_a_guessed_foreign_location_or_citation_is_a_404_and_the_foreign_row_is_untouched(): void
    {
        [, $business, $workspace] = $this->tenant();
        [, $foreignBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $foreignLocation = $this->publicStorefront($foreignBusiness, 'Foreign');
        $foreign = $this->makeCitation($foreignBusiness, $foreignLocation, null, ['listed_name' => 'Foreign Original']);
        $before = $this->dbFingerprint(['seo_citations']);

        // My Business, THEIR Location uid — where their citation lives.
        $this->putCitation($workspace, $business, $foreignLocation, 'bing_places', ['listed_name' => 'Hijacked'])->assertNotFound();

        // A made-up Location uid.
        $this->from($this->citationsUrl($workspace, $business))
            ->put($this->citationUpdateUrl($workspace, $business, 'does-not-exist', 'bing_places'), $this->citationInput())
            ->assertNotFound();

        $this->assertSame($before, $this->dbFingerprint(['seo_citations']));
        $this->assertSame('Foreign Original', $foreign->fresh()->listed_name);
    }

    public function test_a_foreign_business_and_workspace_are_404_for_both_read_and_write(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        [, $foreignBusiness, $foreignWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);

        $this->get($this->citationsUrl($foreignWorkspace, $foreignBusiness))->assertNotFound();
        $this->get($this->citationsUrl($workspace, $foreignBusiness))->assertNotFound();
        $this->get($this->citationsUrl($foreignWorkspace, $business))->assertNotFound();
        $this->putCitation($workspace, $foreignBusiness, $location)->assertNotFound();
    }

    public function test_an_unknown_or_inactive_directory_is_a_404(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $this->putCitation($workspace, $business, $location, 'no_such_directory')->assertNotFound();

        DB::table('seo_citation_directories')->where('key', 'facebook_pages')->update(['is_active' => false]);
        $this->putCitation($workspace, $business, $location, 'facebook_pages')->assertNotFound();

        $this->assertSame(0, SeoCitation::query()->count());
    }

    public function test_the_manager_refuses_an_unknown_location_with_the_same_exception_as_an_inaccessible_one(): void
    {
        [$customer, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->publicStorefront($business, 'Hidden Branch');
        $member = $this->selectedScopeMember($workspace, [$granted]);

        foreach ([[$member->user_id, (string) $hidden->uid], [$customer->user_id, 'made-up-uid']] as [$userId, $uid]) {
            try {
                app(SeoCitationManager::class)->save($userId, $business, $uid, 'bing_places', $this->citationInput());
                $this->fail('Expected refusal.');
            } catch (SeoCitationNotFoundException $e) {
                $this->assertSame('Location not found.', $e->getMessage());
            }
        }
    }

    // -----------------------------------------------------------------
    // Archived Locations (§10.5)
    // -----------------------------------------------------------------

    public function test_an_archived_location_keeps_its_history_visible_and_read_only(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, null, ['notes' => 'kept-history-note']);
        $this->archive($location);

        $html = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('kept-history-note', $html);
        $this->assertStringContainsString('data-role="location-archived"', $html);
        $this->assertStringNotContainsString('data-role="citation-form"', $html, 'No edit form for an Archived Location.');
    }

    public function test_a_write_to_an_archived_location_is_refused_and_writes_nothing(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->archive($location);

        $this->putCitation($workspace, $business, $location)->assertSessionHasErrors('location', null, $this->bag($location));

        $this->assertSame(0, SeoCitation::query()->count());
    }

    // -----------------------------------------------------------------
    // Capabilities (independent of tenancy)
    // -----------------------------------------------------------------

    public function test_view_seo_without_manage_seo_reads_but_cannot_write(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->authenticateAsSeoCustomer($customer, ['view_seo', 'view_google_business_profile']);

        $html = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-role="citation-form"', $html);

        $this->putCitation($workspace, $business, $location)->assertUnauthorized();
        $this->assertSame(0, SeoCitation::query()->count());
    }

    public function test_without_view_seo_the_page_is_refused_even_with_full_tenancy(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsSeoCustomer($customer, ['manage_seo', 'view_google_business_profile']);

        $this->get($this->citationsUrl($workspace, $business))->assertUnauthorized();
    }

    public function test_capability_without_tenancy_is_a_404(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->authenticateAsSeoCustomer($this->createCustomer());

        $this->get($this->citationsUrl($workspace, $business))->assertNotFound();
        $this->putCitation($workspace, $business, $location)->assertNotFound();
    }

    // -----------------------------------------------------------------
    // GBP synthetic row: derived from the read model, never stored
    // -----------------------------------------------------------------

    public function test_the_google_row_reports_the_read_model_and_is_never_stored_as_a_citation(): void
    {
        $this->bindFakeGoogleClient();
        [, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['title' => 'A Different Google Title']);

        $html = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="google-row"', $html);
        $this->assertStringContainsString('Linked', $html);
        // The mismatch count is GBP's own comparator's, delivered by the reader.
        $this->assertMatchesRegularExpression('/data-role="google-mismatch-count">1 detail\(s\) differ from Google\./', $html);

        $this->assertSame(0, SeoCitation::query()->count(), 'The Google row is synthetic and must never be stored.');
        $this->assertSame([], $this->fakeGoogle->calls, 'No provider call: the row comes from the read model only.');
    }

    public function test_an_expired_mirror_shows_the_row_without_a_mismatch_count(): void
    {
        $this->bindFakeGoogleClient();
        [, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), false, ['title' => 'A Different Google Title']);

        $html = $this->get($this->citationsUrl($workspace, $business))->getContent();

        $this->assertStringContainsString('data-role="google-row"', $html);
        $this->assertStringNotContainsString('google-mismatch-count', $html);
        $this->assertStringNotContainsString('A Different Google Title', $html, 'An expired mirror is absent (GBP §13).');
    }

    public function test_an_unbound_location_shows_not_linked(): void
    {
        $this->bindFakeGoogleClient();
        [, $business, $workspace] = $this->tenant();

        $html = $this->get($this->citationsUrl($workspace, $business))->getContent();

        $this->assertStringContainsString('data-role="google-row"', $html);
        $this->assertStringContainsString('Not linked', $html);
    }

    public function test_no_google_row_and_no_placeholder_when_the_actor_may_not_see_gbp(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business));
        $this->authenticateAsSeoCustomer($customer, ['view_seo', 'manage_seo']);

        $html = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('google-row', $html);
        $this->assertStringNotContainsString('Google Business Profile', $html);
    }

    public function test_an_inaccessible_locations_google_status_is_not_shown(): void
    {
        [, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->publicStorefront($business, 'Hidden Branch');
        $connection = $this->activeConnection($business);
        $this->bindGoogleLocation($business, $hidden, $connection);

        $this->authenticateAsSeoCustomer($this->selectedScopeMember($workspace, [$granted]));

        $html = $this->get($this->citationsUrl($workspace, $business))->getContent();

        $this->assertSame(1, substr_count($html, 'data-role="google-row"'), 'Only the accessible Location gets a Google row.');
        $this->assertStringContainsString('Not linked', $html);
        $this->assertStringNotContainsString('Linked</span>', str_replace('Not linked', '', $html));
    }

    // -----------------------------------------------------------------
    // Query budget (§11.4): independent of Locations, directories, citations
    // -----------------------------------------------------------------

    public function test_the_page_query_count_is_independent_of_the_number_of_locations_and_citations(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location);
        $url = $this->citationsUrl($workspace, $business);

        $this->get($url)->assertOk(); // warm any per-process caches
        $small = count($this->capturedQueries(fn () => $this->get($url)->assertOk()));

        for ($i = 0; $i < 12; $i++) {
            $extra = $this->publicStorefront($business, 'Extra ' . $i);
            $this->makeCitation($business, $extra, $this->directory('bing_places'));
            $this->makeCitation($business, $extra, $this->directory('facebook_pages'));
        }

        $large = count($this->capturedQueries(fn () => $this->get($url)->assertOk()));

        $this->assertSame($small, $large, 'The Citations page must not issue queries per Location or per citation.');
        $this->assertLessThanOrEqual(24, $large);
    }

    public function test_the_link_safety_used_for_rendering_is_the_same_boundary_used_for_writing(): void
    {
        // A single source of truth: the view calls SeoLinkSafety, and so does the manager.
        $view = file_get_contents(dirname(__DIR__, 3) . '/resources/views/customer/business/seo/citations.blade.php');
        $manager = file_get_contents(dirname(__DIR__, 3) . '/app/Library/Seo/SeoCitationManager.php');

        $this->assertStringContainsString('SeoLinkSafety::EXTERNAL_REL', $view);
        $this->assertStringContainsString('SeoLinkSafety::isSafeHttpsUrl', $manager);
        $this->assertStringContainsString('SeoLinkSafety::safeHttpsUrl', $manager);
        $this->assertNotNull(SeoLinkSafety::safeHttpsUrl('https://example.com'));
    }
}
