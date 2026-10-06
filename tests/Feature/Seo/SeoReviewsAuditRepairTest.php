<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Seo\SeoReviewLinkResolver;
use App\Library\Seo\SeoReviewsPageReader;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Concerns\CreatesSeoReviewFixtures;
use Tests\TestCase;

/**
 * Reviews repairs from the SEO V1 final audit: the ledger and the Contact
 * choices are capped for EACH Location (a busy Location cannot starve the
 * others), the header counts are taken over the whole ledger so they cannot
 * disagree with the list, the link copy does not claim it is a Google link, the
 * single "which review link does a Location have" rule, and Reviews staying
 * workflow-only (no rating or count metrics, no review markup).
 */
class SeoReviewsAuditRepairTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bypassReviewEntitlementForTest();
    }

    /** @return array{0: \App\Models\Customer, 1: Business, 2: \App\Models\Workspace, 3: BusinessLocation} */
    private function tenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->reviewLocation($business, 'Main Storefront');

        $this->authenticateAsSeoCustomer($customer, $this->reviewPermissions());

        return [$customer, $business, $workspace, $location];
    }

    /** @return array<int, \App\Library\Seo\SeoReviewLocationSection> keyed by Location id */
    private function sections(\App\Models\Customer $customer, \App\Models\Workspace $workspace, Business $business): array
    {
        $sections = [];

        foreach (app(SeoReviewsPageReader::class)->read($workspace, $business->fresh(), $customer->user) as $section) {
            $sections[(int) $section->location->id] = $section;
        }

        return $sections;
    }

    /** Bulk-writes ledger rows, newest first by `$minutesAgo`. */
    private function ledgerRows(Business $business, BusinessLocation $location, int $count, string $status = 'requested', int $startMinutesAgo = 0, int $stepMinutes = 1): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'uid' => (string) Str::uuid(),
                'business_id' => $business->id,
                'business_location_id' => $location->id,
                'channel' => 'sms',
                'status' => $status,
                'requested_at' => now()->subMinutes($startMinutesAgo + $i * $stepMinutes),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('seo_review_requests')->insert($chunk);
        }
    }

    // -----------------------------------------------------------------
    // B9 — per-Location caps and consistent header counts
    // -----------------------------------------------------------------

    public function test_a_busy_location_cannot_starve_another_locations_ledger(): void
    {
        [$customer, $business, $workspace, $busy] = $this->tenant();
        $quiet = $this->reviewLocation($business, 'Quiet Branch');

        // The quiet Location's requests are all OLDER than every busy one, so a single global
        // "newest 200" would drop them entirely.
        $this->ledgerRows($business, $quiet, 3, 'requested', 60 * 24 * 10);
        $this->ledgerRows($business, $busy, 205, 'requested', 0);

        $sections = $this->sections($customer, $workspace, $business);

        $this->assertCount(SeoReviewsPageReader::LEDGER_LIMIT, $sections[$busy->id]->requests, 'The busy Location is capped to its own newest rows.');
        $this->assertCount(3, $sections[$quiet->id]->requests, 'The quiet Location keeps every one of its rows.');
        $this->assertTrue($sections[$busy->id]->requestsAreTruncated());
        $this->assertFalse($sections[$quiet->id]->requestsAreTruncated());
    }

    public function test_header_counts_are_taken_over_the_whole_ledger_and_say_when_the_list_is_the_latest_only(): void
    {
        [$customer, $business, $workspace, $busy] = $this->tenant();
        $quiet = $this->reviewLocation($business, 'Quiet Branch');
        $this->ledgerRows($business, $quiet, 3, 'requested', 60 * 24 * 10);
        $this->ledgerRows($business, $busy, 200, 'requested', 0);
        $this->ledgerRows($business, $busy, 5, 'reviewed', 500);

        $sections = $this->sections($customer, $workspace, $business);
        $html = $this->get($this->reviewsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertSame(205, $sections[$busy->id]->requestCount);
        $this->assertSame(200, $sections[$busy->id]->awaitingCount, 'Awaiting counts every row, not only the visible ones.');
        $this->assertSame(3, $sections[$quiet->id]->awaitingCount);

        // Whole-ledger totals in the summary tiles: 208 requests, 203 awaiting.
        $this->assertSame(1, preg_match('/data-role="summary-requests">(.*?)<\/div>/s', $html, $m));
        $tile = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
        $this->assertStringContainsString('208', $tile);
        $this->assertStringContainsString('203 awaiting an outcome', $tile);

        $this->assertSame(1, substr_count($html, 'data-role="requests-truncated"'), 'Only the Location whose list is capped says so.');
        $this->assertStringContainsString('Showing the latest 200 of 205 requests recorded.', $html);
        $this->assertStringContainsString('Requests recorded: 205', $html);
    }

    public function test_archived_locations_are_not_counted_as_missing_a_link(): void
    {
        [, $business, $workspace, $active] = $this->tenant();
        $this->makeReviewLink($business, $active);
        $archived = $this->reviewLocation($business, 'Old Branch');
        $this->archiveLocation($archived);

        $html = $this->get($this->reviewsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/data-role="summary-with-link">(.*?)<\/div>/s', $html, $with));
        $this->assertSame(1, preg_match('/data-role="summary-missing-link">(.*?)<\/div>/s', $html, $missing));
        $withText = trim(preg_replace('/\s+/', ' ', strip_tags($with[1])));
        $missingText = trim(preg_replace('/\s+/', ' ', strip_tags($missing[1])));

        $this->assertStringContainsString('With a review link 1 1 active Location in total · 1 archived, not counted', $withText);
        $this->assertStringContainsString('Missing a link 0 Every Location is ready.', $missingText);
        $this->assertStringContainsString('100 percent', $html);
        $this->assertStringNotContainsString('data-role="jump-to-missing"', $html, 'There is nothing to add for an archived Location.');
    }

    public function test_contact_choices_are_capped_for_each_location_not_across_all_of_them(): void
    {
        [$customer, $business, $workspace, $busy] = $this->tenant();
        $quiet = $this->reviewLocation($business, 'Quiet Branch');

        // The quiet Location's Contacts are created FIRST (lower ids): a single global "newest 200" would drop them.
        $this->reviewContact($business, $quiet, []);
        $this->reviewContact($business, $quiet, []);

        for ($i = 0; $i < SeoReviewsPageReader::CONTACT_CHOICE_LIMIT + 1; $i++) {
            $this->reviewContact($business, $busy, []);
        }

        $sections = $this->sections($customer, $workspace, $business);

        $this->assertCount(SeoReviewsPageReader::CONTACT_CHOICE_LIMIT, $sections[$busy->id]->contacts);
        $this->assertCount(2, $sections[$quiet->id]->contacts, 'The quiet Location still offers its own Contacts.');
    }

    public function test_the_page_still_issues_a_constant_number_of_queries_with_per_location_caps(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        // Contacts already exist in the baseline, so only the extra Locations differ.
        $this->makeReviewRequest($business, $location, $this->reviewContact($business, $location));
        $this->ledgerRows($business, $location, 3);
        $url = $this->reviewsUrl($workspace, $business);

        $this->get($url)->assertOk();
        $small = count($this->capturedQueries(fn () => $this->get($url)->assertOk()));

        for ($i = 0; $i < 6; $i++) {
            $extra = $this->reviewLocation($business, 'Extra ' . $i);
            $this->reviewContact($business, $extra);
            $this->ledgerRows($business, $extra, 4);
        }

        $this->assertSame($small, count($this->capturedQueries(fn () => $this->get($url)->assertOk())), 'One query per concern, however many Locations.');
    }

    // -----------------------------------------------------------------
    // Copy: the link is a review link, not necessarily a Google one
    // -----------------------------------------------------------------

    public function test_the_page_says_review_link_not_google_review_link(): void
    {
        [, $business, $workspace] = $this->tenant();

        $html = $this->get($this->reviewsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('Google review link', $html);
        $this->assertStringNotContainsString('leave a Google review', $html);
        $this->assertStringContainsString("Keep each Location's review link in one place", $html);
    }

    public function test_reviews_stay_workflow_only_with_no_rating_metric_or_review_markup(): void
    {
        [, $business, $workspace, $location] = $this->tenant();
        $this->makeReviewLink($business, $location);
        $this->makeReviewRequest($business, $location, null, ['status' => 'reviewed', 'resolved_at' => now()]);

        $html = $this->get($this->reviewsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Reviewed (self-reported)', $html);
        foreach (['aggregateRating', 'ratingValue', 'reviewCount', 'application/ld+json', 'itemtype="https://schema.org/Review'] as $schema) {
            $this->assertStringNotContainsString($schema, $html, "[{$schema}] would be a review metric or markup we do not have.");
        }
    }

    // -----------------------------------------------------------------
    // One rule for "which review link does a Location have"
    // -----------------------------------------------------------------

    public function test_the_effective_link_is_the_manual_link_then_the_google_link_then_none(): void
    {
        $manual = 'https://g.page/r/manual/review';
        $google = 'https://search.google.test/review';

        $this->assertSame(['manual' => $manual, 'effective' => $manual, 'source' => 'manual'], SeoReviewLinkResolver::resolve($manual, $google));
        $this->assertSame(['manual' => null, 'effective' => $google, 'source' => 'google'], SeoReviewLinkResolver::resolve(null, $google));
        $this->assertSame(['manual' => null, 'effective' => null, 'source' => null], SeoReviewLinkResolver::resolve(null, null));

        // A stored value only counts when it is a safe https link — exactly what the page would render.
        $this->assertSame(['manual' => null, 'effective' => $google, 'source' => 'google'], SeoReviewLinkResolver::resolve('javascript:alert(1)', $google));
        $this->assertSame(['manual' => null, 'effective' => null, 'source' => null], SeoReviewLinkResolver::resolve('http://insecure.example.com/x', 'ftp://nope.example.com'));
    }

    public function test_the_page_shows_the_google_link_as_the_fallback_when_there_is_no_manual_link(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['new_review_uri' => 'https://search.google.test/review']);

        $section = $this->sections($customer, $workspace, $business)[$location->id];

        $this->assertSame('https://search.google.test/review', $section->effectiveLink);
        $this->assertSame('google', $section->linkSource);
        $this->assertNull($section->manualLink);
    }
}
