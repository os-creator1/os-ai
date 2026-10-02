<?php

namespace Tests\Feature\Navigation;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Agency shell (V1-MASTER-PRODUCT-BLUEPRINT §7 + §28): the Agency owner is a
 * Business owner too. The sidebar carries the Agency's OWN Business modules
 * and the Agency management surface together, in either frame, and neither
 * leaks into a viewed client's Business.
 */
class AgencyShellOwnBusinessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const OWN_BUSINESS_KEYS = ['conversations', 'contacts', 'automations', 'website', 'settings'];

    private const AGENCY_ONLY_KEYS = ['accounts', 'prospecting', 'agency-saas-plans', 'agency-saas-revenue', 'agency-white-label', 'agency-home', 'business-home'];

    public function test_the_agency_owner_keeps_the_business_os_and_the_agency_surface_in_the_business_frame(): void
    {
        [$owner, $own, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Business', 'Northwind Agency');
        $this->authenticateAs($owner);

        $html = $this->home()->assertOk()->getContent();
        $keys = $this->menuKeys($html);

        foreach (['own-business', 'agency', 'home', 'conversations', 'contacts', 'automations', 'website', 'business-settings',
            'agency-home', 'accounts', 'prospecting', 'agency-saas-plans', 'agency-saas-revenue', 'agency-white-label', 'team', 'settings'] as $expected) {
            $this->assertContains($expected, $keys, "The Agency owner must reach [{$expected}].");
        }

        $this->assertSame(array_values(array_unique($keys)), $keys, 'No menu entry is defined twice.');
        $this->assertSame('Business Home', $this->labelOf($html, 'home'));
        $this->assertSame('Agency Home', $this->labelOf($html, 'agency-home'));
        $this->assertContains('home', $this->activeMenuKeys($html), 'In the Business frame, Business Home is the current page.');

        $links = $this->menuLinks($html);
        $this->assertContains(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $own->uid]), $links);
        $this->assertContains(route('customer.workspaces.clients.index', $workspace->uid), $links);
        $this->assertContains(route('customer.workspaces.agency.saas.plans', $workspace->uid), $links);
        $this->assertContains(route('customer.workspaces.agency.white-label.show', $workspace->uid), $links);
        $this->assertContains(route('customer.workspaces.team.show', $workspace->uid), $links);

        // Agency Home is a frame move: a CSRF POST the server re-authorizes.
        $this->assertStringContainsString('action="' . route('customer.context.account.switch') . '"', $this->sidebarHtml($html));
    }

    public function test_the_account_frame_also_reaches_the_own_business_and_marks_agency_home_current(): void
    {
        [$owner, $own, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Business', 'Northwind Agency');
        $this->authenticateAs($owner);
        $this->switchToAccount($workspace)->assertRedirect(route('user.home'));

        $html = $this->home()->assertOk()->getContent();
        $keys = $this->menuKeys($html);

        foreach (['business-home', ...self::OWN_BUSINESS_KEYS, 'business-settings', 'accounts', 'prospecting', 'agency-saas-plans', 'agency-white-label', 'team'] as $expected) {
            $this->assertContains($expected, $keys, "The Agency account frame must reach [{$expected}].");
        }

        $this->assertSame(array_values(array_unique($keys)), $keys);
        $this->assertSame('Agency Home', $this->labelOf($html, 'home'));
        $this->assertContains('home', $this->activeMenuKeys($html), 'In the account frame, Agency Home is the current page.');
        $this->assertContains(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $own->uid]), $this->menuLinks($html));
        $this->assertStringContainsString('action="' . route('customer.context.business.switch') . '"', $this->sidebarHtml($html));
        $this->assertMatchesRegularExpression('/customer-context-frame[^>]*>\s*Agency account\s*</', $this->shellHtml($html));
    }

    public function test_the_two_frames_share_one_set_of_business_entries(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Business', 'Northwind Agency');
        $this->authenticateAs($owner);

        $inBusiness = $this->menuLinks($this->home()->assertOk()->getContent());
        $this->switchToAccount($workspace);
        $inAccount = $this->menuLinks($this->home()->assertOk()->getContent());

        $businessLinks = fn (array $links) => array_values(array_filter($links, fn ($l) => str_contains($l, '/businesses/')));

        $this->assertSame($businessLinks($inBusiness), $businessLinks($inAccount), 'Both frames render the same Business modules through one definition.');
    }

    public function test_an_unentitled_business_module_is_not_force_shown(): void
    {
        [$owner, $own, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Business', 'Northwind Agency');
        $this->authenticateAs($owner);

        $this->assertContains('automations', $this->menuKeys($this->home()->assertOk()->getContent()), 'Precondition: entitled.');

        // Deny it at the Business layer, exactly as a Growth Business would.
        app(EntitlementManager::class)->disableBusinessFeature($own, PlatformFeature::from('automations'), (int) $owner->user_id, 'Agency shell test.');

        $this->assertNotContains('automations', $this->menuKeys($this->home()->assertOk()->getContent()));
        $this->switchToAccount($workspace);
        $this->assertNotContains('automations', $this->menuKeys($this->home()->assertOk()->getContent()), 'Account frame honours the same gate.');
    }

    public function test_view_as_a_client_shows_the_clients_business_and_none_of_the_agency_menu(): void
    {
        $managed = $this->createAgencyManagedClient();
        $this->authenticateAs($managed['agencyOwner']);

        $this->post(route('customer.workspaces.clients.view-as', [$managed['agencyWorkspace']->uid, $managed['clientWorkspace']->uid]))->assertRedirect();

        $html = $this->home()->assertOk()->getContent();
        $keys = $this->menuKeys($html);
        $links = $this->menuLinks($html);

        $this->assertContains('contacts', $keys);
        $this->assertContains(
            route('customer.workspaces.businesses.people.index', [$managed['clientWorkspace']->uid, $managed['clientBusiness']->uid]),
            $links,
        );

        foreach ([...self::AGENCY_ONLY_KEYS, 'own-business', 'agency', 'business-settings', 'team'] as $leak) {
            $this->assertNotContains($leak, $keys, "[{$leak}] must not leak into a viewed client's Business.");
        }

        foreach ($links as $link) {
            $this->assertStringNotContainsString('/' . $managed['agencyBusiness']->uid, $link, "The Agency's own Business data must never be linked while viewing a client.");
        }

        $this->assertStringNotContainsString('action="' . route('customer.context.account.switch') . '"', $this->sidebarHtml($html), 'Switching frame is prohibited while viewing.');
        $this->assertMatchesRegularExpression('/customer-context-frame[^>]*>\s*Client\s*</', $this->shellHtml($html));

        // And the own-Business entries come back the moment the session ends.
        $this->post(route('customer.view-as.exit'))->assertRedirect();
        $this->assertContains('agency-saas-plans', $this->menuKeys($this->home()->assertOk()->getContent()));
    }

    public function test_agency_staff_with_all_scope_get_the_agency_surface_by_role_but_not_team(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Business', 'Northwind Agency');
        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        foreach (['accounts', 'prospecting', 'agency-saas-plans', 'agency-saas-revenue', 'agency-white-label', 'conversations'] as $expected) {
            $this->assertContains($expected, $keys, "Active all-scope Agency staff reach [{$expected}].");
        }

        $this->assertNotContains('team', $keys, 'Team stays owner/admin only.');
    }

    public function test_agency_staff_scoped_to_the_business_get_no_agency_menu(): void
    {
        [, $own, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Business', 'Northwind Agency');
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $own);
        $this->authenticateAs($staff);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        $this->assertContains('conversations', $keys);

        foreach ([...self::AGENCY_ONLY_KEYS, 'own-business', 'agency', 'team'] as $leak) {
            $this->assertNotContains($leak, $keys, "[{$leak}] needs account-frame access.");
        }
    }

    public function test_staff_cannot_perform_the_owner_only_agency_writes_the_menu_now_links_to(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Business', 'Northwind Agency');
        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff);

        $this->get(route('customer.workspaces.agency.saas.plans', $workspace->uid))->assertOk();

        $this->assertContains(
            $this->post(route('customer.workspaces.agency.white-label.update', $workspace->uid), ['display_name' => 'Staff Brand'])->getStatusCode(),
            [401, 403, 404, 302],
            'A write is never granted by a visible link.',
        );
    }

    public function test_core_and_growth_owners_see_no_agency_shell(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner] = $this->tenant($tier);
            $this->authenticateAs($owner);

            $keys = $this->menuKeys($this->home()->assertOk()->getContent());

            $this->assertContains('home', $keys, "[{$tier->value}] keeps Home.");
            $this->assertContains('settings', $keys);

            foreach ([...self::AGENCY_ONLY_KEYS, 'own-business', 'agency', 'business-settings'] as $absent) {
                $this->assertNotContains($absent, $keys, "[{$tier->value}] must not gain [{$absent}].");
            }
        }
    }

    public function test_an_agency_with_no_business_yet_keeps_the_plain_agency_menu(): void
    {
        [$owner, $own, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Business', 'Northwind Agency');
        $own->forceFill(['status' => \App\Enums\Business\BusinessStatus::Draft])->save();
        $this->authenticateAs($owner);
        $this->switchToAccount($workspace);

        $html = $this->home()->assertOk()->getContent();
        $keys = $this->menuKeys($html);

        $this->assertNotContains('own-business', $keys, 'There is no own Business to operate yet.');
        $this->assertNotContains('conversations', $keys);
        $this->assertContains('accounts', $keys, 'Clients stays primary.');
        $this->assertSame('Home', $this->labelOf($html, 'home'));
    }

    private function labelOf(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('/data-nav-key="' . preg_quote($key, '/') . '".*?<span class="menu-title">([^<]*)<\/span>/s', $this->sidebarHtml($html), $m), "No sidebar label for [{$key}].");

        return trim($m[1]);
    }
}
