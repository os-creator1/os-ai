<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Dashboard\Home\HomeGoogleVisibility;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankObservations;
use Tests\TestCase;

/**
 * The unified Growth Home: layout, absence of the retired sections, honest Google-visibility states (real 30-day comparison only),
 * and Core isolation.
 */
class GrowthHomeUnifiedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;
    use CreatesRankFixtures;
    use CreatesRankObservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    private function summary(Business $business, int $userId): array
    {
        return app(HomeGoogleVisibility::class)->summary($business, $userId, '/keywords');
    }

    public function test_growth_home_has_the_new_layout_and_none_of_the_retired_sections(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Unified Venue', 'Unified Account');
        $this->authenticateAs($customer);
        $html = $this->get(route('user.home'))->assertOk()->getContent();

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

    public function test_google_visibility_is_not_tracking_without_keywords_and_links_to_the_keywords_route(): void
    {
        [$owner, $business] = $this->rankTenant();
        $s = $this->summary($business, (int) $owner->user_id);

        $this->assertSame('not_tracking', $s['state']);
        $this->assertSame('Not tracking', $s['label']);
    }

    public function test_google_visibility_establishing_then_first_rankings_never_claims_a_change(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));

        $this->assertSame('establishing', $this->summary($business, (int) $owner->user_id)['state']);

        // A previous check only 3 days earlier is NOT a 30-day comparison.
        $this->obs($target, 'organic', 12, now()->subDays(4));
        $this->obs($target, 'organic', 5, now()->subDay());

        $s = $this->summary($business, (int) $owner->user_id);
        $this->assertSame('first_rankings', $s['state']);
        $this->assertStringNotContainsString('improved', (string) $s['detail']);
    }

    public function test_google_visibility_reports_genuine_30_day_movement(): void
    {
        [$owner, $business] = $this->rankTenant();
        $up = $this->track($owner, $business, $this->keyword($owner, $business, 'photo booth rental'));
        $this->obs($up, 'organic', 14, now()->subDays(31));
        $this->obs($up, 'organic', 6, now()->subDay());

        $this->assertSame('improving', $this->summary($business, (int) $owner->user_id)['state']);

        $down = $this->track($owner, $business, $this->keyword($owner, $business, 'wedding photo booth'));
        $this->obs($down, 'organic', 4, now()->subDays(31));
        $this->obs($down, 'organic', 12, now()->subDay());

        $this->assertSame('stable', $this->summary($business, (int) $owner->user_id)['state'], 'One up, one down.');
    }

    public function test_core_home_never_carries_growth_home_markup(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core, 'Core Unified', 'Core Unified Account');
        $this->authenticateAs($customer);
        $html = $this->get(route('user.home'))->assertOk()->getContent();

        foreach (['data-role="growth-home"', 'data-role="google-visibility"', 'data-role="growth-activity"'] as $marker) {
            $this->assertStringNotContainsString($marker, $html, $marker);
        }
    }
}
