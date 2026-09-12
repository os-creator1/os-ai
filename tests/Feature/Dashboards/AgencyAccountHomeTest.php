<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Business\BusinessStatus;
use App\Enums\Dashboard\AttentionType;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 4 §7, §18 #3, #31, as reshaped by Unified Home
 * §3.1 (A-1) — the Agency Account Home: which client needs the owner, how
 * each client did over the selected period, the outreach truth table, and —
 * only when they need an action — client-account capacity and an account
 * billing problem. No client's messages, results or recommendations.
 */
class AgencyAccountHomeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    public function test_the_agency_account_home_shows_flags_performance_and_outreach_but_no_client_content(): void
    {
        [$agency, $alpha, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $bravo = $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $charlie = $this->addBusiness($agency, $workspace, 'Charlie Cafe', BusinessStatus::Draft);

        $this->wallet($alpha, ['billing_status' => 'suspended']);
        $this->website($alpha, 'draft');
        $this->wallet($bravo);

        // Client content that must never surface at the account frame.
        $this->sent($alpha, 9, '2026-09-01');
        $this->contactsAdded($bravo, 4, '2026-09-01');
        $this->conversationsStarted($bravo, 3, '2026-09-01');
        $this->recommendation($alpha, ['title' => 'Alpha private recommendation']);

        $this->prospectCampaign($workspace, 'active');
        $this->prospectCampaign($workspace, 'active');
        $this->prospectCampaign($workspace, 'draft');
        foreach (range(1, 3) as $i) {
            $this->prospect($workspace);
        }

        $this->authenticateAs($agency);
        $html = $this->home()->assertOk()->getContent();
        $main = $this->mainText($html);

        $this->assertStringContainsString('data-kind="agency"', $html);
        $this->assertMatchesRegularExpression('#<h1[^>]*>.*Agency account home.*Northwind Agency.*</h1>#s', $html);
        // A-1 band order. Capacity and account billing are absent because
        // this Agency has room for more clients and no billing problem.
        $this->assertSame(['clients', 'cross_client', 'prospecting'], $this->bandOrder($html));

        // Client list — the one needing attention first; flags as words.
        $table = $this->between($html, 'data-role="clients-table"', '</table>');
        $this->assertLessThan(strpos($table, 'Bravo Bistro'), strpos($table, 'Alpha Dental'));
        $this->assertStringContainsString('Blocking', $table);
        $this->assertStringContainsString(AttentionType::WalletSuspended->sentence(), $table);
        $this->assertStringContainsString(AttentionType::WebsiteUnpublished->sentence(), $table);
        $this->assertStringContainsString('Nothing needs attention', $table);
        $this->assertStringContainsString('Charlie Cafe', $table);
        $this->assertStringContainsString('Not active', $table);
        $this->assertSame(2, substr_count($table, 'name="business"'), 'Only active clients can be opened.');

        // Cross-client performance: the period's figures, per client.
        $performance = $this->between($html, 'data-role="cross-client-table"', '</table>');
        $this->assertStringContainsString('Bravo Bistro', $performance);
        $this->assertStringContainsString('New contacts', $performance);
        $this->assertStringContainsString('New conversations', $performance);

        // Outreach, still with the two counts the band already carried.
        $this->assertMatchesRegularExpression('/data-role="prospecting-campaigns">2</', $html);
        $this->assertMatchesRegularExpression('/data-role="prospecting-prospects">3</', $html);

        // Capacity is healthy and routine billing is fine, so neither takes
        // Home space; both stay on Settings → Account.
        $this->assertStringNotContainsString('data-band="capacity"', $html);
        $this->assertStringNotContainsString('data-band="account"', $html);
        $this->assertStringNotContainsString('Spent this month', $main);

        // No client content, no KPI, no invented metric.
        $this->assertStringNotContainsString('data-band="headlines"', $html);
        $this->assertStringNotContainsString('data-band="attention"', $html);
        $this->assertStringNotContainsString('data-band="actions"', $html);
        $this->assertStringNotContainsString('Alpha private recommendation', $html);
        $this->assertStringNotContainsString('Messages sent', $main);
        $this->assertDoesNotMatchRegularExpression('/\b(revenue|roi|reply rate|handset delivery|pipeline value|conversions?)\b/i', $main);
        $this->assertStringNotContainsString('locale.', $main);
    }

    public function test_opening_a_client_from_the_account_home_lands_on_that_clients_business_home(): void
    {
        [$agency, $alpha, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $bravo = $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $this->sent($bravo, 5, '2026-09-01');
        $this->authenticateAs($agency);

        $html = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('action="' . route('customer.context.business.switch') . '"', $html);

        $this->switchTo($workspace, $bravo)->assertRedirect(route('user.home'));
        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-kind="business"', $html);
        $this->assertMatchesRegularExpression('#<h1[^>]*>.*Bravo Bistro.*</h1>#s', $html);
    }

    public function test_the_client_list_stacks_at_narrow_widths_instead_of_scrolling_sideways(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $this->authenticateAs($agency);

        $html = $this->home()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="d-none d-md-block" data-role="clients-table"/', $html);
        $this->assertMatchesRegularExpression('/class="list-unstyled d-md-none mb-0" data-role="clients-stacked"/', $html);

        $stacked = $this->between($html, 'data-role="clients-stacked"', '</ul>');
        $this->assertStringContainsString('Alpha Dental', $stacked);
        $this->assertStringContainsString('Bravo Bistro', $stacked);
        $this->assertStringNotContainsString('<table', $stacked);
        $this->assertDoesNotMatchRegularExpression('/style="[^"]*(width|min-width)\s*:/i', $this->mainHtml($html), 'No fixed width forces a horizontal scroll.');
    }

    public function test_a_scoped_admin_never_sees_the_agency_wide_aggregate(): void
    {
        [$owner, $alpha, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $bravo = $this->addBusiness($owner, $workspace, 'Bravo Bistro');
        $this->addBusiness($owner, $workspace, 'Hidden Client');

        $admin = $this->createCustomer();
        $membership = $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $alpha);
        $this->assign($membership, $bravo);
        $this->authenticateAs($admin);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-kind="agency"', $html);
        $this->assertStringNotContainsString('Hidden Client', $this->mainText($html), 'Only the clients in the admin\'s scope are listed.');
        $this->assertStringNotContainsString('data-band="account"', $html, 'A state covering clients outside the admin\'s scope is never shown.');

        // The scoped admin's own portfolio band covers their scope and
        // nothing else.
        $rows = $this->between($html, 'data-role="cross-client-table"', '</table>');
        $this->assertStringContainsString('Alpha Dental', $rows);
        $this->assertStringContainsString('Bravo Bistro', $rows);
        $this->assertStringNotContainsString('Hidden Client', $rows);
    }

    public function test_an_agency_with_no_client_yet_is_offered_its_first_client_account(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $agency = $this->createCustomer();
        $workspace = $this->createWorkspace($agency->user, ['name' => 'Empty Agency']);
        $this->assignTier($workspace, WorkspacePlanTier::Agency);
        $this->authenticateAs($agency);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-kind="zero"', $html);
        $this->assertStringContainsString('Create your first client account', $html);
        $this->assertStringContainsString('href="' . route('customer.workspaces.show', $workspace->uid) . '"', $html);
    }

    // -----------------------------------------------------------------

    private function prospectCampaign(Workspace $workspace, string $status): void
    {
        DB::table('agency_prospect_campaigns')->insert([
            'uid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Campaign ' . Str::random(4),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function prospect(Workspace $workspace): void
    {
        DB::table('agency_prospects')->insert([
            'uid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'company_name' => 'Prospect ' . Str::random(4),
            'phone' => '1707555' . random_int(1000, 9999),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int, string> */
    private function bandOrder(string $html): array
    {
        preg_match_all('/data-band="([a-z_]+)"/', $this->mainHtml($html), $matches);

        return $matches[1];
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "{$from} must be present.");
        $end = strpos($html, $to, $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }
}
