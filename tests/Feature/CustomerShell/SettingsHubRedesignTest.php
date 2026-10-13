<?php

namespace Tests\Feature\CustomerShell;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\BusinessFeatureToggle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The Settings hub redesign is presentation only: grouped, two-column cards of
 * icon / title / description / chevron rows. What appears is still decided by
 * CustomerMenuBuilder::settingsSections(); nothing is hardcoded to match a
 * mock-up.
 */
class SettingsHubRedesignTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private function businessHub(array $tenant, ?array $permissions = null): string
    {
        [$customer, $business, $workspace] = $tenant;
        $this->authenticateAs($customer, $permissions);

        return $this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();
    }

    public function test_the_hub_has_a_heading_a_subheading_and_grouped_cards_with_rows_in_the_target_layout(): void
    {
        $html = $this->businessHub($this->tenant(WorkspacePlanTier::Growth, 'Funky Photos', 'Funky WS'));

        $this->assertStringContainsString('<h1 class="mg-settings-title">Settings</h1>', $html);
        $this->assertStringContainsString('Manage Funky Photos and your own account.', $html);
        $this->assertStringContainsString('grid-template-columns:repeat(2,minmax(0,1fr))', $html);

        // Groups, in the order the builder produced them, then the account card, then Features.
        foreach (['Business setup', 'Communication', 'Billing &amp; team', 'Your account', 'Features'] as $title) {
            $this->assertStringContainsString($title, $html, $title);
        }
        $this->assertSame(['business-setup', 'communication', 'billing-team', 'features'], array_keys($this->settingsHubModules($html)));

        // Every module row carries an icon, a title, a description and a chevron, and is a real link.
        $this->assertSame(8, substr_count($html, 'data-role="settings-module"'));
        $this->assertSame(9, substr_count($html, 'class="mg-settings-icon"'), '8 modules + Profile & language');
        $this->assertSame(9, substr_count($html, 'class="mg-settings-desc"'));
        $this->assertSame(9, substr_count($html, 'mg-settings-chev"'));
        foreach (['Business details', 'Locations', 'Custom fields', 'Text messaging', 'Email', 'Billing', 'Plan &amp; subscription', 'Team', 'Profile &amp; language'] as $label) {
            $this->assertStringContainsString($label, $html, $label);
        }
        $this->assertStringContainsString('The mailbox this business sends and receives email from.', $html);
    }

    public function test_every_selector_the_existing_tests_and_scripts_rely_on_is_kept(): void
    {
        $html = $this->businessHub($this->tenant(WorkspacePlanTier::Core, 'Harbor Lane', 'Harbor WS'));

        foreach (['data-role="settings-hub"', 'data-role="settings-section"', 'data-role="settings-module"', 'data-role="settings-account-link"', 'data-role="settings-profile-language"', 'data-section="business-setup"', 'data-section="billing-team"', 'data-section="features"', 'data-module="locations"', 'data-business-feature-switch', 'data-role="business-features"', 'id="business-feature-settings"'] as $selector) {
            $this->assertStringContainsString($selector, $html, $selector);
        }
        $this->assertMatchesRegularExpression('/data-module="team">\s*<a href="[^"]+team[^"]*"/', $html);
        $this->assertStringContainsString('href="' . route('user.account') . '"', $html);
    }

    public function test_the_rows_stack_on_narrow_screens_without_fixed_widths(): void
    {
        $html = $this->businessHub($this->tenant(WorkspacePlanTier::Growth));

        $this->assertStringContainsString('@media (max-width:767.98px)', $html);
        $this->assertStringContainsString('.mg-settings-grid{grid-template-columns:minmax(0,1fr)}', $html);
        $this->assertStringContainsString('overflow-wrap:anywhere', $html);
        $this->assertDoesNotMatchRegularExpression('/\.mg-settings[^{]*\{[^}]*\bwidth:\d+px/', $html);
    }

    public function test_the_features_section_is_one_full_width_card_with_working_dynamic_switches(): void
    {
        [$customer, $business, $workspace] = $tenant = $this->tenant(WorkspacePlanTier::Growth);
        $html = $this->businessHub($tenant);

        $this->assertStringContainsString('mg-settings-card mg-settings-features', $html);
        $this->assertStringContainsString('grid-column:1 / -1', $html);
        $this->assertStringContainsString('Turn features on or off for this Business. Changes are saved straight away.', $html);
        $this->assertStringContainsString('<h2>Features</h2>', $html);

        // The switches come from the server, not from the page: take the first one it offers and use its own URLs.
        preg_match('/data-feature="([^"]+)"\s*data-enable-url="([^"]+)"\s*data-disable-url="([^"]+)"/s', $html, $m);
        $this->assertNotEmpty($m, 'at least one switch is offered');
        [, $feature, $enableUrl, $disableUrl] = $m;

        $this->post($disableUrl)->assertSessionHas('flash_success');
        $this->assertTrue(BusinessFeatureToggle::where('business_id', $business->id)->where('feature_key', $feature)->exists());
        $this->post($enableUrl)->assertSessionHas('flash_success');
        $this->assertFalse(BusinessFeatureToggle::where('business_id', $business->id)->where('feature_key', $feature)->exists());
    }

    public function test_an_agency_account_hub_keeps_its_own_heading_and_modules_and_no_feature_switches(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);
        $this->switchToAccount($workspace);

        $html = $this->get(route('customer.workspaces.settings.show', $workspace->uid))->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="mg-settings-title">Agency account settings</h1>', $html);
        $this->assertStringNotContainsString('Manage Northwind Agency and your own account.', $html);
        $this->assertStringContainsString('Northwind Agency', $html);
        foreach (['account-details', 'agency-stripe', 'agency-saas-plans', 'agency-white-label'] as $module) {
            $this->assertStringContainsString('data-module="' . $module . '"', $html, $module);
        }
        $this->assertStringNotContainsString('data-business-feature-switch', $html);
    }

    public function test_an_agency_client_business_hub_has_no_plan_or_team_and_no_feature_card(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);
        $this->switchTo($workspace, $client)->assertRedirect(route('user.home'));

        $html = $this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $client->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="mg-settings-title">Settings</h1>', $html);
        $this->assertStringContainsString('data-module="usage-billing"', $html);
        foreach (['plan', 'team', 'account-details'] as $agencyOnly) {
            $this->assertStringNotContainsString('data-module="' . $agencyOnly . '"', $html, $agencyOnly);
        }
        $this->assertStringNotContainsString('data-business-feature-switch', $html);
        $this->assertStringNotContainsString('<h2>Features</h2>', $html);
    }

    public function test_a_module_the_actor_may_not_use_is_not_drawn_to_match_the_mockup(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);
        $this->authenticateAs($staff, array_values(array_diff($this->allCustomerPermissions(), ['view_numbers'])));

        $html = $this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-module="text-messaging"', $html);
        $this->assertStringNotContainsString('Text messaging', $html);
        $this->assertStringNotContainsString('data-module="plan"', $html);
        $this->assertStringNotContainsString('data-module="team"', $html);
        $this->assertStringNotContainsString('data-business-feature-switch', $html);
        $this->assertStringNotContainsString('mg-settings-features"', str_replace('.mg-settings-features', '', $html));
        $this->assertStringContainsString('data-module="locations"', $html);
        $this->assertStringContainsString('data-role="settings-profile-language"', $html, 'the actor\'s own account link is always there');
    }
}
