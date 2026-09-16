<?php

namespace Tests\Feature\QueryBudget;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Shared customer request query-budget optimization (Automations V2 §18,
 * the V2-E blocker on PR #280).
 *
 * B4's Business\AutomationsController is the recon proxy this slice's own
 * investigation traced end-to-end: it runs the exact shared chain V2-E's
 * future workflow controllers also run — ResolveCustomerContext, a
 * controller-owned Workspace/Business tenancy resolution
 * (resolveEntitledBusiness(), the same shape §18's "Shared tenancy
 * resolver trait" describes), a single-feature EntitlementManager::decide()
 * call, and the full customer page shell (menu/nav entitlement snapshot,
 * theme, languages, notifications, app_config). Recon measured this
 * SAME page's real SQL, live, before any change, at 29 queries; this test
 * pins the real, current cost after the fix so any future duplicate read
 * reintroduced into this shared path is caught here, not just in a
 * V2-E-specific test that does not exist on this branch.
 */
class BusinessScopedRequestQueryBudgetTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    /**
     * Observed on this branch after the optimization (see this file's own
     * class docblock for the recon trail: 29 -> 18). A regression back
     * toward 29 means a duplicate shared read came back; a number lower
     * than this is a welcome surprise, never a failure — only growth is
     * pinned as a hard ceiling below.
     *
     * 18 -> 19 (Contract 05, Agency non-payment composition): the customer
     * access gate's CustomerAccountAccessResolver::resolve() now makes ONE
     * read of agency_client_workspace_relationships, to learn whether a
     * managing Agency's own lock also applies to this Workspace. That is a
     * new, necessary read on every gated request, not a duplicate of any
     * existing one — the at-most-once test below still holds for every
     * shared table.
     */
    private const OBSERVED_QUERY_COUNT = 19;

    /**
     * The ORIGINAL, pre-optimization observed cost, kept only as the
     * ceiling this fix must never regress back up to — not as a target.
     */
    private const PRE_OPTIMIZATION_QUERY_COUNT = 29;

    public function test_the_automations_listing_page_stays_at_its_reduced_observed_query_cost(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            self::OBSERVED_QUERY_COUNT,
            count($queries),
            'Query count regressed above the observed post-optimization cost: ' . implode(' | ', array_column($queries, 'query')),
        );
        $this->assertLessThan(
            self::PRE_OPTIMIZATION_QUERY_COUNT,
            count($queries),
            'This is the exact regression this optimization exists to prevent: back at (or past) the pre-optimization cost.',
        );
    }

    /**
     * The mechanical proof, not just the aggregate number: each of these
     * tables is read AT MOST once for the whole request now, where the
     * controller's own tenancy/entitlement check and the menu/shell's
     * snapshot previously each read it independently.
     */
    public function test_no_shared_entitlement_table_is_read_more_than_once(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]))->assertOk();

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        foreach (['workspace_plan_assignments', 'workspace_plan_catalog', 'workspace_entitlement_overrides', 'workspace_plan_features', 'business_feature_toggles'] as $table) {
            $reads = count(array_filter($queries, fn (string $sql) => preg_match('/\bfrom\s+`' . preg_quote($table, '/') . '`/i', $sql) === 1));
            $this->assertLessThanOrEqual(1, $reads, "{$table} was read {$reads} times — the controller's own entitlement check and the menu/shell snapshot must share one read.");
        }
    }

    /**
     * The other half of the mechanical proof: Business and Workspace are
     * each re-read by id (not just by their route uid) at most once — the
     * tenancy check (userCanAccessBusiness()) and the entitlement decision
     * previously each re-read both independently.
     */
    public function test_business_and_workspace_are_each_read_by_id_at_most_once(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]))->assertOk();

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $businessByIdReads = count(array_filter($queries, fn (string $sql) => preg_match('/select \* from `businesses` where `businesses`\.`id` = \?/i', $sql) === 1));
        $workspaceByIdReads = count(array_filter($queries, fn (string $sql) => preg_match('/select \* from `workspaces` where `workspaces`\.`id` = \?/i', $sql) === 1));

        $this->assertLessThanOrEqual(1, $businessByIdReads, "Business was re-read by id {$businessByIdReads} times.");
        $this->assertLessThanOrEqual(1, $workspaceByIdReads, "Workspace was re-read by id {$workspaceByIdReads} times.");
    }

    /**
     * Doubling the data behind the shared shell (a second, sibling
     * Business in the same Workspace) must not add a query to this
     * Business's own page — the CustomerContextSnapshot at the top of the
     * request is already bulk, and none of this optimization's caching
     * introduces a scan over "all Businesses" in place of the one being
     * viewed.
     */
    public function test_a_sibling_business_in_the_same_workspace_does_not_change_the_query_count(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]))->assertOk();
        $beforeLog = array_column(DB::getQueryLog(), 'query');
        $before = count($beforeLog);
        DB::disableQueryLog();

        $this->addBusiness($customer, $workspace, 'Sibling Business');

        // A fresh User model, re-authenticated: Eloquent caches a lazily
        // loaded relation (e.g. User::customer) ON THE MODEL INSTANCE, and
        // $this->actingAs() holding the same PHP object across two
        // $this->get() calls in one test would let the second call skip a
        // query the first one already paid for — an artifact of reusing
        // one instance across two simulated requests, not something this
        // optimization did or that a real second HTTP request would ever
        // see (a real request re-authenticates from scratch every time).
        $this->authenticateAs($customer->fresh());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]))->assertOk();
        $afterLog = array_column(DB::getQueryLog(), 'query');
        $after = count($afterLog);
        DB::disableQueryLog();

        $this->assertSame($before, $after, 'Adding a sibling Business must not change this Business\'s own page cost. BEFORE: ' . implode(' | ', $beforeLog) . ' AFTER: ' . implode(' | ', $afterLog));
    }
}
