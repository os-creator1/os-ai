<?php

namespace Tests\Feature\Growth;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoCitationStatus;
use App\Library\Growth\GrowthThresholds;
use App\Library\Growth\Readers\GrowthCitationFactReader;
use App\Library\Growth\Readers\GrowthReputationFactReader;
use App\Library\Growth\Rules\CitationRules;
use App\Library\Seo\SeoReviewsPageReader;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoNicheCitationRecommendation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * The Growth citation and reputation readers judge by the SAME rules as the
 * Citations and Reviews pages: the page's directory order and importance, the
 * page's "needs setup" / "needs attention" split, the same NAP comparator (an
 * unverifiable field is never a "does not match" finding, the Business phone is
 * not claimed for a secondary Location), and the same effective review link
 * (manual, else the fresh Google link). Fixture business: industry photo booth,
 * phone +15550101234, website https://example.test.
 */
class GrowthCitationReputationReadersTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

    /** @return array{0: \App\Models\Customer, 1: Business, 2: \App\Models\Workspace, 3: BusinessLocation} */
    private function tenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->publicStorefront($business, 'Main Storefront');

        return [$customer, $business, $workspace, $location];
    }

    /** @return array{not_checked: int, needs_attention: int, directories: int} */
    private function citationFacts(Business $business, BusinessLocation $location): array
    {
        return app(GrowthCitationFactReader::class)->read($business->fresh(), CarbonImmutable::now(), app(GrowthThresholds::class))->get('locations')[$location->id];
    }

    /** @return array<int, array{has_link: bool, requests_in_window: int}> */
    private function reviewFacts(Business $business): array
    {
        return app(GrowthReputationFactReader::class)->read($business->fresh(), CarbonImmutable::now(), app(GrowthThresholds::class))->get('locations');
    }

    private function citation(Business $business, BusinessLocation $location, string $directory, SeoCitationStatus $status, array $overrides = []): void
    {
        $this->makeCitation($business, $location, $this->directory($directory), array_merge(['status' => $status->value], $overrides));
    }

    // -----------------------------------------------------------------
    // B8 — priority directories come from the page's order and importance
    // -----------------------------------------------------------------

    public function test_priority_directories_follow_the_page_order_and_skip_the_niches_optional_ones(): void
    {
        [, $business, , $location] = $this->tenant();
        // This niche calls Yelp optional.
        (new SeoNicheCitationRecommendation())->forceFill([
            'niche_key' => 'photo_booth_service', 'seo_citation_directory_id' => $this->directory('yelp')->id,
            'importance' => 'optional', 'sort_order' => 5, 'is_enabled' => true,
        ])->save();

        // The page lists, for this niche: Apple, Bing (essential) then WeddingWire, GigSalad, Eventective (recommended),
        // so those five are the priority directories. The platform's raw sort order would have picked
        // Apple, Bing, Yelp, Facebook, Data Axle instead.
        foreach (['weddingwire_the_knot', 'gigsalad', 'eventective'] as $key) {
            $this->citation($business, $location, $key, SeoCitationStatus::NeedsCorrection);
        }

        $facts = $this->citationFacts($business, $location);

        $this->assertSame(5, $facts['directories']);
        $this->assertSame(3, $facts['needs_attention'], 'The niche-recommended directories are the priority ones.');

        // Optional for this niche, and beyond the first five: neither is ever a priority finding.
        foreach (['yelp', 'facebook_pages', 'bark'] as $key) {
            $this->citation($business, $location, $key, SeoCitationStatus::NeedsCorrection);
        }

        $this->assertSame(3, $this->citationFacts($business, $location)['needs_attention']);
    }

    public function test_an_in_progress_directory_is_not_checked_like_the_page_says_not_a_finding(): void
    {
        [, $business, , $location] = $this->tenant();
        $this->assertSame(5, $this->citationFacts($business, $location)['not_checked']);

        $this->citation($business, $location, 'apple_business', SeoCitationStatus::InProgress);
        $facts = $this->citationFacts($business, $location);
        $this->assertSame(5, $facts['not_checked'], 'Still being set up is not done.');
        $this->assertSame(0, $facts['needs_attention']);

        $this->citation($business, $location, 'bing_places', SeoCitationStatus::Listed, ['listed_name' => $business->name]);
        $this->assertSame(4, $this->citationFacts($business, $location)['not_checked']);
    }

    public function test_a_website_difference_is_a_finding_exactly_as_it_is_on_the_page(): void
    {
        [, $business, , $location] = $this->tenant();

        $this->citation($business, $location, 'apple_business', SeoCitationStatus::Listed, [
            'listed_name' => $business->name, 'listed_website' => 'https://wrong.example.org',
        ]);
        $this->citation($business, $location, 'bing_places', SeoCitationStatus::Listed, [
            'listed_name' => $business->name, 'listed_website' => 'http://www.example.test/',
        ]);

        $this->assertSame(1, $this->citationFacts($business, $location)['needs_attention'], 'A different website counts; the same site written differently does not.');
    }

    // -----------------------------------------------------------------
    // B1 / B13 — never a false "does not match"
    // -----------------------------------------------------------------

    public function test_the_same_address_and_phone_written_differently_and_an_unverifiable_address_are_not_findings(): void
    {
        [, $business, , $location] = $this->tenant();
        DB::table('businesses')->where('id', $business->id)->update(['country_code' => 'US']);

        $this->citation($business, $location, 'apple_business', SeoCitationStatus::Listed, [
            'listed_name' => $business->name, 'listed_phone' => '(555) 010-1234', 'listed_address' => '12 high st, springfield, illinois 62701, usa',
        ]);
        $this->citation($business, $location, 'bing_places', SeoCitationStatus::Listed, [
            'listed_name' => $business->name, 'listed_address' => '12 High Street, Suite 4, Springfield, IL 62701',
        ]);

        $this->assertSame(0, $this->citationFacts($business, $location)['needs_attention']);

        // A real difference still is one.
        $this->citation($business, $location, 'yelp', SeoCitationStatus::Listed, ['listed_name' => $business->name, 'listed_address' => '99 Other Road, Springfield, IL 62701']);
        $this->assertSame(1, $this->citationFacts($business, $location)['needs_attention']);
    }

    public function test_a_secondary_locations_own_phone_is_not_a_finding(): void
    {
        [, $business] = $this->tenant();
        // `is_primary` is not mass-assignable, so flag the main Location on the row, as the app does.
        $primary = $this->createLocation($business, true);
        DB::table('business_locations')->where('id', $primary->id)->update(['is_primary' => true]);
        $secondary = $this->publicStorefront($business, 'Second Branch');

        foreach ([$primary, $secondary] as $location) {
            $this->citation($business, $location, 'apple_business', SeoCitationStatus::Listed, ['listed_name' => $business->name, 'listed_phone' => '+14155550000']);
        }

        $this->assertSame(1, $this->citationFacts($business, $primary)['needs_attention'], 'The main Location carries the Business phone.');
        $this->assertSame(0, $this->citationFacts($business, $secondary)['needs_attention'], 'A secondary Location may list its own number.');
    }

    public function test_the_citation_action_does_not_claim_the_software_can_fix_a_listing(): void
    {
        $definition = (new CitationRules())->definition();

        $this->assertSame('Review listings', $definition->actionLabel);
        $this->assertStringNotContainsString('Fix', $definition->actionLabel);
    }

    // -----------------------------------------------------------------
    // B4 — Growth uses the Reviews page's effective link
    // -----------------------------------------------------------------

    private function manualLink(Business $business, BusinessLocation $location, string $url): void
    {
        DB::table('seo_location_review_links')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'business_location_id' => $location->id,
            'review_url' => $url, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_location_whose_only_link_is_the_fresh_google_one_has_a_review_link(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->authenticateAsSeoCustomer($customer);

        $this->assertFalse($this->reviewFacts($business)[$location->id]['has_link'], 'Nothing yet.');

        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true, ['new_review_uri' => 'https://search.google.test/review']);

        $this->assertTrue($this->reviewFacts($business)[$location->id]['has_link'], 'The page shows "Review link ready" from Google, so Growth must not report a gap.');
        // Parity with the page, by construction.
        $section = collect(app(SeoReviewsPageReader::class)->read($workspace, $business->fresh(), $customer->user))->first(fn ($s) => $s->location->id === $location->id);
        $this->assertNotNull($section->effectiveLink);
    }

    public function test_an_expired_google_mirror_is_no_link_in_growth_or_on_the_page(): void
    {
        $this->bindFakeGoogleClient();
        [$customer, $business, $workspace, $location] = $this->tenant();
        $this->authenticateAsSeoCustomer($customer);
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), false, ['new_review_uri' => 'https://search.google.test/review']);

        $this->assertFalse($this->reviewFacts($business)[$location->id]['has_link']);
        $section = collect(app(SeoReviewsPageReader::class)->read($workspace, $business->fresh(), $customer->user))->first(fn ($s) => $s->location->id === $location->id);
        $this->assertNull($section->effectiveLink);
    }

    public function test_a_manual_link_counts_and_an_unsafe_stored_value_does_not(): void
    {
        [, $business, , $location] = $this->tenant();
        $second = $this->publicStorefront($business, 'Second Branch');
        $this->manualLink($business, $location, 'https://g.page/r/example/review');
        $this->manualLink($business, $second, 'javascript:alert(1)');

        $facts = $this->reviewFacts($business);

        $this->assertTrue($facts[$location->id]['has_link']);
        $this->assertFalse($facts[$second->id]['has_link'], 'The page would not render it as a link, so it is not one.');
    }
}
