<?php

namespace Tests\Feature\Navigation;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\BusinessCandidate;
use App\Library\Navigation\ContextSource;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerFrame;
use App\Library\Navigation\CustomerMenuBuilder;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\Navigation\MenuEntitlements;
use App\Library\Navigation\MenuItem;
use App\Library\Navigation\WorkspaceCandidate;
use App\Library\ViewAs\ViewAsContext;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Slice 4 Correction 1 — the Slice 2A entitlement snapshot, genuinely ONE per
 * HTTP request.
 *
 * CustomerShellComposer used to rebuild MenuEntitlements for every shell view
 * it composed: five identical snapshots, about thirty queries, on a single
 * Dashboard request. It now keeps the snapshot on the Request itself, keyed by
 * the resolved context, and every later consumer in the same request reuses
 * that very object.
 *
 * What is proven here, in the order the correction lists it:
 *  1–3  one snapshot per Business request, however many shell views compose,
 *       within the existing single-snapshot budget;
 *  4    later consumers add zero queries and receive the same object;
 *  5    the Account frame asks nothing;
 *  6    a new HTTP request never inherits the previous request's snapshot;
 *  7    Business A's answer is never served for Business B;
 *  8    a view-as context never inherits a non-view-as answer, or the reverse;
 *  9    the Core/Growth/Agency trees are what a fresh snapshot would build;
 *  10   the query count still does not grow with the feature list.
 *
 * A snapshot is counted by its one unmistakable read: business_feature_toggles,
 * which only EntitlementManager's decision paths ever select.
 */
class MenuEntitlementsRequestSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    /** The five tables EntitlementManager's bulk snapshot reads, besides the Business re-read. */
    private const SNAPSHOT_TABLES = [
        'workspace_plan_assignments',
        'workspace_plan_catalog',
        'workspace_entitlement_overrides',
        'workspace_plan_features',
        'business_feature_toggles',
    ];

    private const SHELL_VIEWS = [
        'panels.sidebar', 'panels.navbar', 'panels.breadcrumb', 'panels.horizontalMenu',
        'components.customer-context-switcher', 'components.view-as-banner',
    ];

    // =================================================================
    // 1–3. One snapshot per Business request
    // =================================================================

    public function test_a_business_request_builds_one_snapshot_however_many_shell_views_compose(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        $composed = 0;
        foreach (self::SHELL_VIEWS as $view) {
            Event::listen('composing: ' . $view, function () use (&$composed) {
                $composed++;
            });
        }

        $reads = $this->snapshotReads(fn () => $this->home()->assertOk());

        $this->assertGreaterThanOrEqual(2, $composed, 'Precondition: several shell views compose on one request.');
        $this->assertSame(1, $reads['snapshots'], "One request, {$composed} composed shell views, {$reads['snapshots']} snapshots.");
    }

    /**
     * Every snapshot table is read exactly once for the whole request, so the
     * request's entitlement cost is the single-snapshot budget: these five
     * reads plus the Business re-read — at most six (Slice 2A §13 #18).
     */
    public function test_the_whole_request_stays_within_the_single_snapshot_budget(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $reads = $this->snapshotReads(fn () => $this->home()->assertOk());

        foreach (self::SNAPSHOT_TABLES as $table) {
            $this->assertSame(1, $reads['tables'][$table], "{$table} must be read once per request, read {$reads['tables'][$table]} times.");
        }

        $this->assertLessThanOrEqual(6, array_sum($reads['tables']) + 1, 'The snapshot plus its Business re-read stays within six queries.');
    }

    // =================================================================
    // 4. Later consumers reuse the first consumer's object, at no cost
    // =================================================================

    public function test_every_consumer_after_the_first_adds_zero_queries_and_receives_the_same_object(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $context = $this->businessContext($customer, $business, $workspace);
        $this->freshRequest();

        // Separate composer instances, as the view factory builds them.
        $first = null;
        $firstCost = $this->queriesDuring(function () use ($context, &$first) {
            $first = app(CustomerShellComposer::class)->currentMenuEntitlements($context);
        });

        $later = [];
        $laterCost = $this->queriesDuring(function () use ($context, &$later) {
            for ($i = 0; $i < 4; $i++) {
                $later[] = app(CustomerShellComposer::class)->currentMenuEntitlements($context);
            }
        });

        $this->assertGreaterThan(0, $firstCost, 'The first consumer resolves the snapshot.');
        $this->assertLessThanOrEqual(6, $firstCost);
        $this->assertSame(0, $laterCost, 'Every later consumer in the request costs nothing.');

        foreach ($later as $entitlements) {
            $this->assertSame($first, $entitlements, 'The exact same object is shared.');
        }
    }

    // =================================================================
    // 5. The Account frame asks nothing
    // =================================================================

    public function test_the_account_frame_issues_no_business_entitlement_query(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Client Two');
        $this->authenticateAs($agency);

        $reads = $this->snapshotReads(fn () => $this->home()->assertOk());
        $this->assertSame(0, $reads['snapshots'], 'An Account-frame request evaluates no Business.');

        $context = $this->accountContext($agency, $workspace->fresh());
        $this->freshRequest();

        $entitlements = null;
        $cost = $this->queriesDuring(function () use ($context, &$entitlements) {
            $entitlements = app(CustomerShellComposer::class)->currentMenuEntitlements($context);
        });

        $this->assertSame(0, $cost);
        $this->assertFalse($entitlements->evaluated);
        $this->assertFalse($entitlements->allows('automations'));
    }

    // =================================================================
    // 6. A snapshot never outlives its HTTP request
    // =================================================================

    public function test_each_http_request_builds_its_own_snapshot_and_sees_a_change_made_between_them(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        $first = $this->snapshotReads(fn () => $this->assertContains('automations', $this->menuKeys($this->home()->assertOk()->getContent())));
        $this->assertSame(1, $first['snapshots']);

        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Automations, (int) $customer->user_id, 'Correction 1 lifecycle proof.');

        $second = $this->snapshotReads(fn () => $this->assertNotContains('automations', $this->menuKeys($this->home()->assertOk()->getContent())));
        $this->assertSame(1, $second['snapshots'], 'The second request resolves its own snapshot rather than reusing the first.');
    }

    public function test_the_snapshot_is_kept_on_the_request_object_itself(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $context = $this->businessContext($customer, $business, $workspace);

        $requestA = $this->freshRequest();
        $fromA = app(CustomerShellComposer::class)->currentMenuEntitlements($context);
        $this->assertCount(1, $requestA->attributes->get(CustomerShellComposer::MENU_ENTITLEMENTS_ATTRIBUTE));

        $requestB = $this->freshRequest();
        $this->assertNull($requestB->attributes->get(CustomerShellComposer::MENU_ENTITLEMENTS_ATTRIBUTE), 'A new request starts empty.');

        $cost = $this->queriesDuring(fn () => app(CustomerShellComposer::class)->currentMenuEntitlements($context));

        $this->assertGreaterThan(0, $cost, 'The new request resolves again.');
        $this->assertNotSame($fromA, app(CustomerShellComposer::class)->currentMenuEntitlements($context));
    }

    // =================================================================
    // 7. Business A's answer is never served for Business B
    // =================================================================

    public function test_business_a_and_business_b_never_share_a_snapshot_within_one_request(): void
    {
        [$owner, $businessA, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client A', 'Northwind Agency');
        $businessB = $this->addBusiness($owner, $workspace, 'Client B');
        app(EntitlementManager::class)->disableBusinessFeature($businessB, PlatformFeature::Automations, (int) $owner->user_id, 'B differs from A.');

        $workspace = $workspace->fresh();
        $contextA = $this->businessContext($owner, $businessA, $workspace);
        $contextB = $this->businessContext($owner, $businessB->fresh(), $workspace);
        $this->freshRequest();

        $a = app(CustomerShellComposer::class)->currentMenuEntitlements($contextA);

        $b = null;
        $costB = $this->queriesDuring(function () use ($contextB, &$b) {
            $b = app(CustomerShellComposer::class)->currentMenuEntitlements($contextB);
        });

        $this->assertGreaterThan(0, $costB, 'B is resolved for B, not served from A.');
        $this->assertNotSame($a, $b);
        $this->assertTrue($a->allows('automations'));
        $this->assertFalse($b->allows('automations'), "B's own toggle is honoured.");

        // A is still A's own object afterwards.
        $this->assertSame($a, app(CustomerShellComposer::class)->currentMenuEntitlements($contextA));
    }

    public function test_switching_business_between_requests_serves_the_new_businesss_answer(): void
    {
        [$owner, $businessA, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client A', 'Northwind Agency');
        $businessB = $this->addBusiness($owner, $workspace, 'Client B');
        app(EntitlementManager::class)->disableBusinessFeature($businessB, PlatformFeature::Automations, (int) $owner->user_id, 'B differs from A.');
        $this->authenticateAs($owner);

        $this->switchTo($workspace, $businessA)->assertRedirect();
        $this->assertContains('automations', $this->menuKeys($this->home()->assertOk()->getContent()));

        $this->switchTo($workspace, $businessB)->assertRedirect();
        $this->assertNotContains('automations', $this->menuKeys($this->home()->assertOk()->getContent()));
    }

    // =================================================================
    // 8. View-as never inherits a non-view-as answer, or the reverse
    // =================================================================

    public function test_a_view_as_context_never_receives_a_non_view_as_or_other_business_snapshot(): void
    {
        [$owner, $businessA, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client A', 'Northwind Agency');
        $businessB = $this->addBusiness($owner, $workspace, 'Client B');
        $workspace = $workspace->fresh();

        $plainA = $this->businessContext($owner, $businessA, $workspace);
        $plainB = $this->businessContext($owner, $businessB->fresh(), $workspace);
        $viewingA = $this->businessContext($owner, $businessA, $workspace, $this->viewAs($owner, $workspace, $businessA));
        $this->freshRequest();

        $a = app(CustomerShellComposer::class)->currentMenuEntitlements($plainA);
        $b = app(CustomerShellComposer::class)->currentMenuEntitlements($plainB);

        $viewed = null;
        $cost = $this->queriesDuring(function () use ($viewingA, &$viewed) {
            $viewed = app(CustomerShellComposer::class)->currentMenuEntitlements($viewingA);
        });

        $this->assertGreaterThan(0, $cost, 'The view-as context resolves its own snapshot.');
        $this->assertNotSame($a, $viewed, 'Never the non-view-as answer for the same Business.');
        $this->assertNotSame($b, $viewed, 'Never another Business\'s answer.');
        $this->assertSame($viewed, app(CustomerShellComposer::class)->currentMenuEntitlements($viewingA));
    }

    public function test_while_viewing_a_client_the_menu_reflects_only_that_clients_entitlements(): void
    {
        [$owner, $businessA, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client A', 'Northwind Agency');
        $businessB = $this->addBusiness($owner, $workspace, 'Client B');
        app(EntitlementManager::class)->disableBusinessFeature($businessA, PlatformFeature::Automations, (int) $owner->user_id, 'A is the restricted one.');
        $this->authenticateAs($owner);

        // Business B selected first, in its own request.
        $this->switchTo($workspace, $businessB)->assertRedirect();
        $this->assertContains('automations', $this->menuKeys($this->home()->assertOk()->getContent()));

        // Viewing A: A's answer, never B's from the earlier request.
        $this->startViewAs($workspace, $businessA, 'Correction 1 view-as proof.')->assertRedirect();
        $this->assertNotContains('automations', $this->menuKeys($this->home()->assertOk()->getContent()));
    }

    // =================================================================
    // 9. The trees are exactly what a fresh snapshot builds
    // =================================================================

    public function test_core_growth_and_agency_trees_match_a_freshly_built_snapshot(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer] = $this->tenant($tier, 'Tree ' . $tier->value, 'Tree Account ' . $tier->value);
            $this->authenticateAs($customer);

            $html = $this->home()->assertOk()->getContent();
            $context = app(CustomerContext::class);
            $this->assertTrue($context->isBusinessFrame(), "Precondition for {$tier->value}.");

            $fresh = MenuEntitlements::forBusiness(
                app(EntitlementManager::class),
                Workspace::query()->findOrFail($context->selectedWorkspace->id),
                Business::query()->findOrFail($context->selectedBusiness->id),
                CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES,
                $context->userId,
            );
            $expected = app(CustomerMenuBuilder::class)->build($context, $customer->user, $fresh);

            $this->assertSame($this->flattenKeys($expected), $this->menuKeys($html), "{$tier->value}: keys differ from a fresh snapshot.");
            $this->assertSame($this->flattenLinks($expected), $this->menuLinks($html), "{$tier->value}: links differ from a fresh snapshot.");
        }
    }

    // =================================================================
    // 10. Still no N+feature growth
    // =================================================================

    public function test_the_query_count_still_does_not_grow_with_the_feature_list(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $count = function (array $features) use ($customer, $business, $workspace): int {
            return $this->queriesDuring(fn () => MenuEntitlements::forBusiness(app(EntitlementManager::class), $workspace, $business, $features, (int) $customer->user_id));
        };

        $this->assertSame(
            $count(CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES),
            $count(['crm', 'conversations', 'automations', 'website_generation', 'google_business_profile_module']),
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{snapshots: int, tables: array<string, int>}
     */
    private function snapshotReads(callable $work): array
    {
        $tables = array_fill_keys(self::SNAPSHOT_TABLES, 0);

        DB::listen(function ($query) use (&$tables) {
            foreach (array_keys($tables) as $table) {
                // The snapshot's own reads are `from <table>`; the context
                // snapshot merely JOINs the plan tables, and is not counted.
                if (preg_match('/\bfrom\s+`' . preg_quote($table, '/') . '`/i', $query->sql) === 1) {
                    $tables[$table]++;
                }
            }
        });

        $work();

        return ['snapshots' => $tables['business_feature_toggles'], 'tables' => $tables];
    }

    private function queriesDuring(callable $work): int
    {
        $count = 0;
        $listening = true;

        DB::listen(function () use (&$count, &$listening) {
            if ($listening) {
                $count++;
            }
        });

        $work();
        $listening = false;

        return $count;
    }

    /** A new, empty Request becomes the current one — as a new HTTP request would. */
    private function freshRequest(): Request
    {
        $request = Request::create('/dashboard');
        $this->app->instance('request', $request);

        return $request;
    }

    private function businessContext(Customer $customer, Business $business, Workspace $workspace, ?ViewAsContext $viewAs = null): CustomerContext
    {
        $candidate = new BusinessCandidate(
            (int) $business->id, (string) $business->uid, (string) $business->name, (string) $business->status->value,
            (int) $business->customer_id, (bool) $business->is_primary, true, (string) $workspace->uid, (string) $workspace->name,
        );

        $workspaceCandidate = $this->workspaceCandidate($customer, $workspace, [$candidate]);

        return new CustomerContext(
            userId: (int) $customer->user_id,
            frame: CustomerFrame::Business,
            workspaces: [$workspaceCandidate],
            selectedWorkspace: $workspaceCandidate,
            selectedBusiness: $candidate,
            source: $viewAs !== null ? ContextSource::ViewAs : ContextSource::Sole,
            viewAs: $viewAs,
            preferenceCleared: false,
        );
    }

    private function accountContext(Customer $customer, Workspace $workspace): CustomerContext
    {
        $workspaceCandidate = $this->workspaceCandidate($customer, $workspace, []);

        return new CustomerContext(
            userId: (int) $customer->user_id,
            frame: CustomerFrame::Account,
            workspaces: [$workspaceCandidate],
            selectedWorkspace: $workspaceCandidate,
            selectedBusiness: null,
            source: ContextSource::None,
            viewAs: null,
            preferenceCleared: false,
        );
    }

    /**
     * @param  array<int, BusinessCandidate>  $businesses
     */
    private function workspaceCandidate(Customer $customer, Workspace $workspace, array $businesses): WorkspaceCandidate
    {
        return new WorkspaceCandidate(
            (int) $workspace->id, (string) $workspace->uid, (string) $workspace->name, true,
            (int) $workspace->owner_user_id, (int) $workspace->owner_user_id === (int) $customer->user_id,
            null, null, false, null, null, $businesses,
        );
    }

    private function viewAs(Customer $actor, Workspace $workspace, Business $business): ViewAsContext
    {
        return new ViewAsContext(
            sessionId: 4242,
            uid: 'correction-1-view-as',
            actorUserId: (int) $actor->user_id,
            actorDisplayName: 'Agency Owner',
            workspaceId: (int) $workspace->id,
            workspaceUid: (string) $workspace->uid,
            businessId: (int) $business->id,
            businessUid: (string) $business->uid,
            businessName: (string) $business->name,
            startedAt: CarbonImmutable::now(),
            expiresAt: CarbonImmutable::now()->addHour(),
        );
    }

    /**
     * @param  array<int, MenuItem>  $items
     * @return array<int, string>
     */
    private function flattenKeys(array $items): array
    {
        $keys = [];

        foreach ($items as $item) {
            $keys[] = $item->key;

            foreach ($item->children as $child) {
                $keys = [...$keys, ...$this->flattenKeys([$child])];
            }
        }

        return $keys;
    }

    /**
     * @param  array<int, MenuItem>  $items
     * @return array<int, string>
     */
    private function flattenLinks(array $items): array
    {
        $links = [];

        foreach ($items as $item) {
            if ($item->url !== null) {
                $links[] = $item->url;
            }

            foreach ($item->children as $child) {
                $links = [...$links, ...$this->flattenLinks([$child])];
            }
        }

        return array_values(array_unique($links));
    }
}
