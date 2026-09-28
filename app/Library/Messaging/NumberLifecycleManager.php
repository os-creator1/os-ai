<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\CarrierReleaseOutcome;
use App\Enums\Messaging\NumberLifecycleEventType;
use App\Enums\Messaging\NumberRenewalOutcome;
use App\Exceptions\Usage\NoActiveRateForFeatureException;
use App\Exceptions\Usage\UsageMeterBusinessScopeMismatchException;
use App\Exceptions\Usage\UsageMeterCurrencyMismatchException;
use App\Exceptions\Usage\UsageMeterNotMeteredException;
use App\Exceptions\Usage\UsageMeterRateIntegrityException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Jobs\Messaging\SendNumberReleaseNotice;
use App\Jobs\Messaging\SendNumberRenewalWarning;
use App\Jobs\Messaging\SendNumberSuspendedNotice;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\NumberReleaseQuery;
use App\Library\Messaging\Exceptions\NumberCarrierReleaseNotConfirmedException;
use App\Library\Messaging\Exceptions\NumberReleaseNotEligibleException;
use App\Library\Usage\UsageWalletManager;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingNumberLifecycleEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phone Numbers + A2P lane — messaging contract §13.2/§13.3, the number
 * lifecycle this platform owes every managed number: a renewal charge
 * that requires sufficient funds, an advance warning when the projected
 * balance looks insufficient, a suspension that pauses new paid outbound
 * while retaining the number, a defined non-zero grace period, an audited
 * release DECISION preceded by confirmed-delivered notification, and
 * finally a genuine carrier-confirmed release
 * (confirmCarrierRelease()) — the only path that ever writes
 * BusinessMessagingNumberStatus::Released, and only after
 * MessagingProvisioningAdapter::releaseNumber() itself confirms the
 * carrier no longer has the number. A recorded decision alone never
 * implies a real release; the two are deliberately separate, audited
 * steps.
 *
 * Every write to business_messaging_numbers' lifecycle columns
 * (next_renewal_at, renewal_warning_sent_at, suspended_at,
 * grace_expires_at, release_notice_delivered_at, release_notice_failed_at,
 * release_decided_at, released_at, carrier_release_failed_at,
 * carrier_release_failure_reason) goes through this class alone, via the
 * query builder — never through the model's own mass assignment
 * (deliberately absent from $fillable, mirroring
 * ProvisioningIncidentRecorder and PortOutRequestManager).
 *
 * The renewal charge itself is real-shaped but structurally inert exactly
 * like TelnyxProvisioningAdapter's own three provisioning feature keys: no
 * UsageMeter/rate exists yet for FEATURE_NUMBER_RENTAL_RENEWAL, so
 * UsageWalletManager::reserve() throws one of the "not configured"
 * exceptions for every real call today — attemptRenewal() reports that as
 * NumberRenewalOutcome::NotConfigured and does nothing else. A genuine
 * InsufficientFunds outcome can therefore only ever occur once an owner
 * has deliberately activated a rate for this feature key — this class
 * changes nothing about that gate.
 *
 * Every mutating method re-fetches and locks (SELECT ... FOR UPDATE) the
 * number row inside its own transaction and rechecks every precondition
 * against that fresh row — never against the possibly-stale $number the
 * caller passed in — so overlapping sweeps, a stale in-memory object, and
 * a racing port-out request can never double-charge, double-suspend, or
 * decide a release out from under an in-flight port-out.
 * PortOutRequestManager::request() takes the same row lock, so the two
 * classes always serialize through the identical row.
 */
class NumberLifecycleManager
{
    public const FEATURE_NUMBER_RENTAL_RENEWAL = 'messaging_number_rental_renewal';

    private const NOT_CONFIGURED_EXCEPTIONS = [
        UsageWalletNotFoundException::class,
        NoActiveRateForFeatureException::class,
        UsageMeterNotMeteredException::class,
        UsageMeterBusinessScopeMismatchException::class,
        UsageMeterCurrencyMismatchException::class,
        UsageMeterRateIntegrityException::class,
    ];

    public function __construct(
        private readonly UsageWalletManager $wallet,
        private readonly PortOutRequestManager $portOutRequests,
    ) {
    }

    /**
     * @return Collection<int, BusinessMessagingNumber>
     */
    public function numbersDueForRenewal(?Carbon $asOf = null): Collection
    {
        $asOf ??= now();

        return BusinessMessagingNumber::query()
            ->where('status', BusinessMessagingNumberStatus::Active->value)
            ->whereNotNull('next_renewal_at')
            ->where('next_renewal_at', '<=', $asOf)
            ->get();
    }

