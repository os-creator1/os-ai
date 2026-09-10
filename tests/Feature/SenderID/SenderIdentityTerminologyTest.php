<?php

namespace Tests\Feature\SenderID;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A (contract §5b/§6a #20-22): the "Sender IDs"
 * customer nav item and its two destination screens now read "Sender
 * identity"/"Sender identities", never "Sender ID"/"Sender IDs" — closing
 * the gap where `view_sender_id` defaults `true` for every tier (not just
 * Agency), so this destination is reachable by direct URL for any customer,
 * independent of the Agency-only nav gate.
 *
 * NOTE on scope: `App\Http\Controllers\Customer\SenderIDController` builds
 * its own breadcrumb arrays with a literal `__('locale.menu.Sender ID')`
 * segment (index(), request(), pay() — lines ~62, ~191, ~293) that render
 * visibly via `panels/breadcrumb.blade.php`. That controller is outside
 * this slice's authorized allowlist (only `MessagingChannelsController.php`
 * and `AgencyProspectingChannelController.php` are named exceptions to the
 * controller stop-list), so the breadcrumb segment is a known, reported,
 * out-of-scope residual — these tests assert only the elements this slice
 * actually owns (page title tag, table header, form field label), not a
 * page-wide absence of "Sender ID".
 */
class SenderIdentityTerminologyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_sender_id_index_page_title_and_table_header_use_sender_identities(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.senderid.index'))->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<title>Sender identities', $html);
        $response->assertSee('Sender identities', false);
        $this->assertStringNotContainsString('<title>Sender ID<', $html);
    }

    public function test_sender_id_index_is_reachable_for_every_tier_not_only_agency(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer] = $this->tenant($tier, 'Business ' . $tier->value, 'Account ' . $tier->value);
            $this->authenticateAs($customer);

            $this->get(route('customer.senderid.index'))->assertOk()->assertSee('Sender identities', false);
        }
    }

    public function test_request_new_sender_identity_form_uses_the_singular_label(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.senderid.request'))->assertOk();

        $response->assertSee('Sender identity', false);
        $response->assertDontSee('>Sender ID<', false);
    }

    public function test_advanced_settings_sender_identities_nav_item_uses_the_new_label(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);

        $home = $this->home()->assertOk();
        $this->assertContains('sender-ids', $this->menuKeys($home->getContent()));
        $this->assertStringContainsString('Sender identities', $this->shellText($home->getContent()));
        $this->assertStringNotContainsString('Sender IDs', $this->shellText($home->getContent()));
    }
}
