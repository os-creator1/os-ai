<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Dashboard\Home\HomeActionRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Every action the final Home offers must reach a destination the actor can actually use: the card or button appears only for an
 * actor the destination controller would accept (contacts list, mailbox connection, payment connection), and an unreadable
 * website status suggests nothing.
 */
class HomeV2ActionAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function staffWith(\App\Models\Workspace $workspace, array $permissions): \App\Models\Customer
    {
        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff->fresh(), $permissions);

        return $staff;
    }

    /** @return array<string, mixed> */
    private function actions($workspace, $business, $user): array
    {
        $shell = app(\App\Library\Navigation\CustomerShellComposer::class);
        $context = $shell->currentContext($user);

        return app(HomeActionRouter::class)->forActor($context, $user, $shell->currentMenuEntitlements($context), [$workspace->uid, $business->uid], null);
    }

    private function home(): string
    {
        return $this->get(route('user.home'))->assertOk()->getContent();
    }

    // ── 1 · Contacts card needs view_contact ────────────────────────────

    public function test_the_contacts_card_links_only_for_view_contact_and_the_link_works(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Contacts Venue', 'Contacts Account');
        $this->contactsAdded($business, 1, '2026-09-02');
        $base = ['view_reports', 'access_backend'];

        $this->staffWith($workspace, array_merge($base, ['create_contact', 'update_contact', 'view_contact_group', 'create_contact_group']));
        $html = $this->home();
        $this->assertDoesNotMatchRegularExpression('#<a[^>]*data-kpi="contacts"#', $html, 'Managing contacts or groups does not let an actor open the list.');
        $this->assertMatchesRegularExpression('#<div[^>]*data-kpi="contacts"#', $html, 'The figure still shows, as plain text.');

        $viewer = $this->staffWith($workspace, array_merge($base, ['view_contact']));
        $html = $this->home();
        $this->assertMatchesRegularExpression('#<a[^>]*href="([^"]*/people\?[^"]*)"[^>]*data-kpi="contacts"#', $html);
        preg_match('#<a[^>]*href="([^"]*/people\?[^"]*)"[^>]*data-kpi="contacts"#', $html, $m);
        $this->get(html_entity_decode($m[1]))->assertOk();
        $this->assertNotNull($viewer);
    }

    // ── 2 · "Connect your email" needs the ability to manage the connection ──

    public function test_connect_email_is_offered_only_to_an_actor_who_can_manage_the_connection(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Email Venue', 'Email Account');

        $this->authenticateAs($owner);
        $this->assertNotNull($this->actions($workspace, $business, $owner->user)['email'], 'The Workspace owner may connect a mailbox.');

        $viewer = $this->staffWith($workspace, ['chat_box', 'access_backend']);
        $this->assertNull($this->actions($workspace, $business, $viewer->fresh()->user)['email'], 'Being able to view the email page is not being able to connect.');

        $manager = $this->staffWith($workspace, ['manage_business_email', 'access_backend']);
        $actions = $this->actions($workspace, $business, $manager->fresh()->user);
        $this->assertNotNull($actions['email']);
        $this->get($actions['email']['fullUrl'])->assertOk();
    }

    public function test_suggested_next_never_offers_email_to_a_viewer_who_cannot_connect(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Suggest Venue', 'Suggest Account');
        // Leave only the email suggestion available: a website, an automation and payments are already in place.
        $this->website($business, 'published');
        $this->automationRuns($business, 1, '2026-09-02');
        DB::table('business_stripe_connections')->insert($this->stripeRow($business));

        $this->staffWith($workspace, ['chat_box', 'access_backend', 'view_reports']);
        $this->assertStringNotContainsString('Connect your email', $this->home());

        $this->authenticateAs($owner);
        $this->assertStringContainsString('Connect your email', $this->home());
    }

    // ── 3 · "Connect payments" is for the owner only ────────────────────

    public function test_connect_payments_is_offered_only_to_an_eligible_owner(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Pay Venue', 'Pay Account');
        $this->contactsAdded($business, 1, '2026-09-02');

        $this->authenticateAs($owner);
        $html = $this->home();
        $this->assertStringContainsString('data-role="revenue-connect"', $html);
        $this->assertStringContainsString('Track revenue by connecting payments', $html);
        $actions = $this->actions($workspace, $business, $owner->user);
        $this->get($actions['payments']['fullUrl'])->assertOk();

        $member = $this->staffWith($workspace, ['access_backend', 'payments_contracts', 'view_reports']);
        $this->assertNull($this->actions($workspace, $business, $member->fresh()->user)['payments'], 'A regular member cannot connect Stripe, so no CTA.');
        $html = $this->home();
        $this->assertStringNotContainsString('data-role="revenue-connect"', $html);
        $this->assertStringNotContainsString('Track revenue by connecting payments', $html, 'Neither the revenue prompt nor Suggested next.');
    }

    // ── 4 · An unknown website status suggests nothing ──────────────────

    public function test_an_unreadable_website_status_does_not_suggest_building_a_website(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Unknown Site', 'Unknown Account');
        $this->contactsAdded($business, 1, '2026-09-02');
        $this->authenticateAs($owner);

        $this->assertStringContainsString('Build your website', $this->home(), 'A readable status that proves there is no website suggests one.');

        DB::listen(function ($q) {
            if (str_contains($q->sql, 'business_usage_wallets')) {
                throw new \RuntimeException('status read failed');
            }
        });

        $home = $this->dashboardFor($owner->user)->band(DashboardSnapshot::BAND_COMMAND);
        $this->assertNull($home['websitePublished'], 'Unknown stays unknown.');
        $this->assertNotContains('website', array_column($home['v2']['suggested'], 'key'));
        $this->assertStringNotContainsString('Build your website', $this->home());
    }

    /** @return array<string, mixed> */
    private function stripeRow(\App\Models\Business $business): array
    {
        return [
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'business_id' => $business->id, 'stripe_account_id' => 'acct_' . \Illuminate\Support\Str::random(16),
            'status' => 'active', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true, 'lock_version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
