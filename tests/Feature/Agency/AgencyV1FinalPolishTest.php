<?php

namespace Tests\Feature\Agency;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Feedback\PageToasts;
use App\Library\ViewAs\ViewAsRouteClass;
use App\Library\ViewAs\ViewAsRouteClassification;
use App\Models\Business;
use App\Models\ClientWorkspaceInvitation;
use App\Models\ViewAsSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Agency V1 final polish / acceptance — one test per defect found when the
 * Agency experience was walked end to end in a browser, so each fix stays fixed.
 *
 * The cross-tenant isolation proof lives in AgencyV1FinalIsolationTest; this file
 * pins the Agency information architecture, the View As banner and Exit, the
 * client-management copy and the empty/error states.
 */
class AgencyV1FinalPolishTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    /**
     * @return array{agencyOwner: \App\Models\Customer, agencyWorkspace: \App\Models\Workspace, agencyBusiness: Business, clientOwner: \App\Models\Customer, clientBusiness: Business, clientWorkspace: \App\Models\Workspace}
     */
    private function managed(WorkspacePlanTier $clientTier = WorkspacePlanTier::Growth): array
    {
        $m = $this->createAgencyManagedClient(null, 'Alder Events Co', 'Alder Events', 'Northwind Photo Booths', 'Northwind Agency');
        $this->assignTier($m['clientWorkspace'], $clientTier);

        return $m;
    }

    private function labelOf(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('/data-nav-key="' . preg_quote($key, '/') . '".*?<span class="menu-title">([^<]*)<\/span>/s', $this->sidebarHtml($html), $m), "No sidebar label for [{$key}].");

        return trim($m[1]);
    }

    private function frameLabel(string $html): string
    {
        $this->assertSame(1, preg_match('/customer-context-frame[^>]*>\s*([^<]*?)\s*</', $this->shellHtml($html), $m), 'The shell carries a frame label.');

        return trim($m[1]);
    }

    private function viewAs(array $m): void
    {
        $this->post(route('customer.workspaces.clients.view-as', [$m['agencyWorkspace']->uid, $m['clientWorkspace']->uid]))->assertRedirect();
    }

    // -----------------------------------------------------------------
    // View As: the banner and its Exit control
    // -----------------------------------------------------------------

    public function test_the_view_as_banner_and_exit_render_once_on_module_pages_with_or_without_the_title_bar(): void
    {
        $m = $this->managed();
        $this->authenticateAs($m['agencyOwner']);
        $this->viewAs($m);

        $pages = [
            route('user.home'),
            route('customer.workspaces.businesses.people.index', [$m['clientWorkspace']->uid, $m['clientBusiness']->uid]),
            route('customer.workspaces.businesses.conversations.index', [$m['clientWorkspace']->uid, $m['clientBusiness']->uid]),
            route('customer.workspaces.businesses.settings.show', [$m['clientWorkspace']->uid, $m['clientBusiness']->uid]),
        ];

        // THEME_BREADCRUMBS=false is how real installs run: the banner used to ride on the title bar, so no module page
        // carried it and the Exit control only existed on Home.
        foreach ([true, false] as $titleBar) {
            config(['custom.vertical.pageHeader' => $titleBar, 'custom.horizontal.pageHeader' => $titleBar]);

            foreach ($pages as $url) {
                $html = $this->get($url)->assertOk()->getContent();

                $this->assertSame(1, substr_count($html, 'data-role="view-as-banner"'), "Exactly one banner on {$url} (title bar " . ($titleBar ? 'on' : 'off') . ').');
                $this->assertSame(1, substr_count($html, 'data-role="view-as-exit"'), "One Exit control on {$url}.");
                $this->assertStringContainsString('action="' . route('customer.view-as.exit') . '"', $html);
                $this->assertStringContainsString('Viewing Alder Events Co as a client.', $html);
            }
        }
    }

    public function test_the_banner_is_readable_sticky_and_compact_on_a_phone(): void
    {
        $m = $this->managed();
        $this->authenticateAs($m['agencyOwner']);
        $this->viewAs($m);

        $html = $this->home()->assertOk()->getContent();
        $banner = $this->between($html, '<div class="alert customer-view-as-banner', '</form>');

        // The theme's `.alert-warning` carries `color … !important` (orange on pale orange, 1.8:1): the banner does not use it.
        $this->assertStringNotContainsString('alert-warning', $banner);
        $this->assertStringContainsString('color: var(--color-status-warning-text);', $html);
        $this->assertStringContainsString('position: sticky;', $html, 'The Exit control stays on screen while the page scrolls.');
        // The long explanation is kept off a phone-width screen, so a sticky banner does not cover a quarter of it.
        $this->assertStringContainsString('d-none d-md-inline', $banner);
        $this->assertStringContainsString('Exit client view', $banner);
    }

    public function test_a_viewed_clients_locked_account_page_still_offers_exit(): void
    {
        $m = $this->managed();
        $this->authenticateAs($m['agencyOwner']);
        $this->viewAs($m);

        // The viewed client's account locks mid-session: the gate sends the viewing actor to the locked screen, which has no
        // shell — the Exit control must be on that page, or the only way out is to sign out.
        app(EntitlementManager::class)->changePlanStatus($m['clientWorkspace'], WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Test suspension.');
        $this->get(route('user.home'))->assertRedirect(route('customer.account-locked.show'));

        $page = $this->get(route('customer.account-locked.show'))->assertOk();
        $page->assertSee('data-role="view-as-banner"', false);
        $page->assertSee('data-role="view-as-exit"', false);
        $page->assertSee('action="' . route('customer.view-as.exit') . '"', false);
    }

    public function test_the_banner_lives_in_the_layouts_not_behind_the_title_bar(): void
    {
        $this->assertStringContainsString('<x-view-as-banner />', file_get_contents(resource_path('views/customer/account-locked.blade.php')));

        foreach (['verticalLayoutMaster', 'horizontalLayoutMaster'] as $layout) {
            $this->assertStringContainsString('<x-view-as-banner />', file_get_contents(resource_path("views/layouts/{$layout}.blade.php")));
        }

        $this->assertStringNotContainsString('<x-view-as-banner />', file_get_contents(resource_path('views/panels/breadcrumb.blade.php')), 'Not behind the title bar.');
    }

    // -----------------------------------------------------------------
    // Information architecture: two contexts, one Agency
    // -----------------------------------------------------------------

    public function test_the_agency_group_says_outreach_everywhere_it_appears(): void
    {
        $m = $this->managed();
        $this->authenticateAs($m['agencyOwner']);

        $this->assertSame('Outreach', $this->labelOf($this->home()->assertOk()->getContent(), 'prospecting'));

        $this->switchToAccount($m['agencyWorkspace']);
        $account = $this->home()->assertOk()->getContent();
        $this->assertSame('Outreach', $this->labelOf($account, 'prospecting'));

        $page = $this->get(route('customer.workspaces.prospecting.overview', $m['agencyWorkspace']->uid))->assertOk();
        $page->assertSee('<h4 class="mb-0">Outreach</h4>', false);
        $page->assertDontSee('Prospecting', false);
    }

    public function test_agency_management_pages_are_the_agency_account_and_own_business_pages_are_not(): void
    {
        $m = $this->managed();
        $workspace = $m['agencyWorkspace'];
        $this->authenticateAs($m['agencyOwner']);

        // The owner starts in their OWN Business…
        $own = $this->get(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $m['agencyBusiness']->uid]))->assertOk()->getContent();
        $this->assertSame('Your business', $this->frameLabel($own));

        // …and every Agency-management page is the Agency account, not "Your business · <own Business>".
        foreach ([
            route('customer.workspaces.clients.index', $workspace->uid),
            route('customer.workspaces.prospecting.overview', $workspace->uid),
            route('customer.workspaces.agency.saas.plans', $workspace->uid),
            route('customer.workspaces.agency.white-label.show', $workspace->uid),
            route('customer.workspaces.team.show', $workspace->uid),
            route('customer.workspaces.settings.show', $workspace->uid),
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame('Agency account', $this->frameLabel($html), "{$url} is the Agency account.");
            $this->assertStringContainsString('· Northwind Agency - ', $html, 'The browser tab names the Agency account.');
            // The current name in the sidebar block is the Agency account ("Your business" only heads the switcher's menu).
            $this->assertSame(1, preg_match('/customer-context-current-name[^>]*>\s*([^<]*?)\s*</', $this->shellHtml($html), $name));
            $this->assertSame('Northwind Agency', trim($name[1]), "{$url} names the Agency account as current.");
        }

        // Home follows where the owner last stood (the Agency account), and opening an own-Business module goes back.
        $this->assertStringContainsString('Agency account home', $this->home()->assertOk()->getContent());
        $this->get(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $m['agencyBusiness']->uid]))->assertOk();
        $this->assertStringNotContainsString('Agency account home', $this->home()->assertOk()->getContent());
    }

    public function test_view_as_never_moves_the_owners_context_and_exit_returns_to_it(): void
    {
        $m = $this->managed();
        $workspace = $m['agencyWorkspace'];
        $this->authenticateAs($m['agencyOwner']);

        // Standing in the Agency account (the Clients page) → View As → Exit → still the Agency account.
        $this->get(route('customer.workspaces.clients.index', $workspace->uid))->assertOk();
        $this->viewAs($m);
        $viewing = $this->home()->assertOk()->getContent();
        $this->assertSame('Client', $this->frameLabel($viewing));
        $this->assertStringContainsString('Alder Events Co', $this->shellText($viewing));
        $this->assertStringNotContainsString('Northwind', $this->shellText($viewing), 'No Agency identity inside the client view.');

        $this->post(route('customer.view-as.exit'))->assertRedirect(route('user.home'));
        $after = $this->home()->assertOk()->getContent();
        $this->assertSame('Agency account', $this->frameLabel($after));
        $this->assertStringContainsString('Agency account home', $after);

        // Standing in their own Business → View As → Exit → still their own Business (no random Business switch).
        $this->get(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $m['agencyBusiness']->uid]))->assertOk();
        $this->viewAs($m);
        $this->get(route('customer.workspaces.businesses.people.index', [$m['clientWorkspace']->uid, $m['clientBusiness']->uid]))->assertOk();
        $this->post(route('customer.view-as.exit'))->assertRedirect(route('user.home'));
        $back = $this->home()->assertOk()->getContent();
        $this->assertSame('Your business', $this->frameLabel($back));
        $this->assertStringContainsString('Northwind Photo Booths', $this->shellText($back));

        $sessions = ViewAsSession::query()->where('actor_user_id', $m['agencyOwner']->user_id)->get();
        $this->assertCount(2, $sessions);
        $this->assertTrue($sessions->every(fn (ViewAsSession $s) => $s->ended_at !== null && (int) $s->actor_user_id === (int) $m['agencyOwner']->user_id), 'Both sessions are audited and ended; identity never changed.');
    }

    public function test_the_switcher_lists_the_owners_own_business_not_clients_and_offers_no_view_as_for_it(): void
    {
        $m = $this->managed();
        $this->authenticateAs($m['agencyOwner']);
        $this->switchToAccount($m['agencyWorkspace']);

        $html = $this->home()->assertOk()->getContent();
        $shell = $this->shellHtml($html);

        $this->assertStringContainsString('Your business', $shell);
        $this->assertStringContainsString('Northwind Photo Booths', $shell);
        $this->assertStringNotContainsString('Client accounts', $shell, "The Agency's own Business is not a client account.");
        $this->assertStringNotContainsString('as a client', $shell, 'No "View <own Business> as a client".');
        $this->assertStringNotContainsString('Alder Events Co', $shell, 'Clients are reached through Clients → View As, never from the switcher.');
    }

    // -----------------------------------------------------------------
    // Agency Home
    // -----------------------------------------------------------------

    public function test_agency_home_with_no_clients_explains_itself_and_offers_the_invitation(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Northwind Photo Booths', 'Northwind Agency');
        $this->authenticateAs($owner);
        $this->switchToAccount($workspace);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('No client accounts yet', $html);
        $this->assertStringContainsString('data-role="clients-invite"', $html);
        $this->assertStringContainsString(route('customer.workspaces.clients.index', $workspace->uid), $html);
        $this->assertStringNotContainsString('data-role="clients-table"', $html, 'No header-only table.');
        $this->assertStringContainsString('data-role="cross-client-empty"', $html);
    }

    public function test_agency_home_buttons_open_the_pages_their_labels_promise(): void
    {
        $m = $this->managed();
        $workspace = $m['agencyWorkspace'];
        $this->authenticateAs($m['agencyOwner']);
        $this->switchToAccount($workspace);

        $html = $this->home()->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<a\b[^>]*\bdata-role="clients-manage"[^>]*>/', $html, $manage), 'Agency Home offers "Manage client accounts".');
        $this->assertStringContainsString('href="' . route('customer.workspaces.clients.index', $workspace->uid) . '"', $manage[0]);
        $this->assertStringNotContainsString('href="' . route('customer.workspaces.show', $workspace->uid) . '"', $this->between($html, 'dashboard-clients-heading', 'dashboard-cross-client-heading'), 'Not the stale account overview, which lists no clients.');
        $this->assertStringContainsString('View as client', $html, 'The per-client button says what it does (it is View As, not "Open").');
    }

    public function test_a_client_still_in_setup_is_described_as_such_on_agency_home_and_clients(): void
    {
        $m = $this->managed();
        DB::table('businesses')->where('id', $m['clientBusiness']->id)->update(['status' => BusinessStatus::Draft->value]);
        $this->authenticateAs($m['agencyOwner']);
        $this->switchToAccount($m['agencyWorkspace']);

        $home = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('Waiting for client setup', $home);
        $this->assertStringContainsString('has not finished setting up', $home);
        $this->assertStringNotContainsString('data-role="client-open"', $home, 'A client in setup cannot be viewed yet.');

        $list = $this->get(route('customer.workspaces.clients.index', $m['agencyWorkspace']->uid))->assertOk()->getContent();
        $this->assertStringContainsString('Waiting for client setup', $list);
    }

    // -----------------------------------------------------------------
    // Client management
    // -----------------------------------------------------------------

    public function test_the_client_detail_page_is_plain_language_and_summarises_the_client(): void
    {
        $m = $this->managed();
        $this->authenticateAs($m['agencyOwner']);

        $html = $this->get(route('customer.workspaces.clients.show', [$m['agencyWorkspace']->uid, $m['clientWorkspace']->uid]))->assertOk()->getContent();

        foreach (['Plan and account', 'At a glance', 'Usage funding', 'Website', 'Google listing', 'new contacts', 'new conversations', 'Needs attention'] as $expected) {
            $this->assertStringContainsString($expected, $html, "The client page shows [{$expected}].");
        }

        $this->assertStringContainsString('Growth', $html, 'The plan, by name.');
        $this->assertStringContainsString('Not started', $html, 'No website yet, said plainly.');
        $this->assertStringNotContainsString('AgencyRebill', $html);
        $this->assertStringNotContainsString('agency_rebill', $html, 'No raw payer value.');
        $this->assertStringNotContainsString('data-integrity', $html);
        $this->assertStringNotContainsString('>workspace<', $html);
        $this->assertMatchesRegularExpression('/data-role="agency-rebill-payer-type">\s*The client\s*</', $html);
    }

    public function test_a_clients_subscription_state_is_never_printed_as_a_raw_enum(): void
    {
        foreach (\App\Enums\AgencyBilling\AgencyClientSubscriptionStatus::cases() as $status) {
            $label = $status->label();

            $this->assertNotSame('', $label);
            $this->assertStringNotContainsString('_', $label, "{$status->value} must read as words.");
            $this->assertSame($label, ucfirst($label), 'Sentence case.');
        }

        $this->assertSame('Payment overdue', \App\Enums\AgencyBilling\AgencyClientSubscriptionStatus::PastDue->label());
    }

    public function test_pending_invitations_are_listed_and_the_owner_can_cancel_them(): void
    {
        Mail::fake();
        Notification::fake();

        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Northwind Photo Booths', 'Northwind Agency');
        $this->authenticateAs($owner);

        $this->assertStringNotContainsString('data-role="pending-invitations"', $this->get(route('customer.workspaces.clients.index', $workspace->uid))->assertOk()->getContent());

        $this->post(route('customer.workspaces.client-invitations.store', $workspace->uid), ['email' => 'new.client@example.test', 'intended_business_name' => 'New Client Studio'])->assertRedirect();

        $list = $this->get(route('customer.workspaces.clients.index', $workspace->uid))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="pending-invitations"', $list);
        $this->assertStringContainsString('new.client@example.test', $list);
        $this->assertStringContainsString('New Client Studio', $list);
        $this->assertStringContainsString('Cancel invitation', $list);

        $invitation = ClientWorkspaceInvitation::query()->where('email', 'new.client@example.test')->firstOrFail();
        $this->post(route('customer.workspaces.client-invitations.revoke', [$workspace->uid, $invitation->uid]))->assertRedirect();

        $this->assertStringNotContainsString('new.client@example.test', $this->get(route('customer.workspaces.clients.index', $workspace->uid))->assertOk()->getContent());
    }

    public function test_another_agencys_pending_invitations_are_never_listed(): void
    {
        Mail::fake();
        Notification::fake();

        [$ownerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Agency, 'Agency A Business', 'Agency A');
        [$ownerB, , $workspaceB] = $this->tenant(WorkspacePlanTier::Agency, 'Agency B Business', 'Agency B');

        $this->authenticateAs($ownerA);
        $this->post(route('customer.workspaces.client-invitations.store', $workspaceA->uid), ['email' => 'only.for.a@example.test'])->assertRedirect();

        $this->authenticateAs($ownerB);
        $html = $this->get(route('customer.workspaces.clients.index', $workspaceB->uid))->assertOk()->getContent();
        $this->assertStringNotContainsString('only.for.a@example.test', $html);
        $this->assertStringNotContainsString('data-role="pending-invitations"', $html);
    }

    // -----------------------------------------------------------------
    // The stale Agency account overview
    // -----------------------------------------------------------------

    public function test_the_agency_overview_offers_no_second_business_and_a_hand_made_post_gets_plain_words(): void
    {
        $m = $this->managed();
        $workspace = $m['agencyWorkspace'];
        $this->authenticateAs($m['agencyOwner']);

        $overview = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-workspace-action="businesses"', $overview, 'An Agency account that has its Business offers no Create Business form.');
        $this->assertStringContainsString('data-role="billing-responsibility-clients-link"', $overview, 'Client billing points at Clients, not "No client accounts yet".');
        $this->assertStringNotContainsString('No client accounts yet.', $overview);

        // The form used to 500 with the database's own duplicate-key error (one Business per account).
        $before = Business::query()->where('workspace_id', $workspace->id)->count();

        $response = $this->post(route('customer.workspaces.businesses.store', $workspace->uid), [
            'name' => 'Second Business',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash_error', fn (string $message) => str_contains($message, 'already has its Business') && str_contains($message, 'invite them from Clients'));
        $this->assertSame($before, Business::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_an_agency_account_with_no_business_still_gets_the_first_business_form(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Fresh Agency']);
        $this->assignTier($workspace, WorkspacePlanTier::Agency);
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->assertSee('data-workspace-action="businesses"', false);
    }

    // -----------------------------------------------------------------
    // Feedback that used to vanish
    // -----------------------------------------------------------------

    public function test_legacy_flash_messages_are_shown_as_toasts_unless_the_page_already_says_them(): void
    {
        $store = fn (array $data) => tap(new Store('test', new ArraySessionHandler(10)), fn (Store $s) => $s->put($data));

        // Pause AI, Save script, a refused top-up… flashed `flash_*` and no page rendered them: the customer saw nothing.
        $this->assertSame(
            [['variant' => 'success', 'title' => null, 'message' => 'Script and settings saved.']],
            PageToasts::forPage($store(['flash_success' => 'Script and settings saved.']), null, ['content' => '<main>Outreach</main>'], null),
        );
        $this->assertSame(
            [['variant' => 'error', 'title' => null, 'message' => 'Add at least one active prospect to this campaign before starting it.']],
            PageToasts::forPage($store(['flash_error' => 'Add at least one active prospect to this campaign before starting it.']), null, ['content' => ''], null),
        );

        // A page that already prints the exact message (escaped as Blade writes it) is not told twice.
        $this->assertSame([], PageToasts::forPage($store(['flash_success' => 'Business created.']), null, ['content' => '<div class="alert">Business created.</div>'], null));
        $this->assertSame([], PageToasts::forPage($store(['flash_error' => "Couldn't save that"]), null, ['content' => '<div>' . e("Couldn't save that") . '</div>'], null));

        // A short message is not "already shown" just because the same word sits inside other text on the page.
        $this->assertSame(
            [['variant' => 'success', 'title' => null, 'message' => 'Saved.']],
            PageToasts::forPage($store(['flash_success' => 'Saved.']), null, ['content' => '<p>Saved. searches live here</p><label>Autosaved. drafts</label>'], null),
        );

        // The established status/message pair still wins on its own terms.
        $this->assertCount(1, PageToasts::forPage($store(['status' => 'success', 'message' => 'Invitation sent.']), null, ['content' => ''], null));
    }

    // -----------------------------------------------------------------
    // View As route boundary: routes merged after the inventory was last swept
    // -----------------------------------------------------------------

    public function test_account_level_routes_added_after_the_inventory_are_closed_while_viewing(): void
    {
        $classification = app(ViewAsRouteClassification::class);

        foreach ([
            'customer.platform-notices.feed',
            'customer.platform-notices.read',
            'customer.platform-announcements.dismiss',
            'customer.agency.stripe.connect-existing.callback',
            'customer.calendar-connection.show',
            'customer.calendar-connection.connect',
            'customer.calendar-connection.disconnect',
            'customer.calendar-connection.oauth.callback',
        ] as $name) {
            $this->assertSame(ViewAsRouteClass::Denied, $classification->classifyByName($name), "{$name} is closed while viewing a client.");
        }

        $m = $this->managed();
        $this->authenticateAs($m['agencyOwner']);

        $before = $this->home()->assertOk()->getContent();
        // @json escapes the slashes of the URL it embeds, so the needle is the JSON form.
        $feed = trim(json_encode(route('customer.platform-notices.feed')), '"');
        $this->assertStringContainsString($feed, $before, 'Outside View As the shell asks for the owner\'s own notices.');

        $this->viewAs($m);
        $viewing = $this->home()->assertOk()->getContent();
        $this->assertStringNotContainsString($feed, $viewing, 'The shell does not poll a closed route on every page while viewing.');
        $this->get(route('customer.platform-notices.feed'))->assertNotFound();
        $this->get(route('customer.calendar-connection.show'))->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Copy: raw keys and jargon
    // -----------------------------------------------------------------

    public function test_no_raw_translation_key_remains_for_the_labels_this_lane_found(): void
    {
        $this->assertTrue(Lang::has('locale.labels.block', 'en'), 'The Conversations "block this number" tooltip rendered the raw key.');
        $this->assertSame('Block', __('locale.labels.block'));

        foreach (['Google', 'Meta', 'Outreach', 'Clients'] as $label) {
            $this->assertTrue(Lang::has('locale.menu.' . $label, 'en'), "No English entry for the navigation label [{$label}].");
        }
    }

    public function test_the_agencys_own_business_billing_page_does_not_call_it_a_client_account(): void
    {
        $m = $this->managed();
        $this->authenticateAs($m['agencyOwner']);

        $html = html_entity_decode($this->get(route('customer.workspaces.businesses.usage-billing.show', [$m['agencyWorkspace']->uid, $m['agencyBusiness']->uid]))->assertOk()->getContent());

        $this->assertStringContainsString("Your agency account pays for this business's usage.", $html);
        $this->assertStringNotContainsString("this client account's usage", $html);
        $this->assertStringNotContainsString('The client sees', $html);
        $this->assertStringNotContainsString('Change who is billed under Client accounts', $html);
    }

    public function test_the_outreach_overview_says_whose_number_and_wallet_it_uses(): void
    {
        $m = $this->managed();
        $this->authenticateAs($m['agencyOwner']);

        $html = $this->get(route('customer.workspaces.prospecting.overview', $m['agencyWorkspace']->uid))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="sending-authority"', $html);
        $this->assertStringContainsString('Northwind Photo Booths', $this->between($html, 'data-role="sending-authority"', '</p>'));
        $this->assertStringContainsString('never a client', $html);
    }

    public function test_outreach_stages_read_as_words_not_numbers(): void
    {
        foreach (['prospect-show', 'campaign-show'] as $view) {
            $source = file_get_contents(resource_path("views/customer/workspaces/prospecting/{$view}.blade.php"));

            $this->assertStringNotContainsString('Stage {{ $member->stage->value }}', $source, "{$view} printed \"Stage 99\" for an opted-out prospect.");
            $this->assertStringContainsString('OutreachProspectStatusPresenter::stageLabel(', $source);
        }
    }

    private function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        $this->assertNotFalse($start, "Missing marker [{$from}].");
        $end = strpos($haystack, $to, $start + strlen($from));
        $this->assertNotFalse($end, "Missing marker [{$to}].");

        return substr($haystack, $start, $end - $start + strlen($to));
    }
}
