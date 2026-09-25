<?php

namespace Tests\Feature\Agency;

use App\Enums\Usage\FundingAttemptState;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Usage\CheckoutSessionResult;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\ViewAs\ViewAsManager;
use App\Models\Business;
use App\Models\BusinessFundingAttempt;
use App\Models\User;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Usage\Concerns\AgencyRebillFixtures;
use Tests\TestCase;

/**
 * Contract 09 §12 (AgencyRebill funding surface) — the managing Agency
 * owner's own route to fund a linked client's Business usage wallet
 * without View As (which deliberately pauses billing/funding, Contract
 * 08A) and without real Client Workspace membership (which the Agency
 * owner deliberately does not have).
 *
 * Every authorization decision proven here is BillingProfileManager's own
 * (assertAuthorizedChargePayer() -> actorMayOriginateChargeFor()) — this
 * suite proves AgencyClientFundingController delegates to it rather than
 * reimplementing any rule, and that the Checkout Session actually charges
 * the Agency's own provider customer, never the client's.
 */
class AgencyClientFundingHttpTest extends TestCase
{
    use AgencyRebillFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
    }

    private function actingAsCustomer(User $user): static
    {
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $this->allCustomerPermissions()))]);

        return $this->actingAs($user);
    }

    private function initiateUrl(string $agencyUid, string $clientUid): string
    {
        return route('customer.workspaces.clients.funding.top-up.initiate', [$agencyUid, $clientUid]);
    }

    private function confirmUrl(string $agencyUid, string $clientUid, int $attemptId): string
    {
        return route('customer.workspaces.clients.funding.top-up.confirm', [$agencyUid, $clientUid, $attemptId]);
    }

    private function showUrl(string $agencyUid, string $clientUid): string
    {
        return route('customer.workspaces.clients.show', [$agencyUid, $clientUid]);
    }

    private function latestAttempt(Business $business): BusinessFundingAttempt
    {
        return app(BusinessFundingAttemptRepository::class)->query()
            ->where('business_id', $business->id)->orderByDesc('id')->firstOrFail();
    }

    private function registerVerifiedCheckoutOutcome(BusinessFundingAttempt $attempt): void
    {
        $manager = app(UsageBillingCheckoutManager::class);
        $paymentMethodId = 'pm_fake_verified_' . uniqid();

        $this->fakeProvider()->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId,
            $attempt->provider_customer_external_id_snapshot,
            'card', 'visa', '1111', 12, 2030,
        ));

        $this->fakeProvider()->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $manager->expectedMinorUnitsFor($attempt),
            $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_fake_verified_' . uniqid(),
            $paymentMethodId,
            'https://fake.stripe.test/receipts/ch_fake_agency_funding',
            'ch_fake_agency_funding',
        ));
    }

    // ------------------------------------------------------------------
    // Initiate: authorization
    // ------------------------------------------------------------------

    public function test_the_agency_owner_can_initiate_funding_for_a_rebilled_client(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');

        $response = $this->actingAsCustomer($m['agencyOwner']->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00']);

        $response->assertRedirect();
        $this->assertStringNotContainsString($this->showUrl($m['agency']->uid, $m['client']->uid), (string) $response->headers->get('Location'));
        $this->assertStringStartsWith('https://checkout.fake.stripe.test/', (string) $response->headers->get('Location'));

        $attempt = $this->latestAttempt($m['business']);
        $this->assertSame(PayerType::AgencyRebill, $attempt->payer_type_snapshot);
    }

    public function test_an_agency_admin_cannot_initiate_funding_owner_only(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $admin = $this->memberOf($m['agency'], WorkspaceMembershipRole::Admin);

        $this->actingAsCustomer($admin->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertNotFound();

        $this->assertCount(0, app(BusinessFundingAttemptRepository::class)->query()->where('business_id', $m['business']->id)->get());
    }

    public function test_an_unrelated_agency_cannot_initiate_funding_by_crafting_the_url(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        [$strangerOwner, $strangerAgency] = $this->agencyAccount('Stranger Agency');

        $this->actingAsCustomer($strangerOwner->user)
            ->post($this->initiateUrl($strangerAgency->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertNotFound();

        $this->assertCount(0, app(BusinessFundingAttemptRepository::class)->query()->where('business_id', $m['business']->id)->get());
    }

    public function test_the_client_owner_cannot_reach_the_agency_funding_route(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');

        // The client owner is not a member of the Agency Workspace at all,
        // so this is refused the same tenancy-safe way any stranger is.
        $this->actingAsCustomer($m['clientOwner']->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertNotFound();

        $this->assertCount(0, app(BusinessFundingAttemptRepository::class)->query()->where('business_id', $m['business']->id)->get());
    }

    public function test_revoked_consent_refuses_funding_even_though_payer_type_is_still_agency_rebill(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        app(\App\Library\Usage\BillingProfileManager::class)->revokeAgencyRebillConsent($m['business'], (int) $m['agencyOwner']->user_id, 'Test: revoked.');

        $this->actingAsCustomer($m['agencyOwner']->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertNotFound();

        $this->assertCount(0, app(BusinessFundingAttemptRepository::class)->query()->where('business_id', $m['business']->id)->get());
    }

    /**
     * Ordering correction — resolveProviderCustomer()'s own authorization
     * rule (assertAuthorizedFundingPayer()) is deliberately broader than
     * charge origination's (no standing consent required, so funding
     * configuration never deadlocks on the very consent it exists to
     * grant). Before this fix, calling it first meant a crafted top-up
     * POST for a revoked-consent Agency with no provider customer yet
     * could create a real Stripe customer before the request finally
     * failed. assertAuthorizedChargePayer() — the SAME canonical
     * origination check initiateTopUp() already performs — must run
     * first, so nothing is ever created for a request that was always
     * going to be refused.
     */
    public function test_revoked_consent_with_no_agency_provider_customer_yet_creates_nothing_at_all(): void
    {
        $gateway = $this->fakeProvider();
        $m = $this->rebilledClient();
        app(\App\Library\Usage\BillingProfileManager::class)->revokeAgencyRebillConsent($m['business'], (int) $m['agencyOwner']->user_id, 'Test: revoked.');

        $this->assertNull(app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $m['agency']->id));

        $this->actingAsCustomer($m['agencyOwner']->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertNotFound();

        $this->assertNull(
            app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $m['agency']->id),
            'No Stripe provider customer may be created for a request charge-origination would always refuse.',
        );
        $this->assertSame([], $gateway->createCheckoutSessionCalls, 'No Checkout Session call may be made.');
        $this->assertCount(0, app(BusinessFundingAttemptRepository::class)->query()->where('business_id', $m['business']->id)->get());
    }

    public function test_a_business_that_is_not_agency_rebill_funded_refuses_the_agency_owner(): void
    {
        $this->fakeProvider();
        $m = $this->managedClient(); // linked, but AgencyRebill never granted
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');

        $this->actingAsCustomer($m['agencyOwner']->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertNotFound();

        $this->assertCount(0, app(BusinessFundingAttemptRepository::class)->query()->where('business_id', $m['business']->id)->get());
    }

    // ------------------------------------------------------------------
    // Isolation: charges the Agency, never the client
    // ------------------------------------------------------------------

    public function test_the_charge_is_checked_out_against_the_agencys_own_provider_customer_never_the_clients(): void
    {
        // Captured once: fakeProvider() rebinds a fresh instance on every
        // call, so re-calling it later to read call history would silently
        // read an empty log from a brand new instance instead.
        $gateway = $this->fakeProvider();
        $m = $this->rebilledClient();
        [$agencyCustomer] = $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        [$clientBusinessCustomer] = $this->businessProviderCustomerWithCard($m['business'], '9999');

        $this->actingAsCustomer($m['agencyOwner']->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertRedirect();

        $attempt = $this->latestAttempt($m['business']);
        $this->assertSame((int) $agencyCustomer->id, (int) $attempt->provider_customer_id);
        $this->assertNotSame((int) $clientBusinessCustomer->id, (int) $attempt->provider_customer_id);

        $call = $gateway->createCheckoutSessionCalls[array_key_last($gateway->createCheckoutSessionCalls)];
        $this->assertSame($agencyCustomer->provider_customer_id, $call['providerCustomerId']);
    }

    /**
     * Correction — first-time funding. Before this fix, an Agency with no
     * payment_provider_customers row of its own failed closed with
     * 'no_provider_customer' even though it was the genuinely-authorized
     * payer, because nothing ever lazily created one for the Agency
     * Workspace the way the client's own Usage & Billing page does for
     * itself. PaymentInstrumentManager::resolveProviderCustomer() is that
     * same existing, idempotent mechanism, reused unchanged: no previously
     * saved card is required (asserted below by never registering one) —
     * the hosted Checkout Session itself collects payment details.
     */
    public function test_first_time_funding_with_no_existing_agency_provider_customer_reaches_checkout(): void
    {
        $gateway = $this->fakeProvider();
        $m = $this->rebilledClient();
        // Deliberately NOT calling workspaceProviderCustomerWithCard($m['agency'], ...)
        // — no Agency provider customer, and no saved card, exist yet.
        $this->businessProviderCustomerWithCard($m['business'], '9999');

        $this->assertNull(app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $m['agency']->id));

        $response = $this->actingAsCustomer($m['agencyOwner']->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00']);

        $response->assertRedirect();
        $this->assertStringStartsWith('https://checkout.fake.stripe.test/', (string) $response->headers->get('Location'));

        $agencyCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $m['agency']->id);
        $this->assertNotNull($agencyCustomer, 'resolveProviderCustomer() must have created one for the Agency Workspace.');
        $this->assertNull($agencyCustomer->business_id, 'The new provider customer belongs to the Agency Workspace, never a Business.');

        $attempt = $this->latestAttempt($m['business']);
        $this->assertSame((int) $agencyCustomer->id, (int) $attempt->provider_customer_id);

        $call = $gateway->createCheckoutSessionCalls[array_key_last($gateway->createCheckoutSessionCalls)];
        $this->assertSame($agencyCustomer->provider_customer_id, $call['providerCustomerId']);
    }

    public function test_first_time_funding_is_still_owner_only_the_lazy_provider_customer_creation_grants_no_new_authority(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $admin = $this->memberOf($m['agency'], WorkspaceMembershipRole::Admin);

        $this->actingAsCustomer($admin->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertNotFound();

        $this->assertNull(app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $m['agency']->id));
        $this->assertCount(0, app(BusinessFundingAttemptRepository::class)->query()->where('business_id', $m['business']->id)->get());
    }

    // ------------------------------------------------------------------
    // The funding-amount label shows the real wallet currency
    // ------------------------------------------------------------------

    public function test_the_funding_amount_label_shows_the_actual_wallet_currency_not_a_hardcoded_usd(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();

        // Proves the label is genuinely read from the wallet, not
        // hardcoded: the fixture's own default is USD, so only a
        // deliberately different wallet currency can distinguish the two.
        $eur = \App\Models\Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'format' => '€', 'status' => true]);
        \Illuminate\Support\Facades\DB::table('business_usage_wallets')->where('business_id', $m['business']->id)->update(['currency_id' => $eur->id]);

        $response = $this->actingAsCustomer($m['agencyOwner']->user)
            ->get($this->showUrl($m['agency']->uid, $m['client']->uid));

        $response->assertOk();
        $response->assertSee('Amount (EUR)');
        $response->assertDontSee('Amount (USD)');
    }

    // ------------------------------------------------------------------
    // The return path: safe without Client Workspace membership
    // ------------------------------------------------------------------

    public function test_the_checkout_session_points_back_to_the_agency_scoped_return_urls_not_the_clients_own_usage_billing_route(): void
    {
        $gateway = $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');

        $this->actingAsCustomer($m['agencyOwner']->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertRedirect();

        $call = $gateway->createCheckoutSessionCalls[array_key_last($gateway->createCheckoutSessionCalls)];

        $this->assertStringContainsString('/clients/' . $m['client']->uid . '/funding/top-up/confirm/', $call['successUrl']);
        $this->assertStringContainsString('/clients/' . $m['client']->uid, $call['cancelUrl']);
        $this->assertStringNotContainsString('/usage-billing', $call['successUrl']);
        $this->assertStringNotContainsString('/usage-billing', $call['cancelUrl']);
    }

    public function test_the_agency_owner_can_confirm_the_return_without_client_workspace_membership(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');

        $this->actingAsCustomer($m['agencyOwner']->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertRedirect();

        $attempt = $this->latestAttempt($m['business']);
        $this->assertSame(FundingAttemptState::ProviderPending, $attempt->state);
        $this->registerVerifiedCheckoutOutcome($attempt);

        $this->actingAsCustomer($m['agencyOwner']->user)
            ->get($this->confirmUrl($m['agency']->uid, $m['client']->uid, $attempt->id))
            ->assertRedirect($this->showUrl($m['agency']->uid, $m['client']->uid));

        $this->assertSame(FundingAttemptState::Succeeded, $attempt->fresh()->state);
    }

    public function test_the_return_path_404s_for_an_attempt_belonging_to_a_different_client(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $other = $this->rebilledClient(WorkspacePlanTier::Growth, '-other');
        $this->workspaceProviderCustomerWithCard($other['agency'], '2222');

        $this->actingAsCustomer($other['agencyOwner']->user)
            ->post($this->initiateUrl($other['agency']->uid, $other['client']->uid), ['amount' => '10.00'])
            ->assertRedirect();
        $otherAttempt = $this->latestAttempt($other['business']);

        $this->actingAsCustomer($m['agencyOwner']->user)
            ->get($this->confirmUrl($m['agency']->uid, $m['client']->uid, $otherAttempt->id))
            ->assertNotFound();
    }

    /**
     * Origination is owner-only (assertAuthorizedChargePayer() ->
     * actorMayOriginateChargeFor(), proven above), but completing an
     * already-started Checkout return is not itself a fresh financial
     * decision — no money moves here beyond what the initiating owner
     * already authorized and Stripe already collected. This route reuses
     * the SAME Agency-authority gate resolveAuthorizedAgencyWorkspace()
     * already applies to AgencyClientsController's own show()/viewAs()
     * (owner or active Admin/Staff), rather than inventing a second,
     * stricter authority rule for this one action alone.
     */
    public function test_an_agency_admin_can_complete_the_return_the_owner_started(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');

        $this->actingAsCustomer($m['agencyOwner']->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertRedirect();
        $attempt = $this->latestAttempt($m['business']);
        $this->registerVerifiedCheckoutOutcome($attempt);

        $admin = $this->memberOf($m['agency'], WorkspaceMembershipRole::Admin);

        $this->actingAsCustomer($admin->user)
            ->get($this->confirmUrl($m['agency']->uid, $m['client']->uid, $attempt->id))
            ->assertRedirect($this->showUrl($m['agency']->uid, $m['client']->uid));

        $this->assertSame(FundingAttemptState::Succeeded, $attempt->fresh()->state);
    }

    public function test_the_return_path_404s_for_a_stranger_with_no_agency_authority(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');

        $this->actingAsCustomer($m['agencyOwner']->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertRedirect();
        $attempt = $this->latestAttempt($m['business']);

        [$strangerOwner] = $this->agencyAccount('Stranger Agency');

        $this->actingAsCustomer($strangerOwner->user)
            ->get($this->confirmUrl($m['agency']->uid, $m['client']->uid, $attempt->id))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // View As restrictions unchanged (this is a separate surface entirely)
    // ------------------------------------------------------------------

    public function test_this_route_exists_independently_of_view_as_and_does_not_alter_its_own_restrictions(): void
    {
        $this->fakeProvider();
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');

        // The Agency owner funds through this surface entirely outside a
        // View As session — no ViewAsManager call happens anywhere in this
        // flow, so View As's own billing/funding pause (Contract 08A) is
        // never touched, let alone loosened, by this controller existing.
        $this->actingAsCustomer($m['agencyOwner']->user)
            ->post($this->initiateUrl($m['agency']->uid, $m['client']->uid), ['amount' => '10.00'])
            ->assertRedirect();

        $this->assertNull(session(ViewAsManager::SESSION_KEY));
    }
}
