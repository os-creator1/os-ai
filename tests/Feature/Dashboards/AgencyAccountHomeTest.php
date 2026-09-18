<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Business\BusinessStatus;
use App\Enums\Dashboard\AttentionType;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\Customer;
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
        // V1 (Contract 13): the Agency Workspace holds only its OWN Business,
        // and each client is a separate Workspace reached through an ACTIVE
        // relationship.
        ['owner' => $agency, 'workspace' => $workspace, 'clients' => $managed] = $this->agencyManaging(['Alpha Dental', 'Bravo Bistro', 'Charlie Cafe']);
        [$alpha, $bravo, $charlie] = [$managed['Alpha Dental'], $managed['Bravo Bistro'], $managed['Charlie Cafe']];
        DB::table('businesses')->where('id', $charlie->id)->update(['status' => BusinessStatus::Draft->value]);

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
        $this->switchToAccount($workspace);
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
        $this->assertSame(2, substr_count($table, 'data-role="client-open"'), 'Only active clients can be opened.');
        $this->assertStringNotContainsString('Northwind HQ', $table, "The Agency's own Business is never one of its clients.");
        $this->assertStringNotContainsString(route('customer.context.business.switch'), $table, 'A managed client is opened through the canonical Agency View As, never an ordinary switch.');

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

    /**
     * A managed client is NOT an ordinary context-switcher entry: it lives in
     * its own Workspace, so the Home row opens it through the canonical
     * Agency View As entry the Agency Clients surface owns, and that endpoint
     * — not this band, and not the view — decides whether the view may start.
     */
    public function test_opening_a_client_from_the_account_home_uses_the_canonical_agency_view_as(): void
    {
        ['owner' => $agency, 'workspace' => $workspace, 'clients' => $managed] = $this->agencyManaging(['Bravo Bistro']);
        $bravo = $managed['Bravo Bistro'];
        $this->sent($bravo, 5, '2026-09-01');
        $clientWorkspace = Workspace::query()->findOrFail($bravo->workspace_id);
        $this->authenticateAs($agency);
        $this->switchToAccount($workspace);

        $html = $this->home()->assertOk()->getContent();
        $viewAsUrl = route('customer.workspaces.clients.view-as', [$workspace->uid, $clientWorkspace->uid]);

        $band = $this->between($html, 'data-band="clients"', 'data-band="cross_client"');
        $this->assertStringContainsString('action="' . $viewAsUrl . '"', $band);
        // The shell's own context switcher still switches BUSINESSES; the
        // client row never does.
        $this->assertStringNotContainsString(route('customer.context.business.switch'), $band);

        $this->post($viewAsUrl)->assertRedirect(route('user.home'));

        $this->assertDatabaseHas('view_as_sessions', [
            'actor_user_id' => $agency->user_id,
            'workspace_id' => $clientWorkspace->id,
            'business_id' => $bravo->id,
            'viewing_agency_workspace_id' => $workspace->id,
        ]);
    }

    /**
     * The whole product flow the Open button exists for, end to end: from the
     * Agency Account Home, opening a managed client renders THAT CLIENT's own
     * Business Home — the client's Workspace and its sole Business — with the
     * View-as banner naming it, and exiting puts the Agency back where it was.
     *
     * The cross-Workspace resolution this depends on is shared infrastructure
     * (PR #323, BusinessRouteAccess/CustomerContextResolver); this asserts the
     * product flow over it, not that infrastructure's own rules.
     */
    public function test_opening_a_client_renders_that_clients_business_home_and_exiting_restores_the_agency(): void
    {
        ['owner' => $agency, 'workspace' => $workspace, 'agencyBusiness' => $agencyBusiness, 'clients' => $managed] = $this->agencyManaging(['Alpha Dental', 'Bravo Bistro']);
        $alpha = $managed['Alpha Dental'];
        $clientWorkspace = Workspace::query()->findOrFail($alpha->workspace_id);
        $this->contactsAdded($alpha, 3, '2026-09-02');

        $this->authenticateAs($agency);
        // An explicit Agency ACCOUNT-frame preference is held before opening:
        // it must not survive into the client's view.
        $this->switchToAccount($workspace);

        $accountHome = $this->home()->assertOk()->getContent();
        $viewAsUrl = route('customer.workspaces.clients.view-as', [$workspace->uid, $clientWorkspace->uid]);
        $this->assertStringContainsString('action="' . $viewAsUrl . '"', $this->between($accountHome, 'data-band="clients"', 'data-band="cross_client"'));

        // A managed client is not an ordinary switcher candidate: the shell's
        // own Business switcher offers the Agency's own Business only.
        $switcher = $this->between($accountHome, 'data-role="context-switcher-menu"', '</ul>');
        $this->assertStringContainsString($agencyBusiness->name, $switcher);
        $this->assertStringNotContainsString('Alpha Dental', $switcher, 'A managed client is opened through View As, never switched into.');
        $this->assertStringNotContainsString($alpha->uid, $accountHome, "The client's Business uid is never a switch target on Home.");

        // Open it.
        $this->post($viewAsUrl)->assertRedirect(route('user.home'));
        $this->assertDatabaseHas('view_as_sessions', [
            'actor_user_id' => $agency->user_id,
            'workspace_id' => $clientWorkspace->id,
            'business_id' => $alpha->id,
            'viewing_agency_workspace_id' => $workspace->id,
            'ended_at' => null,
        ]);

        // The rendered landing is the CLIENT's Business Home.
        $opened = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('data-kind="business"', $opened);
        $this->assertMatchesRegularExpression('#<h1[^>]*>.*Alpha Dental.*</h1>#s', $opened);
        $this->assertStringNotContainsString($agencyBusiness->name, $this->mainText($opened), "The Agency's own Business is not the active Business inside a client view.");
        $this->assertStringNotContainsString('data-kind="agency"', $opened, 'The account-frame preference does not survive into the client view.');

        // Truthfully framed, with the way out.
        $this->assertStringContainsString('data-role="view-as-banner"', $opened);
        $this->assertStringContainsString('Viewing Alpha Dental as a client', $opened);

        // No Agency portfolio figure follows the actor into a client.
        foreach (['clients', 'cross_client', 'prospecting'] as $agencyBand) {
            $this->assertStringNotContainsString('data-band="' . $agencyBand . '"', $opened);
        }

        // Exiting restores the Agency's own context.
        $this->post(route('customer.view-as.exit'))->assertRedirect();
        $restored = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-kind="agency"', $restored);
        $this->assertStringNotContainsString('data-role="view-as-banner"', $restored);
        $this->assertStringContainsString('Alpha Dental', $this->between($restored, 'data-band="clients"', 'data-band="cross_client"'), 'The portfolio is back.');
        $this->assertDatabaseMissing('view_as_sessions', [
            'actor_user_id' => $agency->user_id,
            'workspace_id' => $clientWorkspace->id,
            'ended_at' => null,
        ]);
    }

    /**
     * The same flow when the Agency was last inside its OWN Business rather
     * than the account frame: a Business preference cannot override the
     * viewed client either.
     */
    public function test_an_agency_business_preference_does_not_override_the_viewed_client(): void
    {
        ['owner' => $agency, 'workspace' => $workspace, 'agencyBusiness' => $agencyBusiness, 'clients' => $managed] = $this->agencyManaging(['Alpha Dental']);
        $clientWorkspace = Workspace::query()->findOrFail($managed['Alpha Dental']->workspace_id);

        $this->authenticateAs($agency);
        $this->switchTo($workspace, $agencyBusiness)->assertRedirect(route('user.home'));
        $this->assertMatchesRegularExpression('#<h1[^>]*>.*' . preg_quote($agencyBusiness->name, '#') . '.*</h1>#s', $this->home()->assertOk()->getContent());

        $this->post(route('customer.workspaces.clients.view-as', [$workspace->uid, $clientWorkspace->uid]))->assertRedirect(route('user.home'));
        $opened = $this->home()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<h1[^>]*>.*Alpha Dental.*</h1>#s', $opened);
        $this->assertStringNotContainsString($agencyBusiness->name, $this->mainText($opened));
        $this->assertStringContainsString('Viewing Alpha Dental as a client', $opened);
    }

    public function test_the_client_list_stacks_at_narrow_widths_instead_of_scrolling_sideways(): void
    {
        ['owner' => $agency, 'workspace' => $workspace] = $this->agencyManaging(['Alpha Dental', 'Bravo Bistro']);
        $this->authenticateAs($agency);
        $this->switchToAccount($workspace);

        $html = $this->home()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="d-none d-md-block" data-role="clients-table"/', $html);
        $this->assertMatchesRegularExpression('/class="list-unstyled d-md-none mb-0" data-role="clients-stacked"/', $html);

        $stacked = $this->between($html, 'data-role="clients-stacked"', '</ul>');
        $this->assertStringContainsString('Alpha Dental', $stacked);
        $this->assertStringContainsString('Bravo Bistro', $stacked);
        $this->assertStringNotContainsString('<table', $stacked);
        $this->assertDoesNotMatchRegularExpression('/style="[^"]*(width|min-width)\s*:/i', $this->mainHtml($html), 'No fixed width forces a horizontal scroll.');
    }

    /**
     * An Agency team member's portfolio is THIS Agency's active
     * relationships, and another Agency's clients are never among them.
     *
     * Under the frozen V1 authority (Contract 01) an Agency team member's
     * reach over clients is decided by Agency authority over the Agency
     * Workspace — owner, or an active Admin/Staff of it — and by nothing
     * else: a managed client is a separate Workspace, so the membership's own
     * per-Business scope (which can only ever name Businesses INSIDE this
     * Workspace, i.e. the Agency's own) does not narrow the client list. The
     * pre-V1 assertion that it did described the retired
     * several-Businesses-per-Agency-Workspace topology; per-client scoping
     * has no representation in the current model. Narrowing a team member's
     * access per client would be a new authority model, not a Home change.
     *
     * A Business-scoped (Selected) member does not reach this frame at all —
     * the account switch answers 404 for them — so the actor here is an
     * active agency-wide Admin, the team member who does hold it.
     */
    public function test_an_agency_admins_portfolio_is_this_agencys_clients_and_never_another_agencys(): void
    {
        ['owner' => $owner, 'workspace' => $workspace] = $this->agencyManaging(['Alpha Dental', 'Bravo Bistro']);
        ['workspace' => $rivalWorkspace] = $this->agencyManaging(['Hidden Client'], 'Rival Agency', 'Rival HQ');

        $admin = $this->createCustomer();
        $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($admin);
        $this->switchToAccount($workspace);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-kind="agency"', $html);
        $this->assertStringNotContainsString('Hidden Client', $this->mainText($html), "Another Agency's client is never in this portfolio.");
        $this->assertStringNotContainsString('Rival HQ', $this->mainText($html));

        // This Agency's own active relationships, and only those.
        $rows = $this->between($html, 'data-role="cross-client-table"', '</table>');
        $this->assertStringContainsString('Alpha Dental', $rows);
        $this->assertStringContainsString('Bravo Bistro', $rows);
        $this->assertStringNotContainsString('Hidden Client', $rows);
        $this->assertStringNotContainsString('Northwind HQ', $rows, "The Agency's own Business is not a client row.");
        $this->assertNotNull($rivalWorkspace);
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

    /**
     * The V1 Agency topology (Contract 13): ONE Agency Workspace holding
     * exactly one Business — its own, never a client — managing each named
     * client through a real ACTIVE AgencyClientWorkspaceRelationship to that
     * client's SEPARATE Workspace, each holding exactly one Business.
     *
     * @param  array<int, string>  $clientNames
     * @return array{owner: Customer, workspace: Workspace, agencyBusiness: Business, clients: array<string, Business>}
     */
    private function agencyManaging(
        array $clientNames,
        string $agencyWorkspaceName = 'Northwind Agency',
        string $agencyBusinessName = 'Northwind HQ',
    ): array {
        $first = array_shift($clientNames);
        $fixture = $this->createAgencyManagedClient(null, $first, $first . ' Account', $agencyBusinessName, $agencyWorkspaceName);
        $clients = [$first => $fixture['clientBusiness']];

        foreach ($clientNames as $name) {
            $clients[$name] = $this->createAgencyManagedClient($fixture['agencyWorkspace'], $name, $name . ' Account')['clientBusiness'];
        }

        return [
            'owner' => $fixture['agencyOwner'],
            'workspace' => $fixture['agencyWorkspace'],
            'agencyBusiness' => $fixture['agencyBusiness'],
            'clients' => $clients,
        ];
    }

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
