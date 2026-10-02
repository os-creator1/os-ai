<?php

namespace Tests\Feature\V1Acceptance\AccountShell;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\PlatformSubscription;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\V1Acceptance\AccountShell\Concerns\DrivesAccountJourneys;
use Tests\TestCase;

/**
 * V1 FINAL ACCEPTANCE 01 — journey D: the already-built platform-subscription
 * lifecycle, exercised from a REAL signup rather than a repository fixture, and
 * judged by what the customer's browser and `CustomerAccountAccessResolver`
 * say.
 *
 * Deliberately a small number of end-to-end walks: the unit-level lifecycle
 * (webhook ordering, grace arithmetic, replay) is already proven in
 * `tests/Feature/PlatformBilling` and is not repeated here.
 */
class SubscriptionLifecycleJourneyTest extends TestCase
{
    use RefreshDatabase;
    use DrivesAccountJourneys;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->bootJourney();
    }

    private function resolver(): CustomerAccountAccessResolver
    {
        return app(CustomerAccountAccessResolver::class);
    }

    // =================================================================
    // Unpaid / abandoned / failed signup
    // =================================================================

    public function test_an_unpaid_signup_is_never_granted_a_plan_and_recovers_into_the_same_account(): void
    {
        $account = $this->registerOnTier('core');
        $user = $account['user'];
        $workspace = $account['workspace'];
        $pendingUid = $account['subscription']->uid;
        $this->verifyEmailThroughSignedLink($user);

        // 1. The customer backs out of Stripe. Nothing is paid, nothing assigned.
        $this->get(route('signup.cancelled'))->assertRedirect(route('signup.plan'));
        $this->get(route('signup.plan'))->assertOk()->assertSee('Choose a plan');
        $this->assertSame(0, WorkspacePlanAssignment::query()->count());
        $this->assertSame(BusinessStatus::Draft, Business::query()->sole()->status, 'An unpaid Business is not live.');

        // A forged return is not payment.
        $this->get(route('signup.success') . '?success=true');
        $this->assertSame(0, WorkspacePlanAssignment::query()->count(), 'A query flag is not payment.');

        // The unpaid customer reaches Home but the plan-gated modules refuse
        // them, and the resolver reports the pre-plan state, not a paid one.
        $this->get(route('user.home'))->assertOk();
        $this->get(route('customer.workspaces.businesses.forms.index', [$workspace->uid, $account['business']->uid]))->assertNotFound();
        $this->assertSame('usable', $this->resolver()->resolve($workspace)->reason);

        // 2. Retrying checkout, repeatedly, reuses the one pending identity.
        $this->post(route('signup.resume'), ['tier' => 'core'])->assertRedirectContains('checkout.stripe.test');
        $this->post(route('signup.resume'), ['tier' => 'core'])->assertRedirectContains('checkout.stripe.test');
        $again = $this->accountOf($account['form']['email']);
        $this->assertSame($pendingUid, $again['subscription']->uid, 'The pending identity is stable through retries.');
        $this->assertSame(1, $this->identityCounts($user)['platform_subscriptions']);

        // 3. The provider reports the payment as not yet confirmed
        //    (`incomplete`): the browser returns, nothing activates.
        $providerSubscriptionId = $this->stripe->completeCheckout(
            (string) $again['subscription']->refresh()->provider_checkout_session_id,
            PlatformSubscriptionStatus::Incomplete,
        );
        $this->get(route('signup.success'));
        $this->assertSame(0, WorkspacePlanAssignment::query()->count(), 'An unconfirmed provider subscription activates nothing.');
        $this->assertSame(BusinessStatus::Draft, Business::query()->sole()->status);

        // The checkout is now a live provider relationship, so "start again" is
        // refused rather than opening a second subscription…
        $this->post(route('signup.resume'), ['tier' => 'core'])->assertRedirect(route('signup.plan'));
        $this->assertSame(1, $this->identityCounts($user)['platform_subscriptions']);

        // …and the recovery is the plan page and Stripe's hosted portal.
        $this->get(route('customer.workspaces.plan.show', [$workspace->uid]))->assertOk();
        $this->get(route('customer.workspaces.plan.payment-method', [$workspace->uid]))->assertRedirect();

        // 4. The provider settles the payment; the webhook finishes the signup.
        $this->stripe->setSubscriptionStatus($providerSubscriptionId, PlatformSubscriptionStatus::Active);
        $fixture = $this->webhookFixture($again, $providerSubscriptionId);
        $this->deliverAsStripe('invoice.paid', $fixture, ['event_id' => 'evt_acc_settled'])->assertOk();

        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
        $this->assertSame(BusinessStatus::Active, Business::query()->sole()->status);
        $counts = $this->identityCounts($user);
        $this->assertSame(1, $counts['workspaces']);
        $this->assertSame(1, $counts['businesses']);
        $this->assertSame(1, $counts['locations']);
        $this->assertSame(1, $counts['platform_subscriptions']);
        $this->get(route('customer.workspaces.businesses.people.index', [$workspace->uid, $account['business']->uid]))->assertOk();
    }

    public function test_the_webhook_alone_finishes_a_paid_signup_and_takes_the_business_live(): void
    {
        $account = $this->registerOnTier('growth');
        $this->verifyEmailThroughSignedLink($account['user']);

        // The customer pays and closes the tab: the browser never returns.
        $providerSubscriptionId = $this->stripe->completeCheckout((string) $account['subscription']->provider_checkout_session_id);
        $body = json_encode([
            'id' => 'evt_acc_browserless',
            'type' => 'checkout.session.completed',
            'created' => now()->getTimestamp(),
            'data' => ['object' => [
                'id' => $account['subscription']->provider_checkout_session_id,
                'object' => 'checkout.session',
                'client_reference_id' => $account['subscription']->uid,
                'subscription' => $providerSubscriptionId,
            ]],
        ]);
        $this->postPlatformWebhook($body, ['Stripe-Signature' => $this->stripe->validSignature])->assertOk();

        $assignment = WorkspacePlanAssignment::query()->sole();
        $this->assertFalse((bool) $assignment->is_complimentary);
        $this->assertSame(BusinessStatus::Active, Business::query()->sole()->status);
        $this->assertSame(1, $this->identityCounts($account['user'])['plan_assignments']);
    }

    // =================================================================
    // Cancel and resume
    // =================================================================

    public function test_cancel_and_resume_keep_access_and_never_touch_identity(): void
    {
        $account = $this->completeJourney('growth');
        $workspace = $account['workspace'];
        $before = $this->identityCounts($account['user']);

        $this->post(route('customer.workspaces.plan.cancel', [$workspace->uid]), ['confirm' => '1'])->assertRedirect();

        $this->assertTrue((bool) $account['subscription']->refresh()->cancel_at_period_end);
        $this->get(route('customer.workspaces.plan.show', [$workspace->uid]))->assertOk()->assertSee('Keep my subscription');
        $this->get(route('user.home'))->assertOk();
        $this->assertSame(CustomerAccountAccessState::Usable, $this->resolver()->resolve($workspace)->state, 'Paid time is never taken away.');

        $this->post(route('customer.workspaces.plan.resume', [$workspace->uid]))->assertRedirect();
        $this->assertFalse((bool) $account['subscription']->refresh()->cancel_at_period_end);

        $this->assertSame($before, $this->identityCounts($account['user']));
    }

    // =================================================================
    // Failed renewal -> grace -> locked -> recovered, through the shell
    // =================================================================

    public function test_a_failed_renewal_walks_grace_then_locked_then_recovery_and_the_shell_follows_the_resolver(): void
    {
        $account = $this->completeJourney('growth');
        $workspace = $account['workspace'];
        $fixture = $this->webhookFixture($account, $account['provider_subscription_id']);
        $before = $this->identityCounts($account['user']);

        // Failed renewal: Grace keeps everything working, with a billing prompt.
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::PastDue);
        $this->deliverAsStripe('invoice.payment_failed', $fixture, ['event_id' => 'evt_acc_fail'])->assertOk();

        $decision = $this->resolver()->resolve($workspace->fresh());
        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertSame('plan_grace', $decision->reason);
        $this->get(route('user.home'))->assertOk();
        $this->get(route('customer.workspaces.businesses.people.index', [$workspace->uid, $account['business']->uid]))->assertOk();

        // The grace window lapses and the canonical sweep locks the account.
        $this->travel(EntitlementManager::GRACE_PERIOD_DAYS + 1)->days();

        try {
            $this->artisan('workspaces:advance-account-lifecycle')->assertExitCode(0);

            $locked = $this->resolver()->resolve($workspace->fresh());
            $this->assertSame(CustomerAccountAccessState::Locked, $locked->state);
            $this->assertSame('plan_locked', $locked->reason);

            // Customer gate: Home and Business modules bounce to the locked screen…
            $this->get(route('user.home'))->assertRedirect(route('customer.account-locked.show'));
            $this->get(route('customer.workspaces.businesses.people.index', [$workspace->uid, $account['business']->uid]))
                ->assertRedirect(route('customer.account-locked.show'));
            $this->getJson(route('customer.workspaces.businesses.people.index', [$workspace->uid, $account['business']->uid]))
                ->assertForbidden()->assertJson(['reason' => 'plan_locked']);

            // …which names the real recovery route…
            $this->get(route('customer.account-locked.show'))
                ->assertOk()
                ->assertSee('Account locked')
                ->assertSee(route('customer.workspaces.plan.show', [$workspace->uid]), false);

            // …and that recovery route stays reachable while locked.
            $this->get(route('customer.workspaces.plan.show', [$workspace->uid]))->assertOk();

            // A confirmed payment restores access immediately.
            $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::Active);
            $this->deliverAsStripe('invoice.paid', $fixture, ['event_id' => 'evt_acc_paid'])->assertOk();

            $this->assertSame(CustomerAccountAccessState::Usable, $this->resolver()->resolve($workspace->fresh())->state);
            $this->get(route('user.home'))->assertOk();
            $this->get(route('customer.account-locked.show'))->assertRedirect(route('user.home'));
        } finally {
            $this->travelBack();
        }

        $this->assertSame($before, $this->identityCounts($account['user']), 'Grace, lock and recovery never create or remove a tenant.');
    }

    // =================================================================
    // End of service -> resubscribe on the SAME account
    // =================================================================

    public function test_end_of_service_locks_and_resubscribing_reuses_the_same_workspace_business_and_location(): void
    {
        $account = $this->completeJourney('core');
        $workspace = $account['workspace'];
        $fixture = $this->webhookFixture($account, $account['provider_subscription_id']);

        $this->post(route('customer.workspaces.plan.cancel', [$workspace->uid]), ['confirm' => '1']);
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::Canceled);
        $this->deliverAsStripe('customer.subscription.deleted', $fixture, ['event_id' => 'evt_acc_end'])->assertOk();

        $this->assertSame(CustomerAccountAccessState::Locked, $this->resolver()->resolve($workspace->fresh())->state);
        $this->get(route('user.home'))->assertRedirect(route('customer.account-locked.show'));

        $before = $this->identityCounts($account['user']);

        // The way back from the locked screen: a new provider subscription on
        // the same account.
        $this->post(route('customer.workspaces.plan.resubscribe', [$workspace->uid]), ['tier' => 'growth', 'confirm' => '1'])
            ->assertRedirectContains('checkout.stripe.test');

        $subscription = PlatformSubscription::query()->where('workspace_id', $workspace->id)->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        $this->get(route('customer.workspaces.plan.resubscribe-return', [$workspace->uid]))->assertRedirect();

        $this->assertSame(CustomerAccountAccessState::Usable, $this->resolver()->resolve($workspace->fresh())->state);
        $this->get(route('user.home'))->assertOk();
        $this->assertSame(WorkspacePlanTier::Growth, app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace->fresh())->tier);
        $this->assertSame($before, $this->identityCounts($account['user']), 'Resubscribing never creates a second Workspace, Business or Location.');
    }
}
