<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionEventState;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\Money\StripeMinorUnits;
use App\Library\PlatformBilling\PlatformPriceVerifier;
use App\Library\PlatformBilling\V1SignupManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use App\Repositories\Eloquent\EloquentWorkspacePlanAssignmentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Implementation Contract 21 — the five final-review corrections.
 *
 * Each block exists because review found a concrete production defect, not
 * because the shape looked wrong.
 */
class PlatformBillingCorrectionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        config(['account.can_register' => true]);
    }

    /** @return array<string, string> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Pat',
            'last_name' => 'Rivera',
            'email' => 'pat' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'business_name' => 'Harbor Lane Studios',
            'industry' => 'photo_booth_service',
            // Deliberately NOT the US: the resume defect overwrote exactly this.
            'country_code' => 'LT',
            'timezone' => 'Europe/Vilnius',
            'tier' => 'growth',
        ], $overrides);
    }

    /** @return array{business: array<string, mixed>, location: array<string, mixed>} */
    private function snapshotIdentity(): array
    {
        return [
            'business' => (array) DB::table('businesses')->orderBy('id')->first(),
            'location' => (array) DB::table('business_locations')->orderBy('id')->first(),
        ];
    }

    // =================================================================
    // 1 — RESUME MUST NEVER MUTATE BUSINESS / LOCATION DATA
    // =================================================================

    public function test_resuming_the_same_tier_changes_no_business_or_location_field(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register'), $this->form());
        $before = $this->snapshotIdentity();

        $this->assertSame('LT', (string) $before['business']['country_code']);
        $this->assertSame('Europe/Vilnius', (string) $before['business']['timezone']);

        $this->get(route('signup.cancelled'));
        $this->post(route('signup.resume'), ['tier' => 'growth'])->assertRedirectContains('checkout.stripe.test');

        $this->assertSame($before, $this->snapshotIdentity(),
            'Resume touches the pending checkout and nothing else.');
    }

    public function test_resuming_a_different_tier_changes_no_business_or_location_field(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->sellableTier(WorkspacePlanTier::Agency, price: '497.00');
        $this->post(route('register'), $this->form());
        $before = $this->snapshotIdentity();

        $this->get(route('signup.cancelled'));
        $this->post(route('signup.resume'), ['tier' => 'agency'])->assertRedirectContains('checkout.stripe.test');

        $this->assertSame($before, $this->snapshotIdentity());
        $this->assertSame('LT', (string) $this->snapshotIdentity()['business']['country_code'],
            'The old code rewrote this to US from the resume form defaults.');
    }

    public function test_resuming_never_replaces_the_chosen_niche(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->sellableTier(WorkspacePlanTier::Core, price: '97.00');
        $this->post(route('register'), $this->form());

        $this->get(route('signup.cancelled'));
        $this->post(route('signup.resume'), ['tier' => 'core']);

        $this->assertSame('photo_booth_service', Business::query()->sole()->industry->value,
            'The old code replaced the niche with `other`.');
    }

    public function test_resuming_creates_no_second_workspace_business_or_location(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->sellableTier(WorkspacePlanTier::Agency, price: '497.00');
        $this->post(route('register'), $this->form());

        $this->post(route('signup.resume'), ['tier' => 'agency']);
        $this->post(route('signup.resume'), ['tier' => 'growth']);

        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(1, Business::query()->count());
        $this->assertSame(1, BusinessLocation::query()->count());
        $this->assertSame(1, PlatformSubscription::query()->count());
    }

    public function test_an_already_subscribed_workspace_cannot_use_the_resume_path(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $catalog = $this->sellableTier(WorkspacePlanTier::Agency, price: '497.00');

        try {
            app(V1SignupManager::class)->restartCheckout(
                $fixture['customer'], $catalog, 'https://a', 'https://b',
            );
            $this->fail('A subscriber changes plan through §10.2, not through signup resume.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::ALREADY_SUBSCRIBED, $e->reason);
        }
    }

    // =================================================================
    // 2 — CHECKOUT ATTEMPT IDENTITY
    // =================================================================

    public function test_retrying_the_same_tier_reuses_one_attempt_and_one_session(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register'), $this->form());
        $subscription = PlatformSubscription::query()->sole();
        $attempt = (string) $subscription->checkout_attempt_uid;

        $this->post(route('signup.resume'), ['tier' => 'growth']);

        $this->assertSame($attempt, (string) $subscription->refresh()->checkout_attempt_uid,
            'A retry of the same request keeps the same durable attempt.');
        $this->assertCount(1, $this->stripe->sessions);
        $this->assertSame([], $this->stripe->callsOf('expireCheckoutSession'));
    }

    public function test_switching_tier_retires_the_old_session_and_mints_a_new_attempt(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->sellableTier(WorkspacePlanTier::Agency, price: '497.00');
        $this->post(route('register'), $this->form());

        $subscription = PlatformSubscription::query()->sole();
        $firstAttempt = (string) $subscription->checkout_attempt_uid;
        $firstSession = (string) $subscription->provider_checkout_session_id;

        $this->post(route('signup.resume'), ['tier' => 'agency']);
        $subscription->refresh();

        $this->assertNotSame($firstAttempt, (string) $subscription->checkout_attempt_uid,
            'A different Price is a NEW request and needs a NEW idempotency key.');
        $this->assertSame('price_fake_agency', (string) $subscription->checkout_attempt_price_id);
        $this->assertSame([$firstSession], array_column(
            array_column($this->stripe->callsOf('expireCheckoutSession'), 'args'), 'session'));
        $this->assertSame('expired', $this->stripe->sessions[$firstSession]['status']);

        // The invariant: never two payable sessions.
        $this->assertCount(1, $this->stripe->payableSessionIds());
        $this->assertSame('price_fake_agency',
            $this->stripe->sessions[$subscription->provider_checkout_session_id]['price']);
    }

    public function test_an_expired_session_permits_a_fresh_attempt(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register'), $this->form());
        $subscription = PlatformSubscription::query()->sole();
        $session = (string) $subscription->provider_checkout_session_id;

        // Stripe expires an untouched session on its own after a while.
        $this->stripe->sessions[$session]['status'] = 'expired';

        $this->post(route('signup.resume'), ['tier' => 'growth'])->assertRedirectContains('checkout.stripe.test');

        $this->assertNotSame($session, (string) $subscription->refresh()->provider_checkout_session_id);
        $this->assertCount(1, $this->stripe->payableSessionIds());
    }

    public function test_a_completed_session_never_starts_a_second_subscription(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register'), $this->form());
        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        $this->post(route('signup.resume'), ['tier' => 'growth'])->assertRedirect(route('signup.plan'));

        $this->assertCount(1, $this->stripe->sessions, 'No second checkout was opened.');
        $this->assertCount(1, $this->stripe->subscriptions, 'And no second subscription exists.');
    }

    public function test_an_uncertain_create_response_re_drives_the_same_attempt_key(): void
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $fixture = $this->unassignedWorkspace();
        $manager = app(\App\Library\PlatformBilling\PlatformSubscriptionManager::class);

        $first = $manager->startCheckout($fixture['workspace'], $catalog, 'o@example.test', 'https://a', 'https://b');
        // Simulate the response never arriving: the row has no session id.
        PlatformSubscription::query()->sole()->forceFill(['provider_checkout_session_id' => null])->save();

        $second = $manager->startCheckout($fixture['workspace']->fresh(), $catalog, 'o@example.test', 'https://a', 'https://b');

        $this->assertSame($first->sessionId, $second->sessionId,
            'The same key returns the original session rather than opening a second.');
        $keys = array_column(array_column($this->stripe->callsOf('createSubscriptionCheckout'), 'args'), 'idempotency_key');
        $this->assertSame([$keys[0], $keys[0]], $keys);
    }

    public function test_the_fake_gateway_rejects_one_key_carrying_different_parameters(): void
    {
        // Guards the guard: if this ever stops throwing, correction 2's tests
        // would pass against code Stripe itself would reject.
        $this->stripe->definePrice('price_a');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stripe idempotency violated');

        $this->stripe->createSubscriptionCheckout('price_a', 'ref', 'key-1', 'https://a', 'https://b', 'e@example.test', null);
        $this->stripe->createSubscriptionCheckout('price_b', 'ref', 'key-1', 'https://a', 'https://b', 'e@example.test', null);
    }

    // =================================================================
    // 3 — STRIPE PRICE MUST BE VERIFIED, NOT FORMAT-CHECKED
    // =================================================================

    private function signInAsPlatformOwner(): User
    {
        $admin = User::query()->findOrFail($this->platformAdminId());
        $admin->email_verified_at = now();
        $admin->save();
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);

        return $admin;
    }

    /** @return array<string, mixed> */
    private function ownerConfig(array $overrides = []): array
    {
        return array_merge([
            'price' => '297.00',
            'currency_id' => $this->fixtureCurrencyId(),
            'billing_cycle' => 'monthly',
            'available_for_signup' => '1',
            'provider_price_id' => 'price_underTest01',
            'reason' => 'Launch pricing.',
        ], $overrides);
    }

    private function assertPriceRefused(array $priceFacts, array $configOverrides = []): void
    {
        $this->signInAsPlatformOwner();
        $this->stripe->definePrice('price_underTest01', $priceFacts);

        $this->post(route('admin.platform-billing.update', ['growth']), $this->ownerConfig($configOverrides))
            ->assertSessionHasErrors('provider_price_id');

        // The critical part: NOTHING was written.
        $catalog = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $this->assertNull($catalog->provider_price_id);
        $this->assertNull($catalog->price);
        $this->assertSame(0, DB::table('workspace_plan_catalog_pricing_changes')->count());
    }

    public function test_a_nonexistent_price_is_refused(): void
    {
        $this->signInAsPlatformOwner();
        // Syntactically valid, but no such Price on this account.
        $this->post(route('admin.platform-billing.update', ['growth']), $this->ownerConfig())
            ->assertSessionHasErrors('provider_price_id');

        $this->assertNull(WorkspacePlanCatalog::query()->where('tier', 'growth')->value('price'));
        $this->assertSame(0, DB::table('workspace_plan_catalog_pricing_changes')->count());
    }

    public function test_a_connected_account_price_cannot_validate_through_the_platform_gateway(): void
    {
        // A lane-B / lane-C Price lives on a CONNECTED account and is simply
        // not visible to the platform key — so it is not retrievable, which is
        // the cross-lane boundary doing its job.
        $this->signInAsPlatformOwner();

        try {
            app(PlatformPriceVerifier::class)->mismatches('price_onAConnectedAcct', '297.00', 'USD', 'monthly');
            $this->fail('A connected-account Price must not verify here.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::PRICE_NOT_RETRIEVABLE, $e->reason);
        }
    }

    public function test_an_inactive_price_is_refused(): void
    {
        $this->assertPriceRefused(['active' => false, 'unit_amount' => 29700]);
    }

    public function test_a_one_time_price_is_refused(): void
    {
        $this->assertPriceRefused(['recurring' => false, 'interval' => null, 'interval_count' => null, 'unit_amount' => 29700]);
    }

    public function test_a_wrong_currency_price_is_refused(): void
    {
        $this->assertPriceRefused(['currency' => 'EUR', 'unit_amount' => 29700]);
    }

    public function test_a_wrong_amount_price_is_refused(): void
    {
        $this->assertPriceRefused(['unit_amount' => 9900]);
    }

    public function test_a_wrong_interval_price_is_refused(): void
    {
        $this->assertPriceRefused(['unit_amount' => 29700, 'interval' => 'year']);
    }

    public function test_an_interval_count_other_than_one_is_refused(): void
    {
        $this->assertPriceRefused(['unit_amount' => 29700, 'interval_count' => 3]);
    }

    public function test_a_live_mode_price_is_refused_while_the_platform_is_in_test_mode(): void
    {
        $this->assertPriceRefused(['unit_amount' => 29700, 'livemode' => true]);
    }

    public function test_an_exactly_matching_price_is_accepted(): void
    {
        $this->signInAsPlatformOwner();
        $this->stripe->definePrice('price_underTest01', [
            'currency' => 'USD', 'unit_amount' => 29700, 'interval' => 'month', 'interval_count' => 1,
        ]);

        $this->post(route('admin.platform-billing.update', ['growth']), $this->ownerConfig())
            ->assertSessionHasNoErrors();

        $catalog = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $this->assertSame('price_underTest01', (string) $catalog->provider_price_id);
        $this->assertSame('297.00', (string) $catalog->price);
        $this->assertSame(1, DB::table('workspace_plan_catalog_pricing_changes')->count());
    }

    public function test_a_yearly_plan_requires_a_yearly_price(): void
    {
        $this->signInAsPlatformOwner();
        $this->stripe->definePrice('price_underTest01', [
            'currency' => 'USD', 'unit_amount' => 29700, 'interval' => 'year', 'interval_count' => 1,
        ]);

        $this->post(route('admin.platform-billing.update', ['growth']), $this->ownerConfig(['billing_cycle' => 'yearly']))
            ->assertSessionHasNoErrors();

        $this->assertSame('yearly', (string) WorkspacePlanCatalog::query()->where('tier', 'growth')->value('billing_cycle'));
    }

    public function test_minor_units_follow_stripes_own_zero_decimal_rules(): void
    {
        // Two-decimal, the ordinary case.
        $this->assertSame(29700, StripeMinorUnits::toMinor('297.00', 'USD'));
        $this->assertSame(9900, StripeMinorUnits::toMinor('99', 'EUR'));

        // Zero-decimal: "to charge 500 JPY, provide an amount value of 500".
        $this->assertSame(500, StripeMinorUnits::toMinor('500', 'JPY'));
        $this->assertSame(29700, StripeMinorUnits::toMinor('29700', 'KRW'));
        $this->assertTrue(StripeMinorUnits::isZeroDecimal('VND'));

        // The documented special cases are two-decimal for CHARGES.
        $this->assertFalse(StripeMinorUnits::isZeroDecimal('UGX'));
        $this->assertFalse(StripeMinorUnits::isZeroDecimal('ISK'));
        $this->assertFalse(StripeMinorUnits::isZeroDecimal('HUF'));

        // More precision than the currency can carry is a mismatch, not a rounding.
        $this->assertNull(StripeMinorUnits::toMinor('297.005', 'USD'));
        $this->assertNull(StripeMinorUnits::toMinor('500.50', 'JPY'));
        $this->assertNull(StripeMinorUnits::toMinor('not-a-number', 'USD'));

        // Float arithmetic would give 296 here.
        $this->assertSame(297, StripeMinorUnits::toMinor('2.97', 'USD'));
    }

    // =================================================================
    // 4 — WEBHOOK ACTIVATION MUST RECOVER AFTER POST-FINALIZER FAILURE
    // =================================================================

    /**
     * Reproduces the durability hole exactly.
     *
     * ATTEMPT 1 is modelled by driving the finalizer directly: provider truth
     * is stored, and activation does NOT run — which is precisely the state a
     * transient failure between the two leaves behind.
     *
     * ATTEMPT 2 is the real queue retry. Its finalizer has nothing new to say,
     * and the OLD code activated only on APPLIED, so a paid Workspace stayed
     * unassigned forever.
     */
    public function test_activation_recovers_on_a_later_attempt_after_a_post_finalizer_failure(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 14);
        $this->post(route('register'), $this->form());
        $subscription = PlatformSubscription::query()->sole();
        $providerSubscriptionId = $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        // ATTEMPT 1 — finalizer succeeds, activation never happens.
        app(\App\Library\PlatformBilling\PlatformSubscriptionManager::class)
            ->applyProviderSubscription($subscription, $providerSubscriptionId, 'evt_attempt_one', now());

        $this->assertSame(PlatformSubscriptionStatus::Trialing, $subscription->refresh()->status,
            'The finalizer succeeded…');
        $this->assertSame(0, WorkspacePlanAssignment::query()->count(), '…but activation did not.');

        // ATTEMPT 2 — the same event, delivered again and processed by the job.
        [$body, $headers] = $this->webhookBody(
            'customer.subscription.updated', $providerSubscriptionId, null, 'evt_attempt_two', null,
            (string) $subscription->uid,
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();

        $this->assertSame(1, WorkspacePlanAssignment::query()->count(),
            'The retry activates, because activation is gated on the ROW, not on this attempt changing it.');
        $this->assertFalse((bool) WorkspacePlanAssignment::query()->sole()->is_complimentary);
        $this->assertNotNull(WorkspacePlanAssignment::query()->sole()->trial_ends_at);
    }

    public function test_an_out_of_order_event_still_converges_an_already_confirmed_subscription(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register'), $this->form());
        $subscription = PlatformSubscription::query()->sole();
        $providerSubscriptionId = $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        // Provider truth is already stored, with a RECENT event stamp…
        app(\App\Library\PlatformBilling\PlatformSubscriptionManager::class)
            ->applyProviderSubscription($subscription, $providerSubscriptionId, 'evt_new', now());
        $this->assertSame(0, WorkspacePlanAssignment::query()->count());

        // …and an OLDER event arrives late. It must not move state backwards,
        // but the account must still end up activated rather than stranded.
        [$body, $headers] = $this->webhookBody(
            'customer.subscription.updated', $providerSubscriptionId, null, 'evt_old',
            now()->subHour()->getTimestamp(), (string) $subscription->uid,
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();

        // Whether the late event is ignored as out-of-order or simply re-applies
        // the same provider truth, the two properties that matter both hold:
        // canonical state did not move backwards, and the account converged.
        $this->assertSame(PlatformSubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(1, WorkspacePlanAssignment::query()->count(),
            'A late event must still leave a paid Workspace activated, never stranded.');
    }

    public function test_repeated_retries_never_create_two_assignments(): void
    {
        $paid = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $event = PlatformSubscriptionEvent::query()->first();

        if ($event === null) {
            $this->deliver('customer.subscription.updated', $paid);
            $event = PlatformSubscriptionEvent::query()->sole();
        }

        foreach (range(1, 4) as $ignored) {
            (new \App\Jobs\PlatformBilling\ProcessPlatformSubscriptionEvent((int) $event->id))->handle(
                app(\App\Library\PlatformBilling\PlatformSubscriptionManager::class),
                app(V1SignupManager::class),
            );
        }

        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
    }

    public function test_an_identity_mismatch_still_never_activates(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register'), $this->form());
        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        // The row already believes it owns a different provider subscription.
        $subscription->forceFill(['provider_subscription_id' => 'sub_somethingElse'])->save();

        [$body, $headers] = $this->webhookBody(
            'customer.subscription.updated', 'sub_fake000002', null, 'evt_mismatch', null, (string) $subscription->uid,
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();

        $this->assertSame(0, WorkspacePlanAssignment::query()->count(),
            'An event we cannot prove belongs to this subscription never hands it a plan.');
    }

    public function test_the_webhook_job_has_a_bounded_retry_policy(): void
    {
        $job = new \App\Jobs\PlatformBilling\ProcessPlatformSubscriptionEvent(1);

        $this->assertSame(3, $job->tries, 'Money events must not be one-shot.');
        $this->assertSame([10, 60], $job->backoff());
    }

    public function test_a_duplicate_delivery_redispatches_only_an_exhausted_event(): void
    {
        $paid = $this->subscribedWorkspace();
        $this->deliver('customer.subscription.updated', $paid, ['event_id' => 'evt_dup_state']);
        $event = PlatformSubscriptionEvent::query()->where('provider_event_id', 'evt_dup_state')->sole();
        $this->assertSame(PlatformSubscriptionEventState::Processed, $event->state);

        // A processed duplicate is left strictly alone.
        $this->deliver('customer.subscription.updated', $paid, ['event_id' => 'evt_dup_state'])->assertOk();
        $this->assertSame(1, (int) $event->refresh()->attempts);

        // An exhausted one is picked up again, turning Stripe's own retry into
        // our recovery.
        DB::table('platform_subscription_events')->where('id', $event->id)
            ->update(['state' => PlatformSubscriptionEventState::Failed->value]);
        $this->deliver('customer.subscription.updated', $paid, ['event_id' => 'evt_dup_state'])->assertOk();

        $this->assertSame(2, (int) $event->refresh()->attempts);
    }

    // =================================================================
    // 5 — GUEST BOUNDARY, AND A TRUTHFUL PROVIDER MODE
    // =================================================================

    public function test_an_authenticated_user_cannot_reach_the_anonymous_signup(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->authenticateAs($fixture['customer']);

        $usersBefore = User::query()->count();

        $this->get(route('register'))->assertRedirect();
        $this->post(route('register'), $this->form(['email' => 'someone-else@example.test']))->assertRedirect();

        $this->assertSame($usersBefore, User::query()->count(),
            'A signed-in user cannot silently create a second account and be switched into it.');
        $this->assertNull(User::query()->where('email', 'someone-else@example.test')->first());
    }

    public function test_the_authenticated_re_entry_routes_stay_authenticated(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register'), $this->form());

        // Still signed in from registration — these are the supported path.
        $this->get(route('signup.plan'))->assertOk();
        $this->post(route('signup.resume'), ['tier' => 'growth'])->assertRedirectContains('checkout.stripe.test');
    }

    public function test_provider_mode_is_unknown_rather_than_test_when_the_key_is_missing(): void
    {
        $gateway = new \App\Library\PlatformBilling\StripeApiPlatformGateway();

        config(['services.stripe.secret' => '', 'services.stripe.platform_subscription_webhook.secret' => '']);
        $this->assertNull($gateway->configurationStatus()['mode'],
            'A missing key is NOT test mode; saying so would tell an operator the opposite of the truth.');
        $this->assertFalse($gateway->configurationStatus()['configured']);

        config(['services.stripe.secret' => 'not-a-stripe-key']);
        $this->assertNull($gateway->configurationStatus()['mode']);

        config(['services.stripe.secret' => 'sk_test_abcdef123456']);
        $this->assertSame('test', $gateway->configurationStatus()['mode']);

        config(['services.stripe.secret' => 'sk_live_abcdef123456']);
        $this->assertSame('live', $gateway->configurationStatus()['mode']);
    }

    public function test_the_owner_surface_says_not_configured_rather_than_test(): void
    {
        $this->signInAsPlatformOwner();
        $this->stripe->configured = false;
        $this->stripe->mode = null;

        $this->get(route('admin.platform-billing.index'))
            ->assertOk()
            ->assertSee('Not configured');
    }
}
