<?php

namespace Tests\Feature\V1Acceptance\AccountShell\Concerns;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;

/**
 * V1 FINAL ACCEPTANCE 01 — shared drivers for the account / signup / shell
 * journeys.
 *
 * THESE DRIVE THE REAL INTEGRATED PATH: the guest `/register` form, the Stripe
 * test seam (`FakePlatformStripeGateway`, the same fake the lane-A suites use —
 * no live provider is ever called), the checkout-return endpoint, the signed
 * email-verification link and the rendered customer shell. Nothing here builds
 * a tenant through a repository fixture, because a repository fixture is
 * exactly what hid the Draft-Business defect this lane found: `tenant()`
 * force-writes `status = active`, which the real signup never did.
 */
trait DrivesAccountJourneys
{
    use CreatesPlatformSubscriptions;

    protected const PASSWORD = 'correct-horse-battery';

    protected function bootJourney(): void
    {
        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();

        // A real install seeds every AppConfig row; the test database only has
        // the three the shared fixture creates. `VerificationController::
        // verificationVerify()` reads this one unguarded, so without it the
        // signed-link step 500s for a reason that has nothing to do with the
        // product.
        AppConfig::query()->firstOrCreate(['setting' => 'user_registration_notification_email'], ['value' => '0']);

        // The copied `.env` points at a real SMTP/sendmail transport and
        // phpunit.xml only overrides the legacy MAIL_DRIVER key. No journey may
        // ever attempt a real delivery, so the mailer is pinned to `array`.
        config([
            'mail.default' => 'array',
            'account.can_register' => true,
            'account.verify_account' => true,
        ]);

        $this->sellableTier(WorkspacePlanTier::Core, trialDays: 7, price: '97.00');
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 14, price: '297.00');
        $this->sellableTier(WorkspacePlanTier::Agency, trialDays: null, price: '497.00');
    }

    /** @return array<string, string> */
    protected function signupForm(string $tier, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Pat',
            'last_name' => 'Rivera',
            'email' => $tier . '-' . uniqid() . '@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'business_name' => 'Harbor Lane ' . ucfirst($tier),
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'UTC',
            'currency_code' => 'USD',
            'tier' => $tier,
        ], $overrides);
    }

    /** Back to a genuinely anonymous visitor. */
    protected function signOut(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    /**
     * Step 1 of the journey: an anonymous visitor submits the signup form.
     *
     * @return array{user: User, customer: Customer, workspace: Workspace, business: Business, subscription: PlatformSubscription, form: array<string, string>}
     */
    protected function registerOnTier(string $tier, array $overrides = []): array
    {
        $this->signOut();

        $form = $this->signupForm($tier, $overrides);

        $this->post(route('register'), $form)->assertRedirectContains('checkout.stripe.test');

        return $this->accountOf($form['email']) + ['form' => $form];
    }

    /**
     * @return array{user: User, customer: Customer, workspace: Workspace, business: Business, subscription: PlatformSubscription}
     */
    protected function accountOf(string $email): array
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $workspace = Workspace::query()->where('owner_user_id', $user->id)->sole();

        return [
            'user' => $user,
            'customer' => Customer::query()->where('user_id', $user->id)->firstOrFail(),
            'workspace' => $workspace,
            'business' => Business::query()->where('workspace_id', $workspace->id)->sole(),
            'subscription' => PlatformSubscription::query()->where('workspace_id', $workspace->id)->sole(),
        ];
    }

    /**
     * The customer pays on Stripe's hosted page and the browser returns. Returns
     * the provider subscription id (needed to deliver webhooks for it).
     */
    protected function payAndReturn(PlatformSubscription $subscription): string
    {
        $providerSubscriptionId = $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        $this->get(route('signup.success'))->assertRedirect(route('user.home'));

        return $providerSubscriptionId;
    }

    /** Follows the real signed verification link the notification carries. */
    protected function verifyEmailThroughSignedLink(User $user): void
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('user.home'));

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    /**
     * The fixture shape `deliver()`/`webhookBody()` expect, for an account that
     * registered and paid through the real journey.
     *
     * @param  array{customer: Customer, workspace: Workspace, subscription: PlatformSubscription}  $account
     * @return array{customer: Customer, workspace: Workspace, subscription: PlatformSubscription, provider_subscription_id: string}
     */
    protected function webhookFixture(array $account, string $providerSubscriptionId): array
    {
        return [
            'customer' => $account['customer'],
            'workspace' => $account['workspace'],
            'subscription' => $account['subscription']->refresh(),
            'provider_subscription_id' => $providerSubscriptionId,
        ];
    }

    /**
     * Delivers a signed lane-A webhook the way Stripe does: from a client that
     * holds NO customer session. A real webhook never carries the customer's
     * cookie, so it must not be judged by `CustomerAccountAccessGate` — which a
     * signed-in-but-locked customer's own browser session would otherwise trip,
     * redirecting the very `invoice.paid` that is meant to unlock them.
     *
     * The browser session is restored afterwards.
     *
     * @param  array{customer: Customer, workspace: Workspace, subscription: PlatformSubscription, provider_subscription_id: string}  $fixture
     */
    protected function deliverAsStripe(string $eventType, array $fixture, array $overrides = [])
    {
        $user = Auth::user();

        $this->signOut();

        try {
            return $this->deliver($eventType, $fixture, $overrides);
        } finally {
            if ($user !== null) {
                $this->actingAs($user);
            }
        }
    }

    /**
     * Register -> pay -> verify -> signed in on Home: a finished, signed-in
     * account on `$tier`, reached only through the product's own routes.
     *
     * @return array{user: User, customer: Customer, workspace: Workspace, business: Business, subscription: PlatformSubscription, provider_subscription_id: string, form: array<string, string>}
     */
    protected function completeJourney(string $tier, array $overrides = []): array
    {
        $account = $this->registerOnTier($tier, $overrides);
        $providerSubscriptionId = $this->payAndReturn($account['subscription']);
        $this->verifyEmailThroughSignedLink($account['user']);

        $fresh = $this->accountOf($account['form']['email']);

        return $fresh + ['provider_subscription_id' => $providerSubscriptionId, 'form' => $account['form']];
    }

    /**
     * Whole-database identity counts for one owner, so a refresh, a retry or a
     * resumed checkout is provably not a second tenant.
     *
     * @return array<string, int>
     */
    protected function identityCounts(User $user): array
    {
        $workspaceIds = Workspace::query()->where('owner_user_id', $user->id)->pluck('id');
        $businessIds = Business::query()->whereIn('workspace_id', $workspaceIds)->pluck('id');

        return [
            'users' => User::query()->where('email', $user->email)->count(),
            'workspaces' => $workspaceIds->count(),
            'businesses' => $businessIds->count(),
            'locations' => BusinessLocation::query()->whereIn('business_id', $businessIds)->count(),
            'primary_locations' => BusinessLocation::query()->whereIn('business_id', $businessIds)->where('is_primary', true)->count(),
            'platform_subscriptions' => PlatformSubscription::query()->whereIn('workspace_id', $workspaceIds)->count(),
            'plan_assignments' => DB::table('workspace_plan_assignments')->whereIn('workspace_id', $workspaceIds)->count(),
            'wallets' => DB::table('business_usage_wallets')->whereIn('business_id', $businessIds)->count(),
            'payer_assignments' => DB::table('business_payer_assignments')->whereIn('business_id', $businessIds)->count(),
        ];
    }
}
