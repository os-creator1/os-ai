<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\PlatformBilling\V1SignupManager;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Implementation Contract 21 §7/§12 — THE WEBHOOK MUST BE SUFFICIENT TO FINISH
 * THE ACCOUNT.
 *
 * A customer who pays and immediately closes the tab never reaches the Checkout
 * success endpoint. If activation lived only there, that customer would be
 * charged and left with an unassigned Workspace. These tests exist because that
 * is a money bug, not a UX one.
 */
class WebhookActivationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
    }

    private function signup(): V1SignupManager
    {
        return app(V1SignupManager::class);
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        return [
            'business_name' => 'Harbor Lane Studios',
            'industry' => 'photo_booth_service',
            'timezone' => 'UTC',
            'country_code' => 'US',
        ];
    }

    /**
     * Starts signup and completes checkout AT THE PROVIDER, without the browser
     * ever coming back.
     *
     * @return array{session_id: string, subscription_id: string, subscription: PlatformSubscription}
     */
    private function paidButNeverReturned(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, ?int $trialDays = null): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $catalog = $this->sellableTier($tier, $trialDays);
        $customer = $this->createCustomer();

        $session = $this->signup()->startSubscription(
            $customer, $this->draft(), $catalog, 'https://app.test/done', 'https://app.test/cancel',
        );

        $subscriptionId = $this->stripe->completeCheckout($session->sessionId);

        $this->assertSame(0, WorkspacePlanAssignment::query()->count(),
            'Nothing is assigned until something processes the confirmation.');

        return [
            'session_id' => $session->sessionId,
            'subscription_id' => $subscriptionId,
            'subscription' => PlatformSubscription::query()->orderByDesc('id')->firstOrFail(),
        ];
    }

    /**
     * `checkout.session.completed` carries OUR OWN `client_reference_id`, which
     * is how the webhook resolves the local row without any browser state.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function checkoutCompletedBody(string $sessionId, string $clientReferenceId, string $subscriptionId, ?string $eventId = null): array
    {
        $body = json_encode([
            'id' => $eventId ?? ('evt_' . Str::random(16)),
            'type' => 'checkout.session.completed',
            'created' => now()->getTimestamp(),
            'data' => ['object' => [
                'id' => $sessionId,
                'object' => 'checkout.session',
                'client_reference_id' => $clientReferenceId,
                'subscription' => $subscriptionId,
                'status' => 'complete',
            ]],
        ]);

        return [$body, ['Stripe-Signature' => $this->stripe->validSignature]];
    }

    // =================================================================
    // The blocker itself
    // =================================================================

    /** @return array<string, array{0: WorkspacePlanTier}> */
    public static function tiers(): array
    {
        return [
            'core' => [WorkspacePlanTier::Core],
            'growth' => [WorkspacePlanTier::Growth],
            'agency' => [WorkspacePlanTier::Agency],
        ];
    }

    #[DataProvider('tiers')]
    public function test_the_webhook_alone_finishes_the_account(WorkspacePlanTier $tier): void
    {
        $paid = $this->paidButNeverReturned($tier);

        [$body, $headers] = $this->checkoutCompletedBody(
            $paid['session_id'], (string) $paid['subscription']->uid, $paid['subscription_id'],
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();

        $workspace = Workspace::query()->sole();
        $assignment = WorkspacePlanAssignment::query()->where('workspace_id', $workspace->id)->sole();

        $this->assertFalse((bool) $assignment->is_complimentary,
            'A paying customer is never complimentary, whichever path finished the signup.');
        $this->assertSame($tier, app(EntitlementManager::class)
            ->getWorkspaceEntitlementSummary($workspace)->tier);
        $this->assertSame(CustomerAccountAccessState::Usable,
            app(CustomerAccountAccessResolver::class)->resolve($workspace)->state);
    }

    public function test_a_trial_signup_finished_by_webhook_alone_carries_its_trial(): void
    {
        $paid = $this->paidButNeverReturned(WorkspacePlanTier::Growth, trialDays: 8);

        [$body, $headers] = $this->checkoutCompletedBody(
            $paid['session_id'], (string) $paid['subscription']->uid, $paid['subscription_id'],
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();

        $this->assertSame(PlatformSubscriptionStatus::Trialing, $paid['subscription']->refresh()->status);
        $this->assertNotNull(WorkspacePlanAssignment::query()->sole()->trial_ends_at);
    }

    public function test_a_subscription_created_event_alone_also_finishes_the_account(): void
    {
        // Stripe does not guarantee which of the two arrives first.
        $paid = $this->paidButNeverReturned();

        [$body, $headers] = $this->webhookBody(
            'customer.subscription.created',
            $paid['subscription_id'],
            (string) $paid['subscription']->provider_customer_id,
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();

        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
    }

    // =================================================================
    // Convergence: browser and webhook, in either order, repeated
    // =================================================================

    public function test_webhook_first_then_browser_success_converges_on_one_assignment(): void
    {
        $paid = $this->paidButNeverReturned();

        [$body, $headers] = $this->checkoutCompletedBody(
            $paid['session_id'], (string) $paid['subscription']->uid, $paid['subscription_id'],
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();

        // The customer's browser finally comes back.
        $this->signup()->completeSignup($paid['session_id']);

        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
    }

    public function test_browser_success_first_then_webhook_converges_on_one_assignment(): void
    {
        $paid = $this->paidButNeverReturned();

        $this->signup()->completeSignup($paid['session_id']);

        [$body, $headers] = $this->checkoutCompletedBody(
            $paid['session_id'], (string) $paid['subscription']->uid, $paid['subscription_id'],
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();

        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
    }

    public function test_repeated_webhook_delivery_activates_exactly_once(): void
    {
        $paid = $this->paidButNeverReturned();

        foreach (['evt_a', 'evt_b', 'evt_c'] as $eventId) {
            [$body, $headers] = $this->checkoutCompletedBody(
                $paid['session_id'], (string) $paid['subscription']->uid, $paid['subscription_id'], $eventId,
            );
            $this->postPlatformWebhook($body, $headers)->assertOk();
        }

        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
    }

    public function test_repeated_browser_success_activates_exactly_once(): void
    {
        $paid = $this->paidButNeverReturned();

        $this->signup()->completeSignup($paid['session_id']);
        $this->signup()->completeSignup($paid['session_id']);
        $this->signup()->completeSignup($paid['session_id']);

        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
    }

    // =================================================================
    // What must NOT activate
    // =================================================================

    public function test_an_unconfirmed_subscription_never_activates(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $customer = $this->createCustomer();

        $this->signup()->startSubscription($customer, $this->draft(), $catalog, 'https://a', 'https://b');
        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame(PlatformSubscriptionStatus::Pending, $subscription->status);

        $this->assertFalse($this->signup()->activateFromConfirmedSubscription($subscription));
        $this->assertSame(0, WorkspacePlanAssignment::query()->count());
    }

    public function test_a_terminal_subscription_never_activates(): void
    {
        $paid = $this->paidButNeverReturned();
        $subscription = $paid['subscription'];

        foreach ([
            PlatformSubscriptionStatus::Canceled,
            PlatformSubscriptionStatus::IncompleteExpired,
            PlatformSubscriptionStatus::Incomplete,
            PlatformSubscriptionStatus::Unpaid,
            PlatformSubscriptionStatus::Paused,
        ] as $status) {
            $subscription->forceFill(['status' => $status->value])->save();

            $this->assertFalse($this->signup()->activateFromConfirmedSubscription($subscription->refresh()),
                "[{$status->value}] must never produce a plan assignment.");
        }

        $this->assertSame(0, WorkspacePlanAssignment::query()->count());
    }

    public function test_a_past_due_subscription_still_activates(): void
    {
        // A real subscription whose latest renewal failed. Blueprint §27 keeps
        // it usable through Grace; refusing to finish it would strand a paying
        // customer.
        $paid = $this->paidButNeverReturned();
        $paid['subscription']->forceFill(['status' => PlatformSubscriptionStatus::PastDue->value])->save();

        $this->assertTrue($this->signup()->activateFromConfirmedSubscription($paid['subscription']->refresh()));
        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
    }

    public function test_a_foreign_checkout_event_activates_nothing(): void
    {
        $paid = $this->paidButNeverReturned();

        // Somebody else's checkout on the same Stripe account.
        [$body, $headers] = $this->checkoutCompletedBody(
            'cs_other_product', (string) Str::uuid(), 'sub_other_product',
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();

        $this->assertSame(0, WorkspacePlanAssignment::query()->count());
        $this->assertSame(PlatformSubscriptionStatus::Pending, $paid['subscription']->refresh()->status);
    }

    public function test_a_mismatched_provider_subscription_never_activates_another_workspace(): void
    {
        $first = $this->paidButNeverReturned();
        $second = $this->paidButNeverReturned();

        // An event naming the FIRST workspace's local row but the SECOND's
        // provider subscription.
        [$body, $headers] = $this->checkoutCompletedBody(
            $first['session_id'], (string) $first['subscription']->uid, $second['subscription_id'],
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();

        $event = \App\Models\PlatformSubscriptionEvent::query()->sole();
        $this->assertContains($event->last_error, ['customer_mismatch', 'subscription_mismatch', 'operation_id_mismatch'],
            'One of the fail-closed identity cross-checks must refuse it; which one fires first does not matter.');
        $this->assertSame(\App\Enums\PlatformBilling\PlatformSubscriptionEventState::Failed, $event->state);
        $this->assertSame(0, WorkspacePlanAssignment::query()->count());
    }
}
