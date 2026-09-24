<?php

namespace Tests\Feature\AgencyBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\AgencyBilling\AgencyStripeConnectionStatus;
use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyClientSubscription;
use App\Models\PlatformSubscription;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * Lane C — the spine: an Agency connects Stripe, publishes a plan, offers it to
 * a managed client, and the CLIENT pays the AGENCY.
 *
 * Every test here is about the thing lane C exists for: the money lands in the
 * Agency's account, the client's own Workspace becomes usable, and neither fact
 * leaks into another lane.
 */
class AgencySaasSpineTest extends TestCase
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

    private function tierOf($workspace): ?WorkspacePlanTier
    {
        return app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace->fresh())->tier;
    }

    private function decisionFor($workspace)
    {
        return app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh());
    }

    // =====================================================================
    // §C5.1 — connecting the account that receives the Agency's revenue
    // =====================================================================

    public function test_an_agency_owner_connects_a_stripe_account_and_it_becomes_chargeable(): void
    {
        $fixture = $this->agencyWithClient();

        $url = $this->connections()->connect(
            (int) $fixture['agencyOwner']->user_id,
            $fixture['agencyWorkspace'],
            'US',
            'agency@example.test',
            'https://app.test/refresh',
            'https://app.test/return',
        );

        $this->assertStringStartsWith('https://connect.stripe.test/onboard/', $url);

        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $this->assertSame(AgencyStripeConnectionStatus::Onboarding, $connection->status);
        $this->assertFalse($this->connections()->isChargeReady($fixture['agencyWorkspace']),
            'Nothing may be charged until the provider says the account can take money.');

        // The Agency finishes Stripe's hosted onboarding.
        $this->agencyStripe->completeOnboarding((string) $connection->stripe_account_id);
        $synced = $this->connections()->syncFromProvider((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace']);

        $this->assertSame(AgencyStripeConnectionStatus::Active, $synced->status);
        $this->assertTrue($this->connections()->isChargeReady($fixture['agencyWorkspace']));
        $this->assertNotNull($synced->maskedAccountId());
        $this->assertStringNotContainsString(
            (string) $synced->stripe_account_id,
            (string) $synced->maskedAccountId(),
            'The operator screen never shows the full account identifier.',
        );
    }

    public function test_only_the_agency_owner_may_connect_stripe(): void
    {
        $fixture = $this->agencyWithClient();
        $staff = $this->createCustomer();
        $this->createMembership($fixture['agencyWorkspace'], $staff->user, [
            'role' => \App\Enums\Workspace\WorkspaceMembershipRole::Admin,
        ]);

        // Even an ADMIN of the Agency Workspace is refused: managing clients is
        // not authority over the agency's money.
        $this->expectException(AgencyBillingException::class);

        $this->connections()->connect(
            (int) $staff->user_id,
            $fixture['agencyWorkspace'],
            'US',
            null,
            'https://app.test/refresh',
            'https://app.test/return',
        );
    }

    public function test_a_restricted_account_cannot_be_charged_and_says_why(): void
    {
        $fixture = $this->agencyWithClient();
        $connection = $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $this->agencyStripe->restrictAccount((string) $connection->stripe_account_id, 'requirements.past_due');
        $synced = $this->connections()->syncFromProvider((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace']);

        $this->assertSame(AgencyStripeConnectionStatus::Restricted, $synced->status);
        $this->assertFalse($this->connections()->isChargeReady($fixture['agencyWorkspace']));
        $this->assertSame('requirements.past_due', $synced->requirements_disabled_reason);
    }

    public function test_disconnecting_preserves_history_and_refuses_new_charges(): void
    {
        $fixture = $this->agencyWithClient();
        $connection = $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $this->connections()->disconnect((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace']);

        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
        $this->assertFalse($this->connections()->isChargeReady($fixture['agencyWorkspace']));
        $this->assertCount(1, $this->connections()->history($fixture['agencyWorkspace']),
            'Disconnecting preserves the record; it never deletes it.');
        $this->assertSame(
            (string) $connection->stripe_account_id,
            (string) $this->connections()->history($fixture['agencyWorkspace'])->first()->stripe_account_id,
            'And it rewrites no identifier.',
        );
    }

    // =====================================================================
    // §C5.2 — the Agency's own prices, on the Agency's own account
    // =====================================================================

    public function test_a_published_plan_carries_a_verified_price_on_the_agencys_own_account(): void
    {
        $fixture = $this->agencyWithClient();
        $connection = $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id, price: '349.00');

        $this->assertTrue($plan->isSellable());
        $this->assertSame('349.00', (string) $plan->price);

        $created = $this->agencyStripe->callsOf('createPrice');
        $this->assertCount(1, $created);
        $this->assertSame((string) $connection->stripe_account_id, $created[0]['args']['account'],
            'The Price is created on the AGENCY\'s account, never the platform\'s.');
        $this->assertSame(34900, $created[0]['args']['unit_amount']);
    }

    public function test_a_price_belonging_to_another_agency_is_not_even_retrievable(): void
    {
        $first = $this->agencyWithClient('First Agency', 'First Client');
        $second = $this->agencyWithClient('Second Agency', 'Second Client');

        $firstConnection = $this->connectAgencyStripe($first['agencyWorkspace'], (int) $first['agencyOwner']->user_id);
        $this->connectAgencyStripe($second['agencyWorkspace'], (int) $second['agencyOwner']->user_id);

        $firstPlan = $this->publishedPlan($first['agencyWorkspace'], (int) $first['agencyOwner']->user_id);

        // The SECOND agency tries to bind the FIRST agency's Price.
        $secondPlan = $this->plans()->create((int) $second['agencyOwner']->user_id, $second['agencyWorkspace'], [
            'name' => 'Borrowed', 'tier' => 'growth', 'price' => '349.00',
            'currency_id' => $this->fixtureCurrencyId(), 'currency_code' => 'USD', 'billing_cycle' => 'monthly',
        ]);

        try {
            $this->plans()->bindProviderPrice(
                (int) $second['agencyOwner']->user_id,
                $secondPlan,
                (string) $firstPlan->provider_price_id,
            );
            $this->fail('A Price on another agency\'s account must be unusable.');
        } catch (AgencyBillingException $e) {
            $this->assertSame(AgencyBillingException::PRICE_NOT_RETRIEVABLE, $e->reason,
                'Not "forbidden" — invisible, which is what Stripe actually does.');
        }
    }

    public function test_an_agency_may_not_resell_the_agency_tier(): void
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $this->expectException(AgencyBillingException::class);

        $this->plans()->create((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace'], [
            'name' => 'Reseller', 'tier' => 'agency', 'price' => '999.00',
            'currency_id' => $this->fixtureCurrencyId(), 'currency_code' => 'USD', 'billing_cycle' => 'monthly',
        ]);
    }

    public function test_repricing_a_plan_never_reprices_an_existing_subscriber(): void
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id, price: '349.00');

        $enrolled = $this->enrolledClient($fixture, $plan);
        $this->assertSame('349.00', (string) $enrolled['subscription']->price_snapshot);

        // The Agency raises its price.
        $this->plans()->update((int) $fixture['agencyOwner']->user_id, $plan, ['price' => '449.00'], 'Annual increase.');

        $this->assertSame('349.00', (string) $enrolled['subscription']->refresh()->price_snapshot,
            'An existing subscriber keeps the terms they agreed to.');
        $this->assertFalse($plan->refresh()->is_published,
            'And the plan leaves the shop window until its new Price is verified.');
        $this->assertSame(1, DB::table('agency_saas_plan_pricing_changes')
            ->where('from_price', '349.00')->where('to_price', '449.00')->count(),
            'The repricing itself is audited, exactly once.');
    }

    // =====================================================================
    // §C6 — the client's own financial consent
    // =====================================================================

    public function test_an_offer_takes_no_money_and_grants_no_access(): void
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $offer = $this->subscriptions()->offer(
            (int) $fixture['agencyOwner']->user_id,
            $fixture['agencyWorkspace'],
            $fixture['clientWorkspace'],
            $plan,
        );

        $this->assertSame(AgencyClientSubscriptionStatus::Offered, $offer->status);
        $this->assertNull($offer->provider_subscription_id);
        $this->assertNull($offer->provider_checkout_session_id);
        $this->assertSame([], $this->agencyStripe->callsOf('createSubscriptionCheckout'),
            'Offering reaches no provider at all.');
        $this->assertSame(0, WorkspacePlanAssignment::query()
            ->where('workspace_id', $fixture['clientWorkspace']->id)->count(),
            'And grants the client nothing.');

        // The terms are snapshotted as offered.
        $this->assertSame('349.00', (string) $offer->price_snapshot);
        $this->assertSame('USD', (string) $offer->currency_code);
    }

    public function test_the_agency_cannot_authorise_its_clients_payment(): void
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $this->subscriptions()->offer(
            (int) $fixture['agencyOwner']->user_id,
            $fixture['agencyWorkspace'],
            $fixture['clientWorkspace'],
            $plan,
        );

        try {
            $this->subscriptions()->startCheckout(
                (int) $fixture['agencyOwner']->user_id,
                $fixture['clientWorkspace'],
                'client@example.test',
                'https://app.test/return',
                'https://app.test/cancel',
            );
            $this->fail('The agency must not be able to consent on its client\'s behalf.');
        } catch (AgencyBillingException $e) {
            $this->assertSame(AgencyBillingException::CONSENT_NOT_AUTHORIZED, $e->reason);
        }

        $this->assertSame([], $this->agencyStripe->callsOf('createSubscriptionCheckout'));
    }

    public function test_the_client_consents_and_the_money_lands_on_the_agencys_account(): void
    {
        $fixture = $this->agencyWithClient();
        $connection = $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id, price: '349.00');

        $enrolled = $this->enrolledClient($fixture, $plan);
        $subscription = $enrolled['subscription']->refresh();

        // 1. The subscription is real, and it belongs to the AGENCY's account.
        $this->assertSame(AgencyClientSubscriptionStatus::Active, $subscription->status);
        $this->assertSame((string) $connection->stripe_account_id, (string) $subscription->connected_account_id);

        foreach ($this->agencyStripe->callsOf('createSubscriptionCheckout') as $call) {
            $this->assertSame((string) $connection->stripe_account_id, $call['args']['account'],
                'Every revenue call is scoped to the agency\'s own connected account.');
        }

        // 2. The CLIENT's own canonical plan assignment now exists.
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['clientWorkspace']));
        $this->assertSame(CustomerAccountAccessState::Usable, $this->decisionFor($fixture['clientWorkspace'])->state);

        // 3. And who consented is durable.
        $this->assertSame((int) $fixture['clientOwner']->user_id, (int) $subscription->consented_by_user_id);
        $this->assertNotNull($subscription->consented_at);

        // 4. Nothing of another lane was touched.
        $this->assertSame(0, PlatformSubscription::query()->count(),
            'Lane C creates no platform subscription — the client does not pay us.');
        $this->assertSame(0, DB::table('business_stripe_connections')->count());
        $this->assertSame(0, DB::table('business_document_payments')->count());
    }

    public function test_a_trial_plan_puts_the_client_in_a_provider_confirmed_trial(): void
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id, trialDays: 14);

        $enrolled = $this->enrolledClient($fixture, $plan);
        $subscription = $enrolled['subscription']->refresh();

        $this->assertSame(AgencyClientSubscriptionStatus::Trialing, $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);

        $assignment = WorkspacePlanAssignment::query()
            ->where('workspace_id', $fixture['clientWorkspace']->id)->sole();

        $this->assertNotNull($assignment->trial_ends_at);
        $this->assertSame(
            $subscription->trial_ends_at->getTimestamp(),
            $assignment->trial_ends_at->getTimestamp(),
            'The canonical trial end is the one the PROVIDER confirmed.',
        );
        $this->assertSame('plan_trial', $this->decisionFor($fixture['clientWorkspace'])->reason);
    }

    // =====================================================================
    // §C6.1 — explicit eligibility, never a silent second paid authority
    // =====================================================================

    public function test_a_client_who_already_pays_the_platform_directly_is_refused(): void
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        // The client has a LIVE lane-A subscription of their own.
        $platform = new PlatformSubscription(['workspace_id' => $fixture['clientWorkspace']->id]);
        $platform->generateUid();
        $platform->local_idempotency_key = PlatformSubscription::idempotencyKeyFor((string) $platform->uid);
        $platform->workspace_plan_catalog_id = \App\Models\WorkspacePlanCatalog::query()->where('tier', 'core')->value('id');
        $platform->status = 'active';
        $platform->save();

        try {
            $this->subscriptions()->offer(
                (int) $fixture['agencyOwner']->user_id,
                $fixture['agencyWorkspace'],
                $fixture['clientWorkspace'],
                $plan,
            );
            $this->fail('Two paid authorities for one Workspace must be refused.');
        } catch (AgencyBillingException $e) {
            $this->assertSame(AgencyBillingException::CLIENT_HAS_PLATFORM_SUBSCRIPTION, $e->reason);
        }

        $this->assertSame(0, AgencyClientSubscription::query()->count());
        $this->assertSame('active', (string) $platform->refresh()->status->value,
            'And their own subscription is left completely alone — we are not their agent.');
    }

    public function test_a_complimentary_client_is_refused(): void
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        // The Platform Owner is deliberately carrying this account.
        app(EntitlementManager::class)->assignFirstPlan(
            $fixture['clientWorkspace'], WorkspacePlanTier::Core, $this->platformAdminId(),
            'Carried by the platform.', true, 0,
        );

        try {
            $this->subscriptions()->offer(
                (int) $fixture['agencyOwner']->user_id,
                $fixture['agencyWorkspace'],
                $fixture['clientWorkspace'],
                $plan,
            );
            $this->fail('An agency may not start charging for an account we give away.');
        } catch (AgencyBillingException $e) {
            $this->assertSame(AgencyBillingException::CLIENT_IS_COMPLIMENTARY, $e->reason);
        }
    }

    public function test_an_ended_platform_subscription_does_not_block_enrollment(): void
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $platform = new PlatformSubscription(['workspace_id' => $fixture['clientWorkspace']->id]);
        $platform->generateUid();
        $platform->local_idempotency_key = PlatformSubscription::idempotencyKeyFor((string) $platform->uid);
        $platform->workspace_plan_catalog_id = \App\Models\WorkspacePlanCatalog::query()->where('tier', 'core')->value('id');
        $platform->status = 'canceled';
        $platform->save();

        $offer = $this->subscriptions()->offer(
            (int) $fixture['agencyOwner']->user_id,
            $fixture['agencyWorkspace'],
            $fixture['clientWorkspace'],
            $plan,
        );

        $this->assertSame(AgencyClientSubscriptionStatus::Offered, $offer->status,
            'Nothing live competes, and the ended lane-A row stays intact for audit.');
        $this->assertSame(1, PlatformSubscription::query()->count());
    }

    public function test_an_agency_cannot_offer_to_a_workspace_it_does_not_manage(): void
    {
        $fixture = $this->agencyWithClient();
        $stranger = $this->createIndependentWorkspaceBusiness(businessName: 'Unrelated', workspaceName: 'Unrelated WS');
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        try {
            $this->subscriptions()->offer(
                (int) $fixture['agencyOwner']->user_id,
                $fixture['agencyWorkspace'],
                $stranger['workspace'],
                $plan,
            );
            $this->fail('The management relationship is the authorization link.');
        } catch (AgencyBillingException $e) {
            $this->assertSame(AgencyBillingException::NO_ACTIVE_RELATIONSHIP, $e->reason);
        }
    }
}
