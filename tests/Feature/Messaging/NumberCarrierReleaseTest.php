<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\CarrierReleaseOutcome;
use App\Enums\Messaging\NumberLifecycleEventType;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\Exceptions\NumberCarrierReleaseNotConfirmedException;
use App\Library\Messaging\Exceptions\NumberReleaseNotEligibleException;
use App\Library\Messaging\Exceptions\PortOutRequestNumberNotPortableException;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Library\Messaging\NumberLifecycleManager;
use App\Library\Messaging\PortOutRequestManager;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingNumberLifecycleEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Phone Numbers + A2P lane — the final carrier-release boundary PR #395's
 * release-decision slice deliberately left unbuilt (its own docblock
 * called it out as "a future, separately authorized slice"). This is that
 * slice: NumberLifecycleManager::confirmCarrierRelease() is the ONLY path
 * that ever writes BusinessMessagingNumberStatus::Released, and only after
 * MessagingProvisioningAdapter::releaseNumber() itself confirms it — never
 * merely because a release decision was recorded.
 *
 * No real Telnyx call anywhere here — FakeProvisioningAdapter is bound in
 * every test exactly like BusinessMessagingProvisioningServiceTest's own
 * established pattern; TelnyxProvisioningAdapterTest covers the real
 * adapter's own releaseNumber() endpoint/status mapping under Http::fake().
 */
class NumberCarrierReleaseTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    private function manager(): NumberLifecycleManager
    {
        return app(NumberLifecycleManager::class);
    }

    private function actorId(): int
    {
        return User::create([
            'first_name' => 'Ops', 'last_name' => 'Admin',
            'email' => 'carrier-release-actor-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function bindFakeProvisioningAdapter(): FakeProvisioningAdapter
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => true,
            'services.telnyx.api_key' => 'fixture_key_not_a_real_credential',
        ]);

        return $fake;
    }

    /** A Suspended number with an expired grace period, a matured confirmed-delivered
     * release notice, an already-recorded release decision, and a real provider
     * reference on file — i.e. eligible for confirmCarrierRelease() itself.
     */
    private function decidedNumber(?string $providerNumberReference = 'fake_number_000123'): BusinessMessagingNumber
    {
        $business = $this->makeBusiness();
        $number = $this->attachNumber(
            $this->attachIdentity($business),
            $this->uniqueNumber(),
            true,
            BusinessMessagingNumberStatus::Suspended,
            $providerNumberReference,
        );
        DB::table('business_messaging_numbers')->where('id', $number->id)->update([
            'grace_expires_at' => now()->subDay(),
            'release_notice_delivered_at' => now()->subDays(8),
            'release_decided_at' => now(),
        ]);

        return $number->fresh();
    }

    // =================================================================
    // Non-carrier preconditions — the carrier is never even called.
    // =================================================================

    public function test_refuses_without_a_recorded_release_decision(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $number = $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber(), true, BusinessMessagingNumberStatus::Suspended, 'fake_number_1');
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['grace_expires_at' => now()->subDay()]);

        $this->expectException(NumberReleaseNotEligibleException::class);

        try {
            $this->manager()->confirmCarrierRelease($number->fresh(), $this->actorId(), 'Attempted without a decision.');
        } finally {
            $this->assertSame([], $fake->releaseAttempts, 'The carrier must never be called before a decision is recorded.');
        }
    }

    public function test_refuses_when_no_longer_suspended(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $number = $this->decidedNumber();
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['status' => BusinessMessagingNumberStatus::Active->value]);

        $this->expectException(NumberReleaseNotEligibleException::class);

        try {
            $this->manager()->confirmCarrierRelease($number->fresh(), $this->actorId(), 'Attempted while Active.');
        } finally {
            $this->assertSame([], $fake->releaseAttempts);
        }
    }

    public function test_refuses_without_a_provider_reference_on_file(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $number = $this->decidedNumber(null);

        $this->expectException(NumberReleaseNotEligibleException::class);

        try {
            $this->manager()->confirmCarrierRelease($number->fresh(), $this->actorId(), 'No provider reference recorded.');
        } finally {
            $this->assertSame([], $fake->releaseAttempts);
        }
    }

    public function test_refuses_while_an_active_port_out_request_exists(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $number = $this->decidedNumber();
        $business = $number->identity->business;
        app(PortOutRequestManager::class)->request($business, $number->fresh(), 1);

        $this->expectException(NumberReleaseNotEligibleException::class);

        try {
            $this->manager()->confirmCarrierRelease($number->fresh(), $this->actorId(), 'Should be refused while porting out.');
        } finally {
            $this->assertSame([], $fake->releaseAttempts, 'The carrier must never be called while a port-out request is active.');
        }
    }

    // =================================================================
    // Confirmed — the only path that ever writes Released.
    // =================================================================

    public function test_confirmed_carrier_response_transitions_to_released_and_is_audited(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $number = $this->decidedNumber('fake_number_confirm_1');
        $actorId = $this->actorId();

        $this->manager()->confirmCarrierRelease($number->fresh(), $actorId, 'Confirmed release with the carrier.');

        $number->refresh();
        $this->assertSame(BusinessMessagingNumberStatus::Released, $number->status);
        $this->assertNotNull($number->released_at);
        $this->assertNull($number->carrier_release_failed_at);
        $this->assertNull($number->carrier_release_failure_reason);

        $this->assertCount(1, $fake->releaseAttempts);
        $this->assertSame('fake_number_confirm_1', $fake->releaseAttempts[0]->providerPhoneNumberId);

        $event = BusinessMessagingNumberLifecycleEvent::where('business_messaging_number_id', $number->id)
            ->where('event_type', NumberLifecycleEventType::CarrierReleaseConfirmed->value)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame($actorId, $event->actor_user_id);
        $this->assertSame('Confirmed release with the carrier.', $event->note);
    }

    public function test_is_idempotent_and_never_calls_the_carrier_twice(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $number = $this->decidedNumber('fake_number_idempotent_1');
        $actorId = $this->actorId();

        $this->manager()->confirmCarrierRelease($number->fresh(), $actorId, 'First confirmation.');
        $this->manager()->confirmCarrierRelease($number->fresh(), $actorId, 'Second, redundant call.');

        $this->assertCount(1, $fake->releaseAttempts, 'A number already Released must never be released a second time.');
        $this->assertSame(
            1,
            BusinessMessagingNumberLifecycleEvent::where('business_messaging_number_id', $number->id)
                ->where('event_type', NumberLifecycleEventType::CarrierReleaseConfirmed->value)
                ->count(),
        );
    }

    /**
     * The manager trusts whatever CarrierReleaseOutcome the adapter
     * returns — it has no opinion on HOW that was verified. Confirmed via
     * a retry (e.g. TelnyxProvisioningAdapter's own lookup-already-deleted
     * path, covered at the adapter level in TelnyxProvisioningAdapterTest)
     * must transition to Released exactly like a fresh, direct
     * confirmation does.
     */
    public function test_a_confirmed_outcome_from_a_retry_transitions_to_released_just_like_a_fresh_confirmation(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $number = $this->decidedNumber('fake_number_retry_confirm_1');
        $fake->scriptReleaseOutcome('fake_number_retry_confirm_1', CarrierReleaseOutcome::Confirmed);

        $this->manager()->confirmCarrierRelease($number->fresh(), $this->actorId(), 'Retry after a lost response.');

        $this->assertSame(BusinessMessagingNumberStatus::Released, $number->fresh()->status);
    }

    // =================================================================
    // Not confirmed — never guessed as a success, never silently lost.
    // =================================================================

    public function test_a_not_confirmed_carrier_response_leaves_the_number_suspended_and_is_recorded(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $number = $this->decidedNumber('fake_number_fail_1');
        $fake->scriptReleaseOutcome('fake_number_fail_1', CarrierReleaseOutcome::NotConfirmed);
        $actorId = $this->actorId();

        try {
            $this->manager()->confirmCarrierRelease($number->fresh(), $actorId, 'Attempted release.');
            $this->fail('Expected NumberCarrierReleaseNotConfirmedException.');
        } catch (NumberCarrierReleaseNotConfirmedException) {
            // expected
        }

        $number->refresh();
        $this->assertSame(BusinessMessagingNumberStatus::Suspended, $number->status, 'A NotConfirmed carrier response must never write Released.');
        $this->assertNull($number->released_at);
        $this->assertNotNull($number->carrier_release_failed_at);
        $this->assertSame('fake_not_confirmed', $number->carrier_release_failure_reason);

        $event = BusinessMessagingNumberLifecycleEvent::where('business_messaging_number_id', $number->id)
            ->where('event_type', NumberLifecycleEventType::CarrierReleaseAttemptFailed->value)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame($actorId, $event->actor_user_id);
    }

    public function test_a_failed_attempt_remains_eligible_for_a_retry_that_can_then_succeed(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $number = $this->decidedNumber('fake_number_retry_1');
        $fake->scriptReleaseOutcome('fake_number_retry_1', CarrierReleaseOutcome::NotConfirmed);

        try {
            $this->manager()->confirmCarrierRelease($number->fresh(), $this->actorId(), 'First attempt fails.');
        } catch (NumberCarrierReleaseNotConfirmedException) {
            // expected
        }

        $fake->scriptReleaseOutcome('fake_number_retry_1', CarrierReleaseOutcome::Confirmed);
        $this->manager()->confirmCarrierRelease($number->fresh(), $this->actorId(), 'Retry succeeds.');

        $number->refresh();
        $this->assertSame(BusinessMessagingNumberStatus::Released, $number->status);
        $this->assertNotNull($number->released_at);
        // The stale failure marker from the earlier attempt is cleared on success.
        $this->assertNull($number->carrier_release_failed_at);
        $this->assertNull($number->carrier_release_failure_reason);
        $this->assertCount(2, $fake->releaseAttempts);
    }

    public function test_a_transport_exception_is_treated_as_not_confirmed(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $number = $this->decidedNumber('fake_number_throws_1');

        // Replace the bound fake with one whose releaseNumber() throws, to
        // exercise confirmCarrierRelease()'s own transport-exception
        // handling path independent of a scripted "not confirmed" DTO.
        $this->app->instance(MessagingProvisioningAdapter::class, new class extends FakeProvisioningAdapter {
            public function releaseNumber(\App\Library\Messaging\DTO\NumberReleaseQuery $query): \App\Library\Messaging\DTO\CarrierReleaseResult
            {
                throw new \RuntimeException('Connection timed out.');
            }
        });

        try {
            $this->manager()->confirmCarrierRelease($number->fresh(), $this->actorId(), 'Attempted release.');
            $this->fail('Expected NumberCarrierReleaseNotConfirmedException.');
        } catch (NumberCarrierReleaseNotConfirmedException) {
            // expected
        }

        $number->refresh();
        $this->assertSame(BusinessMessagingNumberStatus::Suspended, $number->status);
        $this->assertNotNull($number->carrier_release_failed_at);
        $this->assertStringContainsString('Connection timed out.', (string) $number->carrier_release_failure_reason);
    }

    // =================================================================
    // Not configured — the action is unreachable without the platform's
    // own provisioning gates, exactly like BusinessMessagingProvisioningService::provisionNumber().
    // =================================================================

    public function test_is_unreachable_when_provisioning_is_not_configured(): void
    {
        $number = $this->decidedNumber();

        $this->expectException(MessagingProviderNotConfiguredException::class);

        $this->manager()->confirmCarrierRelease($number->fresh(), $this->actorId(), 'Attempted without configuration.');
    }

    // =================================================================
    // §13.4 — once truly released, porting out is correctly refused; a
    // release decision alone (never reached here) must not have this
    // effect, which NumberLifecycleTest's own port-out-preservation test
    // already covers.
    // =================================================================

    public function test_a_new_port_out_request_is_refused_once_the_carrier_has_confirmed_release(): void
    {
        $this->bindFakeProvisioningAdapter();
        $number = $this->decidedNumber('fake_number_portout_after_release');
        $business = $number->identity->business;

        $this->manager()->confirmCarrierRelease($number->fresh(), $this->actorId(), 'Confirmed release.');

        $this->expectException(PortOutRequestNumberNotPortableException::class);
        app(PortOutRequestManager::class)->request($business, $number->fresh(), 1);
    }
}
