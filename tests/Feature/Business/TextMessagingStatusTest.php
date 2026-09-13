<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Library\Messaging\ManagedMessageDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Owner product decision — "remove Messaging channel from normal UX".
 *
 * The plain-language, read-only Text messaging status page
 * (TextMessagingController, customer.workspaces.businesses.text-messaging.show)
 * is the ONE customer-facing Settings surface every tier sees; the
 * Agency-only Advanced (BYO) provider-configuration surface
 * (MessagingChannelsController, customer.workspaces.businesses.channels.*)
 * stays exactly as narrow as MessagingChannelsTest and the Security suite
 * already prove.
 *
 * This file proves the seam between the two: Core/Growth structurally
 * cannot reach the BYO surface even holding every permission; an ordinary
 * managed Agency user gets full, correct status from the plain surface
 * without it; a managed Business can actually send without ever having a
 * channel/provider selection; no provider/credential language reaches an
 * ordinary customer response; and the media-capability line the new page
 * shows follows the real, resolved assigned-number state rather than a
 * static claim.
 */
class TextMessagingStatusTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    // -----------------------------------------------------------------
    // 1 & 2 — Core/Growth cannot reach provider/channel configuration,
    // even holding every customer permission.
    // -----------------------------------------------------------------

    public function test_a_core_owner_cannot_access_provider_channel_configuration(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.businesses.channels.index', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    public function test_a_growth_owner_cannot_access_provider_channel_configuration(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.businesses.channels.index', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // 3 — an ordinary Agency managed user does not need the BYO surface:
    // the plain Text messaging status page works fully without it.
    // -----------------------------------------------------------------

    public function test_an_agency_managed_business_shows_full_status_without_the_advanced_surface(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency);
        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, '+14155551234');
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('Ready');
        $response->assertSee('+14155551234');
        $response->assertSee('Available');
    }

    // -----------------------------------------------------------------
    // 4 — the authorized Agency Advanced BYO path still works (spot
    // check; MessagingChannelsTest/RelocatedAdvancedProviderAuthorizationTest
    // own the full contract).
    // -----------------------------------------------------------------

    public function test_the_authorized_agency_advanced_byo_path_still_works(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency);
        $this->authenticateAs($customer, ['view_numbers', 'manage_advanced_provider']);

        $this->get(route('customer.workspaces.businesses.channels.index', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // 5 — a managed Business sends using its internal assignment, with
    // no channel/provider selection ever existing for it.
    // -----------------------------------------------------------------

    public function test_a_managed_business_sends_without_ever_having_a_channel_selection(): void
    {
        $this->bindFakeAdapter();
        [$business] = $this->managedBusiness();

        $this->assertDatabaseMissing('customer_based_sending_servers', ['business_id' => $business->id]);

        $result = app(ManagedMessageDispatcher::class)->dispatch($business, '+14155559999', 'hello', 'op_status_test_1');

        $this->assertTrue($result->accepted);
        $this->assertDatabaseMissing('customer_based_sending_servers', ['business_id' => $business->id]);
    }

    // -----------------------------------------------------------------
    // 6 — no provider/credential name or value leaks into the ordinary
    // customer response.
    // -----------------------------------------------------------------

    public function test_no_provider_or_credential_language_leaks_into_the_status_page(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $identity = $this->attachIdentity($business, 'secret_messaging_profile_id_xyz');
        $this->attachNumber($identity, '+14155552222');
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        foreach (['Twilio', 'Telnyx', 'account_sid', 'auth_token', 'api_key', 'Messaging profile', 'secret_messaging_profile_id_xyz', 'provider'] as $forbidden) {
            $response->assertDontSee($forbidden, false);
        }
    }

    // -----------------------------------------------------------------
    // 7 — media (MMS) capability UI follows the actual resolved
    // assigned-number state, not a static claim.
    // -----------------------------------------------------------------

    public function test_media_capability_shows_unavailable_when_nothing_is_set_up_yet(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('Setup needed');
        $response->assertSeeInOrder(['Picture messages', 'Not available yet']);
    }

    public function test_media_capability_shows_available_once_the_number_is_active(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, '+14155553333');
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('Ready');
        $response->assertSeeInOrder(['Picture messages', 'Available']);
    }

    public function test_media_capability_shows_an_issue_when_the_identity_has_no_working_number(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        // An Active identity with no attached number at all — resolvePrimaryNumber()
        // must fail closed (MessagingIdentityConflictException), never guess.
        $this->attachIdentity($business, null, BusinessMessagingIdentityStatus::Active);
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('Issue');
        $response->assertSee('Not available yet');
    }

    // -----------------------------------------------------------------
    // Business isolation on the new surface.
    // -----------------------------------------------------------------

    public function test_business_a_cannot_view_business_bs_text_messaging_status(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Workspace A');
        [, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Workspace B');
        $this->authenticateAs($customerA);

        $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspaceA->uid, $businessB->uid]))
            ->assertNotFound();
        $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspaceB->uid, $businessB->uid]))
            ->assertNotFound();
    }
}
