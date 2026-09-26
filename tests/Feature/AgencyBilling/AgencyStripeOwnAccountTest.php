<?php

namespace Tests\Feature\AgencyBilling;

use App\Enums\AgencyBilling\AgencyStripeConnectionStatus;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * "Jazmin Media operates the software platform, not the merchant." Every
 * Agency must receive its own client payments directly, pay its own Stripe
 * fees, and bear its own payment-loss liability — never the platform. This
 * suite proves the two ways an Agency gets a connected account both enforce
 * that fail-closed, and that the previously-working connect/onboard/sync
 * flow (StripeApiAgencyGateway::accountCreateParams(), unchanged) still
 * reaches Active exactly as before.
 */
class AgencyStripeOwnAccountTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAgencySaasFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();
    }

    private function startUrl(string $agencyUid): string
    {
        return route('customer.workspaces.agency.saas.stripe.connect-existing', [$agencyUid]);
    }

    private function callbackUrl(string $agencyUid, array $query = []): string
    {
        return route('customer.workspaces.agency.saas.stripe.connect-existing.callback', [$agencyUid])
            . (empty($query) ? '' : ('?' . http_build_query($query)));
    }

    /** Starts the flow and returns the `state` Stripe's own authorize URL was given. */
    private function startAndCaptureState(string $agencyUid): string
    {
        $response = $this->get($this->startUrl($agencyUid));
        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('state', $query, 'oauthAuthorizeUrl() must carry a state parameter.');

        return (string) $query['state'];
    }

    // ------------------------------------------------------------------
    // Connecting a compatible existing account
    // ------------------------------------------------------------------

    public function test_the_owner_can_connect_a_compatible_existing_account_and_it_becomes_active(): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $state = $this->startAndCaptureState($uid);
        $this->agencyStripe->registerExistingAccount('ac_test_code_1', 'acct_existing_compatible');

        $this->get($this->callbackUrl($uid, ['state' => $state, 'code' => 'ac_test_code_1']))
            ->assertRedirect(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertSessionHas('status', 'success');

        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $this->assertNotNull($connection);
        $this->assertSame('acct_existing_compatible', (string) $connection->stripe_account_id);
        $this->assertSame(AgencyStripeConnectionStatus::Active, $connection->status);
        $this->assertTrue($this->connections()->isChargeReady($fixture['agencyWorkspace']));

        $this->get(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertOk()
            ->assertSee('Ready to take payments');
    }

    // ------------------------------------------------------------------
    // Fail-closed on an incompatible existing account
    // ------------------------------------------------------------------

    public static function incompatibleControllerShapes(): array
    {
        return [
            'platform would pay Stripe fees' => [['fees_payer' => 'application']],
            'platform would bear payment losses' => [['losses_payer' => 'application']],
            'platform would collect requirements' => [['requirement_collection' => 'application']],
            'agency has no full Dashboard' => [['dashboard_type' => 'express']],
            'no controller reported at all (legacy/unverifiable)' => [[
                'fees_payer' => null, 'losses_payer' => null,
                'requirement_collection' => null, 'dashboard_type' => null,
            ]],
        ];
    }

    /** @dataProvider incompatibleControllerShapes */
    public function test_an_incompatible_existing_account_never_becomes_chargeable(array $facts): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $state = $this->startAndCaptureState($uid);
        // charges_enabled true on purpose: even an account Stripe itself
        // would let charge must be refused here on its commercial shape
        // alone (objective #4/#5's own fail-closed requirement).
        $this->agencyStripe->registerExistingAccount('ac_test_code_2', 'acct_existing_incompatible', $facts);

        $this->get($this->callbackUrl($uid, ['state' => $state, 'code' => 'ac_test_code_2']))
            ->assertRedirect(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertSessionHas('status', 'error');

        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $this->assertNotNull($connection, 'Still recorded — an accurate, disconnectable history, never a silent no-op.');
        $this->assertSame(AgencyStripeConnectionStatus::Incompatible, $connection->status);
        $this->assertFalse($connection->canCharge());
        $this->assertFalse($this->connections()->isChargeReady($fixture['agencyWorkspace']));
        $this->assertFalse((bool) $connection->charges_enabled, 'charges_enabled is forced false locally regardless of what the provider reported.');

        $page = $this->get(route('customer.workspaces.agency.saas.stripe', [$uid]));
        $page->assertOk();
        $page->assertSee('Not compatible with this platform');
        // Never offered "Finish setup" — the dashboard type cannot change.
        $page->assertDontSee('Finish Stripe setup');
    }

    public function test_syncing_an_account_whose_controller_shape_changed_also_fails_closed(): void
    {
        // Preserves the existing, working create->onboard->sync flow
        // (accountCreateParams() is untouched), then proves the SAME
        // statusFor() gate a resync goes through would also catch a
        // provider-reported shape it does not recognise, not only the
        // OAuth path.
        $fixture = $this->readyAgencyForRegression();

        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $this->assertSame(AgencyStripeConnectionStatus::Active, $connection->status);

        $this->agencyStripe->accounts[(string) $connection->stripe_account_id]['dashboard_type'] = 'express';

        $this->connections()->syncFromProvider((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace']);

        $resynced = $connection->fresh();
        $this->assertSame(AgencyStripeConnectionStatus::Incompatible, $resynced->status);
        $this->assertFalse($this->connections()->isChargeReady($fixture['agencyWorkspace']));
    }

    /** @return array{agencyWorkspace: \App\Models\Workspace, agencyOwner: \App\Models\Customer} */
    private function readyAgencyForRegression(): array
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        return $fixture;
    }

    // ------------------------------------------------------------------
    // OAuth CSRF / denial handling
    // ------------------------------------------------------------------

    public function test_a_mismatched_state_is_refused_and_creates_no_connection(): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $this->startAndCaptureState($uid);
        $this->agencyStripe->registerExistingAccount('ac_test_code_3', 'acct_forged');

        $this->get($this->callbackUrl($uid, ['state' => 'not-the-real-state', 'code' => 'ac_test_code_3']))
            ->assertRedirect(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertSessionHas('status', 'error');

        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
        $this->assertSame([], $this->agencyStripe->callsOf('exchangeOAuthCode'), 'A code must never be exchanged against an unverified state.');
    }

    public function test_stripe_reporting_the_owner_declined_is_handled_without_a_connection(): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $this->startAndCaptureState($uid);

        $this->get($this->callbackUrl($uid, ['error' => 'access_denied', 'error_description' => 'The user denied your request']))
            ->assertRedirect(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertSessionHas('status', 'error');

        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
    }

    // ------------------------------------------------------------------
    // Authorization — owner only, same rule as every other lane-C write
    // ------------------------------------------------------------------

    public function test_agency_staff_cannot_start_connecting_an_existing_account(): void
    {
        $fixture = $this->agencyWithClient();
        $staff = $this->createCustomer();
        $this->createMembership($fixture['agencyWorkspace'], $staff->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);
        $this->authenticateAs($staff);

        $this->get($this->startUrl($fixture['agencyWorkspace']->uid))
            ->assertRedirect()
            ->assertSessionHas('status', 'error');

        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
    }

    public function test_an_unrelated_actor_cannot_reach_the_connect_existing_routes(): void
    {
        $fixture = $this->agencyWithClient();
        $stranger = $this->createCustomer();
        $this->authenticateAs($stranger);

        $this->get($this->startUrl($fixture['agencyWorkspace']->uid))->assertNotFound();
        $this->get($this->callbackUrl($fixture['agencyWorkspace']->uid, ['state' => 'x', 'code' => 'y']))->assertNotFound();
    }

    public function test_an_agency_that_already_has_a_connection_cannot_start_connecting_a_second_one(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $this->authenticateAs($fixture['agencyOwner']);

        $this->get($this->startUrl($fixture['agencyWorkspace']->uid))
            ->assertRedirect()
            ->assertSessionHas('status', 'error');

        $this->assertSame([], $this->agencyStripe->callsOf('oauthAuthorizeUrl'));
    }
}
