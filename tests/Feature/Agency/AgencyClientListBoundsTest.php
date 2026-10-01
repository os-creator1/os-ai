<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Support\RequestScopedCache;
use App\Library\Workspace\AgencyClientListReader;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Agency V1 completion, acceptance items 3 and 13 — the Agency Clients list is
 * one BOUNDED page whose cost does not grow with the number of clients, whose
 * search and filter run in SQL, that can never enumerate another Agency's
 * clients, and whose "Account" column is the ONE Contract 03 truth table the
 * rest of the product already uses (not a second lifecycle authority).
 */
class AgencyClientListBoundsTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
    }

    /** @return array{0: Workspace, 1: Customer} */
    private function agency(string $name = 'Northwind Agency'): array
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);

        return [$workspace, $owner];
    }

    /** One more managed client for an existing Agency, through the real manager. */
    private function addClient(Workspace $agency, string $name, bool $withPlan = true): Workspace
    {
        $client = $this->createAgencyManagedClient($agency, $name . ' Business', $name)['clientWorkspace'];

        // The fixture leaves the independent client without a plan assignment.
        if ($withPlan) {
            $this->assignTier($client, WorkspacePlanTier::Growth);
        }

        return $client->fresh();
    }

    private function indexUrl(Workspace $agency, array $query = []): string
    {
        return route('customer.workspaces.clients.index', ['workspaceUid' => $agency->uid] + $query);
    }

    /** @return array<int, string> */
    private function clientRowNames(string $html): array
    {
        preg_match_all('/data-role="client-row"[^>]*>\s*<td>([^<]*)<\/td>/', $html, $matches);

        return array_map('trim', $matches[1]);
    }

    private function queryCountFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_a_page_never_exceeds_the_bound_and_pagination_reaches_the_rest(): void
    {
        [$agency, $owner] = $this->agency();

        for ($i = 1; $i <= 7; $i++) {
            $this->addClient($agency, sprintf('Client %02d', $i));
        }

        $reader = app(AgencyClientListReader::class);

        $first = $reader->page((int) $agency->id, '', '', 1, 3);
        $this->assertCount(3, $first->items());
        $this->assertSame(7, $first->total());
        $this->assertSame(3, $first->lastPage());

        $third = $reader->page((int) $agency->id, '', '', 3, 3);
        $this->assertCount(1, $third->items());

        // A caller can never widen the bound.
        $this->assertSame(AgencyClientListReader::MAX_PER_PAGE, $reader->page((int) $agency->id, '', '', 1, 10_000)->perPage());

        // Over HTTP: oldest relationship first, 25 per page by default.
        $this->authenticateAs($owner);
        $names = $this->clientRowNames($this->get($this->indexUrl($agency))->assertOk()->getContent());
        $this->assertSame(
            ['Client 01', 'Client 02', 'Client 03', 'Client 04', 'Client 05', 'Client 06', 'Client 07'],
            $names,
        );
    }

    public function test_the_statement_count_does_not_grow_with_the_number_of_clients(): void
    {
        [$agency, $owner] = $this->agency();
        $this->authenticateAs($owner);

        $this->addClient($agency, 'Client A');
        $this->addClient($agency, 'Client B');

        // Warm anything lazily loaded once, so only the list itself is measured.
        $this->get($this->indexUrl($agency))->assertOk();

        $small = $this->queryCountFor(fn () => $this->get($this->indexUrl($agency))->assertOk());

        for ($i = 1; $i <= 8; $i++) {
            $this->addClient($agency, "Extra {$i}");
        }

        $this->get($this->indexUrl($agency))->assertOk();
        $large = $this->queryCountFor(fn () => $this->get($this->indexUrl($agency))->assertOk());

        $this->assertSame($small, $large, 'The Clients list issued more statements for 10 clients than for 2: a per-client query has crept in.');
    }

    public function test_search_runs_in_sql_matches_workspace_or_business_name_and_escapes_wildcards(): void
    {
        [$agency, $owner] = $this->agency();
        $this->authenticateAs($owner);

        $this->addClient($agency, 'Alpha Dental');
        $this->addClient($agency, 'Bravo Plumbing');
        $this->addClient($agency, '100% Roofing');

        $reader = app(AgencyClientListReader::class);

        $names = fn (string $search) => array_map(fn ($r) => $r['workspace_name'], $reader->page((int) $agency->id, $search)->items());

        $this->assertSame(['Alpha Dental'], $names('alpha'));
        // Business names are searched too (the fixture's business is "<name> Business").
        $this->assertSame(['Bravo Plumbing'], $names('Plumbing Business'));
        // A literal percent matches only the literal percent, not "everything".
        $this->assertSame(['100% Roofing'], $names('%'));
        $this->assertSame([], $names('_'));
        $this->assertSame([], $names('no such client'));
    }

    public function test_an_oversized_search_term_is_truncated_not_executed_as_given(): void
    {
        [$agency, $owner] = $this->agency();
        $this->authenticateAs($owner);
        $this->addClient($agency, 'Alpha Dental');

        $long = 'Alpha Dental' . str_repeat('x', 500);
        $rows = app(AgencyClientListReader::class)->page((int) $agency->id, $long)->items();

        // The term is cut to MAX_SEARCH_LENGTH (80), and the junk tail begins inside that window
        // ("Alpha Dental" is 12 characters), so the cut term still matches nothing — and nothing errors.
        $this->assertCount(0, $rows);

        $this->get($this->indexUrl($agency, ['search' => $long]))->assertOk();
    }

    public function test_the_business_state_filter_narrows_in_sql_and_ignores_unknown_values(): void
    {
        [$agency, $owner] = $this->agency();
        $this->authenticateAs($owner);

        $active = $this->addClient($agency, 'Active Client');
        $inactive = $this->addClient($agency, 'Inactive Client');
        DB::table('businesses')->where('workspace_id', $inactive->id)->update(['status' => 'inactive']);
        $draft = $this->addClient($agency, 'Draft Client');
        DB::table('businesses')->where('workspace_id', $draft->id)->update(['status' => 'draft']);

        $reader = app(AgencyClientListReader::class);
        $names = fn (string $state) => array_map(fn ($r) => $r['workspace_name'], $reader->page((int) $agency->id, '', $state)->items());

        $this->assertSame(['Active Client'], $names('active'));
        $this->assertSame(['Inactive Client'], $names('inactive'));
        $this->assertSame(['Draft Client'], $names('setup'));
        // An unknown filter value is "no filter", never an error or an injection.
        $this->assertCount(3, $reader->page((int) $agency->id, '', "active' OR 1=1 --")->items());
    }

    public function test_another_agencys_clients_are_never_enumerable_by_search_filter_or_page(): void
    {
        [$agencyA, $ownerA] = $this->agency('Agency A');
        [$agencyB] = $this->agency('Agency B');

        $this->addClient($agencyA, 'Shared Name Dental A');
        $this->addClient($agencyB, 'Shared Name Dental B');

        $reader = app(AgencyClientListReader::class);

        foreach (['', 'Shared Name', 'Dental B', 'B'] as $search) {
            foreach (['', 'active', 'setup', 'inactive'] as $state) {
                foreach ([1, 2, 99] as $page) {
                    $rows = $reader->page((int) $agencyA->id, $search, $state, $page)->items();

                    foreach ($rows as $row) {
                        $this->assertSame('Shared Name Dental A', $row['workspace_name']);
                    }
                }
            }
        }

        $this->authenticateAs($ownerA);
        $html = $this->get($this->indexUrl($agencyA, ['search' => 'Dental B']))->assertOk()->getContent();
        $this->assertSame([], $this->clientRowNames($html));
        $this->assertStringNotContainsString('Shared Name Dental B', $html);
    }

    public function test_a_terminated_relationship_leaves_the_list_and_the_agency_never_lists_itself(): void
    {
        [$agency, $owner] = $this->agency();

        $kept = $this->addClient($agency, 'Kept Client');
        $ended = $this->createAgencyManagedClient($agency, 'Ended Business', 'Ended Client');

        app(AgencyClientRelationshipManager::class)->terminate((int) $owner->user_id, $ended['relationship'], 'Test: ended.');

        // A legacy self-link row (impossible through the manager) must never list the Agency itself.
        DB::table('agency_client_workspace_relationships')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'agency_workspace_id' => $agency->id,
            'client_workspace_id' => $agency->id,
            'status' => 'active',
            'established_by_user_id' => $owner->user_id,
            'established_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $names = array_map(
            fn ($r) => $r['workspace_name'],
            app(AgencyClientListReader::class)->page((int) $agency->id)->items(),
        );

        $this->assertSame(['Kept Client'], $names);
        $this->assertNotContains('Ended Client', $names);
        $this->assertNotContains('Northwind Agency', $names);
        $this->assertSame($kept->uid, Workspace::query()->where('name', 'Kept Client')->value('uid'));
    }

    public function test_the_account_column_is_the_single_contract_03_truth_table(): void
    {
        $entitlements = app(EntitlementManager::class);
        $resolver = app(CustomerAccountAccessResolver::class);
        $assignments = app(WorkspacePlanAssignmentRepository::class);

        $cases = [
            'active' => fn (Workspace $w) => null,
            'trial' => fn (Workspace $w) => $assignments->update($assignments->findByWorkspaceIdForUpdate((int) $w->id), ['trial_ends_at' => now()->addDays(10)]),
            'grace' => fn (Workspace $w) => $entitlements->enterGracePeriod($w, null, 'Renewal failed.'),
            'locked' => function (Workspace $w) use ($entitlements): void {
                $entitlements->enterGracePeriod($w, null, 'Trial ended.');
                $entitlements->lockForNonPayment($w, null, 'Grace elapsed.');
            },
            'locked_elapsed_grace' => fn (Workspace $w) => $assignments->update($assignments->findByWorkspaceIdForUpdate((int) $w->id), [
                'grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS + 1),
            ]),
            'inactive' => fn (Workspace $w) => $entitlements->changePlanStatus($w, WorkspacePlanAssignmentStatus::Inactive, $this->platformAdminId(), 'Closed.'),
            'suspended' => fn (Workspace $w) => $entitlements->changePlanStatus($w, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Suspended.'),
        ];

        [$agency] = $this->agency();
        $expectedLabel = [
            'active' => 'Active', 'trial' => 'Trial', 'grace' => 'Payment due', 'locked' => 'Locked',
            'locked_elapsed_grace' => 'Locked', 'inactive' => 'Inactive', 'suspended' => 'Suspended',
        ];

        foreach ($cases as $state => $apply) {
            $client = $this->addClient($agency, "Client {$state}");
            $apply($client);
            app(RequestScopedCache::class)->flush();

            // What the canonical resolver says about this very Workspace (the
            // Agency is usable, so composition changes nothing)...
            $canonical = $resolver->resolve($client->fresh());

            // ...is what the list's row says, from the same table.
            $row = collect(app(AgencyClientListReader::class)->page((int) $agency->id, "Client {$state}")->items())->first();

            $this->assertNotNull($row, $state);
            $this->assertSame($expectedLabel[$state], $row['account']['label'], $state);
            $this->assertSame($canonical->reason, $row['account']['reason'], $state);
        }
    }

    public function test_a_client_with_no_plan_assignment_yet_reads_no_plan_and_is_still_listed(): void
    {
        [$agency] = $this->agency();
        $this->addClient($agency, 'Unassigned Client', false);

        $row = collect(app(AgencyClientListReader::class)->page((int) $agency->id)->items())->first();

        $this->assertNotNull($row);
        $this->assertNull($row['plan_name']);
        $this->assertSame('No plan yet', $row['account']['label']);
    }

    public function test_plan_and_agency_billing_columns_come_from_the_authoritative_rows(): void
    {
        [$agency, $owner] = $this->agency();
        $client = $this->addClient($agency, 'Planned Client');

        $row = collect(app(AgencyClientListReader::class)->page((int) $agency->id)->items())->first();

        $this->assertNotNull($row['plan_name'], 'the plan comes from the client\'s own assignment');
        $this->assertNull($row['agency_subscription_status']);

        $this->authenticateAs($owner);
        $html = $this->get($this->indexUrl($agency))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="client-account-state"', $html);
        $this->assertStringContainsString('data-role="client-plan"', $html);
        $this->assertStringContainsString('data-role="client-agency-billing"', $html);
        $this->assertSame($client->uid, Workspace::query()->where('name', 'Planned Client')->value('uid'));
    }
}
