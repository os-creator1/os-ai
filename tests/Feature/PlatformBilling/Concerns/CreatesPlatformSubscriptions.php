<?php

namespace Tests\Feature\PlatformBilling\Concerns;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\PlatformBilling\PlatformStripeGateway;
use App\Models\Currency;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\Support\PlatformBilling\FakePlatformStripeGateway;

/**
 * Implementation Contract 21 lane-A fixtures.
 *
 * Commercial configuration is set through the CATALOG, never hard-coded into
 * application logic (§8's "never `$97`, `$297`, `$497`, 7 or 14 days"). The
 * amounts below are fixture amounts, which the contract explicitly permits.
 */
trait CreatesPlatformSubscriptions
{
    use CreatesCustomerContextFixtures;

    protected FakePlatformStripeGateway $stripe;

    protected function bindFakeStripe(): FakePlatformStripeGateway
    {
        $this->stripe = new FakePlatformStripeGateway();
        $this->stripe->baselineTransactionLevel = DB::transactionLevel();
        $this->app->instance(PlatformStripeGateway::class, $this->stripe);

        return $this->stripe;
    }

    /**
     * Makes one tier sellable: active, offered for signup, priced, and bound
     * to a provider Price identity. `$trialDays` null means no trial.
     */
    protected function sellableTier(WorkspacePlanTier $tier, ?int $trialDays = null, string $price = '99.00'): WorkspacePlanCatalog
    {
        $catalog = WorkspacePlanCatalog::query()->where('tier', $tier->value)->firstOrFail();

        $priceId = 'price_fake_' . $tier->value;

        $catalog->forceFill([
            'price' => $price,
            'currency_id' => $this->fixtureCurrencyId(),
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'available_for_signup' => true,
            'trial_enabled' => $trialDays !== null,
            'trial_days' => $trialDays,
            'provider_price_id' => $priceId,
        ])->save();

        // §11 — the provider Price must actually exist and match, so the
        // fixture registers one whose terms agree with the catalog row it just
        // wrote. A fixture that skipped this would be describing a state the
        // owner surface would now refuse to create.
        $this->stripe->definePrice($priceId, [
            'currency' => 'USD',
            'unit_amount' => \App\Library\Money\StripeMinorUnits::toMinor($price, 'USD'),
            'interval' => 'month',
            'interval_count' => 1,
            'livemode' => false,
        ]);

        return $catalog->refresh();
    }

    protected function fixtureCurrencyId(): int
    {
        $existing = Currency::query()->where('code', 'USD')->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('currencies')->insertGetId([
            'uid' => (string) Str::uuid(),
            'name' => 'US Dollar',
            'code' => 'USD',
            'format' => '$',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A Workspace with NO plan assignment yet — the state a brand-new signup
     * is in when it reaches the payment step.
     *
     * @return array{customer: \App\Models\Customer, workspace: Workspace}
     */
    protected function unassignedWorkspace(string $name = 'Harbor Lane'): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();

        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);

        return ['customer' => $customer, 'workspace' => $workspace->fresh()];
    }

    /**
     * Drives the whole §7 signup spine: start checkout, the customer completes
     * it on Stripe's hosted page, confirm from provider truth, and assign the
     * V1 plan from the CONFIRMED subscription.
     *
     * @return array{customer: \App\Models\Customer, workspace: Workspace, subscription: PlatformSubscription, provider_subscription_id: string}
     */
    protected function subscribedWorkspace(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, ?int $trialDays = null): array
    {
        // Make the tier sellable only if it is not already. A test that has
        // deliberately repriced a tier is asserting what a NEW signup pays, so
        // the fixture must not silently reset the published price underneath
        // it.
        $existing = WorkspacePlanCatalog::query()->where('tier', $tier->value)->firstOrFail();
        $catalog = $existing->isSellable() && $trialDays === null
            ? $existing
            : $this->sellableTier($tier, $trialDays);
        $fixture = $this->unassignedWorkspace();
        $manager = app(\App\Library\PlatformBilling\PlatformSubscriptionManager::class);

        $session = $manager->startCheckout(
            $fixture['workspace'],
            $catalog,
            'owner@example.test',
            'https://app.test/done',
            'https://app.test/cancel',
        );

        $providerSubscriptionId = $this->stripe->completeCheckout($session->sessionId);
        $subscription = $manager->confirmCheckoutSession($session->sessionId);

        $manager->assignPlanFromConfirmedSubscription(
            $fixture['workspace'],
            $subscription,
            $tier,
            (int) $fixture['workspace']->owner_user_id,
        );

        return [
            'customer' => $fixture['customer'],
            'workspace' => $fixture['workspace'],
            'subscription' => $subscription->refresh(),
            'provider_subscription_id' => $providerSubscriptionId,
        ];
    }

    /**
     * A signed lane-A webhook body. `data.object` is shaped like the real
     * event: a subscription event IS the subscription; an invoice event points
     * at one.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    protected function webhookBody(
        string $eventType,
        string $providerSubscriptionId,
        ?string $customerId = null,
        ?string $eventId = null,
        ?int $createdAt = null,
        ?string $operationId = null,
    ): array {
        $isSubscriptionEvent = str_starts_with($eventType, 'customer.subscription.');

        // A real subscription event carries the metadata Checkout put on it —
        // our own local subscription uid — so the fixture carries it too.
        $object = $isSubscriptionEvent
            ? [
                'id' => $providerSubscriptionId,
                'object' => 'subscription',
                'customer' => $customerId,
                'metadata' => $operationId === null ? [] : ['app_operation_id' => $operationId],
            ]
            : ['id' => 'in_fake_' . Str::random(8), 'object' => 'invoice', 'subscription' => $providerSubscriptionId, 'customer' => $customerId];

        $body = json_encode([
            'id' => $eventId ?? ('evt_' . Str::random(16)),
            'type' => $eventType,
            'created' => $createdAt ?? now()->getTimestamp(),
            'data' => ['object' => $object],
        ]);

        return [$body, ['Stripe-Signature' => $this->stripe->validSignature]];
    }

    protected function postPlatformWebhook(string $body, array $headers)
    {
        return $this->call('POST', '/stripe/webhook/platform-subscriptions', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $headers['Stripe-Signature'] ?? '',
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /** Convenience: deliver one event for a subscribed fixture. */
    protected function deliver(string $eventType, array $fixture, array $overrides = [])
    {
        [$body, $headers] = $this->webhookBody(
            $eventType,
            $overrides['subscription'] ?? $fixture['provider_subscription_id'],
            $overrides['customer'] ?? (string) $fixture['subscription']->provider_customer_id,
            $overrides['event_id'] ?? null,
            $overrides['created'] ?? null,
        );

        return $this->postPlatformWebhook($body, $headers);
    }
}