    /**
     * @return Collection<int, BusinessMessagingNumber>
     */
    public function numbersNeedingAdvanceWarning(?Carbon $asOf = null): Collection
    {
        $asOf ??= now();

        return BusinessMessagingNumber::query()
            ->where('status', BusinessMessagingNumberStatus::Active->value)
            ->whereNotNull('next_renewal_at')
            ->where('next_renewal_at', '>', $asOf)
            ->where('next_renewal_at', '<=', $asOf->copy()->addDays($this->advanceWarningDays()))
            ->whereNull('renewal_warning_sent_at')
            ->get();
    }

    /**
     * @return Collection<int, BusinessMessagingNumber>
     */
    public function numbersEligibleForReleaseNotice(?Carbon $asOf = null): Collection
    {
        $asOf ??= now();

        return BusinessMessagingNumber::query()
            ->where('status', BusinessMessagingNumberStatus::Suspended->value)
            ->whereNotNull('grace_expires_at')
            ->where('grace_expires_at', '<=', $asOf)
            ->whereNull('release_notice_delivered_at')
            ->get();
    }

    /**
     * §13.2 "Alert before the renewal date when the projected balance is
     * insufficient" — a look-ahead probe, never the renewal charge itself.
     * Probes via reserve()+release() under a throwaway idempotency key
     * distinct from the real renewal's own, so a probe never holds a real
     * reservation open for the whole advance-warning window.
     *
     * Review correction: the probe key is keyed on $asOf (today), not on
     * next_renewal_at (stable for the whole renewal cycle). reserve() is
     * idempotent per key — a key that stays the same across an entire
     * window would return the SAME reservation (or its already-released
     * remnant) on every later day's call, so a balance that looked
     * sufficient on day one could silently stay "sufficient" even after
     * it dropped on day two. A fresh, dated key forces a genuine
     * reassessment every day this method runs, while still deduplicating
     * repeat calls within the same day.
     *
     * Runs under the same row lock as every other mutating method here so
     * a concurrent renewal/suspension already reflected in the database
     * gates this decision, never a possibly-stale in-memory $number.
     *
     * @return bool whether a warning was actually dispatched
     */
    public function sendAdvanceWarningIfNeeded(BusinessMessagingNumber $number, ?Carbon $asOf = null): bool
    {
        $asOf ??= now();

        return DB::transaction(function () use ($number, $asOf): bool {
            $locked = BusinessMessagingNumber::query()->whereKey($number->id)->lockForUpdate()->first();

            if ($locked === null
                || $locked->status !== BusinessMessagingNumberStatus::Active
                || $locked->next_renewal_at === null
                || $locked->renewal_warning_sent_at !== null
                || $asOf->gte($locked->next_renewal_at)
                || $asOf->copy()->addDays($this->advanceWarningDays())->lt($locked->next_renewal_at)
            ) {
                return false;
            }

            $business = $locked->identity->business;
            $probeKey = sprintf('number_renewal_probe:%d:%s', $locked->id, $asOf->toDateString());

            try {
                $probe = $this->wallet->reserve($business, self::FEATURE_NUMBER_RENTAL_RENEWAL, $probeKey);
            } catch (\Throwable $e) {
                if (! $this->isNotConfiguredException($e)) {
                    throw $e;
                }

                // Nothing real to charge yet — there is nothing to warn about.
                return false;
            }

            if ($probe->granted) {
                if ($probe->reservationId !== null && $probe->createdByThisInvocation) {
                    $this->wallet->release($probe->reservationId);
                }

                return false;
            }

            DB::table('business_messaging_numbers')->where('id', $locked->id)->update([
                'renewal_warning_sent_at' => $asOf,
                'updated_at' => now(),
            ]);

            $this->recordEvent($locked, NumberLifecycleEventType::RenewalWarningSent);

            SendNumberRenewalWarning::dispatch((int) $business->id, (int) $locked->id)->afterCommit();

            return true;
        });
    }

