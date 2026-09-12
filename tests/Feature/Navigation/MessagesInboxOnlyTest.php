<?php

namespace Tests\Feature\Navigation;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Messages is Inbox only (product decision after preview testing).
 *
 * Send and Campaigns are legacy outbound surfaces, not a local-business
 * workflow: a Core or Growth Business — and an Agency client Business once it
 * is selected — gets Messages → Inbox and nothing else, and Home no longer
 * promotes "Send a message". Agency outbound prospecting stays where it
 * belongs, in the Agency account frame. The legacy routes are not deleted and
 * stay fail-closed.
 */
class MessagesInboxOnlyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const LEGACY_OUTBOUND_ROUTES = [
        'customer.workspaces.businesses.outreach.index',
        'customer.workspaces.businesses.outreach.campaigns',
        'customer.outreach.index',
        'customer.outreach.campaigns.entry',
    ];

    public function test_core_and_growth_messages_contain_only_the_inbox(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer, $business, $workspace] = $this->tenant($tier, 'Business ' . $tier->value, 'Account ' . $tier->value);
            $this->authenticateAs($customer);

            $html = $this->home()->assertOk()->getContent();

            $this->assertSame(['inbox'], $this->messagesChildren($html), "{$tier->value}: Messages holds Inbox only.");
            $this->assertSame(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]), $this->navHref($html, 'inbox'));
            $this->assertStringNotContainsString('/outreach', $this->shellHtml($html), "{$tier->value}: no menu link reaches Send or Campaigns.");

            foreach (['Send', 'Campaigns'] as $label) {
                $this->assertDoesNotMatchRegularExpression('/\b' . $label . '\b/', $this->shellText($html), "{$tier->value}: no [{$label}] entry.");
            }
        }
    }

    public function test_home_no_longer_promotes_send_a_message(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringNotContainsString('Send a message', $html);
        $this->assertStringNotContainsString('data-action="send"', $html);
        $this->assertStringNotContainsString('/outreach', $html, 'Nothing on Home links the legacy outbound pages.');
    }

    public function test_an_agency_keeps_prospecting_in_its_account_frame_and_no_messages_there(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Client Two');
        $this->authenticateAs($agency);

        $html = $this->home()->assertOk()->getContent();
        $keys = $this->menuKeys($html);

        $this->assertContains('prospecting', $keys);
        $this->assertSame(route('customer.prospecting.index'), $this->navHref($html, 'prospecting'));
        $this->assertNotContains('messages', $keys, 'Messages belongs to a selected Business, never the Agency account frame.');

        // Reachable in its own context: the entry resolves the Agency account.
        $this->get(route('customer.prospecting.index'))
            ->assertRedirect(route('customer.workspaces.prospecting.overview', $workspace->uid));
        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertOk();
    }

    public function test_a_selected_agency_client_business_gets_the_normal_business_inbox(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $client = $this->addBusiness($agency, $workspace, 'Client Two');
        $this->authenticateAs($agency);

        $this->switchTo($workspace, $client)->assertRedirect();
        $html = $this->home()->assertOk()->getContent();

        $this->assertSame(['inbox'], $this->messagesChildren($html));
        $this->assertSame(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $client->uid]), $this->navHref($html, 'inbox'), 'The Inbox is the selected client Business\'s own.');
        $this->assertNotContains('prospecting', $this->menuKeys($html), 'Prospecting stays in the Agency account frame.');
        $this->assertStringNotContainsString('/outreach', $this->shellHtml($html));

        $this->get($this->navHref($html, 'inbox'))->assertOk();
    }

    public function test_prospecting_is_not_reachable_for_a_business_without_the_agency_plan(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->assertNotContains('prospecting', $this->menuKeys($this->home()->assertOk()->getContent()));
        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertNotFound();
    }

    /**
     * Only the menu entries went: the legacy routes stay registered (this
     * slice deletes nothing) and stay tenant-bound.
     */
    public function test_the_legacy_send_and_campaign_routes_remain_and_stay_fail_closed(): void
    {
        foreach (self::LEGACY_OUTBOUND_ROUTES as $name) {
            $this->assertTrue(Route::has($name), "{$name} is still registered.");
        }

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        [, $foreignBusiness, $foreignWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Foreign Studio', 'Foreign Account');
        $this->authenticateAs($customer);

        foreach (['customer.workspaces.businesses.outreach.index', 'customer.workspaces.businesses.outreach.campaigns'] as $name) {
            $this->get(route($name, [$foreignWorkspace->uid, $foreignBusiness->uid]))->assertNotFound();
            $this->assertNotSame(404, $this->get(route($name, [$workspace->uid, $business->uid]))->getStatusCode(), "{$name} still answers for the customer's own Business.");
        }
    }

    // -----------------------------------------------------------------

    /**
     * The data-nav-key children of the Messages group, in order.
     *
     * @return list<string>
     */
    private function messagesChildren(string $html): array
    {
        $sidebar = $this->sidebarHtml($html);
        $start = strpos($sidebar, 'data-nav-key="messages"');
        $this->assertNotFalse($start, 'The Messages group renders.');
        $end = strpos($sidebar, '</ul>', $start);

        preg_match_all('/data-nav-key="([^"]+)"/', substr($sidebar, $start, $end - $start), $matches);

        return array_values(array_diff($matches[1], ['messages']));
    }

    private function navHref(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('/data-nav-key="' . preg_quote($key, '/') . '">\s*<a href="([^"]+)"/', $this->sidebarHtml($html), $match), "[{$key}] renders as a link.");

        return html_entity_decode($match[1]);
    }
}
