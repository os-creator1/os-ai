<?php

namespace Tests\Feature\Usage\Slice5\Concerns;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;

/**
 * Customer Experience Slice 5 — shared fixtures for the owned tests.
 * Everything runs against the real RFC-005 wallet, ledger, meters and
 * payer assignment; the only fakes are the payment-provider gateway and
 * the explicit fixture rates (contract §28.1a: no real telecom rate is
 * activated anywhere in this suite).
 */
trait Slice5Fixtures
{
    use CreatesCustomerContextFixtures;

    protected ?FakePaymentProviderGateway $gateway = null;

    protected function usd(): int
    {
        return Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true])->id;
    }

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function tenantWithWallet(WorkspacePlanTier $tier, string $businessName = 'Harbor Lane Studios', string $workspaceName = 'Harbor Lane'): array
    {
        $this->usd();
        [$customer, $business, $workspace] = $this->tenant($tier, $businessName, $workspaceName);
        $this->walletFor($business);

        return [$customer, $business->fresh(), $workspace->fresh()];
    }

    protected function walletFor(Business $business): void
    {
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);
    }

    protected function clientBusiness(Workspace $workspace, string $name = 'Client Bakery'): array
    {
        $client = $this->createCustomer();
        $business = $this->addBusiness($client, $workspace, $name);
        $this->walletFor($business);

        return [$client, $business->fresh()];
    }

    /**
     * Fixture-level payer assignment (never the customer path): the
     * authoritative row itself is written, so tests may build any
     * scenario regardless of who would be allowed to change it.
     */
    protected function setPayer(Business $business, PayerType $payerType): void
    {
        DB::table('business_payer_assignments')->updateOrInsert(
            ['business_id' => $business->id],
            ['payer_type' => $payerType->value, 'effective_payment_instrument_id' => null, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    protected function fund(Business $business, int $availableMicro): void
    {
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => $availableMicro]);
    }

    protected function walletRow(Business $business): object
    {
        return DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
    }

    protected function platformAdminUserId(): int
    {
        return User::create([
            'first_name' => 'Slice5', 'last_name' => 'Admin', 'email' => 'slice5-admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    /**
     * Explicit fixture rate — retail and provider cost are deliberately
     * different so retail-versus-provider separation is observable.
     * Default: $1.00 retail per unit, $0.60 provider cost.
     */
    protected function activateFixtureRate(string $featureKey = 'crm', string $retailRateMicro = '1000000', string $providerCostMicro = '600000'): void
    {
        $actorId = $this->platformAdminUserId();
        $currencyId = $this->usd();

        if (app(UsageMeterRepository::class)->findByMeterKey($featureKey) === null) {
            app(UsageMeterRepository::class)->create([
                'meter_key' => $featureKey,
                'feature_key' => $featureKey,
                'business_id' => null,
                'currency_id' => $currencyId,
                'description' => 'Slice 5 fixture meter (fixture rate, not a real rate card).',
                'updated_by_user_id' => $actorId,
            ]);
        }

        app(UsageWalletManager::class)->setActiveRate($featureKey, $retailRateMicro, $providerCostMicro, 'per unit', $currencyId, $actorId, 'Slice 5 fixture rate.');
        app(UsageWalletManager::class)->activateMetering($featureKey, $actorId, 'Slice 5 fixture metering.');
    }

    protected function fakeProvider(): FakePaymentProviderGateway
    {
        config([
            'services.stripe.key' => 'pk_test_fixture',
            'services.stripe.secret' => 'sk_test_fixture',
            'services.stripe.webhook.secret' => 'whsec_fixture',
            'services.stripe.mode' => 'test',
        ]);

        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);

        return $this->gateway;
    }

    /**
     * Attaches a fake card for the payer of $business (the Workspace owner
     * when the Workspace pays, the direct owner when the Business pays).
     */
    protected function attachFakeCard(Business $business, int $payerUserId): void
    {
        $gateway = $this->gateway ?? $this->fakeProvider();
        $business->loadMissing('workspace');

        $instrumentManager = app(PaymentInstrumentManager::class);
        $setupIntent = $instrumentManager->createSetupIntent($business, $payerUserId);

        $payerType = DB::table('business_payer_assignments')->where('business_id', $business->id)->value('payer_type');
        $providerCustomer = $payerType === PayerType::Business->value
            ? app(PaymentProviderCustomerRepository::class)->findActiveByBusinessId((int) $business->id)
            : app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $business->workspace_id);

        $gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_' . substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomer->provider_customer_id,
            'card', 'visa', '4242', 12, 2030,
        ));

        $instrumentManager->confirmSetupIntentAndAttach($business, $payerUserId, $setupIntent->providerSetupIntentId);
    }

    protected function billingContact(Business $business, int $actorUserId, string $email = 'billing@example.test'): void
    {
        app(BillingProfileManager::class)->updateBillingContact($business, null, 'Billing Contact', $email, true, $actorUserId);
    }

    protected function usageBillingUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]);
    }

    protected function usageBillingRoute(string $suffix, Workspace $workspace, Business $business, array $extra = []): string
    {
        return route('customer.workspaces.businesses.usage-billing.' . $suffix, array_merge([$workspace->uid, $business->uid], $extra));
    }
}
