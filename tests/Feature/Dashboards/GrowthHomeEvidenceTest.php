<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Opportunity\OpportunityTypeRegistry;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * "Why MotionGrove suggests this" shows the SELECTED recommendation's persisted evidence, expected outcome and confidence — and
 * nothing from a recommendation the actor cannot see or that was not selected.
 */
class GrowthHomeEvidenceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function evidence(string $summary): string
    {
        return json_encode([['summary' => $summary, 'observed_value' => [], 'retrieved_at' => now()->toIso8601String()]]);
    }

    private function section(string $html): string
    {
        $this->assertSame(1, preg_match('#<details[^>]*data-role="growth-evidence".*?</details>#s', $html, $m), 'The evidence block is rendered.');

        return $m[0];
    }

    public function test_the_block_shows_exactly_the_selected_recommendations_persisted_evidence_expected_outcome_and_confidence(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Evidence Home', 'Evidence Home Account');
        $this->recommendation($business, [
            'worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => 'Publish your website', 'priority_score' => 90,
            'confidence' => 0.80, 'evidence' => $this->evidence('Persisted proof: the site has never been published.'),
        ]);
        $this->recommendation($business, [
            'worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => 'A lower recommendation', 'priority_score' => 50,
            'evidence' => $this->evidence('Lower-ranked proof that must not appear in the primary block.'),
        ]);
        $this->authenticateAs($owner);

        $block = $this->section($this->get(route('user.home'))->assertOk()->getContent());

        $this->assertStringContainsString('Persisted proof: the site has never been published.', $block);
        $this->assertStringContainsString('Moderate confidence', $block, 'The engine\'s own label for 0.80.');
        $expected = OpportunityTypeRegistry::get('website', 'website.not_published:v1')['growth']['expected'] ?? null;
        if ($expected !== null) {
            $this->assertStringContainsString($expected, $block, 'The registry\'s expected outcome, verbatim.');
        }
        $this->assertStringNotContainsString('Lower-ranked proof', $block);
    }

    public function test_missing_evidence_shows_no_invented_proof(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'No Evidence Home', 'No Evidence Account');
        $this->recommendation($business, [
            'worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => 'Publish your website', 'priority_score' => 90,
            'confidence' => 0.40, 'evidence' => json_encode([]),
        ]);
        $this->authenticateAs($owner);

        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Persisted proof', $html);
        if (str_contains($html, 'data-role="growth-evidence"')) {
            $block = $this->section($html);
            $this->assertStringNotContainsString('Moderate confidence', $block);
            $this->assertStringNotContainsString('High confidence', $block);
        }
    }

    public function test_evidence_of_another_locations_recommendation_never_reaches_a_restricted_actor(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Evidence Sites', 'Evidence Sites Account');
        $first = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $second = BusinessLocation::create(['business_id' => $business->id, 'name' => 'Second site', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $this->recommendation($business, [
            'worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => 'Mine', 'priority_score' => 60, 'location_id' => $first->id,
            'evidence' => $this->evidence('Evidence for the first site.'),
        ]);
        $this->recommendation($business, [
            'worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => 'Theirs', 'priority_score' => 99, 'location_id' => $second->id,
            'evidence' => $this->evidence('SECRET evidence for the second site.'),
        ]);
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $first);
        $this->authenticateAs($staff->fresh(), ['business_advisor', 'access_backend']);

        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('SECRET evidence', $html);
        $this->assertStringNotContainsString('Theirs', $html);
    }
}
