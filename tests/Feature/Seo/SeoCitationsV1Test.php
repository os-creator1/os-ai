<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\Seo\SeoCitationStatus;
use App\Enums\Seo\SeoDirectoryImportance;
use App\Enums\Seo\SeoNapFieldResult;
use App\Library\Seo\SeoCitationLocationSection;
use App\Library\Seo\SeoCitationManager;
use App\Library\Seo\SeoNapComparator;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoCitation;
use App\Models\SeoCitationDirectory;
use App\Models\SeoNicheCitationRecommendation;
use Database\Seeders\SeoCitationDirectorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * Citations V1 completion — the unified directory model (platform core, niche
 * recommendations, Business custom), applicability, deterministic progress,
 * "what to do next" ordering, website normalization and the Google read-time
 * comparison. Fixture business: industry photo_booth_service, phone
 * +15550101234, website https://example.test, storefront at
 * "12 High Street, Springfield, IL, 62701, US".
 */
class SeoCitationsV1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

    private const CORE_KEYS = ['apple_business', 'bing_places', 'yelp', 'facebook_pages', 'data_axle', 'foursquare', 'nextdoor_business', 'bbb', 'yellow_pages', 'mapquest'];
    private const PHOTO_BOOTH_KEYS = ['weddingwire_the_knot', 'gigsalad', 'eventective', 'bark', 'the_bash'];

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

    private function keysIn(SeoCitationLocationSection $section): array
    {
        return array_map(fn ($row) => $row->directory->key, $section->rows);
    }

    private function setIndustry(Business $business, string $industry): Business
    {
        DB::table('businesses')->where('id', $business->id)->update(['industry' => $industry]);

        return $business->fresh();
    }

    // -----------------------------------------------------------------
    // The catalog: core + niche, no duplication
    // -----------------------------------------------------------------

    public function test_a_new_business_sees_the_core_catalog_and_its_niche_recommendations_without_any_setup(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();

        $keys = $this->keysIn($this->section($customer, $workspace, $business, $location));

        foreach (array_merge(self::CORE_KEYS, self::PHOTO_BOOTH_KEYS) as $key) {
            $this->assertContains($key, $keys, "[{$key}] must be offered immediately.");
        }

        $this->assertSame(0, SeoCitation::query()->count(), 'Nothing is pre-created per Business.');
    }

    public function test_the_page_groups_essential_recommended_and_optional_and_labels_the_niche(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $html = $this->page($workspace, $business, $location);

        $this->assertStringContainsString('data-group="essential"', $html);
        $this->assertStringContainsString('data-group="recommended"', $html);
        $this->assertStringContainsString('data-group="optional"', $html);
        $this->assertStringContainsString('Essential listings', $html);
        $this->assertStringContainsString('incl. Photo booth picks', $html);
        $this->assertMatchesRegularExpression('/data-directory="apple_business"[^>]*data-importance="essential"/', $html);
        $this->assertMatchesRegularExpression('/data-directory="gigsalad"[^>]*data-importance="recommended"/', $html);
        $this->assertMatchesRegularExpression('/data-directory="bark"[^>]*data-importance="optional"/', $html);
        $this->assertStringContainsString('data-role="niche-badge"', $html);
    }

    public function test_a_niche_never_shows_another_niches_directories(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $business = $this->setIndustry($business, 'professional_services');

        $keys = $this->keysIn($this->section($customer, $workspace, $business, $location));

        foreach (self::PHOTO_BOOTH_KEYS as $key) {
            $this->assertNotContains($key, $keys, "[{$key}] is Photo Booth only.");
        }
        foreach (self::CORE_KEYS as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function test_a_recommendation_added_later_reaches_existing_businesses_without_copying_rows(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $business = $this->setIndustry($business, 'professional_services');
        $before = [SeoCitation::query()->count(), SeoCitationDirectory::query()->count()];

        $this->assertNotContains('gigsalad', $this->keysIn($this->section($customer, $workspace, $business, $location)));

        $directory = $this->directory('gigsalad');
        $rec = new SeoNicheCitationRecommendation();
        $rec->forceFill(['niche_key' => 'professional_services', 'seo_citation_directory_id' => $directory->id, 'importance' => 'essential', 'sort_order' => 1, 'guidance' => 'Pro tip.', 'is_enabled' => true])->save();

        $section = $this->section($customer, $workspace, $business, $location);
        $row = collect($section->rows)->first(fn ($r) => $r->directory->key === 'gigsalad');

        $this->assertNotNull($row);
        $this->assertSame(SeoDirectoryImportance::Essential, $row->importance(), 'The niche override wins over the directory default.');
        $this->assertSame('Pro tip.', $row->nicheGuidance);
        $this->assertSame($before, [SeoCitation::query()->count(), SeoCitationDirectory::query()->count()], 'No per-Business rows were created.');
    }

    public function test_a_disabled_recommendation_is_not_shown(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        SeoNicheCitationRecommendation::query()->whereHas('directory', fn ($q) => $q->where('key', 'bark'))->update(['is_enabled' => false]);

        $this->assertNotContains('bark', $this->keysIn($this->section($customer, $workspace, $business, $location)));
    }

    public function test_a_disabled_platform_directory_leaves_new_businesses_but_stays_readable_where_a_record_exists(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, $this->directory('yelp'), ['listed_name' => 'Kept Name']);
        SeoCitationDirectory::query()->whereIn('key', ['yelp', 'foursquare'])->update(['is_active' => false]);

        $section = $this->section($customer, $workspace, $business, $location);
        $keys = $this->keysIn($section);

        $this->assertNotContains('foursquare', $keys, 'No record: a disabled directory is simply not offered.');
        $this->assertContains('yelp', $keys, 'History survives a platform change.');

        $yelp = collect($section->rows)->first(fn ($r) => $r->directory->key === 'yelp');
        $this->assertSame('Kept Name', $yelp->listedName);
        $this->assertFalse($yelp->writable, 'A disabled directory is read-only.');
        $this->putCitation($workspace, $business, $location, 'yelp')->assertNotFound();
    }

    public function test_every_seeded_claim_link_is_a_safe_https_url_and_there_is_no_duplicate_key(): void
    {
        $keys = [];

        foreach (SeoCitationDirectorySeeder::DIRECTORIES as $directory) {
            $keys[] = $directory['key'];

            if ($directory['claim_url'] !== null) {
                $this->assertTrue(\App\Library\Seo\SeoLinkSafety::isSafeHttpsUrl($directory['claim_url']), $directory['key']);
                $this->assertStringNotContainsString('?', $directory['claim_url'], 'No tracking/affiliate parameters: ' . $directory['key']);
            } else {
                $this->assertSame('manual', $directory['tracking_mode'], 'No verified claim link means manual: ' . $directory['key']);
            }
        }

        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->assertSame(count($keys), SeoCitationDirectory::query()->whereNull('business_id')->count(), 'The seeder is idempotent and the migration seeded the same rows.');

        (new SeoCitationDirectorySeeder())->run();
        $this->assertSame(count($keys), SeoCitationDirectory::query()->whereNull('business_id')->count());
    }

    private function putCitation($workspace, Business $business, BusinessLocation $location, string $key, array $overrides = [])
    {
        return $this->from($this->citationsUrl($workspace, $business))
            ->put($this->citationUpdateUrl($workspace, $business, (string) $location->uid, $key), $this->citationInput($overrides));
    }

    // -----------------------------------------------------------------
    // Custom directories
    // -----------------------------------------------------------------

    private function addCustom($workspace, Business $business, BusinessLocation $location, array $overrides = [])
    {
        $url = route('customer.workspaces.businesses.seo.citations.custom.store', [$workspace->uid, $business->uid, $location->uid]);

        return $this->from($this->citationsUrl($workspace, $business))->post($url, array_merge($this->citationInput([
            'listed_name' => 'Acme Photo Booth',
        ]), ['name' => 'Local Wedding Blog', 'claim_url' => 'https://blog.example-weddings.com/vendors', 'location_scope' => 'all'], $overrides));
    }

    public function test_an_owner_can_add_edit_and_archive_a_custom_directory_that_is_manual_and_labelled_custom(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();

        $this->addCustom($workspace, $business, $location)->assertSessionHasNoErrors();

        $directory = SeoCitationDirectory::query()->where('business_id', $business->id)->sole();
        $this->assertSame('manual', $directory->tracking_mode->value);
        $this->assertFalse($directory->is_platform_core);
        $this->assertStringStartsWith('custom_', $directory->key);
        $this->assertSame('Acme Photo Booth', SeoCitation::query()->sole()->listed_name);

        $html = $this->page($workspace, $business, $location);
        $this->assertStringContainsString('data-group="custom"', $html);
        $this->assertMatchesRegularExpression('/data-directory="' . $directory->key . '"[^>]*data-custom="1"/', $html);
        $this->assertStringContainsString('data-role="custom-badge"', $html);

        $this->put(route('customer.workspaces.businesses.seo.citations.custom.update', [$workspace->uid, $business->uid, $location->uid, $directory->key]), ['name' => 'Renamed', 'claim_url' => ''])->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $directory->fresh()->name);
        $this->assertNull($directory->fresh()->claim_url);

        $this->post(route('customer.workspaces.businesses.seo.citations.custom.archive', [$workspace->uid, $business->uid, $location->uid, $directory->key]))->assertRedirect();
        $this->assertFalse($directory->fresh()->is_active);
        $this->assertSame(1, SeoCitation::query()->count(), 'Archiving keeps the history.');
    }

    public function test_a_custom_directory_with_an_unsafe_link_is_refused_whole(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $this->addCustom($workspace, $business, $location, ['claim_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('claim_url', null, 'citation_' . $location->uid . '_new_custom');

        $this->assertSame(0, SeoCitationDirectory::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, SeoCitation::query()->count());
    }

    public function test_a_custom_directory_never_leaks_to_another_business_or_a_custom_key_of_another_business_is_a_404(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->addCustom($workspace, $business, $location, ['name' => 'Private Directory'])->assertSessionHasNoErrors();
        $custom = SeoCitationDirectory::query()->where('business_id', $business->id)->sole();

        [$otherCustomer, $otherBusiness, $otherWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $otherLocation = $this->publicStorefront($otherBusiness, 'Other');
        $this->authenticateAsSeoCustomer($otherCustomer);

        $this->assertStringNotContainsString('Private Directory', $this->page($otherWorkspace, $otherBusiness, $otherLocation));
        $this->putCitation($otherWorkspace, $otherBusiness, $otherLocation, $custom->key)->assertNotFound();
        $this->put(route('customer.workspaces.businesses.seo.citations.custom.update', [$otherWorkspace->uid, $otherBusiness->uid, $otherLocation->uid, $custom->key]), ['name' => 'Hijack'])->assertNotFound();
        $this->assertSame('Private Directory', $custom->fresh()->name);
    }

    public function test_a_location_scoped_custom_directory_is_absent_from_other_locations(): void
    {
        [, $business, $workspace, $first] = $this->tenant();
        $second = $this->publicStorefront($business, 'Second Branch');

        $this->addCustom($workspace, $business, $first, ['name' => 'Only At First', 'location_scope' => 'this'])->assertSessionHasNoErrors();
        $custom = SeoCitationDirectory::query()->where('business_id', $business->id)->sole();

        $this->assertStringContainsString('Only At First', $this->page($workspace, $business, $first));
        $this->assertStringNotContainsString('Only At First', $this->page($workspace, $business, $second));
        $this->putCitation($workspace, $business, $second, $custom->key)->assertNotFound();
    }

    public function test_a_platform_directory_cannot_be_edited_or_archived_through_the_custom_routes(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $yelp = $this->directory('yelp');

        $this->put(route('customer.workspaces.businesses.seo.citations.custom.update', [$workspace->uid, $business->uid, $location->uid, 'yelp']), ['name' => 'Hacked', 'claim_url' => 'https://evil.example.com'])->assertNotFound();
        $this->post(route('customer.workspaces.businesses.seo.citations.custom.archive', [$workspace->uid, $business->uid, $location->uid, 'yelp']))->assertNotFound();

        $this->assertSame($yelp->name, $yelp->fresh()->name);
        $this->assertTrue($yelp->fresh()->is_active);
    }

    public function test_the_custom_directory_count_has_a_safety_ceiling(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        config(['seo.citations.max_custom_directories' => 2]);

        $this->addCustom($workspace, $business, $location, ['name' => 'One'])->assertSessionHasNoErrors();
        $this->addCustom($workspace, $business, $location, ['name' => 'Two'])->assertSessionHasNoErrors();
        $this->addCustom($workspace, $business, $location, ['name' => 'Three'])->assertSessionHasErrors('name', null, 'citation_' . $location->uid . '_new_custom');

        $this->assertSame(2, SeoCitationDirectory::query()->where('business_id', $business->id)->count());
    }

    // -----------------------------------------------------------------
    // Not applicable / restore
    // -----------------------------------------------------------------

    private function applicability($workspace, Business $business, BusinessLocation $location, string $key, bool $applicable)
    {
        return $this->from($this->citationsUrl($workspace, $business))
            ->post(route('customer.workspaces.businesses.seo.citations.applicability', [$workspace->uid, $business->uid, $location->uid, $key]), ['applicable' => $applicable ? 1 : 0]);
    }

    public function test_not_applicable_removes_a_directory_from_progress_and_attention_and_restore_keeps_what_was_recorded(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, $this->directory('bbb'), ['status' => SeoCitationStatus::Listed->value, 'listed_name' => 'Recorded Name', 'listing_url' => 'https://www.bbb.org/us/il/biz']);

        $before = $this->section($customer, $workspace, $business, $location)->summary();

        $this->applicability($workspace, $business, $location, 'bbb', false)->assertSessionHasNoErrors();
        $this->assertSame(SeoCitationStatus::NotApplicable, SeoCitation::query()->sole()->status);
        $this->assertSame('Recorded Name', SeoCitation::query()->sole()->listed_name, 'Marking not applicable keeps the record.');

        $after = $this->section($customer, $workspace, $business, $location)->summary();
        $this->assertSame($before['tracked'] - 1, $after['tracked']);
        $this->assertSame(1, $after['notApplicable']);
        $this->assertNotContains('bbb', array_map(fn ($i) => $i['row']?->directory->key, $this->section($customer, $workspace, $business, $location)->attentionItems()));

        $this->applicability($workspace, $business, $location, 'bbb', true)->assertSessionHasNoErrors();
        $restored = SeoCitation::query()->sole();
        $this->assertSame(SeoCitationStatus::NotStarted, $restored->status);
        $this->assertSame('Recorded Name', $restored->listed_name);
    }

    public function test_marking_a_never_touched_directory_not_applicable_creates_exactly_one_row_and_restoring_it_is_not_started(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $this->applicability($workspace, $business, $location, 'mapquest', false);
        $this->assertSame(1, SeoCitation::query()->count());
        $this->applicability($workspace, $business, $location, 'mapquest', true);
        $this->assertSame(SeoCitationStatus::NotStarted, SeoCitation::query()->sole()->status);
    }

    public function test_applicability_respects_location_access_and_an_archived_location(): void
    {
        [, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->publicStorefront($business, 'Hidden');
        $this->authenticateAsSeoCustomer($this->selectedScopeMember($workspace, [$granted]));

        $this->applicability($workspace, $business, $hidden, 'bbb', false)->assertNotFound();
        $this->assertSame(0, SeoCitation::query()->count());
    }

    // -----------------------------------------------------------------
    // Progress is defined, not implied
    // -----------------------------------------------------------------

    public function test_completion_needs_listed_and_recorded_details_and_the_catalog_alone_is_never_complete(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();

        $untouched = $this->section($customer, $workspace, $business, $location)->summary();
        $this->assertSame(0, $untouched['completed']);

        // Listed but nothing recorded: not complete.
        $this->makeCitation($business, $location, $this->directory('apple_business'), ['status' => SeoCitationStatus::Listed->value]);
        $this->assertSame(0, $this->section($customer, $workspace, $business, $location)->summary()['completed']);

        // Listed with a recorded value: complete, and counted toward Essential.
        SeoCitation::query()->update(['listed_name' => $business->name]);
        $summary = $this->section($customer, $workspace, $business, $location)->summary();
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(1, $summary['essentialDone']);
        $this->assertSame(0, $summary['recommendedDone']);

        // In progress with details recorded: still not complete.
        $this->makeCitation($business, $location, $this->directory('yelp'), ['status' => SeoCitationStatus::InProgress->value, 'listed_name' => $business->name]);
        $this->assertSame(1, $this->section($customer, $workspace, $business, $location)->summary()['completed']);
    }

    public function test_essential_total_counts_core_essentials_plus_google_and_excludes_not_applicable(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();

        $total = $this->section($customer, $workspace, $business, $location)->summary()['essentialTotal'];
        $this->assertSame(4, $total, 'Apple, Bing, Yelp + the Google row.');

        $this->bindGoogleLocation($business, $location, $this->activeConnection($business));
        $summary = $this->section($customer, $workspace, $business, $location)->summary();
        $this->assertSame(4, $summary['essentialTotal']);
        $this->assertSame(1, $summary['essentialDone'], 'A connected Google row is complete.');

        $this->applicability($workspace, $business, $location, 'yelp', false);
        $this->assertSame(3, $this->section($customer, $workspace, $business, $location)->summary()['essentialTotal']);
    }

    public function test_the_summary_page_shows_progress_and_no_invented_scores(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $html = strtolower($this->page($workspace, $business, $location));

        $this->assertStringContainsString('data-progress="essential"', $html);
        $this->assertStringContainsString('data-progress="recommended"', $html);
        foreach (['visibility score', 'citation authority', 'seo score', 'guaranteed', 'rank higher', 'improve your rankings'] as $myth) {
            $this->assertStringNotContainsString($myth, $html);
        }
    }

    // -----------------------------------------------------------------
    // What should I do next? — prioritised, exact actions
    // -----------------------------------------------------------------

    public function test_next_steps_are_ordered_essential_missing_then_essential_inaccurate_then_recommended_then_stale_then_optional(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['title' => $business->name]);

        // Essential + inaccurate (Bing: wrong phone).
        $this->makeCitation($business, $location, $this->directory('bing_places'), ['status' => SeoCitationStatus::Listed->value, 'listed_phone' => '+19998887777']);
        // Essential + done (Apple), Yelp essential missing.
        $this->makeCitation($business, $location, $this->directory('apple_business'), ['status' => SeoCitationStatus::Listed->value, 'listed_name' => $business->name]);
        // Recommended + stale (Facebook listed 200 days ago).
        $this->makeCitation($business, $location, $this->directory('facebook_pages'), ['status' => SeoCitationStatus::Listed->value, 'listed_name' => $business->name, 'last_verified_at' => now()->subDays(200)->toDateString()]);

        $items = $this->section($customer, $workspace, $business, $location)->attentionItems();
        $priorities = array_column($items, 'priority');

        $this->assertSame($priorities, collect($priorities)->sort()->values()->all(), 'Items are sorted by priority.');

        $byKey = collect($items)->keyBy(fn ($i) => $i['row']?->directory->key ?? 'google');
        $this->assertSame(1, $byKey['yelp']['priority']);
        $this->assertSame('Claim', $byKey['yelp']['action'], 'Missing with a claim link: Claim.');
        $this->assertSame(2, $byKey['bing_places']['priority']);
        $this->assertSame('Review', $byKey['bing_places']['action']);
        $this->assertSame(3, $byKey['gigsalad']['priority'], 'A Recommended niche listing that is missing.');
        $this->assertSame(4, $byKey['facebook_pages']['priority']);
        $this->assertSame('stale', $byKey['facebook_pages']['kind']);
        $this->assertSame(5, $byKey['bark']['priority'], 'An Optional listing is last.');
        $this->assertArrayNotHasKey('apple_business', $byKey->all(), 'A completed accurate listing needs nothing.');
        $this->assertArrayNotHasKey('google', $byKey->all(), 'A connected Google row needs nothing.');

        $this->assertSame('Record details', $byKey['yellow_pages']['action'], 'No claim link: Record details.');
    }

    public function test_nothing_is_listed_next_when_nothing_needs_doing(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['title' => $business->name]);
        SeoCitationDirectory::query()->update(['is_active' => false]);

        $this->assertSame([], $this->section($customer, $workspace, $business, $location)->attentionItems());
        $this->assertStringNotContainsString('data-section="citation-actions"', $this->page($workspace, $business, $location));
    }

    public function test_a_manual_listing_past_the_review_window_says_review_recommended_and_a_fresh_one_does_not(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, $this->directory('bbb'), ['listed_name' => $business->name, 'last_verified_at' => now()->subDays(120)->toDateString()]);
        $this->makeCitation($business, $location, $this->directory('yelp'), ['listed_name' => $business->name, 'last_verified_at' => now()->subDays(10)->toDateString()]);

        $html = $this->page($workspace, $business, $location);

        $this->assertSame(1, substr_count($html, 'data-role="review-due"'));
        $this->assertStringContainsString('Review recommended', $html);

        config(['seo.citations.review_after_days' => 7]);
        $this->assertSame(2, substr_count($this->page($workspace, $business, $location), 'data-role="review-due"'));
    }

    // -----------------------------------------------------------------
    // Website comparison and normalization
    // -----------------------------------------------------------------

    public function test_website_normalization_ignores_scheme_www_case_trailing_slash_and_fragment_but_not_the_path(): void
    {
        $c = app(SeoNapComparator::class);

        foreach (['http://www.Example.test/', 'https://example.test', 'EXAMPLE.test', 'https://www.example.test/#top'] as $same) {
            $this->assertSame(SeoNapFieldResult::Consistent, $c->compareWebsite('https://example.test', $same), $same);
        }

        $this->assertSame(SeoNapFieldResult::NotComparable, $c->compareWebsite('https://example.test', 'https://example.test/other'), 'The same site with another page is unable to verify, not a mismatch.');
        $this->assertSame(SeoNapFieldResult::Mismatch, $c->compareWebsite('https://example.test', 'https://example.com'));
        $this->assertSame(SeoNapFieldResult::Mismatch, $c->compareWebsite('https://a.example.test', 'https://example.test'), 'No fuzzy matching of subdomains.');
        $this->assertSame(SeoNapFieldResult::NotComparable, $c->compareWebsite('https://example.test', null));
        $this->assertSame(SeoNapFieldResult::NotComparable, $c->compareWebsite(null, 'https://example.test'));
        $this->assertSame(SeoNapFieldResult::NotComparable, $c->compareWebsite('https://example.test', 'not a url at all'));
    }

    public function test_a_recorded_website_is_compared_and_a_missing_one_is_neither_a_mismatch_nor_unchecked(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, $this->directory('bbb'), ['listed_name' => $business->name, 'listed_phone' => '+1 (555) 010-1234', 'listed_website' => 'http://www.example.test/']);
        $this->makeCitation($business, $location, $this->directory('yelp'), ['listed_name' => $business->name, 'listed_phone' => '+1 (555) 010-1234', 'listed_address' => '12 High Street, Springfield, IL, 62701, US']);
        $this->makeCitation($business, $location, $this->directory('foursquare'), ['listed_name' => $business->name, 'listed_website' => 'https://wrong.example.org']);

        $rows = collect($this->section($customer, $workspace, $business, $location)->rows)->keyBy(fn ($r) => $r->directory->key);

        $this->assertSame(SeoNapFieldResult::Consistent, $rows['bbb']->websiteResult);
        $this->assertSame(SeoNapFieldResult::NotComparable, $rows['yelp']->websiteResult);
        $this->assertSame('3 / 3 match', $rows['yelp']->napBadge()['label'], 'A missing website does not dilute an otherwise full match.');
        $this->assertSame(SeoNapFieldResult::Mismatch, $rows['foursquare']->websiteResult);
        $this->assertSame('Website differs', $rows['foursquare']->napBadge()['label']);
        $this->assertContains('website', $rows['foursquare']->napTally()['differing']);
    }

    public function test_setup_and_nap_are_two_separate_badges(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, $this->directory('bing_places'), ['status' => SeoCitationStatus::Listed->value, 'listed_name' => $business->name, 'listed_phone' => '+19998887777']);

        $html = $this->page($workspace, $business, $location);

        $this->assertMatchesRegularExpression('/data-directory="bing_places".*?data-role="citation-status"[^>]*>.*?Listed/s', $html);
        $this->assertMatchesRegularExpression('/data-directory="bing_places".*?data-role="nap-status"[^>]*>.*?Phone differs/s', $html);
        $this->assertSame(1, preg_match('/data-directory="mapquest".*?data-role="nap-status"[^>]*>.*?Not checked/s', $html));
    }

    // -----------------------------------------------------------------
    // Google: compared live, never stored
    // -----------------------------------------------------------------

    public function test_a_connected_google_profile_is_checked_automatically_and_compared_without_storing_anything(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, [
            'title' => $business->name,
            'phone_primary' => '+15550101234',
            'website_uri' => 'https://www.example.test/',
        ]);
        $before = $this->dbFingerprint(['seo_citations', 'business_google_locations']);

        $section = $this->section($customer, $workspace, $business, $location);

        $this->assertTrue($section->googleCheckedAutomatically());
        $this->assertSame(
            ['name' => SeoNapFieldResult::Consistent, 'phone' => SeoNapFieldResult::Consistent, 'website' => SeoNapFieldResult::Consistent],
            $section->googleNap,
        );

        $html = $this->page($workspace, $business, $location);
        $this->assertStringContainsString('Checked automatically', $html);
        $this->assertMatchesRegularExpression('/data-google-field="phone" data-result="consistent"/', $html);
        $this->assertSame($before, $this->dbFingerprint(['seo_citations', 'business_google_locations']), 'Nothing Google-derived is stored.');
    }

    public function test_google_differences_are_shown_and_an_expired_mirror_claims_no_automatic_check(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $google = $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['title' => 'Another Name', 'phone_primary' => '+15550101234', 'website_uri' => 'https://example.test']);

        $section = $this->section($customer, $workspace, $business, $location);
        $this->assertSame(SeoNapFieldResult::Mismatch, $section->googleNap['name']);
        $this->assertSame('Review', collect($section->attentionItems())->firstWhere('name', 'Google Business Profile')['action']);

        $google->forceFill(['mirror_expires_at' => now()->subDay()])->save();

        $stale = $this->section($customer, $workspace, $business, $location);
        $this->assertNull($stale->googleNap, 'An expired mirror is absent, never stale-but-shown.');
        $this->assertFalse($stale->googleCheckedAutomatically());
        $this->assertStringNotContainsString('Checked automatically', $this->page($workspace, $business, $location));
    }

    public function test_google_never_exposes_a_street_address_comparison(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business));

        $nap = $this->section($customer, $workspace, $business, $location)->googleNap;

        $this->assertSame(['name', 'phone', 'website'], array_keys($nap));
    }

    public function test_a_lost_google_connection_is_not_checked_automatically(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business, ['state' => GoogleConnectionState::Revoked]));

        $section = $this->section($customer, $workspace, $business, $location);

        $this->assertFalse($section->googleCheckedAutomatically());
        $this->assertSame(SeoCitationLocationSection::GOOGLE_CONNECTION_LOST, $section->googleState());
    }

    // -----------------------------------------------------------------
    // Filters, search, location isolation
    // -----------------------------------------------------------------

    public function test_filter_and_search_controls_and_data_hooks_are_rendered(): void
    {
        [, $business, $workspace, $location] = $this->tenant();

        $html = $this->page($workspace, $business, $location);

        foreach (['all', 'essential', 'recommended', 'attention', 'notchecked', 'accurate', 'custom'] as $filter) {
            $this->assertStringContainsString('data-filter="' . $filter . '"', $html);
        }
        $this->assertStringContainsString('data-role="citation-search"', $html);
        $this->assertMatchesRegularExpression('/data-directory="yelp"[^>]*data-name="yelp for business"/', $html);
    }

    public function test_citations_and_progress_never_cross_locations(): void
    {
        [$customer, $business, $workspace, $first] = $this->tenant();
        $second = $this->publicStorefront($business, 'Second Branch');
        $this->makeCitation($business, $first, $this->directory('apple_business'), ['status' => SeoCitationStatus::Listed->value, 'listed_name' => 'First-Only-Name']);

        $this->assertSame(1, $this->section($customer, $workspace, $business, $first)->summary()['completed']);
        $this->assertSame(0, $this->section($customer, $workspace, $business, $second)->summary()['completed']);
        $this->assertStringNotContainsString('First-Only-Name', $this->page($workspace, $business, $second));
    }

    public function test_the_new_write_routes_fail_closed_for_a_forged_location_and_without_manage_seo(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenant();
        $foreignLocation = $this->publicStorefront($this->entitledTenant(WorkspacePlanTier::Growth)[1], 'Foreign');
        $this->authenticateAsSeoCustomer($customer);

        $this->applicability($workspace, $business, $foreignLocation, 'bbb', false)->assertNotFound();
        $this->addCustom($workspace, $business, $foreignLocation)->assertNotFound();

        $this->authenticateAsSeoCustomer($customer, ['view_seo']);
        $this->applicability($workspace, $business, $location, 'bbb', false)->assertUnauthorized();
        $this->addCustom($workspace, $business, $location)->assertUnauthorized();
        $this->assertSame(0, SeoCitation::query()->count());
    }

    public function test_the_manager_source_still_has_no_http_queue_cache_or_provider_call(): void
    {
        foreach (['SeoCitationManager', 'SeoCitationLocationSection', 'SeoCitationRow', 'SeoCitationCatalogManager'] as $class) {
            $code = file_get_contents(dirname(__DIR__, 3) . '/app/Library/Seo/' . $class . '.php');
            $code = preg_replace('#/\*.*?\*/#s', '', $code);

            foreach (['Http::', 'Cache::', 'Queue::', 'dispatch(', 'Bus::', 'curl_', 'file_get_contents(', 'GoogleBusinessProfileReadClient'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$class} must not contain {$forbidden}");
            }
        }
    }

    public function test_viewing_the_page_writes_nothing(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $before = $this->dbFingerprint(['seo_citations', 'seo_citation_directories', 'seo_niche_citation_recommendations']);

        $this->page($workspace, $business, $location);

        $this->assertSame($before, $this->dbFingerprint(['seo_citations', 'seo_citation_directories', 'seo_niche_citation_recommendations']));
    }
}
