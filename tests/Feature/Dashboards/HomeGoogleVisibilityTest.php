<?php

namespace Tests\Feature\Dashboards;

use App\Library\Dashboard\Home\HomeGoogleVisibility;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankObservations;
use Tests\TestCase;

/** Growth Home's Google visibility states, from stored observations only (SEO fixtures; no provider call). */
class HomeGoogleVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;
    use CreatesRankObservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    private function summary(Business $business, $owner): array
    {
        return app(HomeGoogleVisibility::class)->summary($business, (int) $owner->user_id, '/keywords');
    }

    private function pair($owner, Business $business, string $phrase, string $type, int $then, int $now, int $thenDaysAgo = 31, int $nowDaysAgo = 1)
    {
        $target = $this->track($owner, $business, $this->keyword($owner, $business, $phrase));
        $this->obs($target, $type, $then, now()->subDays($thenDaysAgo));
        $this->obs($target, $type, $now, now()->subDays($nowDaysAgo));

        return $target;
    }

    public function test_no_keywords_is_not_tracking_and_a_tracked_keyword_without_checks_is_establishing(): void
    {
        [$owner, $business] = $this->rankTenant();
        $this->assertSame('not_tracking', $this->summary($business, $owner)['state']);

        $this->track($owner, $business, $this->keyword($owner, $business));
        $this->assertSame('establishing', $this->summary($business, $owner)['state']);
    }

    public function test_a_stopped_target_is_ignored(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->pair($owner, $business, 'old phrase', 'local', 14, 6);
        $target->forceFill(['tracking_state' => 'stopped'])->save();

        $this->assertSame('not_tracking', $this->summary($business, $owner)['state']);
    }

    public function test_no_baseline_is_first_rankings_and_claims_no_change(): void
    {
        [$owner, $business] = $this->rankTenant();
        $this->pair($owner, $business, 'recent only', 'local', 12, 5, 4, 1);

        $s = $this->summary($business, $owner);
        $this->assertSame('first_rankings', $s['state']);
        $this->assertStringNotContainsString('improved', (string) $s['detail']);
    }

    public function test_only_stale_or_not_found_observations_are_not_first_rankings(): void
    {
        [$owner, $business] = $this->rankTenant();
        $stale = $this->track($owner, $business, $this->keyword($owner, $business, 'stale'));
        $this->obs($stale, 'local', 7, now()->subDays(20));
        $gone = $this->track($owner, $business, $this->keyword($owner, $business, 'gone'));
        $this->obs($gone, 'local', null, now()->subDay());

        $this->assertSame('no_recent', $this->summary($business, $owner)['state']);
    }

    public function test_improving_declining_mixed_and_stable_are_distinct(): void
    {
        [$owner, $business] = $this->rankTenant();
        $this->pair($owner, $business, 'one', 'local', 14, 6);
        $this->assertSame('improving', $this->summary($business, $owner)['state']);

        $this->pair($owner, $business, 'two', 'local', 4, 12);
        $this->assertSame('mixed', $this->summary($business, $owner)['state'], 'One up and one down is mixed movement, never stable.');
        $this->assertSame('Mixed movement', $this->summary($business, $owner)['label']);

        [$o2, $b2] = $this->rankTenant(domain: 'photobooth-flat.example');
        $this->pair($o2, $b2, 'flat', 'local', 8, 8);
        $this->assertSame('stable', $this->summary($b2, $o2)['state']);

        [$o3, $b3] = $this->rankTenant(domain: 'photobooth-down.example');
        $this->pair($o3, $b3, 'down', 'local', 3, 9);
        $this->assertSame('declining', $this->summary($b3, $o3)['state']);
    }

    public function test_local_is_preferred_and_organic_is_not_a_second_vote_for_the_same_target(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->pair($owner, $business, 'both', 'local', 14, 6);
        // Organic got worse for the same target; it must not turn the verdict into mixed.
        $this->obs($target, 'organic', 3, now()->subDays(31));
        $this->obs($target, 'organic', 11, now()->subDay());

        $s = $this->summary($business, $owner);
        $this->assertSame('improving', $s['state']);
        $this->assertStringContainsString('Local results', (string) $s['detail']);
    }

    public function test_organic_is_used_only_without_comparable_local_and_is_named(): void
    {
        [$owner, $business] = $this->rankTenant();
        $this->pair($owner, $business, 'organic only', 'organic', 14, 6);

        $s = $this->summary($business, $owner);
        $this->assertSame('improving', $s['state']);
        $this->assertStringContainsString('organic', (string) $s['detail']);
    }

    public function test_activation_is_offered_only_when_nothing_is_tracked_and_the_actor_may_manage(): void
    {
        [$owner, $business] = $this->rankTenant();
        $visibility = app(HomeGoogleVisibility::class);

        $off = $visibility->summary($business, (int) $owner->user_id, '/keywords');
        $this->assertSame('not_tracking', $off['state']);
        $this->assertNull($off['activation'], 'A view-only actor is offered nothing to start.');

        $offPlan = $visibility->summary($business, (int) $owner->user_id, '/keywords', null, true, false);
        $this->assertNull($offPlan['activation'], 'Actor permission alone does not imply rank-tracking entitlement.');
        $on = $visibility->summary($business, (int) $owner->user_id, '/keywords', null, true, true);
        $this->assertSame(['available' => true], $on['activation']);

        $this->track($owner, $business, $this->keyword($owner, $business));
        $tracked = $visibility->summary($business, (int) $owner->user_id, '/keywords', null, true, true);
        $this->assertArrayNotHasKey('activation', $tracked, 'Existing tracking suppresses the setup offer.');
        $this->assertSame('establishing', $tracked['state'], 'And no ranking is claimed before results exist.');
    }
}
