<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Dashboard\DashboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Growth Home's primary action must establish a real website before routine
 * SEO/reputation work, without treating an existing external site as missing.
 */
class GrowthHomeWebsitePriorityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function primary($customer): ?array
    {
        return $this->dashboardFor($customer->user)->band(DashboardSnapshot::BAND_COMMAND)['primary'];
    }

    public function test_missing_primary_service_shows_the_actionable_setup_step_instead_of_a_review_alert(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Incomplete Booth', 'Incomplete WS');
        $this->adoptSeoFirstPolicy($business);
        DB::table('business_services')->where('business_id', $business->id)->delete();
        $this->recommendation($business, [
            'worker_key' => 'reputation',
            'type' => 'reviews.no_review_link:v1',
            'title' => 'No review link is saved for this location',
            'priority_score' => 95,
        ]);
        $this->authenticateAs($customer);

        $html = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="setup-step"', $html);
        $this->assertStringContainsString('Add your primary service', $html);
        $this->assertStringNotContainsString('data-move="website_setup"', $html);
        $this->assertStringNotContainsString('data-move="seo_keyword_activation"', $html);
    }

    public function test_missing_website_outranks_a_review_task_and_opens_the_authorised_website_screen(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Site Missing Booth', 'Site Missing WS');
        $this->recommendation($business, [
            'worker_key' => 'reputation',
            'type' => 'reviews.no_review_link:v1',
            'title' => 'No review link is saved for this location',
            'priority_score' => 95,
        ]);
        $this->authenticateAs($customer);

        $move = $this->primary($customer);

        $this->assertSame('website_setup', $move['key']);
        $this->assertSame('setup', $move['kind']);
        $this->assertSame(
            route('customer.workspaces.businesses.website.show', [$workspace->uid, $business->uid]),
            $move['action']['url'],
        );
        $this->assertSame('Set up website', $move['action']['label']);
        $this->assertStringContainsString('nothing goes live until you publish', strtolower($move['summary']));
        $html = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-move="website_setup"', $html);
        $this->assertStringNotContainsString('data-move="seo_keyword_activation"', $html);
        $this->assertSame(1, substr_count($html, 'data-role="next-best-move"'));
    }

    public function test_existing_external_website_does_not_prompt_a_duplicate_site(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Existing Site Booth', 'External WS');
        DB::table('businesses')->where('id', $business->id)->update([
            'website_mode' => 'external',
            'website_url' => 'https://example.com',
        ]);
        $this->authenticateAs($customer);

        $move = $this->primary($customer);
        $this->assertNotSame('website_setup', $move['key']);
    }

    public function test_saved_external_address_without_selected_mode_prompts_connection_not_a_duplicate_build(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Saved Website Booth', 'Saved Website WS');
        DB::table('businesses')->where('id', $business->id)->update(['website_url' => 'https://example.com']);
        $this->authenticateAs($customer);

        $move = $this->primary($customer);
        $this->assertSame('website_setup', $move['key']);
        $this->assertSame('Review existing website', $move['action']['label']);
        $this->assertStringContainsString('instead of creating a duplicate', $move['summary']);
    }

    public function test_a_published_hosted_website_is_not_a_missing_website(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Published Site Booth', 'Published Site WS');
        $this->website($business, 'published');
        $this->authenticateAs($customer);

        $this->assertNotSame('website_setup', $this->primary($customer)['key']);
    }

    public function test_an_urgent_customer_reply_still_outranks_missing_website(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Urgent Site Booth', 'Urgent Site WS');
        $this->conversationWith($business, [['incoming', now()->subMinutes(30)]]);
        $this->authenticateAs($customer);

        $this->assertSame('conversations_awaiting_reply', $this->primary($customer)['key']);
    }

    public function test_a_verified_ads_delivery_issue_stays_ahead_of_site_setup(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Ads Running Booth', 'Ads Running WS');
        $this->recommendation($business, [
            'worker_key' => 'ads',
            'type' => 'ads.delivery_issue:v1',
            'title' => 'An ad is not delivering properly',
            'priority_score' => 98,
        ]);
        $this->authenticateAs($customer);

        $this->assertNotSame('website_setup', $this->primary($customer)['key']);
    }

    public function test_a_verified_booking_problem_stays_ahead_of_site_setup(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Booking Site Booth', 'Booking Site WS');
        $this->recommendation($business, [
            'worker_key' => 'sales',
            'type' => 'booking.type_not_ready:v1',
            'title' => 'A booking type is not ready',
            'priority_score' => 95,
        ]);
        $this->authenticateAs($customer);

        $this->assertNotSame('website_setup', $this->primary($customer)['key']);
    }
}