    /**
     * §13.2: "A renewal charge requires sufficient balance or an
     * authorized auto-recharge" — auto-recharge itself is
     * UsageWalletManager's own concern (reserve() already accounts for
     * it); this method only decides what happens to the NUMBER depending
     * on the outcome. NotConfigured never suspends anything — there is no
     * real charge to have failed.
     *
     * Review correction: re-fetches and locks the number row inside its
     * own transaction and rechecks eligibility against that fresh row
     * before ever calling reserve(). An overlapping sweep, or a caller
     * still holding a stale in-memory $number from before another call
     * already renewed (or suspended) it, now finds next_renewal_at (or
     * status) has moved on and gets NumberRenewalOutcome::AlreadyProcessed
     * instead of a second charge or a duplicate lifecycle event. The
     * wallet's own idempotency key is a second, independent safeguard
     * against a double charge for the same due date.
     */
    public function attemptRenewal(BusinessMessagingNumber $number, ?Carbon $asOf = null): NumberRenewalOutcome
    {
        $asOf ??= now();

        return DB::transaction(function () use ($number, $asOf): NumberRenewalOutcome {
            $locked = BusinessMessagingNumber::query()->whereKey($number->id)->lockForUpdate()->first();

            if ($locked === null
                || $locked->status !== BusinessMessagingNumberStatus::Active
                || $locked->next_renewal_at === null
                || $locked->next_renewal_at->gt($asOf)
            ) {
                return NumberRenewalOutcome::AlreadyProcessed;
            }

            $business = $locked->identity->business;
            $chargeKey = sprintf('number_renewal:%d:%s', $locked->id, $locked->next_renewal_at->toDateString());

            try {
                $reservation = $this->wallet->reserve($business, self::FEATURE_NUMBER_RENTAL_RENEWAL, $chargeKey);
            } catch (\Throwable $e) {
                if (! $this->isNotConfiguredException($e)) {
                    throw $e;
                }

                return NumberRenewalOutcome::NotConfigured;
            }

            if (! $reservation->granted || $reservation->reservationId === null) {
                $this->suspendLocked($locked);

                return NumberRenewalOutcome::InsufficientFunds;
            }

            $this->wallet->commit($reservation->reservationId);

            DB::table('business_messaging_numbers')->where('id', $locked->id)->update([
                // Idempotent per period (contract S-8): a replayed sweep for
                // the same due date finds next_renewal_at already advanced
                // and reserve()'s own idempotency key match means it never
                // charges twice either way.
                'next_renewal_at' => $locked->next_renewal_at->copy()->addMonthNoOverflow(),
                'renewal_warning_sent_at' => null,
                'updated_at' => now(),
            ]);

            $this->recordEvent($locked, NumberLifecycleEventType::RenewalCharged);

            return NumberRenewalOutcome::Succeeded;
        });
    }

    /**
     * §13.3: "`suspended` stops new paid outbound while retaining the
     * number." The outbound path already only resolves an Active primary
     * number (BusinessMessagingIdentityResolver::resolvePrimaryNumber()),
     * so this transition alone is what actually stops new paid outbound —
     * no separate kill switch needed. Inbound handling required by law or
     * compliance is preserved through the grace period by
     * BusinessMessagingIdentityResolver::resolveByPhoneNumber() treating
     * Suspended as a valid inbound-matching status, unchanged by this
     * method.
     *
     * Review correction: re-fetches and locks the row and rechecks status
     * against that fresh row, so a stale in-memory $number can never
     * trigger a duplicate Suspended event or overwrite an already-running
     * grace period. attemptRenewal() calls the already-locked variant
     * directly since it already holds this same row's lock.
     */
    public function suspend(BusinessMessagingNumber $number): void
    {
        DB::transaction(function () use ($number): void {
            $locked = BusinessMessagingNumber::query()->whereKey($number->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== BusinessMessagingNumberStatus::Active) {
                // Never re-suspend, never overwrite an existing suspension's
                // own grace_expires_at, never touch Pending/Released.
                return;
            }

            $this->suspendLocked($locked);
        });
    }

    private function suspendLocked(BusinessMessagingNumber $locked): void
    {
        $business = $locked->identity->business;
        $suspendedAt = now();
        $graceExpiresAt = $suspendedAt->copy()->addDays($this->graceDays());

        DB::table('business_messaging_numbers')->where('id', $locked->id)->update([
            'status' => BusinessMessagingNumberStatus::Suspended->value,
            'suspended_at' => $suspendedAt,
            'grace_expires_at' => $graceExpiresAt,
            'updated_at' => now(),
        ]);

        $this->recordEvent($locked, NumberLifecycleEventType::Suspended);

        SendNumberSuspendedNotice::dispatch((int) $business->id, (int) $locked->id)->afterCommit();
    }

