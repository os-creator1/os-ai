<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Coo\Context\CooContextEnvelopeFactory;
use App\Library\Dashboard\BusinessHomePresenter;
use App\Library\Dashboard\DashboardSnapshot;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Coo\Insight\Concerns\CreatesCooInsightFixtures;
use Tests\TestCase;

/**
 * Requested correction — BusinessHomePresenter::envelopeFor() memoizes one
 * CooContextEnvelope per present() call (Contract 19 §5.8 R-31). Nothing
 * guarantees a fresh presenter instance per call (the container's binding is
 * not this class's concern to assume), so present() itself resets the
 * memoization as its first statement. This proves the reason that matters:
 * reusing one presenter for a second Business/actor never reads back the
 * first call's envelope.
 *
 * The exact leak a stale envelope would cause: two staff members of the SAME
 * Business, with different authorized Location subsets, have different
 * authorization-scope fingerprints (§5.8). A cached "What we notice" row
 * generated for staff A's exact fingerprint must be invisible to staff B
 * (R-31) — reusing A's memoized envelope for B's own present() call would
 * make B's read carry A's fingerprint instead of their own, and B would
 * incorrectly see A's answer.
 */
class BusinessHomePresenterEnvelopeScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCooInsightFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCooInsights();
    }

    public function test_reusing_one_presenter_instance_for_a_second_actor_never_reads_back_the_first_actors_envelope(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Shared Venue', 'Shared Account');
        $locations = $this->locations($business, 3);
        $business = $business->fresh();

        $staffA = $this->staffWithLocations($workspace, $business, [$locations[0], $locations[1]]);
        $staffB = $this->staffWithLocations($workspace, $business, [$locations[1], $locations[2]]);

        // A cached row for exactly staff A's own authorization scope — never
        // staff B's, since [1,2] and [2,3] are different Location subsets.
        $envelopeA = app(CooContextEnvelopeFactory::class)->forActor($business, $staffA->user);
        $this->cachedInsight($business, [
            'authorization_scope_fingerprint' => $envelopeA->authorizationScopeFingerprint,
            'audience_user_id' => null,
        ]);

        $presenter = app(BusinessHomePresenter::class);

        $this->authenticateAs($staffA);
        $forA = $presenter->present($this->resolvedContext($staffA->user), $staffA->user);
        $this->assertNotNull($this->insightOf($forA), 'Precondition: staff A sees their own cached row.');

        // The same instance, immediately reused for a different actor on the
        // same Business. If envelopeFor() were still memoized across calls,
        // this read would incorrectly carry staff A's fingerprint.
        $this->authenticateAs($staffB);
        $forB = $presenter->present($this->resolvedContext($staffB->user), $staffB->user);
        $this->assertNull($this->insightOf($forB), "Staff B's own Location subset never matches staff A's cached row.");
    }

    public function test_reusing_one_presenter_instance_for_a_second_business_never_reads_back_the_first_businesss_envelope(): void
    {
        [$customerOne, $businessOne] = $this->tenant(WorkspacePlanTier::Growth, 'First Venue', 'First Account');
        [$customerTwo, $businessTwo] = $this->tenant(WorkspacePlanTier::Growth, 'Second Venue', 'Second Account');

        $this->cachedInsight($businessOne->fresh());

        $presenter = app(BusinessHomePresenter::class);

        $this->authenticateAs($customerOne);
        $forOne = $presenter->present($this->resolvedContext($customerOne->user), $customerOne->user);
        $this->assertNotNull($this->insightOf($forOne), 'Precondition: the first Business sees its own cached row.');

        $this->authenticateAs($customerTwo);
        $forTwo = $presenter->present($this->resolvedContext($customerTwo->user), $customerTwo->user);
        $this->assertNull($this->insightOf($forTwo), "A second, unrelated Business never inherits the first Business's cached row.");
    }

    /** @return array<string, mixed>|null */
    private function insightOf(?DashboardSnapshot $snapshot): ?array
    {
        $this->assertNotNull($snapshot);

        return $snapshot->band(DashboardSnapshot::BAND_HEADLINES)['insight'] ?? null;
    }

    /** @return array<int, BusinessLocation> */
    private function locations(Business $business, int $count): array
    {
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $created[] = BusinessLocation::create([
                'business_id' => $business->id,
                'name' => 'Location ' . ($i + 1),
                'service_mode' => 'storefront',
                'country_code' => 'US',
            ]);
        }

        return $created;
    }

    /** @param array<int, BusinessLocation> $granted */
    private function staffWithLocations(mixed $workspace, Business $business, array $granted): Customer
    {
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();

        foreach ($granted as $location) {
            app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);
        }

        return $staff->fresh();
    }
}
