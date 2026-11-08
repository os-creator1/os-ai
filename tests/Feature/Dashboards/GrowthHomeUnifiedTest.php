<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * The unified Growth Home over HTTP: layout, retired sections gone, the Google card and its real link, authorization, first-use and
 * Core isolation. (Rank maths lives in HomeGoogleVisibilityTest, which uses the SEO fixtures; the two fixture sets are never combined.)
 */
class GrowthHomeUnifiedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    public function test_growth_home_has_the_new_layout_and_none_of_the_retired_sections(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Unified Venue', 'Unified Account');
        $this->authenticateAs($customer);
        $html = $this->home()->assertOk()->getContent();

        foreach (['data-role="growth-home"', 'data-role="growth-performance"', 'data-role="growth-activity"', 'data-band="ask"'] as $marker) {
            $this->assertStringContainsString($marker, $html, $marker);
        }
        foreach (['growth-stand', 'growth-progress', 'growth-configured', 'growth-handling', 'growth-whats-next', 'Where you stand', 'Your progress', 'Already set up', 'MotionGrove is handling', "What's next"] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }
        $this->assertLessThanOrEqual(1, substr_count($html, 'data-role="next-best-move"'));
        $this->assertStringNotContainsString('data-role="growth-evidence" open', $html, 'Evidence is collapsed by default.');
        $this->assertSame(1, substr_count($html, 'data-role="home-ask"'));
    }

    public function test_an_entitled_growth_owner_sees_the_google_card_and_its_link_reaches_the_seo_keywords_page(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Card Venue', 'Card Account');
        $this->authenticateAs($customer);
        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-role="google-visibility"', $html);
        $url = route('customer.workspaces.businesses.seo.keywords.index', [$workspace->uid, $business->uid]);
        $this->assertStringContainsString('href="' . $url . '"', $html);
        $this->assertStringContainsString('Not tracking', $html, 'No keywords tracked: the honest initial state.');
        $this->get($url)->assertOk();
    }

    public function test_an_actor_without_view_seo_gets_no_google_card_and_no_seo_link(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'No Seo Venue', 'No Seo Account');
        $this->authenticateAs($customer, []);
        $html = $this->home()->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="google-visibility"', $html);
        $this->assertStringNotContainsString('/seo/keywords', $html);
    }

    public function test_activity_is_newest_first_and_pending_work_is_not_interleaved_with_dated_work(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Feed Venue', 'Feed Account');
        $this->website($business, 'published');
        $this->websiteRevision($business, 1, now()->subHours(30)->toDateTimeString());
        $this->websiteRevision($business, 2, now()->subHours(3)->toDateTimeString());
        $this->authenticateAs($customer);
        $html = $this->home()->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Website version 1 published'), strpos($html, 'Website version 2 published'), 'Newest first.');
        $this->assertStringContainsString('Recorded', $html, 'A website publication is recorded, not claimed as automatic MotionGrove work.');
        if (str_contains($html, 'data-role="current-work"')) {
            $this->assertLessThan(strpos($html, 'data-role="activity-items"'), strpos($html, 'data-role="current-work"'));
        }
        $this->assertLessThanOrEqual(10, substr_count($html, 'data-role="activity-item"'));
    }

    public function test_a_new_growth_business_keeps_the_unified_home_not_the_setup_checklist(): void
    {
        [$customer] = $this->bareTenant(WorkspacePlanTier::Growth, 'Fresh Venue', 'Fresh Account');
        $this->authenticateAs($customer);
        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-home-layout="growth"', $html);
        $this->assertStringContainsString('data-role="growth-home"', $html);
        $this->assertStringNotContainsString('data-role="home-setup"', $html);
        $this->assertStringNotContainsString('data-role="setup-progress"', $html);
    }

    public function test_core_home_never_carries_growth_home_markup_and_new_core_keeps_setup(): void
    {
        [$customer] = $this->bareTenant(WorkspacePlanTier::Core, 'Core Fresh', 'Core Fresh Account');
        $this->authenticateAs($customer);
        $html = $this->home()->assertOk()->getContent();

        foreach (['data-role="growth-home"', 'data-role="google-visibility"', 'data-role="growth-activity"'] as $marker) {
            $this->assertStringNotContainsString($marker, $html, $marker);
        }
        $this->assertStringContainsString('data-home-layout="setup"', $html);
    }

    public function test_growth_home_links_to_the_work_history_and_core_home_does_not(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Work Link Venue', 'Work Link Account');
        $this->authenticateAs($customer);
        $html = $this->home()->assertOk()->getContent();

        $url = route('customer.workspaces.businesses.growth.work', [$workspace->uid, $business->uid]);
        $this->assertStringContainsString('data-role="growth-work-link"', $html);
        $this->assertStringContainsString('href="' . $url . '"', $html);
        $this->get($url)->assertOk();

        [$core] = $this->tenant(WorkspacePlanTier::Core, 'Core Work Venue', 'Core Work Account');
        $this->authenticateAs($core);
        $this->assertStringNotContainsString('data-role="growth-work-link"', $this->home()->assertOk()->getContent());

        // A user without the advisor capability gets no link at all.
        $this->authenticateAs($customer, ['view_reports']);
        $this->assertStringNotContainsString('data-role="growth-work-link"', $this->home()->assertOk()->getContent());
    }
}