    /**
     * §13.3's "preceded by notification" — this method only DISPATCHES the
     * notice job. It never itself records that the customer was notified:
     * recordReleaseNoticeDelivered()/recordReleaseNoticeDeliveryFailure()
     * are the only writers of release_notice_delivered_at/
     * release_notice_failed_at, and only SendNumberReleaseNotice calls
     * them, after a confirmed outcome. A missing billing contact, an
     * opt-out, a blank email, or the notification channel throwing can
     * therefore never silently satisfy the "notice was sent" precondition
     * a release decision depends on — it shows up as a recorded failure
     * instead, and numbersEligibleForReleaseNotice() keeps offering the
     * number for a retry until delivery is actually confirmed.
     */
    public function sendReleaseNotice(BusinessMessagingNumber $number): void
    {
        if ($number->status !== BusinessMessagingNumberStatus::Suspended || $number->release_notice_delivered_at !== null) {
            return;
        }

        $business = $number->identity->business;

        SendNumberReleaseNotice::dispatch((int) $business->id, (int) $number->id)->afterCommit();
    }

    /**
     * Called only by SendNumberReleaseNotice, after a confirmed successful
     * send. Locked and idempotent: recorded at most once.
     */
    public function recordReleaseNoticeDelivered(BusinessMessagingNumber $number): void
    {
        DB::transaction(function () use ($number): void {
            $locked = BusinessMessagingNumber::query()->whereKey($number->id)->lockForUpdate()->first();

            if ($locked === null || $locked->release_notice_delivered_at !== null) {
                return;
            }

            DB::table('business_messaging_numbers')->where('id', $locked->id)->update([
                'release_notice_delivered_at' => now(),
                'updated_at' => now(),
            ]);

            $this->recordEvent($locked, NumberLifecycleEventType::ReleaseNoticeDelivered);
        });
    }

    /**
     * Called only by SendNumberReleaseNotice, on any confirmed failure to
     * deliver (missing contact, opt-out, blank email, or the notification
     * channel throwing) — makes a stuck number visible to operators
     * instead of leaving it silently unnoticed. Never overwrites an
     * already-confirmed delivery from an earlier attempt.
     */
    public function recordReleaseNoticeDeliveryFailure(BusinessMessagingNumber $number, string $reason): void
    {
        DB::transaction(function () use ($number, $reason): void {
            $locked = BusinessMessagingNumber::query()->whereKey($number->id)->lockForUpdate()->first();

            if ($locked === null || $locked->release_notice_delivered_at !== null) {
                return;
            }

            DB::table('business_messaging_numbers')->where('id', $locked->id)->update([
                'release_notice_failed_at' => now(),
                'updated_at' => now(),
            ]);

            $this->recordEvent($locked, NumberLifecycleEventType::ReleaseNoticeDeliveryFailed, null, $reason);
        });
    }

