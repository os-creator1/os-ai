<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Library\Entitlement\CustomerAccountAccessDecision;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Workspace;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 05 — Agency non-payment composition.
 *
 * A Client Workspace with an ACTIVE managing Agency (Contract 01) loses
 * effective access while that Agency's own account is locked, inactive or
 * suspended — without a single write to either Workspace's lifecycle record
 * or to the relationship (Addendum §8).
 *
 * Every row of Contract 05 §5's truth table is its own test method, and each
 * variant inside a row (Active / Trial / Grace, and each way an Agency can be
 * locked) is its own data-provider case, so a failure names the exact cell.
 * Lifecycle states are produced through Contract 03's real writers wherever
 * a writer exists; only states no writer can produce on demand (a running
 * trial, a Grace window that has already elapsed) are stored through the
 * assignment repository.
 */
class AgencyNonPaymentCompositionTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function resolver(): CustomerAccountAccessResolver
    {
        return app(CustomerAccountAccessResolver::class);
    }

    /** The composed decision — what every consumer actually receives. */
    private function resolve(Workspace $workspace): CustomerAccountAccessDecision
    {
        return $this->resolver()->resolve($workspace->fresh());
    }

    /** The private, non-composing primitive, invoked directly. */
    private function own(Workspace $workspace, ?CustomerAccountAccessResolver $resolver = null): CustomerAccountAccessDecision
    {
        return (new ReflectionMethod(CustomerAccountAccessResolver::class, 'resolveOwnWorkspaceDecision'))
            ->invoke($resolver ?? $this->resolver(), $workspace->fresh());
    }

    private function agencyWorkspace(string $name = 'Northwind Agency'): Workspace
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);
        $this->assignTier($workspace, WorkspacePlanTier::Agency);

        return $workspace->fresh();
    }

    private function clientWorkspace(string $name = 'Alpha Dental', ?WorkspacePlanTier $tier = WorkspacePlanTier::Growth): Workspace
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);

        if ($tier !== null) {
            $this->assignTier($workspace, $tier);
        }

        return $workspace->fresh();
    }

    /** A real Contract 01 relationship, established by the Agency's own owner. */
    private function manage(Workspace $agency, Workspace $client): AgencyClientWorkspaceRelationship
    {
        return app(AgencyClientRelationshipManager::class)->create((int) $agency->owner_user_id, $agency, $client);
    }

    /**
     * Puts one Workspace into a Contract 03 lifecycle state. Must be called
     * AFTER manage(): establishing a relationship requires an Agency that is
     * currently eligible, and a later lapse must not end it.
     */
    private function putInto(Workspace $workspace, string $state): void
    {
        $manager = app(EntitlementManager::class);

        match ($state) {
            'active' => null,
            'trial' => $this->storeLifecycle($workspace, ['trial_ends_at' => now()->addDays(10)]),
            'grace' => $manager->enterGracePeriod($workspace, null, 'Renewal failed.'),
            'locked' => [
                $manager->enterGracePeriod($workspace, null, 'Trial ended without conversion'),
                $manager->lockForNonPayment($workspace, null, 'Grace period elapsed without payment'),
            ],
            // Contract 03's defensive derivation: Grace elapsed, no locked_at
            // written yet — still Locked, so it must compose as Locked too.
            'locked_elapsed_grace' => $this->storeLifecycle($workspace, [
                'grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS + 1),
            ]),
            'inactive' => $manager->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $this->platformAdminId(), 'Closed.'),
            'suspended' => $manager->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Suspended.'),
        };
    }

    private function storeLifecycle(Workspace $workspace, array $columns): void
    {
        $assignments = app(WorkspacePlanAssignmentRepository::class);
        $assignments->update($assignments->findByWorkspaceIdForUpdate((int) $workspace->id), $columns);
    }

    /**
     * An Agency and a Client in the given own states, linked by an ACTIVE
     * relationship.
     *
     * @return array{0: Workspace, 1: Workspace}
     */
    private function managedPair(string $clientState, string $agencyState): array
    {
        $agency = $this->agencyWorkspace();
        $client = $this->clientWorkspace();
        $this->manage($agency, $client);
        $this->putInto($client, $clientState);
        $this->putInto($agency, $agencyState);

        return [$agency->fresh(), $client->fresh()];
    }

    private function assertAgencyCausedLock(CustomerAccountAccessDecision $decision, string $expectedReason): void
    {
        $this->assertTrue($decision->isLocked());
        $this->assertSame(CustomerAccountAccessState::Locked, $decision->state);
        $this->assertSame($expectedReason, $decision->reason);
        $this->assertNotNull($decision->heading);
        $this->assertNotNull($decision->message);
        // Nothing on the Client's own billing page can fix an Agency's
        // account, so no recovery route is ever promised.
        $this->assertNull($decision->recoveryRouteName);
        $this->assertNull($decision->recoveryLabel);
        $this->assertFalse($decision->isInTrial());
        $this->assertFalse($decision->isInGracePeriod());
    }

    /** Full lifecycle rows, every column, straight from the table. */
    private function lifecycleRow(Workspace $workspace): array
    {
        return (array) DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
    }

    /** @return array<int, string> every SQL statement run inside $callback */
    private function captureQueries(callable $callback): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }

    public static function usableStates(): array
    {
        return ['active' => ['active'], 'trial' => ['trial'], 'grace' => ['grace']];
    }

    public static function usableClientByUsableAgency(): array
    {
        $cases = [];
        foreach (['active', 'trial', 'grace'] as $client) {
            foreach (['active', 'trial', 'grace'] as $agency) {
                $cases["client {$client} / agency {$agency}"] = [$client, $agency];
            }
        }

        return $cases;
    }

    public static function usableClientByLockedAgency(): array
    {
        $cases = [];
        foreach (['active', 'trial', 'grace'] as $client) {
            foreach (['locked', 'locked_elapsed_grace'] as $agency) {
                $cases["client {$client} / agency {$agency}"] = [$client, $agency];
            }
        }

        return $cases;
    }

    public static function everyClientState(): array
    {
        return [
            'active' => ['active'],
            'trial' => ['trial'],
            'grace' => ['grace'],
            'locked' => ['locked'],
            'inactive' => ['inactive'],
            'suspended' => ['suspended'],
        ];
    }

    public static function lockedAgencyStates(): array
    {
        return ['locked' => ['locked'], 'inactive' => ['inactive'], 'suspended' => ['suspended']];
    }

    public static function administrativeClientByAnyAgency(): array
    {
        $cases = [];
        foreach (['inactive', 'suspended'] as $client) {
            foreach (['active', 'grace', 'locked', 'inactive', 'suspended'] as $agency) {
                $cases["client {$client} / agency {$agency}"] = [$client, $agency];
            }
        }

        return $cases;
    }

    // =====================================================================
    // §5 row 1 — Client usable × Agency usable -> Usable
    // =====================================================================

    #[DataProvider('usableClientByUsableAgency')]
    public function test_row_1_usable_client_with_usable_agency_is_the_clients_own_decision(string $clientState, string $agencyState): void
    {
        [, $client] = $this->managedPair($clientState, $agencyState);

        $decision = $this->resolve($client);

        $this->assertFalse($decision->isLocked());
        // The Client's own decision, untouched — its own Trial/Grace hints
        // included, and never the Agency's.
        $this->assertEquals($this->own($client), $decision);
    }

    // =====================================================================
    // §5 rows 2–4 — Client usable × Agency Locked / Inactive / Suspended
    // =====================================================================

    #[DataProvider('usableClientByLockedAgency')]
    public function test_row_2_usable_client_with_locked_agency_is_locked_as_agency_locked(string $clientState, string $agencyState): void
    {
        [$agency, $client] = $this->managedPair($clientState, $agencyState);

        $this->assertSame(CustomerAccountAccessState::Locked, $this->own($agency)->state);
        $this->assertFalse($this->own($client)->isLocked());

        $this->assertAgencyCausedLock($this->resolve($client), 'agency_locked');
    }

    #[DataProvider('usableStates')]
    public function test_row_3_usable_client_with_inactive_agency_is_locked_as_agency_inactive(string $clientState): void
    {
        [, $client] = $this->managedPair($clientState, 'inactive');

        $this->assertAgencyCausedLock($this->resolve($client), 'agency_inactive');
    }

    #[DataProvider('usableStates')]
    public function test_row_4_usable_client_with_suspended_agency_is_locked_as_agency_suspended(string $clientState): void
    {
        [, $client] = $this->managedPair($clientState, 'suspended');

        $this->assertAgencyCausedLock($this->resolve($client), 'agency_suspended');
    }

    // =====================================================================
    // §5 row 5 — no Active relationship -> exactly the pre-Contract-05 result
    // =====================================================================

    #[DataProvider('everyClientState')]
    public function test_row_5_a_workspace_with_no_relationship_resolves_exactly_as_before(string $clientState): void
    {
        $client = $this->clientWorkspace();
        $this->putInto($client, $clientState);

        $decision = $this->resolve($client);

        $this->assertEquals($this->own($client), $decision);
        $this->assertSame(
            [
                'active' => 'usable',
                'trial' => 'plan_trial',
                'grace' => 'plan_grace',
                'locked' => 'plan_locked',
                'inactive' => 'plan_inactive',
                'suspended' => 'plan_suspended',
            ][$clientState],
            $decision->reason,
        );
    }

    #[DataProvider('everyClientState')]
    public function test_row_5_a_terminated_relationship_to_a_locked_agency_composes_nothing(string $clientState): void
    {
        $agency = $this->agencyWorkspace();
        $client = $this->clientWorkspace();
        $relationship = $this->manage($agency, $client);
        app(AgencyClientRelationshipManager::class)->terminate((int) $agency->owner_user_id, $relationship, 'Client moved on.');
        $this->putInto($client, $clientState);
        $this->putInto($agency, 'locked');

        $this->assertEquals($this->own($client), $this->resolve($client));
    }

    public function test_row_5_an_unassigned_workspace_with_no_relationship_is_still_usable(): void
    {
        $client = $this->clientWorkspace('Unassigned Dental', null);

        $this->assertEquals(CustomerAccountAccessDecision::usable(), $this->resolve($client));
    }

    // =====================================================================
    // §5 rows 6–7 — Client's own Locked always wins
    // =====================================================================

    #[DataProvider('usableStates')]
    public function test_row_6_a_locked_client_with_a_usable_agency_keeps_its_own_lock(string $agencyState): void
    {
        [, $client] = $this->managedPair('locked', $agencyState);

        $decision = $this->resolve($client);

        // The Client's own delinquency is never hidden by a healthy Agency.
        $this->assertSame('plan_locked', $decision->reason);
        $this->assertEquals($this->own($client), $decision);
    }

    #[DataProvider('lockedAgencyStates')]
    public function test_row_7_a_locked_client_with_a_locked_agency_surfaces_the_clients_own_reason(string $agencyState): void
    {
        [, $client] = $this->managedPair('locked', $agencyState);

        $decision = $this->resolve($client);

        // No combined reason: the Client's own decision, unchanged.
        $this->assertSame('plan_locked', $decision->reason);
        $this->assertSame('customer.workspaces.plan.show', $decision->recoveryRouteName);
        $this->assertEquals($this->own($client), $decision);
    }

    // =====================================================================
    // §5 row 8 — Client's own Inactive / Suspended always wins
    // =====================================================================

    #[DataProvider('administrativeClientByAnyAgency')]
    public function test_row_8_an_inactive_or_suspended_client_keeps_its_own_state_whatever_the_agency(string $clientState, string $agencyState): void
    {
        [, $client] = $this->managedPair($clientState, $agencyState);

        $decision = $this->resolve($client);

        $this->assertSame($clientState === 'inactive' ? 'plan_inactive' : 'plan_suspended', $decision->reason);
        $this->assertSame(
            $clientState === 'inactive' ? CustomerAccountAccessState::LockedInactive : CustomerAccountAccessState::LockedSuspended,
            $decision->state,
        );
        $this->assertEquals($this->own($client), $decision);
    }

    // =====================================================================
    // Composition edges the table implies
    // =====================================================================

    public function test_an_unassigned_client_is_locked_by_a_locked_managing_agency(): void
    {
        // §5's rule is a plain OR over isLocked(): an Agency-managed Client
        // that has no plan of its own must not stay usable while the Agency
        // behind it is locked.
        $agency = $this->agencyWorkspace();
        $client = $this->clientWorkspace('Unassigned Dental', null);
        $this->manage($agency, $client);
        $this->putInto($agency, 'locked');

        $this->assertAgencyCausedLock($this->resolve($client), 'agency_locked');
    }

    public function test_composition_is_one_directional_an_agency_is_never_locked_by_its_clients(): void
    {
        $agency = $this->agencyWorkspace();
        $client = $this->clientWorkspace();
        $this->manage($agency, $client);
        $this->putInto($client, 'suspended');

        $this->assertEquals($this->own($agency), $this->resolve($agency));
        $this->assertFalse($this->resolve($agency)->isLocked());
    }

    // =====================================================================
    // Zero writes
    // =====================================================================

    #[DataProvider('lockedAgencyStates')]
    public function test_resolving_a_composed_lock_writes_nothing_to_either_lifecycle_row_or_the_relationship(string $agencyState): void
    {
        $agency = $this->agencyWorkspace();
        $client = $this->clientWorkspace();
        $relationship = $this->manage($agency, $client);
        $this->putInto($client, 'grace');
        $this->putInto($agency, $agencyState);

        $clientBefore = $this->lifecycleRow($client);
        $agencyBefore = $this->lifecycleRow($agency);
        $relationshipBefore = (array) DB::table('agency_client_workspace_relationships')->where('id', $relationship->id)->first();

        $queries = $this->captureQueries(function () use ($client): void {
            $this->assertTrue($this->resolve($client)->isLocked());
        });

        // Byte-for-byte identical rows — status, all three lifecycle
        // timestamps and updated_at included — for the Client, the Agency and
        // the relationship.
        $this->assertSame($clientBefore, $this->lifecycleRow($client));
        $this->assertSame($agencyBefore, $this->lifecycleRow($agency));
        $this->assertSame($relationshipBefore, (array) DB::table('agency_client_workspace_relationships')->where('id', $relationship->id)->first());

        // And not one write statement of any kind was issued.
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $sql);
        }
        $this->assertSame(WorkspacePlanAssignmentStatus::Active->value, $clientBefore['status']);
        $this->assertNotNull($clientBefore['grace_started_at']);
    }

    // =====================================================================
    // The non-composing primitive, and non-recursion
    // =====================================================================

    public function test_resolve_own_workspace_decision_performs_no_relationship_lookup(): void
    {
        $agency = $this->agencyWorkspace();
        $client = $this->clientWorkspace();
        $this->manage($agency, $client);
        $this->putInto($agency, 'locked');

        // A relationship repository that fails the test on ANY call.
        $relationships = Mockery::mock(AgencyClientWorkspaceRelationshipRepository::class);
        $relationships->shouldNotReceive('findActiveForClientWorkspace');
        $relationships->shouldNotReceive('findActiveForClientWorkspaceForUpdate');
        $relationships->shouldNotReceive('activeForAgencyWorkspace');
        $relationships->shouldNotReceive('historyForClientWorkspace');
        $relationships->shouldNotReceive('findById');
        $relationships->shouldNotReceive('query');
        $this->instance(AgencyClientWorkspaceRelationshipRepository::class, $relationships);
        $resolver = app(CustomerAccountAccessResolver::class);

        $queries = $this->captureQueries(function () use ($client, $resolver, &$decision): void {
            $decision = $this->own($client, $resolver);
        });

        // The Client's own row only: usable, even though an Active
        // relationship to a locked Agency exists.
        $this->assertFalse($decision->isLocked());
        $this->assertSame('usable', $decision->reason);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('agency_client_workspace_relationships', $sql);
        }
    }

    public function test_resolve_performs_exactly_one_relationship_lookup(): void
    {
        $agency = $this->agencyWorkspace();
        $client = $this->clientWorkspace();
        $this->manage($agency, $client);
        $this->putInto($agency, 'locked');

        $queries = $this->captureQueries(function () use ($client): void {
            $this->assertTrue($this->resolve($client)->isLocked());
        });

        $relationshipReads = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'agency_client_workspace_relationships'));
        $this->assertCount(1, $relationshipReads);
    }

    /**
     * Static proof over the resolver's own source: builds the call graph of
     * `$this->method(` calls in every method body (comments excluded) and
     * checks that resolve() cannot reach itself, and that the primitive can
     * reach neither resolve() nor the relationship repository.
     */
    public function test_public_resolve_cannot_recursively_call_itself(): void
    {
        $graph = $this->resolverCallGraph();

        // The scanner really sees self-calls — resolveAmbiguous() legitimately
        // calls resolve() per candidate — so a clean result below is not a
        // vacuous one.
        $this->assertContains('resolve', $graph['resolveAmbiguous']['calls']);

        $this->assertNotContains('resolve', $this->reachableFrom('resolve', $graph));
        $this->assertSame(['composeWithManagingAgency', 'resolveOwnWorkspaceDecision'], $this->sorted($graph['resolve']['calls']));

        $fromPrimitive = $this->reachableFrom('resolveOwnWorkspaceDecision', $graph);
        foreach (['resolve', 'resolveOwnWorkspaceDecision', 'resolveAmbiguous', 'resolveForContext', 'composeWithManagingAgency'] as $forbidden) {
            $this->assertNotContains($forbidden, $fromPrimitive, "resolveOwnWorkspaceDecision() must never reach {$forbidden}().");
        }
        foreach (array_merge(['resolveOwnWorkspaceDecision'], $fromPrimitive) as $method) {
            $this->assertFalse($graph[$method]['readsRelationships'], "{$method}() must not read Agency relationships.");
        }

        // resolve() is the one and only relationship reader.
        $readers = array_keys(array_filter($graph, fn (array $node): bool => $node['readsRelationships']));
        $this->assertSame(['resolve'], $readers);
    }

    /**
     * Behavioural proof: a management CYCLE (A manages B, B manages A) —
     * which Contract 01's rules never create, inserted here straight through
     * the repository — would recurse forever through any implementation that
     * resolved the Agency with resolve(). It must terminate, one hop deep.
     */
    public function test_a_relationship_cycle_terminates_one_hop_deep(): void
    {
        $a = $this->agencyWorkspace('Workspace A');
        $b = $this->agencyWorkspace('Workspace B');
        $this->insertRelationship(agency: $b, client: $a);
        $this->insertRelationship(agency: $a, client: $b);
        $this->putInto($b, 'locked');

        $this->assertAgencyCausedLock($this->resolve($a), 'agency_locked');
        $this->assertSame('plan_locked', $this->resolve($b)->reason);
    }

    public function test_a_two_hop_chain_never_composes_the_second_hop(): void
    {
        // X is managed by Y, Y is managed by Z, and only Z is locked. One hop
        // means X sees Y's OWN state (usable) — never Z's.
        $x = $this->clientWorkspace('Workspace X');
        $y = $this->agencyWorkspace('Workspace Y');
        $z = $this->agencyWorkspace('Workspace Z');
        $this->insertRelationship(agency: $y, client: $x);
        $this->insertRelationship(agency: $z, client: $y);
        $this->putInto($z, 'locked');

        $this->assertAgencyCausedLock($this->resolve($y), 'agency_locked');
        $this->assertFalse($this->resolve($x)->isLocked());
        $this->assertEquals($this->own($x), $this->resolve($x));
    }

    // =====================================================================
    // Isolation, termination, recovery
    // =====================================================================

    public function test_client_a_being_effectively_locked_never_affects_an_unrelated_client_b(): void
    {
        $lockedAgency = $this->agencyWorkspace('Lapsed Agency');
        $healthyAgency = $this->agencyWorkspace('Healthy Agency');
        $clientA = $this->clientWorkspace('Client A');
        $clientB = $this->clientWorkspace('Client B');
        $unmanaged = $this->clientWorkspace('Unmanaged C');
        $this->manage($lockedAgency, $clientA);
        $this->manage($healthyAgency, $clientB);
        $this->putInto($lockedAgency, 'locked');

        $clientBBefore = $this->lifecycleRow($clientB);

        $this->assertAgencyCausedLock($this->resolve($clientA), 'agency_locked');
        $this->assertFalse($this->resolve($clientB)->isLocked());
        $this->assertEquals($this->own($clientB), $this->resolve($clientB));
        $this->assertFalse($this->resolve($unmanaged)->isLocked());
        $this->assertSame($clientBBefore, $this->lifecycleRow($clientB));
    }

    public function test_a_sibling_clients_own_lock_never_affects_another_client_of_the_same_agency(): void
    {
        $agency = $this->agencyWorkspace();
        $clientA = $this->clientWorkspace('Client A');
        $clientB = $this->clientWorkspace('Client B');
        $this->manage($agency, $clientA);
        $this->manage($agency, $clientB);
        $this->putInto($clientA, 'locked');

        $this->assertSame('plan_locked', $this->resolve($clientA)->reason);
        $this->assertFalse($this->resolve($clientB)->isLocked());
        $this->assertFalse($this->resolve($agency)->isLocked());
    }

    public function test_terminating_the_relationship_immediately_removes_agency_composition(): void
    {
        $agency = $this->agencyWorkspace();
        $client = $this->clientWorkspace();
        $relationship = $this->manage($agency, $client);
        $this->putInto($agency, 'locked');

        // One resolver instance throughout: nothing is cached inside it.
        $resolver = $this->resolver();
        $this->assertSame('agency_locked', $resolver->resolve($client->fresh())->reason);

        app(AgencyClientRelationshipManager::class)->terminate((int) $agency->owner_user_id, $relationship, 'Client moved on.');
        $this->assertSame(AgencyClientRelationshipStatus::Terminated, $relationship->fresh()->status);

        $decision = $resolver->resolve($client->fresh());
        $this->assertFalse($decision->isLocked());
        $this->assertEquals($this->own($client), $decision);
    }

    public static function agencyRecoveries(): array
    {
        return [
            'locked, then payment recovers it' => ['locked'],
            'inactive, then reactivated' => ['inactive'],
            'suspended, then unsuspended' => ['suspended'],
        ];
    }

    #[DataProvider('agencyRecoveries')]
    public function test_agency_recovery_immediately_restores_the_clients_effective_access(string $agencyState): void
    {
        [$agency, $client] = $this->managedPair('grace', $agencyState);
        $resolver = $this->resolver();
        $this->assertTrue($resolver->resolve($client->fresh())->isLocked());

        $this->recover($agency, $agencyState);

        $decision = $resolver->resolve($client->fresh());
        $this->assertFalse($decision->isLocked());
        // Back to exactly the Client's own decision, its own Grace hint and
        // all — the Client's record was never changed, so nothing to undo.
        $this->assertSame('plan_grace', $decision->reason);
        $this->assertEquals($this->own($client), $decision);
    }

    #[DataProvider('agencyRecoveries')]
    public function test_the_clients_own_lock_remains_after_the_agency_recovers(string $agencyState): void
    {
        [$agency, $client] = $this->managedPair('locked', $agencyState);
        $this->assertSame('plan_locked', $this->resolve($client)->reason);

        $this->recover($agency, $agencyState);

        $this->assertSame('plan_locked', $this->resolve($client)->reason);

        // Only the Client's own recovery clears the Client's own lock.
        app(EntitlementManager::class)->recoverAccess($client, $this->platformAdminId(), 'Client paid.');
        $this->assertFalse($this->resolve($client)->isLocked());
    }

    private function recover(Workspace $agency, string $agencyState): void
    {
        $manager = app(EntitlementManager::class);

        match ($agencyState) {
            'locked' => $manager->recoverAccess($agency, $this->platformAdminId(), 'Agency paid.'),
            'inactive', 'suspended' => $manager->changePlanStatus($agency, WorkspacePlanAssignmentStatus::Active, $this->platformAdminId(), 'Agency restored.'),
        };
    }

    // =====================================================================
    // Consumers get composition for free (Contract 05 §4)
    // =====================================================================

    public function test_the_access_gate_and_locked_screen_enforce_an_agency_caused_lock(): void
    {
        [$customer, , $client] = $this->tenant(WorkspacePlanTier::Growth, 'Alpha Dental', 'Alpha Dental');
        $agency = $this->agencyWorkspace();
        $relationship = $this->manage($agency, $client);
        $this->putInto($agency, 'locked');
        $this->authenticateAs($customer);

        $this->home()->assertRedirect(route('customer.account-locked.show'));

        $originalName = $client->name;
        $this->postJson(route('customer.workspaces.rename', $client->uid), ['name' => 'Renamed While Agency Locked'])
            ->assertStatus(403)
            ->assertJson(['status' => 'error', 'reason' => 'agency_locked']);
        $this->assertSame($originalName, $client->fresh()->name);

        $locked = $this->get(route('customer.account-locked.show'));
        $locked->assertOk();
        $locked->assertSee('Account unavailable');
        $locked->assertDontSee('Continue to billing');

        // Ending the relationship lifts it on the very next request.
        app(AgencyClientRelationshipManager::class)->terminate((int) $agency->owner_user_id, $relationship, 'Client moved on.');
        $this->home()->assertOk();
    }

    // =====================================================================
    // Helpers for the structural proofs
    // =====================================================================

    private function insertRelationship(Workspace $agency, Workspace $client): void
    {
        app(AgencyClientWorkspaceRelationshipRepository::class)->create([
            'agency_workspace_id' => $agency->id,
            'client_workspace_id' => $client->id,
            'status' => AgencyClientRelationshipStatus::Active,
            'established_by_user_id' => $this->platformAdminId(),
            'established_at' => now(),
        ]);
    }

    /**
     * @return array<string, array{calls: array<int, string>, readsRelationships: bool}>
     */
    private function resolverCallGraph(): array
    {
        $class = new ReflectionClass(CustomerAccountAccessResolver::class);
        $lines = file($class->getFileName());
        $graph = [];

        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            $tokens = array_values(array_filter(
                token_get_all('<?php ' . $body),
                fn ($token): bool => ! (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)),
            ));

            $calls = [];
            $readsRelationships = false;

            foreach ($tokens as $i => $token) {
                $isThis = is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$this';
                $arrow = $tokens[$i + 1] ?? null;
                $name = $tokens[$i + 2] ?? null;

                if (! $isThis || ! is_array($arrow) || ! in_array($arrow[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) || ! is_array($name) || $name[0] !== T_STRING) {
                    continue;
                }

                if ($name[1] === 'relationshipRepository') {
                    $readsRelationships = true;
                }

                if (($tokens[$i + 3] ?? null) === '(') {
                    $calls[] = $name[1];
                }
            }

            $graph[$method->getName()] = ['calls' => array_values(array_unique($calls)), 'readsRelationships' => $readsRelationships];
        }

        return $graph;
    }

    /**
     * @param array<string, array{calls: array<int, string>, readsRelationships: bool}> $graph
     * @return array<int, string> every class method reachable from $start (excluding $start itself unless a cycle returns to it)
     */
    private function reachableFrom(string $start, array $graph): array
    {
        $seen = [];
        $stack = $graph[$start]['calls'];

        while ($stack !== []) {
            $method = array_pop($stack);

            if (isset($seen[$method]) || ! isset($graph[$method])) {
                continue;
            }

            $seen[$method] = true;
            array_push($stack, ...$graph[$method]['calls']);
        }

        return array_keys($seen);
    }

    private function sorted(array $values): array
    {
        sort($values);

        return array_values($values);
    }
}
