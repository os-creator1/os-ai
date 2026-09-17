<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Contract 13 test-fixture topology remediation, R1 — the shared fixture
 * foundation. Proves the two new explicit multi-tenant helpers can never
 * violate `businesses_workspace_id_unique`, that the Agency helper only ever
 * establishes its relationship through the canonical
 * AgencyClientRelationshipManager, and that the narrowed addBusiness() fails
 * fast instead of letting the DB throw an opaque constraint violation.
 */
class CreatesCustomerContextFixturesTopologyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_addbusiness_succeeds_for_an_empty_workspace(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $business = $this->addBusiness($customer, $workspace, 'First Business');

        $this->assertSame($workspace->id, $business->workspace_id);
        $this->assertSame(1, Business::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_addbusiness_fails_clearly_when_the_workspace_already_has_a_business(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->addBusiness($customer, $workspace, 'First Business');

        try {
            $this->addBusiness($this->createCustomer(), $workspace, 'Second Business');
            $this->fail('Expected addBusiness() to refuse a second Business in an occupied Workspace.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Contract 13 enforces one Business per Workspace; use the explicit independent/client fixture helper instead.',
                $e->getMessage()
            );
        }

        $this->assertSame(1, Business::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_independent_helper_creates_a_different_workspace_with_exactly_one_business(): void
    {
        $a = $this->createIndependentWorkspaceBusiness(businessName: 'Alpha', workspaceName: 'Alpha Workspace');
        $b = $this->createIndependentWorkspaceBusiness(businessName: 'Beta', workspaceName: 'Beta Workspace');

        $this->assertNotSame($a['workspace']->id, $b['workspace']->id);
        $this->assertSame(1, Business::query()->where('workspace_id', $a['workspace']->id)->count());
        $this->assertSame(1, Business::query()->where('workspace_id', $b['workspace']->id)->count());
        $this->assertSame($a['workspace']->id, $a['business']->workspace_id);
    }

    public function test_agency_managed_client_helper_creates_a_separate_client_workspace_and_business(): void
    {
        $m = $this->createAgencyManagedClient(clientBusinessName: 'Client Bakery', clientWorkspaceName: 'Client Bakery Workspace');

        $this->assertNotSame($m['agencyWorkspace']->id, $m['clientWorkspace']->id);
        $this->assertSame($m['clientWorkspace']->id, $m['clientBusiness']->workspace_id);
        $this->assertSame(1, Business::query()->where('workspace_id', $m['clientWorkspace']->id)->count());
        $this->assertSame(0, Business::query()->where('workspace_id', $m['agencyWorkspace']->id)->count());
    }

    public function test_agency_managed_client_helper_establishes_a_real_active_relationship_through_canonical_authority(): void
    {
        $m = $this->createAgencyManagedClient();

        $this->assertInstanceOf(AgencyClientWorkspaceRelationship::class, $m['relationship']);
        $this->assertTrue($m['relationship']->isActive());
        $this->assertSame(AgencyClientRelationshipStatus::Active, $m['relationship']->status);
        $this->assertSame($m['agencyWorkspace']->id, $m['relationship']->agency_workspace_id);
        $this->assertSame($m['clientWorkspace']->id, $m['relationship']->client_workspace_id);
        $this->assertSame((int) $m['agencyOwner']->user_id, $m['relationship']->established_by_user_id);

        // Persisted through the real repository, not fabricated in the fixture.
        $this->assertDatabaseHas('agency_client_workspace_relationships', [
            'id' => $m['relationship']->id,
            'agency_workspace_id' => $m['agencyWorkspace']->id,
            'client_workspace_id' => $m['clientWorkspace']->id,
            'status' => AgencyClientRelationshipStatus::Active->value,
        ]);
    }

    public function test_agency_managed_client_helper_can_attach_a_second_client_to_the_same_agency(): void
    {
        $first = $this->createAgencyManagedClient(clientBusinessName: 'Client One', clientWorkspaceName: 'Client One Workspace');
        $second = $this->createAgencyManagedClient($first['agencyWorkspace'], 'Client Two', 'Client Two Workspace');

        $this->assertSame($first['agencyWorkspace']->id, $second['agencyWorkspace']->id);
        $this->assertNotSame($first['clientWorkspace']->id, $second['clientWorkspace']->id);
        $this->assertSame(1, Business::query()->where('workspace_id', $first['clientWorkspace']->id)->count());
        $this->assertSame(1, Business::query()->where('workspace_id', $second['clientWorkspace']->id)->count());
    }

    public function test_neither_helper_can_ever_produce_a_multi_business_workspace(): void
    {
        $independent = $this->createIndependentWorkspaceBusiness();
        $managed = $this->createAgencyManagedClient();

        foreach ([$independent['workspace']->id, $managed['agencyWorkspace']->id, $managed['clientWorkspace']->id] as $workspaceId) {
            $this->assertLessThanOrEqual(1, Business::query()->where('workspace_id', $workspaceId)->count());
        }
    }

    public function test_contract_13_unique_constraint_still_refuses_a_second_business_at_the_db_layer(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->addBusiness($customer, $workspace, 'First Business');

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('businesses')->insert(array_merge($this->businessAttributes(['name' => 'Second Business']), [
            'uid' => uniqid('biz_', true),
            'customer_id' => $customer->id,
            'workspace_id' => $workspace->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
