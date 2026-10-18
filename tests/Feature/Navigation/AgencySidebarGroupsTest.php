<?php

namespace Tests\Feature\Navigation;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The Agency sidebar is two groups under Agency Home: "Your agency" (what the
 * agency itself runs on) and "Agency management" (clients and the agency's
 * commercial/team surfaces). Phone & Messaging lives under Agency settings.
 */
class AgencySidebarGroupsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private function agencyKeys(bool $accountFrame): array
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Business', 'Northwind Agency');
        $this->authenticateAs($owner);

        if ($accountFrame) {
            $this->switchToAccount($workspace);
        }

        $this->workspace = $workspace;

        return $this->menuKeys($this->home()->assertOk()->getContent());
    }

    private $workspace;

    public function test_both_groups_are_ordered_and_neither_duplicates_an_entry_in_either_frame(): void
    {
        foreach ([false, true] as $accountFrame) {
            $keys = $this->agencyKeys($accountFrame);

            $this->assertSame(array_values(array_unique($keys)), $keys, 'No entry or heading is defined twice.');
            $position = fn (string $key) => array_search($key, $keys, true);
            $this->assertSame(1, count(array_keys($keys, 'settings', true)), 'One Agency settings entry.');

            $yourAgency = ['contacts', 'conversations', 'calendar', 'automations', 'prospecting', 'website', 'seo', 'ads'];
            $management = ['accounts', 'agency-saas-plans', 'agency-saas-revenue', 'agency-white-label', 'team', 'settings'];

            $last = $position('own-business');
            foreach ($yourAgency as $key) {
                $this->assertNotFalse($position($key), "[{$key}] is under Your agency.");
                $this->assertGreaterThan($last, $position($key), "[{$key}] follows the heading and the previous entry.");
                $last = $position($key);
            }

            $this->assertGreaterThan($last, $position('agency'), 'Agency management comes after Your agency.');
            $last = $position('agency');
            foreach ($management as $key) {
                $this->assertNotFalse($position($key), "[{$key}] is under Agency management.");
                $this->assertGreaterThan($last, $position($key));
                $last = $position($key);
            }

            $this->assertSame(0, $position($accountFrame ? 'home' : 'agency-home'), 'Agency Home is the first entry.');
        }
    }

    public function test_outreach_is_not_listed_in_management_and_phone_messaging_has_no_sidebar_entry_of_its_own(): void
    {
        $keys = $this->agencyKeys(true);

        $this->assertNotContains('phone-messaging', $keys, 'Phone & Messaging is reached from Agency settings, not a second sidebar entry.');
        $this->assertSame(1, count(array_keys($keys, 'prospecting', true)));
    }

    public function test_phone_and_messaging_is_in_the_agency_settings_hub_once(): void
    {
        $this->agencyKeys(true);

        $html = $this->get(route('customer.workspaces.settings.show', $this->workspace->uid))->assertOk()->getContent();
        $modules = $this->settingsHubModuleKeys($html);

        $this->assertSame(1, count(array_keys($modules, 'phone-messaging', true)), 'Phone & Messaging appears exactly once in the hub.');
        $this->assertStringContainsString(route('customer.workspaces.prospecting.phone-messaging', $this->workspace->uid), $html);
    }

    public function test_the_phone_page_lights_agency_settings_and_not_outreach(): void
    {
        $this->agencyKeys(true);

        $html = $this->get(route('customer.workspaces.prospecting.phone-messaging', $this->workspace->uid))->assertOk()->getContent();
        $active = $this->activeMenuKeys($html);

        $this->assertContains('settings', $active);
        $this->assertNotContains('prospecting', $active);
        $this->assertStringContainsString('Option A', $html);
        $this->assertStringContainsString('Option B', $html, 'BYO Telnyx and managed numbers share this one page.');
    }

    public function test_outreach_offers_set_up_phone_until_messaging_is_configured(): void
    {
        $this->agencyKeys(true);

        $overview = $this->get(route('customer.workspaces.prospecting.overview', $this->workspace->uid))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="outreach-phone-setup"', $overview);
        $this->assertStringContainsString(route('customer.workspaces.prospecting.phone-messaging', $this->workspace->uid), $overview);

        $phone = $this->get(route('customer.workspaces.prospecting.phone-messaging', $this->workspace->uid))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-role="outreach-phone-setup"', $phone, 'No banner on the setup page itself.');
    }

    public function test_a_connected_byo_channel_does_not_hide_the_shortcut_because_outreach_sends_through_the_managed_number(): void
    {
        $this->agencyKeys(true);

        // A channel connected through the existing flow (Twilio needs no provider check), then enabled.
        $this->post(route('customer.workspaces.prospecting.channels.connect', [$this->workspace->uid, 'Twilio']), [
            'sender_number' => '+12025553000', 'account_sid' => 'AC_TEST', 'auth_token' => 'token_test',
        ])->assertSessionHas('flash_success');
        \App\Models\AgencyProspectingChannel::query()->where('workspace_id', $this->workspace->id)->update(['status' => 'active']);

        $overview = $this->get(route('customer.workspaces.prospecting.overview', $this->workspace->uid))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="outreach-phone-setup"', $overview, 'BYO channels power AI Prospecting only; Outreach still needs the managed number.');
    }
}
