<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\SeoLocationReviewLink;
use App\Models\SeoReviewRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Seo\Concerns\CreatesSeoReviewFixtures;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice 18F (§8.6, §15.F, §16 T-SEO-TEN / T-SEO-LOC) — the
 * Reviews surface behind the (bypassed, test-only) entitlement step: the
 * review link, the request ledger, Contact PII, Location ACL, the deep links
 * and the guarantee that SEO sends nothing.
 */
class SeoReviewsHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bypassReviewEntitlementForTest();
    }

    /**
     * @return array{0: Customer, 1: Business, 2: \App\Models\Workspace, 3: BusinessLocation}
     */
    private function tenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->reviewLocation($business, 'Main Storefront');

        $this->authenticateAsSeoCustomer($customer, $this->reviewPermissions());

        return [$customer, $business, $workspace, $location];
    }

    private function page(\App\Models\Workspace $workspace, Business $business): string
    {
        return $this->get($this->reviewsUrl($workspace, $business))->assertOk()->getContent();
    }

    // -----------------------------------------------------------------
    // Review link
    // -----------------------------------------------------------------

    public function test_a_manual_link_is_saved_replaced_and_removed_through_the_page(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $url = $this->reviewRoute('link.save', $workspace, $business, (string) $location->uid);

        $this->from($this->reviewsUrl($workspace, $business))->put($url, ['review_url' => 'https://g.page/r/one/review'])
            ->assertRedirect($this->reviewsUrl($workspace, $business))->assertSessionHas('status', 'success');
        $this->assertSame('https://g.page/r/one/review', SeoLocationReviewLink::query()->sole()->review_url);

        $this->put($url, ['review_url' => 'https://g.page/r/two/review']);
        $this->assertSame(1, SeoLocationReviewLink::query()->count());
        $this->assertSame('https://g.page/r/two/review', SeoLocationReviewLink::query()->sole()->review_url);
        $this->assertSame($customer->user_id, SeoLocationReviewLink::query()->sole()->set_by_user_id);

        $this->post($this->reviewRoute('link.clear', $workspace, $business, (string) $location->uid))->assertSessionHas('status', 'success');
        $this->assertSame(0, SeoLocationReviewLink::query()->count());
    }

    public function test_an_unsafe_link_is_refused_with_calm_copy_and_stores_nothing(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $url = $this->reviewRoute('link.save', $workspace, $business, (string) $location->uid);

        foreach (['http://g.page/r/x', 'javascript:alert(1)', 'https://a@evil.example/x'] as $bad) {
            $this->from($this->reviewsUrl($workspace, $business))->put($url, ['review_url' => $bad])
                ->assertSessionHas('status', 'error')
                ->assertSessionHas('message', 'Enter a full link that starts with https://.');
        }

        $this->put($url, [])->assertSessionHasErrors('review_url');
        $this->assertSame(0, SeoLocationReviewLink::query()->count());
    }

    public function test_the_page_shows_a_manual_link_with_the_contracted_rel_and_no_tracking_wrapper(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeReviewLink($business, $location, 'https://g.page/r/abc/review');

        $html = $this->page($workspace, $business);

        $this->assertStringContainsString('href="https://g.page/r/abc/review"', $html, 'The destination is linked directly — no redirect, short link or click-tracking hop.');
        preg_match_all('/<a\b[^>]*target="_blank"[^>]*>/i', $html, $matches);
        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $tag) {
            $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $tag);
        }

        $this->assertStringContainsString('data-source="manual"', $html);
    }

    public function test_an_unsafe_stored_link_is_never_rendered(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeReviewLink($business, $location, 'javascript:alert(1)');

        $html = $this->page($workspace, $business);

        // The shared shell has its own javascript: void links; scope to this feature's markup.
        $feature = substr($html, (int) strpos($html, 'data-role="reviews-note"'));
        $this->assertStringNotContainsString('href="javascript:', $feature);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringContainsString('data-role="review-link-none"', $html);
    }

    public function test_the_effective_link_prefers_manual_then_the_unexpired_google_mirror_and_never_stores_google(): void
    {
        $this->bindFakeGoogleClient();
        [, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['new_review_uri' => 'https://search.google.test/review']);

        $html = $this->page($workspace, $business);
        $this->assertStringContainsString('href="https://search.google.test/review"', $html);
        $this->assertStringContainsString('data-source="google"', $html);
        $this->assertSame(0, SeoLocationReviewLink::query()->count(), 'The Google link is read at render time and never copied into SEO tables.');

        $this->makeReviewLink($business, $location, 'https://g.page/r/manual/review');
        $html = $this->page($workspace, $business);
        $this->assertStringContainsString('href="https://g.page/r/manual/review"', $html);
        $this->assertStringNotContainsString('search.google.test', $html);
    }

    public function test_an_expired_google_mirror_gives_no_link_and_an_unsafe_google_uri_is_ignored(): void
    {
        $this->bindFakeGoogleClient();
        [, $business, $workspace, $location] = $this->tenant();
        $second = $this->reviewLocation($business, 'Second');
        $connection = $this->activeConnection($business);
        $this->bindGoogleLocation($business, $location, $connection, false, ['new_review_uri' => 'https://expired.google.test/review']);
        $this->bindGoogleLocation($business, $second, $connection, true, ['new_review_uri' => 'javascript:alert(1)']);

        $html = $this->page($workspace, $business);

        $this->assertStringNotContainsString('expired.google.test', $html);
        // The shared shell has its own javascript: void links; scope to this feature's markup.
        $feature = substr($html, (int) strpos($html, 'data-role="reviews-note"'));
        $this->assertStringNotContainsString('href="javascript:', $feature);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertSame(2, substr_count($html, 'data-role="review-link-none"'));
    }

    // -----------------------------------------------------------------
    // Request ledger through the page
    // -----------------------------------------------------------------

    public function test_a_request_is_recorded_and_says_nothing_was_sent(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);

        $this->from($this->reviewsUrl($workspace, $business))
            ->post($this->reviewRoute('requests.store', $workspace, $business, (string) $location->uid), ['channel' => 'sms', 'contact_uid' => (string) $contact->uid])
            ->assertSessionHas('status', 'success');

        $this->assertStringContainsString('Nothing was sent', (string) session('message'));
        $row = SeoReviewRequest::query()->sole();
        $this->assertSame($contact->id, $row->contact_id);
        $this->assertSame($customer->user_id, $row->created_by_user_id);
    }

    public function test_a_duplicate_inside_the_cooldown_is_refused_with_fixed_copy_and_records_nothing_new(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);
        $url = $this->reviewRoute('requests.store', $workspace, $business, (string) $location->uid);

        $this->from($this->reviewsUrl($workspace, $business))->post($url, ['channel' => 'sms', 'contact_uid' => (string) $contact->uid]);
        $this->from($this->reviewsUrl($workspace, $business))->post($url, ['channel' => 'email', 'contact_uid' => (string) $contact->uid])
            ->assertSessionHas('status', 'error');

        $this->assertStringContainsString('already asked recently', (string) session('message'));
        $this->assertStringNotContainsString((string) $contact->uid, (string) session('message'));
        $this->assertSame(1, SeoReviewRequest::query()->count());
    }

    public function test_mark_reviewed_is_labelled_self_reported_and_declined_frees_the_contact(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);
        $store = $this->reviewRoute('requests.store', $workspace, $business, (string) $location->uid);

        $this->post($store, ['channel' => 'sms', 'contact_uid' => (string) $contact->uid]);
        $request = SeoReviewRequest::query()->sole();

        $this->post($this->reviewRoute('requests.reviewed', $workspace, $business, (string) $request->uid))->assertSessionHas('status', 'success');
        $html = $this->page($workspace, $business);
        $this->assertStringContainsString('Reviewed (self-reported)', $html);
        $this->assertStringNotContainsString('data-role="mark-reviewed-form"', $html, 'A resolved request offers no further outcome.');

        $second = $this->makeReviewRequest($business, $location, $this->reviewContact($business, $location));
        $this->post($this->reviewRoute('requests.declined', $workspace, $business, (string) $second->uid))->assertSessionHas('status', 'success');
        $this->assertSame('declined', $second->fresh()->status->value);
    }

    public function test_the_page_shows_a_plain_count_and_no_target_or_ranking(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeReviewRequest($business, $location);
        $this->makeReviewRequest($business, $location);

        $html = $this->page($workspace, $business);

        $this->assertStringContainsString('Requests recorded: 2', $html);
        $this->assertDoesNotMatchRegularExpression('/\b(target|goal|quota|leaderboard|ranking|of \d+ )\b/i', strip_tags($html));
    }

    // -----------------------------------------------------------------
    // Location ACL
    // -----------------------------------------------------------------

    public function test_an_inaccessible_locations_link_ledger_contacts_and_counts_are_absent(): void
    {
        [, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->reviewLocation($business, 'Hidden Branch');
        $hiddenContact = $this->reviewContact($business, $hidden, ['FIRST_NAME' => 'Hidden', 'LAST_NAME' => 'Person']);
        $this->makeReviewRequest($business, $hidden, $hiddenContact);
        $this->makeReviewRequest($business, $hidden);
        $this->makeReviewLink($business, $hidden, 'https://g.page/r/hidden-branch/review');
        $this->makeReviewRequest($business, $granted);

        $owner = $this->page($workspace, $business);
        $this->assertSame(2, substr_count($owner, 'data-section="review-location"'));
        $this->assertStringContainsString('Hidden Person', $owner);

        $this->authenticateAsSeoCustomer($this->selectedScopeMember($workspace, [$granted]), $this->reviewPermissions());
        $html = $this->page($workspace, $business);

        $this->assertSame(1, substr_count($html, 'data-section="review-location"'));
        $this->assertSame(1, substr_count($html, 'data-role="review-request"'));
        $this->assertStringContainsString('Requests recorded: 1', $html, 'Counted only over the accessible Location.');
        foreach (['Hidden Branch', 'Hidden Person', $hidden->uid, 'hidden-branch/review', $hiddenContact->uid] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_writes_to_an_inaccessible_or_foreign_location_or_request_are_404_and_write_nothing(): void
    {
        [, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->reviewLocation($business, 'Hidden Branch');
        $hiddenRequest = $this->makeReviewRequest($business, $hidden);
        [, $foreignBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $foreignLocation = $this->reviewLocation($foreignBusiness, 'Foreign');
        $foreignRequest = $this->makeReviewRequest($foreignBusiness, $foreignLocation);

        $this->authenticateAsSeoCustomer($this->selectedScopeMember($workspace, [$granted]), $this->reviewPermissions());
        $before = $this->dbFingerprint(['seo_review_requests', 'seo_location_review_links']);

        foreach ([$hidden, $foreignLocation] as $location) {
            $this->put($this->reviewRoute('link.save', $workspace, $business, (string) $location->uid), ['review_url' => 'https://g.page/r/x'])->assertNotFound();
            $this->post($this->reviewRoute('link.clear', $workspace, $business, (string) $location->uid))->assertNotFound();
            $this->post($this->reviewRoute('requests.store', $workspace, $business, (string) $location->uid), ['channel' => 'in_person'])->assertNotFound();
        }

        foreach ([$hiddenRequest, $foreignRequest] as $request) {
            $this->post($this->reviewRoute('requests.reviewed', $workspace, $business, (string) $request->uid))->assertNotFound();
            $this->post($this->reviewRoute('requests.declined', $workspace, $business, (string) $request->uid))->assertNotFound();
        }

        $this->post($this->reviewRoute('requests.reviewed', $workspace, $business, 'made-up'))->assertNotFound();
        $this->assertSame($before, $this->dbFingerprint(['seo_review_requests', 'seo_location_review_links']));
    }

    public function test_foreign_business_and_workspace_are_404_and_a_stranger_is_404(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        [, $foreignBusiness, $foreignWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);

        $this->get($this->reviewsUrl($foreignWorkspace, $foreignBusiness))->assertNotFound();
        $this->get($this->reviewsUrl($workspace, $foreignBusiness))->assertNotFound();
        $this->get($this->reviewsUrl($foreignWorkspace, $business))->assertNotFound();
        $this->post($this->reviewRoute('requests.store', $workspace, $foreignBusiness, (string) $location->uid), ['channel' => 'sms'])->assertNotFound();

        $this->authenticateAsSeoCustomer($this->createCustomer(), $this->reviewPermissions());
        $this->get($this->reviewsUrl($workspace, $business))->assertNotFound();
    }

    public function test_an_archived_location_keeps_history_visible_but_read_only(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);
        $this->makeReviewRequest($business, $location, $contact);
        $this->makeReviewLink($business, $location);
        $this->archiveLocation($location);

        $html = $this->page($workspace, $business);

        $this->assertStringContainsString('data-role="location-archived"', $html);
        $this->assertStringContainsString('data-role="review-request"', $html);
        foreach (['data-role="request-form"', 'data-role="link-form"', 'data-role="mark-reviewed-form"', 'data-role="link-clear-form"'] as $control) {
            $this->assertStringNotContainsString($control, $html);
        }

        $this->post($this->reviewRoute('requests.store', $workspace, $business, (string) $location->uid), ['channel' => 'in_person'])->assertSessionHas('status', 'error');
        $this->assertSame(1, SeoReviewRequest::query()->count());
    }

    // -----------------------------------------------------------------
    // Capabilities
    // -----------------------------------------------------------------

    public function test_view_seo_without_manage_seo_reads_but_cannot_write(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->authenticateAsSeoCustomer($customer, ['view_seo', 'view_google_business_profile']);

        $html = $this->page($workspace, $business);
        foreach (['data-role="request-form"', 'data-role="link-form"'] as $control) {
            $this->assertStringNotContainsString($control, $html);
        }

        $this->post($this->reviewRoute('requests.store', $workspace, $business, (string) $location->uid), ['channel' => 'sms'])->assertUnauthorized();
        $this->put($this->reviewRoute('link.save', $workspace, $business, (string) $location->uid), ['review_url' => 'https://g.page/r/x'])->assertUnauthorized();
        $this->assertSame(0, SeoReviewRequest::query()->count());
    }

    public function test_without_view_seo_the_page_is_refused_even_with_full_tenancy(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsSeoCustomer($customer, ['manage_seo', 'view_contact']);

        $this->get($this->reviewsUrl($workspace, $business))->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // Contact PII: no broader than the existing Contacts authority
    // -----------------------------------------------------------------

    public function test_a_user_without_view_contact_sees_no_contact_name_or_number_and_gets_no_contact_choices(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location, ['FIRST_NAME' => 'Casey', 'LAST_NAME' => 'Quinn', 'EMAIL' => 'casey@example.test']);
        $this->makeReviewRequest($business, $location, $contact);

        $withContacts = $this->page($workspace, $business);
        $this->assertStringContainsString('Casey Quinn', $withContacts);
        $this->assertStringContainsString((string) $contact->phone, $withContacts);
        $this->assertStringContainsString('name="contact_uid"', $withContacts);

        $this->authenticateAsSeoCustomer($customer, ['view_seo', 'manage_seo', 'view_google_business_profile']);
        $html = $this->page($workspace, $business);

        foreach (['Casey', 'Quinn', 'casey@example.test', (string) $contact->phone, (string) $contact->uid] as $pii) {
            $this->assertStringNotContainsString($pii, $html, "[{$pii}] must not be exposed without view_contact.");
        }

        $this->assertStringContainsString('data-role="request-contact-hidden"', $html);
        $this->assertStringNotContainsString('name="contact_uid"', $html);
        $this->assertStringNotContainsString('data-role="deep-link-contact"', $html);
    }

    public function test_only_the_name_and_number_the_contacts_directory_shows_are_exposed_never_email_or_details(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location, ['FIRST_NAME' => 'Casey', 'EMAIL' => 'casey@example.test', 'COMPANY' => 'Quinn & Sons']);
        $this->makeReviewRequest($business, $location, $contact);

        $html = $this->page($workspace, $business);

        $this->assertStringContainsString('Casey', $html);
        $this->assertStringNotContainsString('casey@example.test', $html);
        $this->assertStringNotContainsString('Quinn', $html);
    }

    public function test_a_contact_who_moved_to_an_inaccessible_location_is_no_longer_named_on_an_accessible_row(): void
    {
        [, $business, $workspace, $granted] = $this->tenant();
        $elsewhere = $this->reviewLocation($business, 'Elsewhere');
        $contact = $this->reviewContact($business, $granted, ['FIRST_NAME' => 'Moved', 'LAST_NAME' => 'Away']);
        $this->makeReviewRequest($business, $granted, $contact);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $elsewhere->id]);

        $this->authenticateAsSeoCustomer($this->selectedScopeMember($workspace, [$granted]), $this->reviewPermissions());
        $html = $this->page($workspace, $business);

        $this->assertStringNotContainsString('Moved Away', $html);
        $this->assertStringContainsString('data-role="request-contact-hidden"', $html);
    }

    public function test_only_contacts_of_the_same_business_and_location_are_offered(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $other = $this->reviewLocation($business, 'Other');
        [, $foreignBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $mine = $this->reviewContact($business, $location, ['FIRST_NAME' => 'Mine']);
        $elsewhere = $this->reviewContact($business, $other, ['FIRST_NAME' => 'Elsewhere']);
        $foreign = $this->reviewContact($foreignBusiness, $this->reviewLocation($foreignBusiness), ['FIRST_NAME' => 'Foreign']);
        $unplaced = $this->reviewContact($business, null, ['FIRST_NAME' => 'Unplaced']);

        $html = $this->page($workspace, $business);

        // The first section (Main Storefront) offers only its own Contact.
        $mainSection = substr($html, (int) strpos($html, 'data-location="' . $location->uid . '"'));
        $mainSection = substr($mainSection, 0, (int) strpos($mainSection, 'data-section="review-location"', 10) ?: strlen($mainSection));

        $this->assertStringContainsString('value="' . $mine->uid . '"', $html);
        $this->assertStringContainsString('value="' . $elsewhere->uid . '"', $html, 'Offered — but only inside its own Location section.');
        $this->assertStringNotContainsString($foreign->uid, $html);
        $this->assertStringNotContainsString($unplaced->uid, $html);
        $this->assertStringNotContainsString('Foreign', $html);
        $this->assertStringNotContainsString('Unplaced', $html);
    }

    public function test_a_foreign_or_mismatched_contact_over_http_is_refused_with_generic_copy(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $other = $this->reviewLocation($business, 'Other');
        [, $foreignBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $foreign = $this->reviewContact($foreignBusiness, $this->reviewLocation($foreignBusiness));
        $mismatch = $this->reviewContact($business, $other);
        $url = $this->reviewRoute('requests.store', $workspace, $business, (string) $location->uid);

        $messages = [];

        foreach ([$foreign, $mismatch] as $contact) {
            $this->from($this->reviewsUrl($workspace, $business))->post($url, ['channel' => 'sms', 'contact_uid' => (string) $contact->uid])->assertSessionHas('status', 'error');
            $messages[] = session('message');
        }

        $this->assertSame($messages[0], $messages[1], 'Foreign and mismatched Contacts are indistinguishable to the caller.');
        $this->assertSame(0, SeoReviewRequest::query()->count());
    }

    // -----------------------------------------------------------------
    // Deep links go to existing product surfaces; nothing is sent
    // -----------------------------------------------------------------

    public function test_deep_links_point_only_at_existing_contacts_conversations_and_automations_screens(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);
        $this->makeReviewRequest($business, $location, $contact);

        $html = $this->page($workspace, $business);

        $this->assertStringContainsString('href="' . route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact->uid]) . '"', $html);
        $this->assertStringContainsString('href="' . route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]) . '"', $html);
        $this->assertStringContainsString('href="' . route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]) . '"', $html);

        // Every internal link on the page resolves to a real, named route.
        preg_match_all('/href="(' . preg_quote(url('/'), '/') . '[^"]*)"/', $html, $links);

        foreach ($links[1] as $href) {
            $path = parse_url($href, PHP_URL_PATH);

            if (! str_contains((string) $path, '/businesses/')) {
                continue; // shell assets, not feature links
            }

            $this->assertNotNull(app('router')->getRoutes()->match(\Illuminate\Http\Request::create($href, 'GET'))->getName() ?? null, "[{$path}] must be an existing named route.");
        }
    }

    public function test_deep_links_are_shown_only_to_users_who_hold_the_target_capability(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->makeReviewRequest($business, $location, $this->reviewContact($business, $location));

        $this->authenticateAsSeoCustomer($customer, $this->seoPermissions());
        $html = $this->page($workspace, $business);

        foreach (['deep-link-contact', 'deep-link-conversations', 'deep-link-automations'] as $link) {
            $this->assertStringNotContainsString($link, $html);
        }
    }

    public function test_deep_links_carry_no_message_body_phone_or_contact_data_in_the_url(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);
        $this->makeReviewLink($business, $location, 'https://g.page/r/abc/review');
        $this->makeReviewRequest($business, $location, $contact);

        $html = $this->page($workspace, $business);

        preg_match_all('/data-role="deep-link-(?:conversations|automations)"/', $html, $matches);
        $this->assertCount(2, $matches[0]);
        $this->assertDoesNotMatchRegularExpression('/deep-link-(conversations|automations)[^>]*\?/', $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*(' . preg_quote((string) $contact->phone, '/') . '|g\.page)[^"]*"[^>]*deep-link/', $html);
    }

    public function test_seo_reviews_sends_zero_messages_and_touches_no_messaging_or_automation_table(): void
    {
        Http::fake();
        Mail::fake();
        Notification::fake();
        Bus::fake();
        Queue::fake();
        $google = $this->bindFakeGoogleClient();

        [, $business, $workspace, $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);
        $tables = ['chat_boxes', 'chat_box_messages', 'tracking_logs', 'campaigns', 'automations', 'reports', 'sms_logs'];
        $tables = array_values(array_filter($tables, fn ($table) => \Illuminate\Support\Facades\Schema::hasTable($table)));
        $before = $this->dbFingerprint($tables);

        $this->put($this->reviewRoute('link.save', $workspace, $business, (string) $location->uid), ['review_url' => 'https://g.page/r/abc/review']);
        $this->post($this->reviewRoute('requests.store', $workspace, $business, (string) $location->uid), ['channel' => 'sms', 'contact_uid' => (string) $contact->uid]);
        $this->post($this->reviewRoute('requests.reviewed', $workspace, $business, (string) SeoReviewRequest::query()->sole()->uid));
        $this->post($this->reviewRoute('link.clear', $workspace, $business, (string) $location->uid));
        $this->page($workspace, $business);

        Http::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        $this->assertSame([], $google->calls);
        $this->assertNotEmpty($tables);
        $this->assertSame($before, $this->dbFingerprint($tables), 'No conversation, message, campaign, automation or log row may change.');
    }

    // -----------------------------------------------------------------
    // Review gating / incentives cannot be smuggled in through input
    // -----------------------------------------------------------------

    public function test_rating_sentiment_and_incentive_fields_are_ignored_and_change_nothing(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $one = $this->reviewContact($business, $location);
        $two = $this->reviewContact($business, $location);
        $url = $this->reviewRoute('requests.store', $workspace, $business, (string) $location->uid);

        $this->post($url, ['channel' => 'sms', 'contact_uid' => (string) $one->uid, 'rating' => 1, 'sentiment' => 'negative', 'satisfied' => false, 'reward' => '10% off'])
            ->assertSessionHas('status', 'success');
        $this->post($url, ['channel' => 'sms', 'contact_uid' => (string) $two->uid, 'rating' => 5, 'sentiment' => 'positive', 'satisfied' => true, 'reward' => 'gift card'])
            ->assertSessionHas('status', 'success');

        $rows = SeoReviewRequest::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]->getAttributes()['status'], $rows[1]->getAttributes()['status'], 'Identical treatment whatever the smuggled fields say.');
        $this->assertSame([], array_intersect(array_keys($rows[0]->getAttributes()), ['rating', 'sentiment', 'satisfied', 'reward']));
    }

    // -----------------------------------------------------------------
    // Query budget (§11.4)
    // -----------------------------------------------------------------

    public function test_the_page_query_count_is_independent_of_locations_requests_and_contacts(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeReviewRequest($business, $location, $this->reviewContact($business, $location));
        $url = $this->reviewsUrl($workspace, $business);

        $this->get($url)->assertOk();
        $small = count($this->capturedQueries(fn () => $this->get($url)->assertOk()));

        for ($i = 0; $i < 10; $i++) {
            $extra = $this->reviewLocation($business, 'Extra ' . $i);
            $this->makeReviewLink($business, $extra);

            for ($j = 0; $j < 2; $j++) {
                $this->makeReviewRequest($business, $extra, $this->reviewContact($business, $extra));
            }
        }

        $large = count($this->capturedQueries(fn () => $this->get($url)->assertOk()));

        $this->assertSame($small, $large, 'Reviews must not issue queries per Location, request or Contact.');

        // §11.4: a section index issues at most 14 queries of its own. The
        // rest of the request is the shared tenancy chain and application
        // shell, which the section does not control, so only queries against
        // the tables this section reads are counted.
        $owned = array_filter(
            $this->capturedQueries(fn () => $this->get($url)->assertOk()),
            fn (string $sql) => (bool) preg_match('/`(seo_|contacts|contact_group_fields|contacts_custom_field|business_locations|business_google_)/', $sql)
        );
        $this->assertLessThanOrEqual(14, count($owned), 'Contract 18 §11.4: a section index issues at most 14 queries of its own.');
    }
}