    /**
     * §13.3: "The transition from suspended to released must be explicit,
     * audited, and preceded by notification." This slice makes no real
     * Telnyx call, so this method records only the platform operator's
     * audited DECISION to release — never a confirmed carrier release.
     * The number's `status` stays Suspended; a future, separately
     * authorized slice is responsible for confirming the actual
     * carrier-side release and only then transitioning status to
     * Released. Keeping status Suspended also keeps the customer's own
     * port-out path available right up to (and, deliberately, even
     * after) this decision, until a real release is confirmed.
     *
     * Every precondition is rechecked here against the current, locked
     * database row — never against the possibly-stale $number the caller
     * passed in — including a minimum-notice-days gate so the customer
     * has a meaningful opportunity to act after the notice was confirmed
     * delivered, not merely dispatched. §13.4's port-out exit path is
     * checked too: PortOutRequestManager::request() takes the same row
     * lock, so a port-out request and a release decision can never race
     * past each other.
     *
     * Idempotent: a number whose decision was already recorded is a
     * silent no-op, never a second event.
     *
     * @throws NumberReleaseNotEligibleException
     */
    public function recordReleaseDecision(BusinessMessagingNumber $number, int $actorUserId, string $note): void
    {
        DB::transaction(function () use ($number, $actorUserId, $note): void {
            $locked = BusinessMessagingNumber::query()->whereKey($number->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new NumberReleaseNotEligibleException(sprintf(
                    'Number [%d] no longer exists.',
                    (int) $number->id,
                ));
            }

            if ($locked->release_decided_at !== null) {
                return;
            }

            if ($locked->status !== BusinessMessagingNumberStatus::Suspended) {
                throw new NumberReleaseNotEligibleException(sprintf(
                    'Number [%d] is not Suspended (status: %s) and cannot be released.',
                    (int) $locked->id,
                    $locked->status->value,
                ));
            }

            if ($locked->grace_expires_at === null || $locked->grace_expires_at->isFuture()) {
                throw new NumberReleaseNotEligibleException(sprintf(
                    'Number [%d]\'s grace period has not yet expired.',
                    (int) $locked->id,
                ));
            }

            if ($locked->release_notice_delivered_at === null) {
                throw new NumberReleaseNotEligibleException(sprintf(
                    'Number [%d] has not yet had its release notice confirmed delivered.',
                    (int) $locked->id,
                ));
            }

            $noticeMatureAt = $locked->release_notice_delivered_at->copy()->addDays($this->minimumNoticeDays());

            if ($noticeMatureAt->isFuture()) {
                throw new NumberReleaseNotEligibleException(sprintf(
                    'Number [%d]\'s release notice must remain outstanding for at least %d day(s) so the customer has a meaningful opportunity to act; eligible from %s.',
                    (int) $locked->id,
                    $this->minimumNoticeDays(),
                    $noticeMatureAt->toDateTimeString(),
                ));
            }

            if ($this->portOutRequests->activeRequestFor($locked) !== null) {
                throw new NumberReleaseNotEligibleException(sprintf(
                    'Number [%d] has an active port-out request and must not be released out from under it.',
                    (int) $locked->id,
                ));
            }

            DB::table('business_messaging_numbers')->where('id', $locked->id)->update([
                'release_decided_at' => now(),
                'updated_at' => now(),
            ]);

            $this->recordEvent($locked, NumberLifecycleEventType::ReleaseDecided, $actorUserId, $note);
        });
    }

