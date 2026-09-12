<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Dashboard\AccountHomePresenter;
use App\Library\Dashboard\BusinessHomePresenter;
use App\Library\Dashboard\DashboardSnapshot;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 4 §13, §18 #20, #24–#27, #46, #54 — the page's
 * cost, counted with DB::listen around the presenter itself, so the shared
 * shell (auth, context, view-as, menu) and Slice 2A's entitlement snapshot
 * — already built once for the request — are excluded by construction.
 *
 * The contract's figures are ceilings; per §13 the stricter observed number
 * is what these tests pin.
 */
class DashboardQueryBudgetTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    /** Observed on this base: Business re-read, status read, payer assignment + Workspace (payer authority), Advisor page count + rows. */
    private const BUSINESS_HOME_DASHBOARD_OWNED = 6;

    private const BUSINESS_HOME_ANALYTICS = 6;

    private const BUSINESS_HOME_CONVERSATIONS = 2;

    /** Observed: status read, Workspace, three capacity reads, prospecting, two Agency-wide control reads. */
    private const AGENCY_HOME_DASHBOARD_OWNED = 8;

    private const B5_TABLES = '/\b(reports|contacts|contact_groups|automation_executions|campaigns)\b/';

    /**
     * Tables only Slice 2A's bulk snapshot reads on this page. The plan
     * assignment and catalog reads of decideBusinessSlotCapacity() — the
     * Agency capacity band §7 contracts — are dashboard-owned, not a second
     * snapshot.
     */
    private const SNAPSHOT_ONLY_TABLES = '/\b(workspace_entitlement_overrides|workspace_plan_features|business_feature_toggles)\b/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    // =================================================================
    // #25, #46 — Business Home
    // =================================================================

    public function test_the_business_home_stays_inside_every_ceiling_at_its_pinned_observed_cost(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Budget Venue', 'Budget Account');
        $this->populate($business, 1);
        $this->authenticateAs($customer);

        $cost = $this->businessHomeCost($customer->user);

        $this->assertSame(0, $cost['entitlement'], 'The Dashboard adds no entitlement query: it reuses the request\'s one snapshot.');
        $this->assertSame(self::BUSINESS_HOME_DASHBOARD_OWNED, $cost['dashboard'], 'Dashboard-owned: ' . implode(' | ', $cost['dashboardSql']));
        $this->assertSame(self::BUSINESS_HOME_ANALYTICS, $cost['analytics']);
        $this->assertSame(self::BUSINESS_HOME_CONVERSATIONS, $cost['conversations']);

        $this->assertLessThanOrEqual(10, $cost['dashboard']);
        $this->assertLessThanOrEqual(6, $cost['analytics']);
        $this->assertLessThanOrEqual(2, $cost['conversations']);
        $this->assertLessThanOrEqual(18, $cost['dashboard'] + $cost['analytics'] + $cost['conversations'], 'Total Business Home product-data ceiling.');

        // Within the TTL the Analytics seam costs nothing (B5's own cache).
        $warm = $this->businessHomeCost($customer->user, flush: false);
        $this->assertSame(0, $warm['analytics']);
    }

    /**
     * H-3 §2.5 — Business performance gained a chart, and the page's cost did
     * not move: the series is fetched afterwards by the browser from B5's own
     * endpoint. No daily-bucket aggregate (B5's `CASE WHEN created_at < ?`
     * expression, the only thing that produces one) runs on a Home request,
     * whatever period the customer selected.
     */
    public function test_the_business_home_request_runs_no_series_aggregate_for_any_period(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Series Venue', 'Series Account');
        $this->populate($business, 1);
        $this->authenticateAs($customer);

        $periods = [[], ['range' => 'last_90_days'], ['range' => 'custom', 'start' => '2026-06-01', 'end' => '2026-08-31']];

        foreach ($periods as $period) {
            Cache::flush();
            $sql = $this->sqlDuring(fn () => $this->get(route('user.home', $period))->assertOk());

            $this->assertNotEmpty($sql, 'Precondition: the request read something.');

            foreach ($sql as $statement) {
                $this->assertStringNotContainsString(
                    'case when created_at <',
                    strtolower($statement),
                    'A daily-bucket series aggregate ran on the Home request: ' . $statement,
                );
            }
        }
    }

    public function test_the_whole_dashboard_request_builds_the_entitlement_snapshot_exactly_once(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Once Venue', 'Once Account');
        $this->populate($business, 1);
        $this->authenticateAs($customer);

        $sql = $this->sqlDuring(fn () => $this->home()->assertOk());

        // Only the snapshot reads these three; each is read once, so the shell
        // and the Dashboard body shared one snapshot.
        foreach (['workspace_entitlement_overrides', 'workspace_plan_features', 'business_feature_toggles'] as $table) {
            $reads = count(array_filter($sql, fn (string $s) => preg_match('/\b' . $table . '\b/', $s) === 1));
            $this->assertSame(1, $reads, "{$table}: one snapshot for the shell and the Dashboard together.");
        }
    }

    // =================================================================
    // #27 — no N+1 of any kind
    // =================================================================

    public function test_doubling_businesses_contacts_campaigns_conversations_and_messages_leaves_every_count_identical(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Growing Venue', 'Growing Account');
        $this->populate($business, 1);
        $this->authenticateAs($customer);

        $before = $this->businessHomeCost($customer->user);

        $sibling = $this->addBusiness($customer, $workspace, 'Sibling Venue');
        $this->populate($sibling, 1);
        $this->populate($business, 1);
        $this->switchTo($workspace, $business)->assertRedirect(route('user.home'));

        $after = $this->businessHomeCost($customer->user);

        foreach (['dashboard', 'analytics', 'conversations', 'entitlement'] as $layer) {
            $this->assertSame($before[$layer], $after[$layer], "{$layer} must not grow with the data.");
        }
    }

    // =================================================================
    // #26 — Agency Account Home
    // =================================================================

    public function test_the_agency_account_home_is_one_bounded_read_set_whatever_the_number_of_clients(): void
    {
        [$agency, $alpha, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $this->wallet($alpha, ['billing_status' => 'suspended']);
        $this->authenticateAs($agency);

        $two = $this->agencyHomeCost($agency->user);

        $this->assertSame(0, $two['analytics'], 'No N × B5 fan-out: no client KPI at all.');
        $this->assertSame(0, $two['conversations']);
        $this->assertSame(0, $two['entitlement'], 'The Account frame carries no Business, so no Business snapshot.');
        $this->assertSame(self::AGENCY_HOME_DASHBOARD_OWNED, $two['dashboard'], 'Agency-owned: ' . implode(' | ', $two['dashboardSql']));
        $this->assertLessThanOrEqual(12, $two['dashboard']);

        foreach (['Charlie Cafe', 'Delta Deli', 'Echo Eats', 'Foxtrot Florist'] as $name) {
            $client = $this->addBusiness($agency, $workspace, $name);
            $this->wallet($client, ['debt_balance_micro' => 5]);
            $this->website($client, 'draft');
            $this->sent($client, 3, '2026-09-01');
        }

        $six = $this->agencyHomeCost($agency->user);

        $this->assertSame($two['dashboard'], $six['dashboard'], 'Per-client flags are one statement for any number of clients.');
        $this->assertSame(0, $six['analytics']);
    }

    // =================================================================
    // #24 — no query in any dashboard view
    // =================================================================

    public function test_the_dashboard_views_execute_no_query(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $second = $this->addBusiness($customer, $workspace, 'Bravo Bistro');
        $this->populate($business, 1);
        $this->website($business, 'draft');
        $this->authenticateAs($customer);

        $agencySnapshot = $this->dashboardFor($customer->user);
        $this->assertSame(DashboardSnapshot::KIND_AGENCY, $agencySnapshot->kind);

        $this->switchTo($workspace, $business)->assertRedirect(route('user.home'));
        $businessSnapshot = $this->dashboardFor($customer->user);
        $this->assertSame(DashboardSnapshot::KIND_BUSINESS, $businessSnapshot->kind);

        $failed = new DashboardSnapshot(DashboardSnapshot::KIND_BUSINESS, 'Business home', 'Alpha Dental', [DashboardSnapshot::BAND_ACTIONS => ['items' => [], 'parentMessage' => null]], [
            DashboardSnapshot::BAND_ATTENTION, DashboardSnapshot::BAND_ACTIVITY, DashboardSnapshot::BAND_RECOMMENDATIONS, DashboardSnapshot::BAND_HEADLINES,
        ]);
        $chooser = new DashboardSnapshot(DashboardSnapshot::KIND_CHOOSER, 'Account home', 'Northwind Agency', [DashboardSnapshot::BAND_CHOOSER => [
            'noun' => 'business', 'showWorkspace' => true, 'switchUrl' => route('customer.context.business.switch'),
            'businesses' => [['name' => 'Bravo Bistro', 'workspaceName' => 'Northwind Agency', 'workspace' => $workspace->uid, 'business' => $second->uid]],
        ], DashboardSnapshot::BAND_TEAM_ACCOUNT => ['label' => 'Login as Parent: Pat Owner', 'url' => route('user.home'), 'message' => 'Team member message.']]);
        $zero = new DashboardSnapshot(DashboardSnapshot::KIND_ZERO, 'Account home', 'Northwind Agency', emptyState: [
            'title' => 'Create your first business', 'explanation' => 'Fixture.', 'state' => 'empty',
            'primary' => ['label' => 'Create your first business', 'url' => route('customer.workspaces.index')], 'secondary' => null, 'ownerHint' => null, 'icon' => 'briefcase',
        ]);

        $renders = [
            'business-home' => fn () => view('customer.dashboard.business-home', ['dashboard' => $businessSnapshot])->render(),
            'business-home (every band failed)' => fn () => view('customer.dashboard.business-home', ['dashboard' => $failed])->render(),
            'agency-home' => fn () => view('customer.dashboard.agency-home', ['dashboard' => $agencySnapshot])->render(),
            'chooser' => fn () => view('customer.dashboard.chooser', ['dashboard' => $chooser])->render(),
            'zero' => fn () => view('layouts.partials.empty-state', $zero->emptyState)->render(),
        ];

        foreach ($renders as $name => $render) {
            $html = '';
            $sql = $this->sqlDuring(function () use ($render, &$html) {
                $html = $render();
            });

            $this->assertNotSame('', trim($html), "{$name} rendered.");
            $this->assertSame([], $sql, "{$name} must execute no query: " . implode(' | ', $sql));
        }

        foreach ($this->dashboardViewFiles() as $file) {
            $source = file_get_contents($file);

            foreach (['DB::', '::query(', '::where(', '::find(', 'Auth::', 'auth()', '->count(', '->get(', '->first(', '->pluck(', 'Gate::', '@can'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$file} contains {$forbidden}.");
            }
        }
    }

    // =================================================================
    // #20, #54 — B5 SQL is never duplicated
    // =================================================================

    public function test_no_b5_table_is_queried_from_dashboard_code(): void
    {
        foreach (glob(app_path('Library/Dashboard/*.php')) ?: [] as $file) {
            $source = file_get_contents($file);
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? '';

            $this->assertDoesNotMatchRegularExpression('/\b(table|from|join|leftJoin|rightJoin|joinSub)\(\s*[\'"](reports|campaigns|contacts|contact_groups|automation_executions|tracking_logs)\b/i', $code, "{$file} queries a B5 table.");
            $this->assertDoesNotMatchRegularExpression('/\b(from|join)\s+`?(reports|campaigns|contacts|contact_groups|automation_executions|tracking_logs)\b/i', $code, "{$file} carries raw SQL over a B5 table.");
            $this->assertDoesNotMatchRegularExpression('/use App\\\\Models\\\\(Reports|Campaigns|Contacts|ContactGroups|AutomationExecution)\b/', $source, "{$file} imports a B5 model.");
            $this->assertDoesNotMatchRegularExpression('/\b(Reports|Campaigns|Contacts|ContactGroups|AutomationExecution)::/', $source, "{$file} queries a B5 model.");
        }

        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Source Venue', 'Source Account');
        $this->populate($business, 1);
        $this->authenticateAs($customer);
        $context = $this->resolvedContext($customer->user);
        $this->warmShellEntitlements($context);
        Cache::flush();

        $origins = [];
        DB::listen(function ($query) use (&$origins): void {
            if (preg_match(self::B5_TABLES, $query->sql) !== 1) {
                return;
            }

            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $file = str_replace('\\', '/', $frame['file'] ?? '');
                if (str_contains($file, '/app/')) {
                    $origins[] = substr($file, strpos($file, '/app/') + 1);
                    break;
                }
            }
        });

        app(BusinessHomePresenter::class)->present($context, $customer->user);

        $this->assertNotEmpty($origins);
        $this->assertSame(['app/Library/Analytics/BusinessAnalyticsQueries.php'], array_values(array_unique($origins)), 'Every B5-table read is B5\'s own.');
    }

    // -----------------------------------------------------------------

    private function populate(Business $business, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->wallet($business, ['available_balance_micro' => 1000000, 'auto_recharge_threshold_micro' => 5000000]);
            $this->recommendation($business, ['title' => 'Recommendation ' . uniqid()]);
            $this->sent($business, 3, '2026-09-01');
            $this->sent($business, 2, '2026-07-20');
            $this->contactsAdded($business, 2, '2026-09-02');
            $this->conversationsStarted($business, 2, '2026-09-02');
            $this->automationRuns($business, 1, '2026-09-03', 'failed');
            DB::table('campaigns')->insert([
                'uid' => uniqid('', true),
                'user_id' => $business->customer_id,
                'business_id' => $business->id,
                'campaign_name' => 'Campaign ' . uniqid(),
                'message' => 'Hello',
                'sms_type' => 'plain',
                'status' => 'done',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array{dashboard: int, analytics: int, conversations: int, entitlement: int, dashboardSql: array<int, string>}
     */
    private function businessHomeCost(User $user, bool $flush = true): array
    {
        $context = $this->resolvedContext($user);
        $this->warmShellEntitlements($context);

        if ($flush) {
            Cache::flush();
        }

        $snapshot = null;
        $sql = $this->sqlDuring(function () use ($context, $user, &$snapshot) {
            $snapshot = app(BusinessHomePresenter::class)->present($context, $user);
        });

        $this->assertInstanceOf(DashboardSnapshot::class, $snapshot);
        $this->assertSame([], $snapshot->failedBands, 'Precondition: every band rendered.');

        return $this->categorise($sql);
    }

    /**
     * @return array{dashboard: int, analytics: int, conversations: int, entitlement: int, dashboardSql: array<int, string>}
     */
    private function agencyHomeCost(User $user): array
    {
        $context = $this->resolvedContext($user);
        $this->warmShellEntitlements($context);
        Cache::flush();

        $snapshot = null;
        $sql = $this->sqlDuring(function () use ($context, $user, &$snapshot) {
            $snapshot = app(AccountHomePresenter::class)->present($context, $user);
        });

        $this->assertSame(DashboardSnapshot::KIND_AGENCY, $snapshot->kind);
        $this->assertSame([], $snapshot->failedBands);

        return $this->categorise($sql);
    }

    /**
     * @param  array<int, string>  $sql
     * @return array{dashboard: int, analytics: int, conversations: int, entitlement: int, dashboardSql: array<int, string>}
     */
    private function categorise(array $sql): array
    {
        $result = ['dashboard' => 0, 'analytics' => 0, 'conversations' => 0, 'entitlement' => 0, 'dashboardSql' => []];

        foreach ($sql as $statement) {
            if (str_contains($statement, 'chat_boxes')) {
                $result['conversations']++;
            } elseif (preg_match(self::B5_TABLES, $statement) === 1 && ! str_contains($statement, 'agency_prospect')) {
                $result['analytics']++;
            } elseif (preg_match(self::SNAPSHOT_ONLY_TABLES, $statement) === 1) {
                $result['entitlement']++;
            } else {
                $result['dashboard']++;
                $result['dashboardSql'][] = $statement;
            }
        }

        return $result;
    }

    /** @return array<int, string> */
    private function dashboardViewFiles(): array
    {
        $files = [resource_path('views/customer/dashboard.blade.php')];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/customer/dashboard'), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
