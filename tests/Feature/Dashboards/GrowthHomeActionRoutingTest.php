<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Growth\GrowthNavigation;
use App\Library\Growth\GrowthRuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Growth Home — the Best Next Move's primary button goes straight to the owning module (through GrowthNavigation's closed map and the
 * same link gate every Home link passes), and falls back to the recommendation detail only when no authorized destination exists.
 */
class GrowthHomeActionRoutingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    /** @return array{0: string, 1: string} href and label of the red card's primary action */
    private function cta(): array
    {
        $html = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#href="([^"]+)"\s+data-role="next-best-move-action">\s*([^<]+?)\s*<#', $html, $m), 'The Best Next Move has one primary action.');

        return [html_entity_decode($m[1]), trim($m[2])];
    }

    public function test_every_growth_navigation_target_resolves_to_a_registered_business_scoped_route(): void
    {
        $this->assertNotEmpty(GrowthNavigation::targets());

        foreach (GrowthNavigation::targets() as $target) {
            $access = GrowthNavigation::access($target);

            $this->assertNotNull($access, "Target [{$target}] has a registered route and a reviewed access rule.");
            $this->assertTrue(Route::has($access['route']), "{$target} → {$access['route']}");
            $this->assertSame(
                route($access['route'], ['ws-uid', 'biz-uid']),
                GrowthNavigation::url($target, 'ws-uid', 'biz-uid'),
                "{$target} is scoped to the given workspace and business.",
            );
        }

        $this->assertSame(route('customer.workspaces.businesses.website.show', ['w', 'b']), GrowthNavigation::url('website', 'w', 'b'));
        $this->assertSame(route('customer.workspaces.businesses.calendar.index', ['w', 'b']), GrowthNavigation::url('booking_types', 'w', 'b'));
    }

    public function test_every_rule_target_is_in_the_closed_map(): void
    {
        foreach (GrowthRuleRegistry::all() as $rule) {
            $target = $rule->definition()->target;

            $this->assertContains($target, GrowthNavigation::targets(), "{$rule->definition()->key} targets [{$target}], which is not in the reviewed map.");
        }
    }

    public function test_an_unknown_target_has_no_url_and_no_access(): void
    {
        $this->assertNull(GrowthNavigation::url('nowhere', 'w', 'b'));
        $this->assertNull(GrowthNavigation::access('nowhere'));
    }

    public function test_website_not_published_is_one_click_to_the_business_website_module(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Route Home', 'Route Home Account');
        $this->recommendation($business, ['worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => 'Your website is not published', 'priority_score' => 90]);
        $this->authenticateAs($owner);

        [$href, $label] = $this->cta();

        $this->assertSame('Finish your website', $label);
        $this->assertSame(route('customer.workspaces.businesses.website.show', [$workspace->uid, $business->uid]), $href, 'Straight to THIS Business\'s Website module, not Growth Opportunities.');
        $this->assertStringNotContainsString('/growth/', $href);
    }

    public function test_forms_recommendation_goes_straight_to_forms(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Forms Route', 'Forms Route Account');
        $this->recommendation($business, ['worker_key' => 'sales', 'type' => 'forms.active_without_submissions:v1', 'title' => 'Your form has no submissions', 'priority_score' => 90]);
        $this->authenticateAs($owner);

        [$href] = $this->cta();

        $this->assertSame(route('customer.workspaces.businesses.forms.index', [$workspace->uid, $business->uid]), $href);
    }

    public function test_without_the_website_permission_the_primary_action_falls_back_to_the_recommendation_detail(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'No Website Perm', 'No Website Perm Account');
        $this->recommendation($business, ['worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => 'Your website is not published', 'priority_score' => 90]);
        // This fixture reads permissions from the session, not Customer::permissions.
        // Deny Website explicitly while keeping the permissions needed to view Growth Home.
        $this->authenticateAs($owner->fresh(), array_values(array_diff($this->allCustomerPermissions(), ['website'])));

        [$href, $label] = $this->cta();

        $this->assertSame('See details', $label);
        $this->assertStringContainsString('/growth/opportunities/', $href);
        $this->assertNotSame(route('customer.workspaces.businesses.website.show', [$workspace->uid, $business->uid]), $href, 'No link to a module this actor cannot open.');
    }
}
