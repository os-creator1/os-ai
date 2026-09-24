<?php

namespace Tests\Feature\AgencyBilling\Concerns;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\AgencyBilling\AgencyClientSubscriptionManager;
use App\Library\AgencyBilling\AgencySaasPlanManager;
use App\Library\AgencyBilling\AgencyStripeConnectManager;
use App\Library\AgencyBilling\AgencyStripeGateway;
use App\Models\AgencyClientSubscription;
use App\Models\AgencySaasPlan;
use App\Models\AgencyStripeConnection;
use App\Models\Currency;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\Support\AgencyBilling\FakeAgencyStripeGateway;

/**
 * Lane C fixtures.
 *
 * Commercial configuration is set through the AGENCY's own catalog, never
 * hard-coded into application logic. The amounts below are fixture amounts.
 */
trait CreatesAgencySaasFixtures
{
    use CreatesCustomerContextFixtures;

    protected FakeAgencyStripeGateway $agencyStripe;

    protected function bindFakeAgencyStripe(): FakeAgencyStripeGateway
    {
        $this->agencyStripe = new FakeAgencyStripeGateway();
        $this->agencyStripe->baselineTransactionLevel = DB::transactionLevel();
        $this->app->instance(AgencyStripeGateway::class, $this->agencyStripe);

        return $this->agencyStripe;
    }

    protected function connections(): AgencyStripeConnectManager
    {
        return app(AgencyStripeConnectManager::class);
    }

    protected function plans(): AgencySaasPlanManager
    {
        return app(AgencySaasPlanManager::class);
    }

