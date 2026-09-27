<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\NumberLifecycleEventType;
use App\Enums\Messaging\NumberRenewalOutcome;
use App\Library\Messaging\Exceptions\NumberReleaseNotEligibleException;
use App\Library\Messaging\NumberLifecycleManager;
use App\Library\Messaging\PortOutRequestManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingNumberLifecycleEvent;
use App\Models\Currency;
use App\Models\User;
use App\Notifications\Messaging\NumberRenewalWarningNotification;
use App\Notifications\Messaging\NumberReleaseNoticeNotification;
use App\Notifications\Messaging\NumberSuspendedNotification;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Phone Numbers + A2P lane — messaging contract §13.2/§13.3: the number
 * renewal/warning/suspension/grace/release lifecycle.
 *
 * No real Telnyx call, purchase, release, or charge anywhere here — every
 * "funded"/"unfunded" scenario uses a test-only fixture rate for
 * NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL, exactly mirroring
 * TelnyxProvisioningAdapterTest's own established fundWallet() pattern;
 * every notification assertion uses Notification::fake() and inspects
 * what the job would have sent, never a real mail transport.
 */
class NumberLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // Mirrors TelnyxProvisioningAdapterTest::setUp() exactly — no
        // currency is seeded by the base migrations, and fundWallet()
        // below needs exactly one active currency to resolve the
        // Business's wallet against.
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function manager(): NumberLifecycleManager
    {
        return app(NumberLifecycleManager::class);
    }

    private function actorId(): int
    {
        return User::create([
            'first_name' => 'Ops', 'last_name' => 'Admin',
            'email' => 'lifecycle-actor-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    /**
     * Mirrors TelnyxProvisioningAdapterTest::fundWallet() exactly — a
     * test-only fixture rate for this feature key, never claiming or
     * implying a real approved retail rate.
     */
    private function fundWallet(Business $business, int $availableMicro): void
    {
        $wallet = app(UsageWalletManager::class);
        $wallet->initializeWalletForNewBusiness($business->id);

        $actorId = $this->actorId();
        $currencyId = Currency::query()->first()->id;

        app(UsageMeterRepository::class)->create([
            'meter_key' => NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL,
            'feature_key' => PlatformFeature::MessagingTransport->value,
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'Number lifecycle test fixture meter (test-only).',
            'updated_by_user_id' => $actorId,
        ]);

        $wallet->setActiveRate(NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL, '1000000', '500000', 'per month', $currencyId, $actorId, 'Test rate activation.');
        $wallet->activateMetering(NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL, $actorId, 'Test metering activation.');

        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => $availableMicro]);
    }

    private function billingContact(Business $business, string $email): void
    {
        app(BillingProfileManager::class)->updateBillingContact($business, null, 'Test Contact', $email, true, (int) $business->customer_id);
    }

    private function activeNumberDueOn(Business $business, Carbon $nextRenewalAt): BusinessMessagingNumber
    {
        $number = $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber());
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['next_renewal_at' => $nextRenewalAt]);

        return $number->fresh();
    }

    // =================================================================
    // attemptRenewal() — NotConfigured is never a failure.
    // =================================================================

    public function test_attempt_renewal_reports_not_configured_when_no_rate_exists(): void
    {
        $business = $this->makeBusiness();
        $number = $this->activeNumberDueOn($business, now()->subDay());

        $outcome = $this->manager()->attemptRenewal($number);

        $this->assertSame(NumberRenewalOutcome::NotConfigured, $outcome);
        $this->assertSame(BusinessMessagingNumberStatus::Active, $number->fresh()->status, 'NotConfigured must never suspend a number.');
        $this->assertSame(0, BusinessMessagingNumberLifecycleEvent::where('business_messaging_number_id', $number->id)->count());
    }

    // =================================================================
    // attemptRenewal() — a real, funded renewal.
    // =================================================================

    public function test_attempt_renewal_succeeds_and_advances_next_renewal_at(): void
    {
        $business = $this->makeBusiness();
        $this->fundWallet($business, 100_000_000);
        $dueAt = now()->subDay();
        $number = $this->activeNumberDueOn($business, $dueAt);

        $outcome = $this->manager()->attemptRenewal($number);

        $this->assertSame(NumberRenewalOutcome::Succeeded, $outcome);
        $number->refresh();
        $this->assertSame(BusinessMessagingNumberStatus::Active, $number->status);
        $this->assertTrue($number->next_renewal_at->gt($dueAt), 'next_renewal_at must advance one billing cycle forward.');
        $this->assertSame(
            1,
            BusinessMessagingNumberLifecycleEvent::where('business_messaging_number_id', $number->id)
                ->where('event_type', NumberLifecycleEventType::RenewalCharged->value)
                ->count(),
        );
    }

    public function test_a_replayed_renewal_reservation_charges_only_once(): void
    {
        // Contract S-8: "Number renewal | Idempotent per period; a
        // replayed renewal charges once." Proven directly at the wallet
        // layer with the exact idempotency key attemptRenewal() derives,
        // simulating a sweep that reserves twice for the same due date
        // before the number's own next_renewal_at ever advances (e.g. two
        // overlapping runs).
        $business = $this->makeBusiness();
        $this->fundWallet($business, 100_000_000);
        $number = $this->activeNumberDueOn($business, now()->subDay());

        $wallet = app(UsageWalletManager::class);
        $key = sprintf('number_renewal:%d:%s', $number->id, $number->next_renewal_at->toDateString());

        $first = $wallet->reserve($business, NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL, $key);
        $second = $wallet->reserve($business, NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL, $key);

        $this->assertTrue($first->granted);
        $this->assertTrue($second->granted);
        $this->assertSame($first->reservationId, $second->reservationId, 'A replayed reservation with the same idempotency key must return the same reservation, never a second charge.');
    }

    // =================================================================
    // attemptRenewal() — insufficient funds suspends the number.
    // =================================================================

    public function test_attempt_renewal_suspends_the_number_on_insufficient_funds(): void
    {
        Notification::fake();
        $business = $this->makeBusiness();
        $this->fundWallet($business, 0);
        $this->billingContact($business, 'owner@example.test');
        $number = $this->activeNumberDueOn($business, now()->subDay());

        $outcome = $this->manager()->attemptRenewal($number);

        $this->assertSame(NumberRenewalOutcome::InsufficientFunds, $outcome);
        $number->refresh();
        $this->assertSame(BusinessMessagingNumberStatus::Suspended, $number->status);
        $this->assertNotNull($number->suspended_at);
        $this->assertNotNull($number->grace_expires_at);
        $this->assertEqualsWithDelta(
            (int) config('messaging.number_renewal_grace_days', 14),
            (int) $number->suspended_at->diffInDays($number->grace_expires_at),
            1,
        );

        $this->assertSame(
            1,
            BusinessMessagingNumberLifecycleEvent::where('business_messaging_number_id', $number->id)
                ->where('event_type', NumberLifecycleEventType::Suspended->value)
                ->count(),
        );

        Notification::assertSentOnDemand(
            NumberSuspendedNotification::class,
            fn (NumberSuspendedNotification $n, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'owner@example.test',
        );
    }

    public function test_suspend_is_a_no_op_for_an_already_suspended_number(): void
    {
        $business = $this->makeBusiness();
        $number = $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber(), true, BusinessMessagingNumberStatus::Suspended);
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['grace_expires_at' => now()->addDays(3)]);
        $originalGraceExpiresAt = $number->fresh()->grace_expires_at;

        $this->manager()->suspend($number->fresh());

        $this->assertEquals($originalGraceExpiresAt, $number->fresh()->grace_expires_at, 'Re-suspending an already-Suspended number must never reset its grace period.');
        $this->assertSame(0, BusinessMessagingNumberLifecycleEvent::where('business_messaging_number_id', $number->id)->count());
    }

    // =================================================================
    // Outbound stays blocked, inbound is preserved, through suspension.
    // =================================================================

    public function test_a_suspended_number_still_blocks_new_paid_outbound(): void
    {
        $business = $this->makeBusiness();
        $this->fundWallet($business, 0);
        $number = $this->activeNumberDueOn($business, now()->subDay());
        $this->manager()->attemptRenewal($number);

        $this->bindFakeAdapter();
        $this->expectException(\App\Library\Messaging\Exceptions\MessagingIdentityConflictException::class);
        app(\App\Library\Messaging\ManagedMessageDispatcher::class)->dispatch($business, '+14155559999', 'hello', 'op_lifecycle_suspended');
    }

    // =================================================================
    // Advance warning.
    // =================================================================

    public function test_advance_warning_fires_when_projected_balance_is_insufficient(): void
    {
        Notification::fake();
        $business = $this->makeBusiness();
        $this->fundWallet($business, 0);
        $this->billingContact($business, 'warn-me@example.test');
        $number = $this->activeNumberDueOn($business, now()->addDays(3));

        $dispatched = $this->manager()->sendAdvanceWarningIfNeeded($number);

        $this->assertTrue($dispatched);
        $this->assertNotNull($number->fresh()->renewal_warning_sent_at);
        $this->assertSame(
            1,
            BusinessMessagingNumberLifecycleEvent::where('business_messaging_number_id', $number->id)
                ->where('event_type', NumberLifecycleEventType::RenewalWarningSent->value)
                ->count(),
        );

        Notification::assertSentOnDemand(
            NumberRenewalWarningNotification::class,
            fn (NumberRenewalWarningNotification $n, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'warn-me@example.test',
        );

        // And the number itself was never charged or suspended by a mere warning.
        $this->assertSame(BusinessMessagingNumberStatus::Active, $number->fresh()->status);
    }

    public function test_advance_warning_does_not_fire_when_balance_is_sufficient(): void
    {
        Notification::fake();
        $business = $this->makeBusiness();
        $this->fundWallet($business, 100_000_000);
        $number = $this->activeNumberDueOn($business, now()->addDays(3));

        $dispatched = $this->manager()->sendAdvanceWarningIfNeeded($number);

        $this->assertFalse($dispatched);
        $this->assertNull($number->fresh()->renewal_warning_sent_at);
        Notification::assertNothingSent();
    }

    public function test_advance_warning_does_not_fire_outside_the_window(): void
    {
        Notification::fake();
        $business = $this->makeBusiness();
        $this->fundWallet($business, 0);
        $number = $this->activeNumberDueOn($business, now()->addDays(30));

        $this->assertFalse($this->manager()->sendAdvanceWarningIfNeeded($number));
        Notification::assertNothingSent();
    }

    public function test_advance_warning_never_fires_twice_for_the_same_cycle(): void
    {
        Notification::fake();
        $business = $this->makeBusiness();
        $this->fundWallet($business, 0);
        $this->billingContact($business, 'warn-twice@example.test');
        $number = $this->activeNumberDueOn($business, now()->addDays(3));

        $this->assertTrue($this->manager()->sendAdvanceWarningIfNeeded($number));
        $this->assertFalse($this->manager()->sendAdvanceWarningIfNeeded($number->fresh()), 'Already warned this cycle.');

        Notification::assertSentOnDemandTimes(NumberRenewalWarningNotification::class, 1);
    }

    public function test_advance_warning_reports_nothing_when_not_configured(): void
    {
        Notification::fake();
        $business = $this->makeBusiness();
        $number = $this->activeNumberDueOn($business, now()->addDays(3));

        $this->assertFalse($this->manager()->sendAdvanceWarningIfNeeded($number));
        Notification::assertNothingSent();
    }

    // =================================================================
    // Query scoping.
    // =================================================================

    public function test_numbers_due_for_renewal_excludes_suspended_and_future_numbers(): void
    {
        $business = $this->makeBusiness();
        $identity = $this->attachIdentity($business);
        $due = $this->attachNumber($identity, $this->uniqueNumber(), true);
        DB::table('business_messaging_numbers')->where('id', $due->id)->update(['next_renewal_at' => now()->subDay()]);
        $future = $this->attachNumber($identity, $this->uniqueNumber(), false);
        DB::table('business_messaging_numbers')->where('id', $future->id)->update(['next_renewal_at' => now()->addDays(10)]);
        $suspended = $this->attachNumber($identity, $this->uniqueNumber(), false, BusinessMessagingNumberStatus::Suspended);
        DB::table('business_messaging_numbers')->where('id', $suspended->id)->update(['next_renewal_at' => now()->subDay()]);

        $ids = $this->manager()->numbersDueForRenewal()->pluck('id')->all();

        $this->assertContains($due->id, $ids);
        $this->assertNotContains($future->id, $ids);
        $this->assertNotContains($suspended->id, $ids);
    }

    public function test_numbers_eligible_for_release_notice_requires_expired_grace_and_no_notice_yet(): void
    {
        $business = $this->makeBusiness();
        $identity = $this->attachIdentity($business);

        $expired = $this->attachNumber($identity, $this->uniqueNumber(), true, BusinessMessagingNumberStatus::Suspended);
        DB::table('business_messaging_numbers')->where('id', $expired->id)->update(['grace_expires_at' => now()->subDay()]);

        $notYetExpired = $this->attachNumber($identity, $this->uniqueNumber(), false, BusinessMessagingNumberStatus::Suspended);
        DB::table('business_messaging_numbers')->where('id', $notYetExpired->id)->update(['grace_expires_at' => now()->addDays(3)]);

        $alreadyNoticed = $this->attachNumber($identity, $this->uniqueNumber(), false, BusinessMessagingNumberStatus::Suspended);
        DB::table('business_messaging_numbers')->where('id', $alreadyNoticed->id)->update(['grace_expires_at' => now()->subDay(), 'release_notice_sent_at' => now()]);

        $ids = $this->manager()->numbersEligibleForReleaseNotice()->pluck('id')->all();

        $this->assertContains($expired->id, $ids);
        $this->assertNotContains($notYetExpired->id, $ids);
        $this->assertNotContains($alreadyNoticed->id, $ids);
    }

    // =================================================================
    // Release notice.
    // =================================================================

    public function test_send_release_notice_is_sent_once_and_recorded(): void
    {
        Notification::fake();
        $business = $this->makeBusiness();
        $this->billingContact($business, 'release-notice@example.test');
        $number = $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber(), false, BusinessMessagingNumberStatus::Suspended);
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['grace_expires_at' => now()->subDay()]);

        $this->manager()->sendReleaseNotice($number->fresh());
        $this->manager()->sendReleaseNotice($number->fresh());

        $this->assertNotNull($number->fresh()->release_notice_sent_at);
        $this->assertSame(
            1,
            BusinessMessagingNumberLifecycleEvent::where('business_messaging_number_id', $number->id)
                ->where('event_type', NumberLifecycleEventType::ReleaseNoticeSent->value)
                ->count(),
            'A release notice must never be recorded or sent twice.',
        );

        Notification::assertSentOnDemandTimes(NumberReleaseNoticeNotification::class, 1);
    }

    // =================================================================
    // release() — every §13.3 precondition, and §13.4's port-out path
    // preserved right up to the moment of release.
    // =================================================================

    public function test_release_refuses_an_active_number(): void
    {
        $business = $this->makeBusiness();
        $number = $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber());

        $this->expectException(NumberReleaseNotEligibleException::class);
        $this->manager()->release($number, $this->actorId(), 'Attempted release of an Active number.');
    }

    public function test_release_refuses_before_grace_expires(): void
    {
        $business = $this->makeBusiness();
        $number = $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber(), false, BusinessMessagingNumberStatus::Suspended);
        DB::table('business_messaging_numbers')->where('id', $number->id)->update([
            'grace_expires_at' => now()->addDays(3),
            'release_notice_sent_at' => now(),
        ]);

        $this->expectException(NumberReleaseNotEligibleException::class);
        $this->manager()->release($number->fresh(), $this->actorId(), 'Attempted early release.');
    }

    public function test_release_refuses_without_a_prior_release_notice(): void
    {
        $business = $this->makeBusiness();
        $number = $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber(), false, BusinessMessagingNumberStatus::Suspended);
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['grace_expires_at' => now()->subDay()]);

        $this->expectException(NumberReleaseNotEligibleException::class);
        $this->manager()->release($number->fresh(), $this->actorId(), 'Attempted release without notice.');
    }

    public function test_release_refuses_while_an_active_port_out_request_exists(): void
    {
        $business = $this->makeBusiness();
        $number = $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber(), false, BusinessMessagingNumberStatus::Suspended);
        DB::table('business_messaging_numbers')->where('id', $number->id)->update([
            'grace_expires_at' => now()->subDay(),
            'release_notice_sent_at' => now(),
        ]);
        app(PortOutRequestManager::class)->request($business, $number->fresh(), 1);

        $this->expectException(NumberReleaseNotEligibleException::class);
        $this->manager()->release($number->fresh(), $this->actorId(), 'Attempted release out from under an active port-out request.');
    }

    public function test_release_succeeds_once_every_precondition_holds_and_is_audited(): void
    {
        $business = $this->makeBusiness();
        $number = $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber(), false, BusinessMessagingNumberStatus::Suspended);
        DB::table('business_messaging_numbers')->where('id', $number->id)->update([
            'grace_expires_at' => now()->subDay(),
            'release_notice_sent_at' => now(),
        ]);
        $actorId = $this->actorId();

        $this->manager()->release($number->fresh(), $actorId, 'Verified with the customer; releasing after grace expired.');

        $number->refresh();
        $this->assertSame(BusinessMessagingNumberStatus::Released, $number->status);
        $this->assertNotNull($number->released_at);

        $event = BusinessMessagingNumberLifecycleEvent::where('business_messaging_number_id', $number->id)
            ->where('event_type', NumberLifecycleEventType::Released->value)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame($actorId, $event->actor_user_id);
        $this->assertSame('Verified with the customer; releasing after grace expired.', $event->note);
    }

    /**
     * The core §13.4 preservation test: a customer can still request (and
     * cancel) port-out for a number all the way through suspension and
     * grace, right up until the moment it is actually released — release
     * never happens without cancelling the port-out attempt first.
     */
    public function test_port_out_remains_available_throughout_suspension_and_grace_until_release(): void
    {
        $business = $this->makeBusiness();
        $this->fundWallet($business, 0);
        $number = $this->activeNumberDueOn($business, now()->subDay());

        $this->manager()->attemptRenewal($number->fresh());
        $number->refresh();
        $this->assertTrue($number->isSuspended());

        $portOutRequest = app(PortOutRequestManager::class)->request($business, $number, 1);
        $this->assertNotNull($portOutRequest);

        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['grace_expires_at' => now()->subDay(), 'release_notice_sent_at' => now()]);

        try {
            $this->manager()->release($number->fresh(), $this->actorId(), 'Should be refused while porting out.');
            $this->fail('Expected NumberReleaseNotEligibleException while a port-out request is active.');
        } catch (NumberReleaseNotEligibleException) {
            // expected
        }

        app(PortOutRequestManager::class)->cancel((int) $portOutRequest->id, 1);
        $this->manager()->release($number->fresh(), $this->actorId(), 'Port-out cancelled; releasing now.');

        $this->assertSame(BusinessMessagingNumberStatus::Released, $number->fresh()->status);
    }
}
