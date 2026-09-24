<?php

namespace Tests\Feature\PaymentsConformance;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Payments\PaymentManager;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\AgencyClientSubscription;
use App\Models\Business;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Implementation Contract 21 §2 / Lane C §C1 — FOUR-LANE CONFORMANCE.
 *
 * Every lane already proves its own isolation with the other three lanes
 * EMPTY (PlatformBillingBoundaryTest, AgencySaasLifecycleTest, the Contract 17
 * and RFC-005 suites). This class proves the one thing none of them can: that
 * all four lanes running in ONE application at the SAME time keep their money,
 * their records, their customer identities, their event streams and their
 * entitlement effects apart.
 *
 * The scenario is the hardest legitimate one: an Agency that uses ONE
 * underlying Stripe account both for its own end-customer revenue (lane B) and
 * for its resold SaaS subscriptions (lane C). Sharing a Stripe account is the
 * merchant's business; it must never become sharing a commercial record.
 *
 * Every provider here is a fake gateway. Nothing in this class is evidence of
 * real Stripe acceptance — that is docs/product/PAYMENTS-LIVE-ACCEPTANCE.md §5.
 */
class FourLaneCoexistenceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions, CreatesAgencySaasFixtures, CreatesPayableDocuments {
        CreatesPlatformSubscriptions::fixtureCurrencyId insteadof CreatesAgencySaasFixtures;
    }

    private FakePaymentProviderGateway $usageGateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();

        $this->bindFakeStripe();
        $this->bindFakeAgencyStripe();
        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();

        $this->usageGateway = new FakePaymentProviderGateway();
        $this->app->instance(PaymentProviderGateway::class, $this->usageGateway);

        // Real Stripe object ids are globally unique across accounts. Each fake
        // numbers its own objects from 1, so the lane-C fake is moved into a
        // disjoint range — otherwise an id "collision" would be an artefact of
        // the fakes, not something production can ever produce.
        $this->agencyStripe->sequence = 700000;
    }

    // =====================================================================
    // The shared four-lane world
    // =====================================================================

    /**
     * Lane A: an independent Workspace paying the Platform Owner.
     * Lane C: an Agency whose client pays the Agency, on the Agency's account.
     * Lane B: that SAME Agency selling to its own end customer, on the SAME
     *         underlying Stripe account, through its own lane-B connection.
     *
     * @return array<string, mixed>
     */
    private function fourLaneWorld(): array
    {
        $laneA = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        // A name no other fixture uses, so "not shown on another lane's page"
        // is a real assertion rather than a coincidence of fixture names.
        $laneA['workspace']->forceFill(['name' => 'Direct Platform Subscriber'])->save();

        $agency = $this->agencyWithClient();
        $ownerId = (int) $agency['agencyOwner']->user_id;
        $agencyConnection = $this->connectAgencyStripe($agency['agencyWorkspace'], $ownerId);
        $sharedAccount = (string) $agencyConnection->stripe_account_id;

        $plan = $this->publishedPlan($agency['agencyWorkspace'], $ownerId, WorkspacePlanTier::Core, '149.00');
        $laneC = $this->enrolledClient($agency, $plan);

        $laneB = $this->agencyLaneBPayment($agency, $sharedAccount);

        return [
            'laneA' => $laneA,
            'agency' => $agency,
            'sharedAccount' => $sharedAccount,
            'plan' => $plan,
            'laneC' => $laneC,
            'laneB' => $laneB,
        ];
    }

    /**
     * Lane B on the Agency's OWN Business: a signed document, paid on the
     * shared account through the Contract 17 PaymentManager.
     *
     * @return array{connection: \App\Models\BusinessStripeConnection, payment: BusinessDocumentPayment, document: \App\Models\BusinessDocument}
     */
    private function agencyLaneBPayment(array $agency, string $sharedAccount): array
    {
        $tenant = $this->merchantTenant($agency['agencyOwner'], $agency['agencyBusiness'], $agency['agencyWorkspace']);
        $connection = $this->chargeReadyConnection($tenant['business'], $sharedAccount);

        $document = $this->draftDocument($tenant);
        [$document, $token] = $this->sendAndCaptureToken($document);
        app(\App\Library\Documents\DocumentManager::class)->sign($document, [
            'signer_name' => 'Pat Rivera',
            'signer_email' => 'pat@example.test',
            'typed_name' => 'Pat Rivera',
            'ip_address' => '127.0.0.1',
            'user_agent' => null,
        ]);

        app(PaymentManager::class)->start($this->accessFor($document->refresh(), $token));
        $payment = BusinessDocumentPayment::query()->sole();

        return ['connection' => $connection, 'payment' => $payment, 'document' => $document->refresh()];
    }

    /** The lane-B prerequisites (a Location and a Contact) on an existing Business. */
    private function merchantTenant(Customer $customer, Business $business, Workspace $workspace): array
    {
        $business->status = \App\Enums\Business\BusinessStatus::Active;
        $business->currency_code = 'USD';
        $business->save();

        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'name' => 'Main',
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);

        $group = \App\Models\ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => 'Clients ' . uniqid(),
            'status' => true,
        ]);

        $contact = Contacts::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => '1415555' . random_int(1000, 9999),
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location->id]);

        return [
            'customer' => $customer,
            'business' => $business->fresh(),
            'workspace' => $workspace->fresh(),
            'location' => $location,
            'contact' => $contact->fresh(),
        ];
    }

    /** The lane-B provider confirmation, delivered to lane B's own endpoint. */
    private function deliverLaneBSucceeded(BusinessDocumentPayment $payment, string $account, ?string $eventId = null)
    {
        [$body, $headers] = $this->webhookPayload(
            'payment_intent.succeeded',
            (string) $payment->provider_payment_intent_id,
            $account,
            (int) $payment->amount_minor,
            (string) $payment->currency_code,
            (string) $payment->local_idempotency_key,
            $eventId,
        );

        return $this->postWebhook($body, $headers);
    }

    /**
     * Lane D: a Workspace-paid top-up of the given Business's usage wallet,
     * confirmed from provider truth. Returns the funding attempt id.
     */
    private function laneDTopUp(Customer $payer, Business $business, int $amountMicro = 5_000_000): int
    {
        if (app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id) === null) {
            app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        }

        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $payer->user_id, 'Conformance fixture.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $payer->user_id);

        $checkout = app(UsageBillingCheckoutManager::class);
        $result = $checkout->initiateTopUp($business, $payer->user_id, $amountMicro);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $suffix = Str::lower(Str::random(8));
        $this->usageGateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_' . $suffix, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->usageGateway->registerCheckoutSessionResult(new \App\Library\Usage\CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $checkout->expectedMinorUnitsFor($attempt),
            $checkout->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_usage_' . $suffix,
            'pm_fake_' . $suffix,
            'https://fake.stripe.test/receipts/ch_usage_' . $suffix,
            'ch_usage_' . $suffix,
        ));

        $checkout->confirmAttemptFromReturn($attempt);

        return (int) $attempt->id;
    }

    private function assignmentOf(Workspace $workspace): WorkspacePlanAssignment
    {
        return WorkspacePlanAssignment::query()->where('workspace_id', $workspace->id)->sole();
    }

    private function accessOf(Workspace $workspace): CustomerAccountAccessState
    {
        return app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh())->state;
    }

    private function tierOf(Workspace $workspace): ?WorkspacePlanTier
    {
        return app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace->fresh())->tier;
    }

    /** @return array<string, string> a content hash per table */
    private function snapshot(array $tables): array
    {
        $out = [];

        foreach ($tables as $table) {
            $out[$table] = md5(DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $out;
    }

    private const ALL_LANE_TABLES = [
        // lane A
        'platform_subscriptions', 'platform_subscription_events',
        // lane B
        'business_stripe_connections', 'business_document_payments', 'business_payment_events',
        // lane C
        'agency_stripe_connections', 'agency_client_subscriptions', 'agency_client_subscription_events',
        // lane D
        'business_usage_wallets', 'business_usage_ledger_entries', 'business_funding_attempts',
        'payment_provider_customers', 'payment_provider_events',
        // the canonical entitlement authority
        'workspace_plan_assignments',
    ];

    // =====================================================================
    // Financial ownership — who gets the money
    // =====================================================================

    public function test_all_four_lanes_coexist_and_each_payment_lands_with_its_own_owner(): void
    {
        $world = $this->fourLaneWorld();
        $shared = $world['sharedAccount'];

        $this->deliverLaneBSucceeded($world['laneB']['payment'], $shared)->assertOk();
        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $world['laneB']['payment']->refresh()->status);

        $this->deliverAgencyEvent('invoice.paid', $world['laneC'], ['event_id' => 'evt_c_paid'])->assertOk();

        $attemptId = $this->laneDTopUp($world['agency']['clientOwner'], $world['agency']['clientBusiness']);
        $this->assertSame(FundingAttemptState::Succeeded, app(BusinessFundingAttemptRepository::class)->findById($attemptId)->state);

        // Lane A: first-party only. No provider call ever named an account.
        $this->assertNotEmpty($this->stripe->calls);
        foreach ($this->stripe->calls as $call) {
            $this->assertArrayNotHasKey('account', $call['args'], "Lane A {$call['method']}() must never act on a connected account.");
        }

        // Lane C: every revenue call was made ON the Agency's account.
        foreach ($this->agencyStripe->calls as $call) {
            if (array_key_exists('account', $call['args'])) {
                $this->assertSame($shared, $call['args']['account'], "Lane C {$call['method']}() left the Agency's account.");
            }
        }
        $this->assertNotEmpty($this->agencyStripe->callsOf('createSubscriptionCheckout'));

        // Lane B: only the Business's own connected account was ever touched.
        $this->assertSame([$shared], array_values(array_unique($this->gateway->accountsTouched())));

        // No platform cut anywhere: no fee, transfer or on-behalf-of argument
        // reached ANY of the three revenue gateways.
        foreach ([$this->stripe->calls, $this->agencyStripe->calls, $this->gateway->calls] as $calls) {
            foreach ($calls as $call) {
                foreach (['application_fee_amount', 'application_fee', 'transfer_data', 'on_behalf_of'] as $forbidden) {
                    $this->assertArrayNotHasKey($forbidden, $call['args']);
                }
            }
        }

        // Each lane's record belongs to its own payer and payee only.
        $this->assertSame(
            [(int) $world['laneA']['workspace']->id],
            PlatformSubscription::query()->pluck('workspace_id')->map(fn ($id) => (int) $id)->all(),
            'The only platform SaaS subscription is the lane-A Workspace\'s. Agency and client revenue is not ours.',
        );
        $laneC = AgencyClientSubscription::query()->sole();
        $this->assertSame((int) $world['agency']['clientWorkspace']->id, (int) $laneC->client_workspace_id);
        $this->assertSame((int) $world['agency']['agencyWorkspace']->id, (int) $laneC->agency_workspace_id);
        $this->assertSame($shared, (string) $laneC->connected_account_id);

        $laneBConnection = DB::table('business_stripe_connections')->sole();
        $this->assertSame((int) $world['agency']['agencyBusiness']->id, (int) $laneBConnection->business_id);
        $this->assertSame((int) $world['agency']['agencyBusiness']->id, (int) BusinessDocumentPayment::query()->sole()->business_id);

        // Lane D funded the client Business's wallet, and ONLY lane D did.
        $ledger = DB::table('business_usage_ledger_entries')->get();
        $this->assertCount(1, $ledger, 'A lane-B or lane-C payment never credits a usage wallet.');
        $this->assertSame($attemptId, (int) $ledger->first()->funding_attempt_id);
        $this->assertSame('5000000', (string) app(BusinessUsageWalletRepository::class)
            ->findByBusinessId((int) $world['agency']['clientBusiness']->id)->available_balance_micro);
    }

    // =====================================================================
    // Identity isolation — one Stripe account, two commercial identities
    // =====================================================================

    public function test_one_shared_stripe_account_keeps_lane_b_and_lane_c_records_and_identities_apart(): void
    {
        $world = $this->fourLaneWorld();
        $shared = $world['sharedAccount'];
        $this->laneDTopUp($world['agency']['clientOwner'], $world['agency']['clientBusiness']);

        // Two connection RECORDS for one physical account, each owned by its
        // own commercial identity.
        $this->assertSame(1, DB::table('agency_stripe_connections')->where('stripe_account_id', $shared)->count());
        $this->assertSame(1, DB::table('business_stripe_connections')->where('stripe_account_id', $shared)->count());

        // Customer identities never cross a lane.
        $laneACustomer = (string) PlatformSubscription::query()->sole()->provider_customer_id;
        $laneCCustomer = (string) AgencyClientSubscription::query()->sole()->provider_customer_id;
        $laneDCustomers = DB::table('payment_provider_customers')->pluck('provider_customer_id')->map(fn ($id) => (string) $id)->all();

        $this->assertNotSame('', $laneACustomer);
        $this->assertNotSame('', $laneCCustomer);
        $this->assertNotSame($laneACustomer, $laneCCustomer);
        $this->assertNotContains($laneACustomer, $laneDCustomers, 'Lane D\'s provider customer is never "the Stripe customer for this account".');
        $this->assertNotContains($laneCCustomer, $laneDCustomers);

        // Operation / idempotency identities never cross a lane either.
        $keys = [
            (string) PlatformSubscription::query()->sole()->local_idempotency_key,
            (string) AgencyClientSubscription::query()->sole()->local_idempotency_key,
            (string) BusinessDocumentPayment::query()->sole()->local_idempotency_key,
        ];
        $this->assertCount(3, array_unique($keys));
    }

    public function test_an_event_on_the_shared_account_resolves_only_within_its_own_lane(): void
    {
        $world = $this->fourLaneWorld();
        $shared = $world['sharedAccount'];
        $client = $world['agency']['clientWorkspace'];
        $laneCSubscription = $world['laneC']['subscription']->refresh();
        $clientAssignmentBefore = $this->assignmentOf($client)->toArray();

        // Both Connect endpoints receive events for EVERY connected account.
        // So lane C's endpoint legitimately sees lane B's payment event for
        // the shared account, and lane B's endpoint sees lane C's invoice.

        // (1) Lane B's payment event arrives at LANE C's endpoint.
        [$bBody] = $this->webhookPayload(
            'payment_intent.succeeded',
            (string) $world['laneB']['payment']->provider_payment_intent_id,
            $shared,
            (int) $world['laneB']['payment']->amount_minor,
            'USD',
            (string) $world['laneB']['payment']->local_idempotency_key,
            'evt_b_to_c',
        );
        $this->postAgencyWebhook($bBody, ['Stripe-Signature' => $this->agencyStripe->validSignature])->assertOk();

        $this->assertSame(BusinessDocumentPaymentStatus::Created, $world['laneB']['payment']->refresh()->status,
            'A lane-B payment is never confirmed by lane C.');
        $this->assertSame(0, DB::table('business_payment_events')->count());

        // (2) Lane C's invoice / subscription events arrive at LANE B's endpoint.
        foreach (['invoice.paid', 'customer.subscription.deleted'] as $i => $type) {
            [$cBody] = $this->agencyWebhookBody(
                $type, $shared, (string) $laneCSubscription->provider_subscription_id,
                (string) $laneCSubscription->provider_customer_id, 'evt_c_to_b_' . $i, null, (string) $laneCSubscription->uid,
            );
            $this->postWebhook($cBody, ['Stripe-Signature' => $this->gateway->validSignature])->assertOk();
        }

        // Paying a lane-C invoice also emits a payment_intent on the shared
        // account, carrying no lane-B operation id; lane B's endpoint is
        // subscribed to that type for every connected account.
        [$invoiceIntent] = $this->webhookPayload('payment_intent.succeeded', 'pi_invoice_of_lane_c', $shared, 14900, 'USD', null, 'evt_c_invoice_pi');
        $this->postWebhook($invoiceIntent, ['Stripe-Signature' => $this->gateway->validSignature])->assertOk();
        $this->assertSame(1, BusinessDocumentPayment::query()->count(), 'A lane-C invoice never becomes a lane-B payment.');

        $this->assertSame(AgencyClientSubscriptionStatus::Active, $laneCSubscription->refresh()->status,
            'A lane-B intake can never cancel a lane-C subscription on the same account.');
        $this->assertSame(0, DB::table('agency_client_subscription_events')->whereIn('provider_event_id', ['evt_c_to_b_0', 'evt_c_to_b_1'])->count());
        $this->assertSame(BusinessDocumentPaymentStatus::Created, $world['laneB']['payment']->refresh()->status);

        // (3) Lane C's subscription id delivered to LANE A's endpoint, and
        //     lane A's to lane C's.
        [$toA] = $this->webhookBody('customer.subscription.deleted', (string) $laneCSubscription->provider_subscription_id,
            (string) $laneCSubscription->provider_customer_id, 'evt_c_to_a');
        $this->postPlatformWebhook($toA, ['Stripe-Signature' => $this->stripe->validSignature]);

        [$toC] = $this->agencyWebhookBody('customer.subscription.deleted', $shared, $world['laneA']['provider_subscription_id'],
            (string) $world['laneA']['subscription']->provider_customer_id, 'evt_a_to_c');
        $this->postAgencyWebhook($toC, ['Stripe-Signature' => $this->agencyStripe->validSignature]);

        $this->assertSame(AgencyClientSubscriptionStatus::Active, $laneCSubscription->refresh()->status);
        $this->assertNotSame(PlatformSubscriptionStatus::Canceled, $world['laneA']['subscription']->refresh()->status,
            'A lane-C intake can never end a platform subscription.');
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessOf($world['laneA']['workspace']));

        // (4) And a lane-C event delivered to LANE D's usage-billing endpoint.
        [$toD] = $this->agencyWebhookBody('invoice.paid', $shared, (string) $laneCSubscription->provider_subscription_id,
            (string) $laneCSubscription->provider_customer_id, 'evt_c_to_d');
        $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => 'valid',
        ], $toD);
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->count(), 'An agency invoice is not usage funding.');

        // The client's canonical entitlement was driven by none of it.
        $this->assertEquals($clientAssignmentBefore, $this->assignmentOf($client)->toArray());
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessOf($client));
    }

    /**
     * Four endpoints, four signing secrets — proved with REAL Stripe signature
     * verification rather than by comparing config values. The real gateways
     * are resolved (the fakes are dropped), each body is HMAC-signed exactly as
     * Stripe signs it, and every body is delivered to every endpoint: only the
     * endpoint whose own secret signed it may accept it, and a refused delivery
     * stores nothing anywhere. No network call is made; verification is local.
     */
    public function test_each_webhook_endpoint_accepts_only_its_own_lanes_signing_secret(): void
    {
        foreach ([
            \App\Library\PlatformBilling\PlatformStripeGateway::class,
            \App\Library\AgencyBilling\AgencyStripeGateway::class,
            \App\Library\Payments\StripeConnectGateway::class,
            PaymentProviderGateway::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        // Placeholder test-mode values only; nothing here is a credential.
        $secrets = [
            'A' => 'whsec_conformance_lane_a',
            'B' => 'whsec_conformance_lane_b',
            'C' => 'whsec_conformance_lane_c',
            'D' => 'whsec_conformance_lane_d',
        ];
        config([
            'services.stripe.secret' => 'sk_test_conformance_placeholder',
            'services.stripe.mode' => 'test',
            'services.stripe.api_version' => '2024-06-20',
            'services.stripe.platform_subscription_webhook.secret' => $secrets['A'],
            'services.stripe.connect_webhook.secret' => $secrets['B'],
            'services.stripe.agency_subscription_webhook.secret' => $secrets['C'],
            'services.stripe.webhook.secret' => $secrets['D'],
        ]);
        $this->assertCount(4, array_unique($secrets));

        $endpoints = [
            'A' => '/stripe/webhook/platform-subscriptions',
            'B' => '/stripe/webhook/business-payments',
            'C' => '/stripe/webhook/agency-subscriptions',
            'D' => route('webhooks.stripe.usage-billing', [], false),
        ];
        $eventTables = ['platform_subscription_events', 'business_payment_events', 'agency_client_subscription_events', 'payment_provider_events'];

        foreach ($secrets as $signer => $secret) {
            foreach ($endpoints as $lane => $endpoint) {
                $body = json_encode([
                    'id' => 'evt_sig_' . $signer . '_to_' . $lane,
                    'object' => 'event',
                    'type' => 'balance.available',
                    'created' => now()->getTimestamp(),
                    'account' => 'acct_conformance_sig',
                    'data' => ['object' => ['id' => 'bal_conformance', 'object' => 'balance']],
                ]);
                $timestamp = now()->getTimestamp();
                $header = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

                $before = array_map(fn (string $table) => DB::table($table)->count(), $eventTables);

                $response = $this->call('POST', $endpoint, [], [], [], [
                    'HTTP_STRIPE_SIGNATURE' => $header,
                    'CONTENT_TYPE' => 'application/json',
                ], $body);

                if ($signer === $lane) {
                    $this->assertSame(200, $response->getStatusCode(), "Lane {$lane} must accept its own signature.");
                } else {
                    $this->assertSame(400, $response->getStatusCode(), "Lane {$lane} accepted a body signed with lane {$signer}'s secret.");
                    $this->assertSame($before, array_map(fn (string $table) => DB::table($table)->count(), $eventTables),
                        "A refused lane-{$lane} delivery must store nothing in any lane.");
                }
            }
        }
    }

    public function test_replayed_and_retired_events_never_double_transition_across_lanes(): void
    {
        $world = $this->fourLaneWorld();
        $shared = $world['sharedAccount'];

        // Deliver each lane's confirming event three times.
        foreach (range(1, 3) as $ignored) {
            $this->deliverLaneBSucceeded($world['laneB']['payment'], $shared, 'evt_b_once')->assertOk();
            $this->deliverAgencyEvent('invoice.paid', $world['laneC'], ['event_id' => 'evt_c_once'])->assertOk();
            $this->deliver('invoice.paid', $world['laneA'], ['event_id' => 'evt_a_once'])->assertOk();
        }

        $this->assertSame(1, DB::table('business_payment_events')->where('provider_event_id', 'evt_b_once')->count());
        $this->assertSame(1, DB::table('agency_client_subscription_events')->where('provider_event_id', 'evt_c_once')->count());
        $this->assertSame(1, DB::table('platform_subscription_events')->where('provider_event_id', 'evt_a_once')->count());
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertSame(1, PlatformSubscription::query()->count());
        $this->assertSame(1, AgencyClientSubscription::query()->count());

        // A retired lane-C subscription: cancel, start again, then replay the
        // OLD subscription's end. Neither the new lane-C life nor lane A moves.
        $client = $world['agency']['clientWorkspace'];
        $old = $world['laneC'];
        $this->agencyStripe->setSubscriptionStatus($old['provider_subscription_id'], AgencyClientSubscriptionStatus::Canceled);
        $this->deliverAgencyEvent('customer.subscription.deleted', $old, ['event_id' => 'evt_c_end'])->assertOk();
        $this->assertSame(CustomerAccountAccessState::Locked, $this->accessOf($client));

        $session = $this->subscriptions()->startResubscribeCheckout(
            (int) $world['agency']['clientOwner']->user_id, $client, $world['plan'], 'client@example.test',
            'https://app.test/return', 'https://app.test/cancel',
        );
        $this->agencyStripe->completeCheckout($session->sessionId);
        $this->subscriptions()->confirmCheckoutSession(AgencyClientSubscription::query()->sole(), $session->sessionId);
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessOf($client));

        $platformBefore = $world['laneA']['subscription']->refresh()->toArray();
        $this->deliverAgencyEvent('customer.subscription.deleted', $old, ['event_id' => 'evt_c_old_end_replay'])->assertOk();

        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessOf($client),
            'The retired subscription cannot lock the client who has paid to come back.');
        $this->assertEquals($platformBefore, $world['laneA']['subscription']->refresh()->toArray());
    }

    // =====================================================================
    // Canonical entitlements
    // =====================================================================

    public function test_each_subscription_drives_only_its_own_workspace_lifecycle(): void
    {
        $world = $this->fourLaneWorld();
        $laneAWorkspace = $world['laneA']['workspace'];
        $client = $world['agency']['clientWorkspace'];
        $agencyWorkspace = $world['agency']['agencyWorkspace'];

        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($laneAWorkspace));
        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($client), 'The client\'s plan is the one it bought from its agency.');
        $agencyBefore = $this->assignmentOf($agencyWorkspace)->toArray();
        $laneABefore = $this->assignmentOf($laneAWorkspace)->toArray();

        // The client fails a lane-C renewal: ONE grace window, on the client.
        $this->agencyStripe->setSubscriptionStatus($world['laneC']['provider_subscription_id'], AgencyClientSubscriptionStatus::PastDue);
        $this->deliverAgencyEvent('invoice.payment_failed', $world['laneC'], ['event_id' => 'evt_c_fail'])->assertOk();
        $this->assertNotNull($this->assignmentOf($client)->grace_started_at);
        $this->assertEquals($laneABefore, $this->assignmentOf($laneAWorkspace)->toArray(), 'Lane C never moves a lane-A Workspace.');
        $this->assertEquals($agencyBefore, $this->assignmentOf($agencyWorkspace)->toArray(), 'A client\'s default is never the agency\'s.');

        // The lane-A Workspace fails a renewal: grace on it alone.
        $clientBefore = $this->assignmentOf($client)->toArray();
        $this->stripe->setSubscriptionStatus($world['laneA']['provider_subscription_id'], PlatformSubscriptionStatus::PastDue);
        $this->deliver('invoice.payment_failed', $world['laneA'], ['event_id' => 'evt_a_fail'])->assertOk();
        $this->assertNotNull($this->assignmentOf($laneAWorkspace)->grace_started_at);
        $this->assertEquals($clientBefore, $this->assignmentOf($client)->toArray(), 'Lane A never moves a lane-C client.');

        // A lane-B sale and a lane-D top-up change NO lifecycle.
        $this->deliverLaneBSucceeded($world['laneB']['payment'], $world['sharedAccount'])->assertOk();
        $this->laneDTopUp($world['agency']['clientOwner'], $world['agency']['clientBusiness']);

        $this->assertNotNull($this->assignmentOf($client)->grace_started_at, 'Wallet funding is not a subscription payment.');
        $this->assertEquals($clientBefore, $this->assignmentOf($client)->toArray());
        $this->assertEquals($agencyBefore, $this->assignmentOf($agencyWorkspace)->toArray(), 'Business revenue is not SaaS payment.');
    }

    public function test_neither_a_wallet_top_up_nor_a_business_sale_unlocks_a_locked_workspace(): void
    {
        $world = $this->fourLaneWorld();
        $client = $world['agency']['clientWorkspace'];

        // A pending lane-D top-up is started while the client is healthy.
        $business = $world['agency']['clientBusiness'];
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $world['agency']['clientOwner']->user_id, 'Fixture.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $world['agency']['clientOwner']->user_id);
        $checkout = app(UsageBillingCheckoutManager::class);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById(
            $checkout->initiateTopUp($business, $world['agency']['clientOwner']->user_id, 7_000_000)->fundingAttemptId,
        );

        // The client's lane-C subscription then fails and grace expires.
        $this->agencyStripe->setSubscriptionStatus($world['laneC']['provider_subscription_id'], AgencyClientSubscriptionStatus::PastDue);
        $this->deliverAgencyEvent('invoice.payment_failed', $world['laneC'], ['event_id' => 'evt_c_fail'])->assertOk();
        $this->travel(EntitlementManager::GRACE_PERIOD_DAYS + 1)->days();
        $this->artisan('workspaces:advance-account-lifecycle')->assertExitCode(0);
        $this->assertSame(CustomerAccountAccessState::Locked, $this->accessOf($client));
        $lockedAssignment = $this->assignmentOf($client)->toArray();

        // The top-up confirms now, while the client is locked.
        $this->usageGateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_locked', $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->usageGateway->registerCheckoutSessionResult(new \App\Library\Usage\CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference, 'complete', 'paid', null,
            $checkout->expectedMinorUnitsFor($attempt), $checkout->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot, 'pi_usage_locked', 'pm_fake_locked',
            'https://fake.stripe.test/receipts/ch_usage_locked', 'ch_usage_locked',
        ));
        $checkout->confirmAttemptFromReturn($attempt);

        $this->assertSame('7000000', (string) app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id)->available_balance_micro,
            'The money is credited as usage funding…');
        $this->assertSame(CustomerAccountAccessState::Locked, $this->accessOf($client), '…and a wallet credit never unlocks an account.');
        $this->assertEquals($lockedAssignment, $this->assignmentOf($client)->toArray());

        // The agency's own lane-B sale does not unlock its client either.
        $this->deliverLaneBSucceeded($world['laneB']['payment'], $world['sharedAccount'])->assertOk();
        $this->assertSame(CustomerAccountAccessState::Locked, $this->accessOf($client));

        // Only the client's OWN lane-C payment recovers it.
        $this->agencyStripe->setSubscriptionStatus($world['laneC']['provider_subscription_id'], AgencyClientSubscriptionStatus::Active);
        $this->deliverAgencyEvent('invoice.paid', $world['laneC'], ['event_id' => 'evt_c_recover'])->assertOk();
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessOf($client));
        $this->travelBack();
    }

    /**
     * Contract 21 §2 / §C6 — one Workspace has at most one paid SaaS
     * authority at a time. Lane C already refuses to enrol a client whose
     * lane-A subscription still grants access; this is the mirror image. A
     * client whose PREVIOUS platform subscription ended, and who now pays its
     * agency, must not be able to "start again" on lane A as well — that would
     * charge the same account twice and put two lifecycles in charge of one
     * canonical plan assignment.
     */
    public function test_a_client_billed_by_its_agency_cannot_also_restart_a_platform_subscription(): void
    {
        $fixture = $this->agencyWithClient();
        $ownerId = (int) $fixture['agencyOwner']->user_id;
        $this->connectAgencyStripe($fixture['agencyWorkspace'], $ownerId);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], $ownerId, WorkspacePlanTier::Core);
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);

        // The client once paid the platform directly; that subscription ended.
        $platform = new PlatformSubscription(['workspace_id' => $fixture['clientWorkspace']->id]);
        $platform->generateUid();
        $platform->local_idempotency_key = PlatformSubscription::idempotencyKeyFor((string) $platform->uid);
        $platform->workspace_plan_catalog_id = $catalog->id;
        $platform->status = PlatformSubscriptionStatus::Canceled->value;
        $platform->provider_subscription_id = 'sub_platform_old';
        $platform->save();

        // It now pays its agency (the ended platform row does not block that).
        $this->enrolledClient($fixture, $plan);
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessOf($fixture['clientWorkspace']));
        $this->assertFalse(
            app(\App\Library\PlatformBilling\CustomerSubscriptionPresenter::class)->present($fixture['clientWorkspace']->fresh())['can_resubscribe'],
            'The Plan & subscription page does not offer a restart the manager would refuse.',
        );
        $before = $this->snapshot(self::ALL_LANE_TABLES);
        $checkoutCalls = count($this->stripe->callsOf('createSubscriptionCheckout'));

        try {
            app(PlatformSubscriptionManager::class)->startResubscribeCheckout(
                $fixture['clientWorkspace'], $catalog, 'client@example.test', 'https://app.test/return', 'https://app.test/cancel',
            );
            $this->fail('A Workspace its agency is billing must not open a second, platform-paid subscription.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::CHANGE_NOT_PERMITTED, $e->reason);
        }

        $this->assertSame($checkoutCalls, count($this->stripe->callsOf('createSubscriptionCheckout')), 'No platform Checkout was opened.');
        $this->assertSame($before, $this->snapshot(self::ALL_LANE_TABLES), 'Nothing in any lane was touched.');
    }

    /**
     * Lane C §C6.1 names the live lane-A states exactly: trialing, active,
     * past_due, unpaid and paused. `unpaid` and `paused` grant no access, but
     * the provider subscription is still alive and can be paid or resumed at
     * any moment, so an agency offer on top of it would be a second authority.
     */
    public function test_an_unpaid_or_paused_platform_subscription_still_blocks_an_agency_offer(): void
    {
        $fixture = $this->agencyWithClient();
        $ownerId = (int) $fixture['agencyOwner']->user_id;
        $this->connectAgencyStripe($fixture['agencyWorkspace'], $ownerId);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], $ownerId, WorkspacePlanTier::Core);

        $platform = new PlatformSubscription(['workspace_id' => $fixture['clientWorkspace']->id]);
        $platform->generateUid();
        $platform->local_idempotency_key = PlatformSubscription::idempotencyKeyFor((string) $platform->uid);
        $platform->workspace_plan_catalog_id = \App\Models\WorkspacePlanCatalog::query()->where('tier', 'core')->value('id');

        foreach ([PlatformSubscriptionStatus::Unpaid, PlatformSubscriptionStatus::Paused] as $status) {
            $platform->status = $status->value;
            $platform->save();

            try {
                $this->subscriptions()->offer($ownerId, $fixture['agencyWorkspace'], $fixture['clientWorkspace'], $plan);
                $this->fail("A {$status->value} platform subscription is still a live paid authority.");
            } catch (\App\Exceptions\AgencyBilling\AgencyBillingException $e) {
                $this->assertSame(\App\Exceptions\AgencyBilling\AgencyBillingException::CLIENT_HAS_PLATFORM_SUBSCRIPTION, $e->reason);
            }
        }

        $this->assertSame(0, AgencyClientSubscription::query()->count());
        $this->assertSame([], $this->agencyStripe->callsOf('createSubscriptionCheckout'));
    }

    // =====================================================================
    // Reporting
    // =====================================================================

    public function test_the_platform_owners_lane_a_view_counts_no_other_lane(): void
    {
        $world = $this->fourLaneWorld();
        $this->deliverLaneBSucceeded($world['laneB']['payment'], $world['sharedAccount'])->assertOk();
        $this->laneDTopUp($world['agency']['clientOwner'], $world['agency']['clientBusiness']);

        // A lane-C client in grace is the AGENCY's delinquency, not ours.
        $this->agencyStripe->setSubscriptionStatus($world['laneC']['provider_subscription_id'], AgencyClientSubscriptionStatus::PastDue);
        $this->deliverAgencyEvent('invoice.payment_failed', $world['laneC'], ['event_id' => 'evt_c_fail'])->assertOk();
        $this->assertNotNull($this->assignmentOf($world['agency']['clientWorkspace'])->grace_started_at);

        $admin = User::query()->findOrFail($this->platformAdminId());
        $admin->email_verified_at = now();
        $admin->save();
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);

        $html = $this->get(route('admin.platform-billing.index'))->assertOk()->getContent();

        $metric = function (string $key) use ($html): string {
            $this->assertSame(1, preg_match('/data-role="metric-' . preg_quote($key, '/') . '">([^<]*)</', $html, $m), "metric {$key}");

            return trim($m[1]);
        };

        $this->assertSame('1', $metric('active'), 'Exactly one platform SaaS subscriber: the lane-A Workspace.');
        $this->assertSame('0', $metric('grace'), 'A lane-C client in grace is not a lane-A subscriber in grace.');
        $this->assertSame('0', $metric('locked'));
        $this->assertStringContainsString('data-role="attention-empty"', $html);
        $this->assertStringNotContainsString((string) $world['agency']['clientWorkspace']->uid, $html);
        $this->assertStringNotContainsString((string) $world['agency']['agencyWorkspace']->uid, $html);

        // The agency's own revenue page names only the agency's revenue.
        $this->app['auth']->forgetGuards();
        $this->authenticateAs($world['agency']['agencyOwner']);
        $revenue = $this->get(route('customer.workspaces.agency.saas.revenue', [$world['agency']['agencyWorkspace']->uid]))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString((string) $world['laneA']['workspace']->name, $revenue);
    }
}