    /**
     * The final carrier-release boundary §13.3 leaves for a "future,
     * separately authorized slice" (see recordReleaseDecision()'s own
     * docblock) — that slice. `status` becomes Released, and `released_at`
     * is finally written (present on this table since Slice 3, never
     * written until now), only after MessagingProvisioningAdapter::
     * releaseNumber() itself confirms the carrier no longer has the
     * number. Every non-carrier precondition is rechecked here against the
     * current, locked database row, exactly like recordReleaseDecision():
     * a release decision must already be recorded, the number must still
     * be Suspended, it must carry a real provider reference, and no active
     * port-out request may exist — PortOutRequestManager::request() takes
     * this exact same row lock, so the two can never race past each other.
     *
     * The provider round trip deliberately happens INSIDE this same lock
     * rather than after releasing it. This is a rare, human-triggered,
     * one-off action per number — never a hot path — so holding the row
     * lock for the duration of one bounded HTTP call (TIMEOUT_SECONDS on
     * TelnyxProvisioningAdapter) is the trade that actually GUARANTEES "no
     * active port-out request" stays true through the moment of the real
     * carrier call, rather than merely at some earlier instant a caller
     * could still race past.
     *
     * Idempotent: a number already Released is a silent no-op — the
     * carrier is never called a second time for the same number. A
     * NotConfirmed carrier response (or a transport-level exception) never
     * writes Released; it is recorded as a visible, retryable failure
     * (carrier_release_failed_at/carrier_release_failure_reason plus a
     * CarrierReleaseAttemptFailed event) and this method throws
     * NumberCarrierReleaseNotConfirmedException AFTER that failure record
     * has already committed, so the audit trail survives even though the
     * caller sees an exception.
     *
     * @throws NumberReleaseNotEligibleException          when a non-carrier
     *                                                     precondition fails —
     *                                                     the carrier is never
     *                                                     even called
     * @throws NumberCarrierReleaseNotConfirmedException  when every
     *                                                     precondition held but
     *                                                     the carrier did not
     *                                                     confirm removal
     */
    public function confirmCarrierRelease(BusinessMessagingNumber $number, int $actorUserId, string $note): void
    {
        // Deliberately NOT caught here, mirroring
        // BusinessMessagingProvisioningService::provisionNumber()'s own
        // discipline: this action must be unreachable at all unless the
        // caller already confirmed ProvisioningAvailability::isConfigured(),
        // and resolving it lazily (never via this class's own constructor)
        // means merely constructing NumberLifecycleManager — used
        // throughout renewal/suspension/notice flows that have nothing to
        // do with provisioning — never requires managed messaging to be
        // configured at all.
        $adapter = app(MessagingProvisioningAdapter::class);

        $outcome = DB::transaction(function () use ($number, $actorUserId, $note, $adapter): ?CarrierReleaseOutcome {
            $locked = BusinessMessagingNumber::query()->whereKey($number->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new NumberReleaseNotEligibleException(sprintf(
                    'Number [%d] no longer exists.',
                    (int) $number->id,
                ));
            }

            if ($locked->status === BusinessMessagingNumberStatus::Released) {
                // Already confirmed released — the carrier is never called
                // twice for the same number.
                return null;
            }

            if ($locked->release_decided_at === null) {
                throw new NumberReleaseNotEligibleException(sprintf(
                    'Number [%d] has no recorded release decision yet.',
                    (int) $locked->id,
                ));
            }

            if ($locked->status !== BusinessMessagingNumberStatus::Suspended) {
                throw new NumberReleaseNotEligibleException(sprintf(
                    'Number [%d] is not Suspended (status: %s) and cannot be released.',
                    (int) $locked->id,
                    $locked->status->value,
                ));
            }

            if (blank($locked->provider_number_reference)) {
                throw new NumberReleaseNotEligibleException(sprintf(
                    'Number [%d] has no provider reference on file and cannot be released from the carrier.',
                    (int) $locked->id,
                ));
            }

            if ($this->portOutRequests->activeRequestFor($locked) !== null) {
                throw new NumberReleaseNotEligibleException(sprintf(
                    'Number [%d] has an active port-out request and must not be released out from under it.',
                    (int) $locked->id,
                ));
            }

            try {
                $result = $adapter->releaseNumber(NumberReleaseQuery::fromModel($locked));
            } catch (\Throwable $e) {
                $this->recordCarrierReleaseFailure($locked, $actorUserId, $note, $e->getMessage());

                return CarrierReleaseOutcome::NotConfirmed;
            }

            if ($result->outcome !== CarrierReleaseOutcome::Confirmed) {
                $this->recordCarrierReleaseFailure($locked, $actorUserId, $note, $result->detail);

                return CarrierReleaseOutcome::NotConfirmed;
            }

            DB::table('business_messaging_numbers')->where('id', $locked->id)->update([
                'status' => BusinessMessagingNumberStatus::Released->value,
                'released_at' => now(),
                'carrier_release_failed_at' => null,
                'carrier_release_failure_reason' => null,
                'updated_at' => now(),
            ]);

            $this->recordEvent($locked, NumberLifecycleEventType::CarrierReleaseConfirmed, $actorUserId, $note);

            return CarrierReleaseOutcome::Confirmed;
        });

        if ($outcome === CarrierReleaseOutcome::NotConfirmed) {
            throw new NumberCarrierReleaseNotConfirmedException(sprintf(
                'Number [%d]\'s release could not be confirmed by the carrier. It remains Suspended; see the recorded event for details.',
                (int) $number->id,
            ));
        }
    }

    private function recordCarrierReleaseFailure(BusinessMessagingNumber $locked, int $actorUserId, string $note, ?string $reason): void
    {
        DB::table('business_messaging_numbers')->where('id', $locked->id)->update([
            'carrier_release_failed_at' => now(),
            'carrier_release_failure_reason' => $reason !== null ? Str::limit($reason, 490, '') : null,
            'updated_at' => now(),
        ]);

        $this->recordEvent($locked, NumberLifecycleEventType::CarrierReleaseAttemptFailed, $actorUserId, $reason !== null ? $note . ' | ' . $reason : $note);
    }

    private function recordEvent(
        BusinessMessagingNumber $number,
        NumberLifecycleEventType $type,
        ?int $actorUserId = null,
        ?string $note = null,
    ): BusinessMessagingNumberLifecycleEvent {
        return BusinessMessagingNumberLifecycleEvent::create([
            'business_id' => (int) $number->identity->business_id,
            'business_messaging_number_id' => (int) $number->id,
            'event_type' => $type->value,
            'actor_user_id' => $actorUserId,
            'note' => $note,
        ]);
    }

    private function isNotConfiguredException(\Throwable $e): bool
    {
        foreach (self::NOT_CONFIGURED_EXCEPTIONS as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function graceDays(): int
    {
        return (int) config('messaging.number_renewal_grace_days', 14);
    }

    private function advanceWarningDays(): int
    {
        return (int) config('messaging.number_renewal_advance_warning_days', 7);
    }

    private function minimumNoticeDays(): int
    {
        return (int) config('messaging.number_release_minimum_notice_days', 7);
    }
}
