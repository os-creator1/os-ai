<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Seo\Rank\Provider\SeoRankProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/** SEO-first Best Next Move on Growth Home: activation wins over routine tasks only, and never over urgent or better-supported moves. */
class GrowthHomeSeoActivationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function reviewLink(\App\Models\Business $business): void
    {
        $this->recommendation($business, ['worker_key' => 'reputation', 'type' => 'reviews.no_review_link:v1', 'title' => 'No review link is saved for this location', 'priority_score' => 95]);
    }

    private function html(): string
    {
        return $this->home()->assertOk()->getContent();
    }

    public function test_without_tracked_keywords_seo_setup_outranks_a_routine_review_link_and_opens_the_keywords_page(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Seo First Venue', 'Seo First Account');
        $this->reviewLink($business);
        $this->authenticateAs($customer);

        $html = $this->html();

        $this->assertStringContainsString('data-move="seo_keyword_activation"', $html);
        $this->assertStringContainsString('Choose the Google searches you want to be found for', $html);
        $keywords = route('customer.workspaces.businesses.seo.keywords.index', [$workspace->uid, $business->uid]);
        $this->assertMatchesRegularExpression('#href="' . preg_quote($keywords, '#') . '" data-role="next-best-move-action"#', $html, 'The action opens this Business\'s own keyword page.');
        $this->assertSame(1, substr_count($html, 'data-role="next-best-move"'), 'Exactly one primary move.');
        $this->assertSame(1, substr_count($html, 'No review link is saved'), 'The displaced task is shown once, as the secondary.');
        $this->assertStringContainsString('so MotionGrove has no ranking data to report', $html, 'Missing data is said plainly, not as poor rankings.');
        $this->assertDoesNotMatchRegularExpression('/lost traffic|you are losing|ranking (is|are) poor|projected|estimated bookings/i', $html);
    }

    public function test_essential_first_use_setup_precedes_keyword_tracking(): void
    {
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Unconfigured Venue', 'Unconfigured Account');
        $this->authenticateAs($customer);

        $html = $this->html();

        $this->assertStringContainsString('data-role="setup-step"', $html);
        $this->assertStringNotContainsString('data-move="seo_keyword_activation"', $html, 'Required first-use setup outranks optional keyword tracking.');
    }

    public function test_urgent_customer_issues_still_win(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Urgent Venue', 'Urgent Account');
        $this->reviewLink($business);
        $this->conversationWith($business, [['incoming', now()->subMinutes(40)]]);
        $this->authenticateAs($customer);

        $html = $this->html();

        $this->assertStringNotContainsString('seo_keyword_activation', $html);
        $this->assertStringContainsString('1 customer needs a reply', $html);
    }

    public function test_a_well_supported_website_opportunity_beats_generic_seo_setup(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Site First Venue', 'Site First Account');
        $this->recommendation($business, ['worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => 'Publish your website', 'priority_score' => 90]);
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('data-move="seo_keyword_activation"', $this->html());
    }

    public function test_activation_needs_manage_seo_and_view_seo(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Perm Seo Venue', 'Perm Seo Account');
        $this->reviewLink($business);

        $this->authenticateAs($customer, ['view_seo']);
        $this->assertStringNotContainsString('seo_keyword_activation', $this->html(), 'Viewing is not enough to start tracking.');

        $this->authenticateAs($customer, []);
        $this->assertStringNotContainsString('seo_keyword_activation', $this->html());
    }

    public function test_core_home_never_gets_the_seo_activation_move(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Core, 'Core Seo Venue', 'Core Seo Account');
        $this->reviewLink($business);
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('seo_keyword_activation', $this->html());
    }

    public function test_opening_home_starts_no_rank_check_and_queues_nothing(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'No Spend Venue', 'No Spend Account');
        $this->authenticateAs($customer);
        Queue::fake();
        $this->app->instance(SeoRankProvider::class, \Mockery::mock(SeoRankProvider::class));

        $this->html();

        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('seo_rank_check_runs')->count());
        $this->assertSame(0, DB::table('seo_rank_targets')->count());
    }

    public function test_a_verified_broken_booking_path_is_not_displaced_by_seo_setup(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Booking Path Venue', 'Booking Path Account');
        $this->recommendation($business, ['worker_key' => 'sales', 'type' => 'booking.type_not_ready:v1', 'title' => 'A booking type is not ready', 'priority_score' => 90]);
        $this->authenticateAs($customer);

        $html = $this->html();

        $this->assertStringNotContainsString('seo_keyword_activation', $html);
        $this->assertStringContainsString('A booking type is not ready', $html);
        $this->assertStringContainsString('Not known yet: which of your new contacts went on to book', $html);
    }

    public function test_the_explanation_states_what_is_unknown_and_never_invents_a_conversion_diagnosis(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Unknown Venue', 'Unknown Account');
        $this->reviewLink($business);
        $this->authenticateAs($customer);

        $html = $this->html();

        $this->assertStringContainsString('Not known yet: how many visitors or enquiries Google sends you', $html);
        $this->assertSame(1, preg_match('/<details[^>]*data-role="growth-evidence"[^>]*>(.*?)<\/details>/s', $html, $evidence), 'The assertion must inspect the move evidence, not unrelated scripts and styles on the full page.');
        $this->assertDoesNotMatchRegularExpression('/conversion rate of|\d+\s*%|lost (traffic|bookings|leads)|will (increase|bring)|projected|uplift/i', strip_tags($evidence[1]));
    }

    public function test_core_evidence_carries_no_growth_unknown_line(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Core, 'Core Unknown Venue', 'Core Unknown Account');
        $this->recommendation($business, ['worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => 'Publish your website', 'priority_score' => 90]);
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('Not known yet:', $this->html());
    }
}