    protected function subscriptions(): AgencyClientSubscriptionManager
    {
        return app(AgencyClientSubscriptionManager::class);
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
     * An Agency Workspace whose Stripe account is connected and READY to take
     * money — the state every lane-C sale starts from.
     *
     * @return array{agencyWorkspace: Workspace, agencyOwner: \App\Models\Customer, connection: AgencyStripeConnection, clientWorkspace: Workspace, clientOwner: \App\Models\Customer, relationship: \App\Models\AgencyClientWorkspaceRelationship}
     */
    protected function agencyWithClient(string $agencyName = 'Northwind Agency', string $clientName = 'Harbor Lane'): array
    {
        $fixture = $this->createAgencyManagedClient(
            clientBusinessName: $clientName,
            clientWorkspaceName: $clientName . ' Workspace',
            agencyWorkspaceName: $agencyName,
        );

        return [
            'agencyWorkspace' => $fixture['agencyWorkspace'],
            'agencyOwner' => $fixture['agencyOwner'],
            'agencyBusiness' => $fixture['agencyBusiness'],
            'clientWorkspace' => $fixture['clientWorkspace'],
            'clientOwner' => $fixture['clientOwner'],
            'clientBusiness' => $fixture['clientBusiness'],
            'relationship' => $fixture['relationship'],
        ];
    }

    /** Connects the Agency's Stripe account and finishes onboarding. */
    protected function connectAgencyStripe(Workspace $agencyWorkspace, int $ownerUserId): AgencyStripeConnection
    {
        $this->connections()->connect(
            $ownerUserId,
            $agencyWorkspace,
            'US',
            'agency@example.test',
            'https://app.test/connect/refresh',
            'https://app.test/connect/return',
        );

        $connection = $this->connections()->liveConnection($agencyWorkspace);
        $this->agencyStripe->completeOnboarding((string) $connection->stripe_account_id);
        $this->connections()->syncFromProvider($ownerUserId, $agencyWorkspace);

        return $this->connections()->liveConnection($agencyWorkspace);
    }

    /**
     * §C6 — THE PLATFORM OWNER'S CANONICAL CATALOG MUST BE PRICED for the tier
     * an Agency resells.
     *
     * This is a real deployment prerequisite, not a fixture convenience.
     * `EntitlementManager::assignFirstPlan()` asserts base pricing on the
     * canonical `workspace_plan_catalog` row before it will assign a
     * non-complimentary plan, and lane C deliberately does NOT bypass that: the
     * catalog row carries the capacity and slot rules the assignment depends
     * on, so an unpriced tier is an unconfigured tier whoever is paying.
     *
     * In production the Platform Owner has already priced Core and Growth for
     * lane A. A deployment that has not cannot enroll agency clients onto them
     * either, and will say so rather than assigning something half-configured.
     */
    protected function ensureCanonicalTierPriced(WorkspacePlanTier $tier): void
    {
        $catalog = \App\Models\WorkspacePlanCatalog::query()->where('tier', $tier->value)->firstOrFail();

        if ($catalog->price !== null && $catalog->currency_id !== null) {
            return;
        }

        $catalog->forceFill([
            'price' => '99.00',
            'currency_id' => $this->fixtureCurrencyId(),
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ])->save();
    }

    /**
     * A published, sellable resale plan on the Agency's own account, with a
     * matching Stripe Price generated for it.
     */
    protected function publishedPlan(
        Workspace $agencyWorkspace,
        int $ownerUserId,
        WorkspacePlanTier $tier = WorkspacePlanTier::Growth,
        string $price = '349.00',
        ?int $trialDays = null,
        string $name = 'Growth Partner',
    ): AgencySaasPlan {
        $this->ensureCanonicalTierPriced($tier);

        $plan = $this->plans()->create($ownerUserId, $agencyWorkspace, [
            'name' => $name,
            'description' => 'Everything the agency runs for you.',
            'tier' => $tier->value,
            'price' => $price,
            'currency_id' => $this->fixtureCurrencyId(),
            'currency_code' => 'USD',
            'billing_cycle' => 'monthly',
            'trial_enabled' => $trialDays !== null,
            'trial_days' => $trialDays,
        ]);

        // Null provider price id => the gateway generates one on the Agency's
        // own account, which is the path an Agency uses in practice.
        $plan = $this->plans()->bindProviderPrice($ownerUserId, $plan, null);

        return $this->plans()->publish($ownerUserId, $plan);
    }

    /**
     * Drives the whole §C6 spine: the Agency offers, the CLIENT consents and
     * pays, and provider truth is confirmed.
     *
     * @return array{subscription: AgencyClientSubscription, provider_subscription_id: string, session_id: string}
     */
    protected function enrolledClient(array $fixture, AgencySaasPlan $plan): array
    {
        $this->subscriptions()->offer(
            (int) $fixture['agencyOwner']->user_id,
            $fixture['agencyWorkspace'],
            $fixture['clientWorkspace'],
            $plan,
        );

        $session = $this->subscriptions()->startCheckout(
            (int) $fixture['clientOwner']->user_id,
            $fixture['clientWorkspace'],
            'client@example.test',
            'https://app.test/agency-plan/return',
            'https://app.test/agency-plan',
        );

        $providerSubscriptionId = $this->agencyStripe->completeCheckout($session->sessionId);

        $subscription = AgencyClientSubscription::query()
            ->where('client_workspace_id', $fixture['clientWorkspace']->id)
            ->firstOrFail();

        $this->subscriptions()->confirmCheckoutSession($subscription, $session->sessionId);

        return [
            'subscription' => $subscription->refresh(),
            'provider_subscription_id' => $providerSubscriptionId,
            'session_id' => $session->sessionId,
        ];
    }

    /**
     * A signed lane-C Connect webhook body. `data.object` is shaped like the
     * real event, and the envelope carries the `account` every Connect event
     * carries.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    protected function agencyWebhookBody(
        string $eventType,
        string $connectedAccountId,
        string $providerSubscriptionId,
        ?string $customerId = null,
        ?string $eventId = null,
        ?int $createdAt = null,
        ?string $operationId = null,
    ): array {
        $isSubscriptionEvent = str_starts_with($eventType, 'customer.subscription.');

        $object = $isSubscriptionEvent
            ? [
                'id' => $providerSubscriptionId,
                'object' => 'subscription',
                'customer' => $customerId,
                'metadata' => $operationId === null ? [] : ['app_operation_id' => $operationId],
            ]
            : [
                'id' => 'in_fake_' . Str::random(8),
                'object' => 'invoice',
                'subscription' => $providerSubscriptionId,
                'customer' => $customerId,
            ];

        $body = json_encode([
            'id' => $eventId ?? ('evt_' . Str::random(16)),
            'type' => $eventType,
            'created' => $createdAt ?? now()->getTimestamp(),
            // Every Connect event names its account.
            'account' => $connectedAccountId,
            'data' => ['object' => $object],
        ]);

        return [$body, ['Stripe-Signature' => $this->agencyStripe->validSignature]];
    }

    protected function postAgencyWebhook(string $body, array $headers)
    {
        return $this->call('POST', '/stripe/webhook/agency-subscriptions', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $headers['Stripe-Signature'] ?? '',
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /** Convenience: deliver one event for an enrolled fixture. */
    protected function deliverAgencyEvent(string $eventType, array $enrolled, array $overrides = [])
    {
        $subscription = $enrolled['subscription']->refresh();

        [$body, $headers] = $this->agencyWebhookBody(
            $eventType,
            $overrides['account'] ?? (string) $subscription->connected_account_id,
            $overrides['subscription'] ?? ($enrolled['provider_subscription_id'] ?? (string) $subscription->provider_subscription_id),
            $overrides['customer'] ?? (string) $subscription->provider_customer_id,
            $overrides['event_id'] ?? null,
            $overrides['created'] ?? null,
            $overrides['operation_id'] ?? (string) $subscription->uid,
        );

        return $this->postAgencyWebhook($body, $headers);
    }

    protected function statusOf(Workspace $clientWorkspace): ?AgencyClientSubscriptionStatus
    {
        return AgencyClientSubscription::query()
            ->where('client_workspace_id', $clientWorkspace->id)
            ->first()?->status;
    }
}
