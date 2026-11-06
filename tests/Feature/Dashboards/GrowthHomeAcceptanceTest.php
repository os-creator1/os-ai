<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Growth Home end to end over HTTP: where its recommendation links lead, who may see them, which Location's and Business's
 * recommendations can appear, and that "already set up" follows persisted evidence.
 *
 * Results/progress (real figures, first month, observed zero, unavailable reads, reporting range) are already proven on the
 * Growth tier by HomeV2ExperienceTest — see docs in the PR acceptance matrix — and are not repeated here.
 */
class GrowthHomeAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function rec(\App\Models\Business $business, string $title, int $priority, array $extra = []): array
    {
        $uid = (string) \Illuminate\Support\Str::uuid();
        $this->recommendation($business, array_merge([
            'uid' => $uid, 'worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => $title, 'priority_score' => $priority,
        ], $extra));

        return [$uid, $title];
    }

    private function growthSnapshot(\App\Models\Business $business): void
    {
        \App\Models\GrowthScoreSnapshot::create([
            'business_id' => $business->id, 'snapshot_date' => now()->toDateString(),
            'algorithm_version' => \App\Library\Growth\GrowthScoreCalculator::ALGORITHM_VERSION, 'overall_score' => 70,
            'scored_category_count' => 1, 'total_category_count' => 9, 'applicable_rule_count' => 1,
            'category_scores' => ['website' => 70], 'breakdown' => [], 'computed_at' => now(),
        ]);
    }

    private function home(): string
    {
        return $this->get(route('user.home'))->assertOk()->getContent();
    }

    /** @return list<string> every Growth recommendation detail uid linked from the page */
    private function linkedRecommendationUids(string $html): array
    {
        preg_match_all('#/growth/opportunities/([0-9a-f-]{36})#', $html, $m);

        return array_values(array_unique($m[1]));
    }

    private function restrictedStaff($workspace, $location): \App\Models\Customer
    {
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);

        return $staff;
    }

    public function test_primary_and_secondary_links_open_this_businesss_detail_pages_and_never_a_foreign_one(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Links Venue', 'Links Account');
        [, $foreign] = $this->tenant(WorkspacePlanTier::Growth, 'Foreign Venue', 'Foreign Account');
        $this->growthSnapshot($business);
        $own = [];
        foreach ([90, 80, 70, 60] as $i => $priority) {
            $own[] = $this->rec($business, 'Own recommendation ' . ($i + 1), $priority)[0];
        }
        [$foreignUid, $foreignTitle] = $this->rec($foreign, 'Foreign secret recommendation', 99);
        $this->authenticateAs($owner);

        $html = $this->home();

        $linked = $this->linkedRecommendationUids($html);
        $this->assertNotEmpty($linked, 'The recommendation links are offered to the owner.');
        $this->assertSame([], array_diff($linked, $own), 'Only this Business\'s recommendations are linked.');
        $this->assertStringNotContainsString($foreignUid, $html);
        $this->assertStringNotContainsString($foreignTitle, $html);
        $this->assertLessThanOrEqual(2, substr_count($html, 'data-role="whats-next-item"'), 'At most two secondary actions.');
        $this->assertLessThanOrEqual(1, substr_count($html, 'data-role="next-best-move"'), 'One primary move.');
        $this->assertStringContainsString($workspace->uid . '/businesses/' . $business->uid . '/growth/opportunities/', $html, 'Links are scoped to this Workspace and Business.');
        // The deterministic order: the highest-priority recommendation is never left out in favour of a lower one.
        $this->assertContains($own[0], $linked);
        $this->assertNotContains($own[3], $linked, 'The lowest of four is neither primary nor one of the two secondaries.');
    }

    public function test_an_actor_without_growth_permission_is_offered_no_recommendation_links(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Permission Venue', 'Permission Account');
        $this->growthSnapshot($business);
        $this->rec($business, 'Hidden from this actor', 90);
        $this->rec($business, 'Also hidden', 80);

        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff->fresh(), ['access_backend']);

        $html = $this->home();

        $this->assertSame([], $this->linkedRecommendationUids($html));
        $this->assertStringNotContainsString('Hidden from this actor', $html);
    }

    public function test_a_location_restricted_actor_sees_only_their_locations_recommendations_everywhere_on_home(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Two Site Growth', 'Two Site Growth Account');
        $this->growthSnapshot($business);
        $first = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $second = BusinessLocation::create(['business_id' => $business->id, 'name' => 'Second site', 'service_mode' => 'storefront', 'country_code' => 'US']);
        [$mineUid] = $this->rec($business, 'Visible at the first site', 70, ['location_id' => $first->id]);
        [$theirUid, $theirTitle] = $this->rec($business, 'Only at the second site', 95, ['location_id' => $second->id]);

        $staff = $this->restrictedStaff($workspace, $first);
        $this->authenticateAs($staff->fresh(), ['business_advisor', 'access_backend']);

        $html = $this->home();

        $this->assertStringNotContainsString($theirUid, $html, 'Another Location\'s recommendation never appears as primary, secondary or a link.');
        $this->assertStringNotContainsString($theirTitle, $html);
        $this->assertNotContains($theirUid, $this->linkedRecommendationUids($html));
        // A restricted actor also never opens the other Location's detail page by its uid.
        $this->get(route('customer.workspaces.businesses.growth.opportunities.show', [$workspace->uid, $business->uid, $theirUid]))->assertNotFound();
    }

    public function test_another_business_cannot_be_opened_through_this_businesss_growth_route(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Route Venue', 'Route Account');
        [, $foreign] = $this->tenant(WorkspacePlanTier::Growth, 'Route Foreign', 'Route Foreign Account');
        [$foreignUid] = $this->rec($foreign, 'Foreign detail', 90);
        $this->authenticateAs($owner);

        $this->get(route('customer.workspaces.businesses.growth.opportunities.show', [$workspace->uid, $business->uid, $foreignUid]))->assertNotFound();
    }

    public function test_core_workspaces_with_the_same_recommendations_keep_the_standard_home(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Core, 'Core Recs', 'Core Recs Account');
        $this->growthSnapshot($business);
        $this->rec($business, 'Core recommendation', 90);
        $this->authenticateAs($owner);

        $html = $this->home();

        $this->assertStringNotContainsString('data-role="growth-home"', $html);
        $this->assertStringContainsString('data-role="home-greeting"', $html);
    }

    public function test_already_set_up_follows_persisted_evidence_and_shrinks_when_the_evidence_goes(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Evidence Venue', 'Evidence Account');
        $this->authenticateAs($owner);

        $before = substr_count($this->home(), 'data-role="configured-item"');
        $this->assertGreaterThan(0, $before, 'A finished-setup fixture lists persisted steps.');

        // Remove the evidence for online booking: the type stays active, but nobody is assigned to it.
        DB::table('booking_type_staff')->delete();
        $html = $this->home();

        $this->assertTrue(
            ! str_contains($html, 'data-role="growth-home"') || substr_count($html, 'data-role="configured-item"') < $before,
            'A step whose evidence is gone is no longer listed as set up.',
        );
    }
}
