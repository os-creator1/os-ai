<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
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
use App\Library\Messaging\Exceptions\NumberReleaseNotEligibleException;
use App\Library\Usage\UsageWalletManager;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingNumberLifecycleEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phone Numbers + A2P lane — messaging contract §13.2/§13.3, the number
 * lifecycle this platform owes every managed number: a renewal charge
 * that requires sufficient funds, an advance warning when the projected
 * balance looks insufficient, a suspension that pauses new paid outbound
 * while retaining the number, a defined non-zero grace period, and a
 * release that is never silent — explicit, audited, and preceded by
 * notification.
 *
 * Every write to business_messaging_numbers' five lifecycle columns
 * (next_renewal_at, renewal_warning_sent_at, suspended_at,
 * grace_expires_at, release_notice_sent_at) goes through this class alone,
 * via the query builder — never through the model's own mass assignment
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
            ->whereNull('release_notice_sent_at')
            ->get();
    }

    /**
     * §13.2 "Alert before the renewal date when the projected balance is
     * insufficient" — a look-ahead probe, never the renewal charge itself.
     * Probes via reserve()+release() under a throwaway idempotency key
     * distinct from the real renewal's own, so a probe never holds a real
     * reservation open for the whole advance-warning window.
     *
     * @return bool whether a warning was actually dispatched
     */
    public function sendAdvanceWarningIfNeeded(BusinessMessagingNumber $number, ?Carbon $asOf = null): bool
    {
        $asOf ??= now();

        if ($number->status !== BusinessMessagingNumberStatus::Active
            || $number->next_renewal_at === null
            || $number->renewal_warning_sent_at !== null
            || $asOf->gte($number->next_renewal_at)
            || $asOf->copy()->addDays($this->advanceWarningDays())->lt($number->next_renewal_at)
        ) {
            return false;
        }

        $business = $number->identity->business;
        $probeKey = sprintf('number_renewal_probe:%d:%s', $number->id, $number->next_renewal_at->toDateString());

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

        DB::transaction(function () use ($number, $business, $asOf): void {
            DB::table('business_messaging_numbers')->where('id', $number->id)->update([
                'renewal_warning_sent_at' => $asOf,
                'updated_at' => now(),
            ]);

            $this->recordEvent($number, NumberLifecycleEventType::RenewalWarningSent);

            SendNumberRenewalWarning::dispatch((int) $business->id, (int) $number->id)->afterCommit();
        });

        return true;
    }

    /**
     * §13.2: "A renewal charge requires sufficient balance or an
     * authorized auto-recharge" — auto-recharge itself is
     * UsageWalletManager's own concern (reserve() already accounts for
     * it); this method only decides what happens to the NUMBER depending
     * on the outcome. NotConfigured never suspends anything — there is no
     * real charge to have failed.
     */
    public function attemptRenewal(BusinessMessagingNumber $number, ?Carbon $asOf = null): NumberRenewalOutcome
    {
        $asOf ??= now();
        $business = $number->identity->business;
        $chargeKey = sprintf('number_renewal:%d:%s', $number->id, $number->next_renewal_at->toDateString());

        try {
            $reservation = $this->wallet->reserve($business, self::FEATURE_NUMBER_RENTAL_RENEWAL, $chargeKey);
        } catch (\Throwable $e) {
            if (! $this->isNotConfiguredException($e)) {
                throw $e;
            }

            return NumberRenewalOutcome::NotConfigured;
        }

        if (! $reservation->granted || $reservation->reservationId === null) {
            $this->suspend($number);

            return NumberRenewalOutcome::InsufficientFunds;
        }

        $this->wallet->commit($reservation->reservationId);

        DB::transaction(function () use ($number): void {
            DB::table('business_messaging_numbers')->where('id', $number->id)->update([
                // Idempotent per period (contract S-8): a replayed sweep for
                // the same due date finds next_renewal_at already advanced
                // and reserve()'s own idempotency key match means it never
                // charges twice either way.
                'next_renewal_at' => $number->next_renewal_at->copy()->addMonthNoOverflow(),
                'renewal_warning_sent_at' => null,
                'updated_at' => now(),
            ]);

            $this->recordEvent($number, NumberLifecycleEventType::RenewalCharged);
        });

        return NumberRenewalOutcome::Succeeded;
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
     */
    public function suspend(BusinessMessagingNumber $number): void
    {
        if ($number->status !== BusinessMessagingNumberStatus::Active) {
            // Never re-suspend, never overwrite an existing suspension's
            // own grace_expires_at, never touch Pending/Released.
            return;
        }

        $business = $number->identity->business;
        $suspendedAt = now();
        $graceExpiresAt = $suspendedAt->copy()->addDays($this->graceDays());

        DB::transaction(function () use ($number, $business, $suspendedAt, $graceExpiresAt): void {
            DB::table('business_messaging_numbers')->where('id', $number->id)->update([
                'status' => BusinessMessagingNumberStatus::Suspended->value,
                'suspended_at' => $suspendedAt,
                'grace_expires_at' => $graceExpiresAt,
                'updated_at' => now(),
            ]);

            $this->recordEvent($number, NumberLifecycleEventType::Suspended);

            SendNumberSuspendedNotice::dispatch((int) $business->id, (int) $number->id)->afterCommit();
        });
    }

    /**
     * §13.3's "preceded by notification" — idempotent: a number's release
     * notice is sent at most once, ever.
     */
    public function sendReleaseNotice(BusinessMessagingNumber $number): void
    {
        if ($number->release_notice_sent_at !== null) {
            return;
        }

        $business = $number->identity->business;

        DB::transaction(function () use ($number, $business): void {
            DB::table('business_messaging_numbers')->where('id', $number->id)->update([
                'release_notice_sent_at' => now(),
                'updated_at' => now(),
            ]);

            $this->recordEvent($number, NumberLifecycleEventType::ReleaseNoticeSent);

            SendNumberReleaseNotice::dispatch((int) $business->id, (int) $number->id)->afterCommit();
        });
    }

    /**
     * §13.3: "The transition from suspended to released must be explicit,
     * audited, and preceded by notification." Every precondition is
     * checked here, independently of whatever the caller believes —
     * never a second, looser path to the same effect. §13.4's port-out
     * exit path is checked too: releasing a number out from under an
     * active port-out request would contradict the customer's own
     * in-flight request to leave with it specifically.
     *
     * @throws NumberReleaseNotEligibleException
     */
    public function release(BusinessMessagingNumber $number, int $actorUserId, string $note): void
    {
        if ($number->status !== BusinessMessagingNumberStatus::Suspended) {
            throw new NumberReleaseNotEligibleException(sprintf(
                'Number [%d] is not Suspended (status: %s) and cannot be released.',
                (int) $number->id,
                $number->status->value,
            ));
        }

        if ($number->grace_expires_at === null || $number->grace_expires_at->isFuture()) {
            throw new NumberReleaseNotEligibleException(sprintf(
                'Number [%d]\'s grace period has not yet expired.',
                (int) $number->id,
            ));
        }

        if ($number->release_notice_sent_at === null) {
            throw new NumberReleaseNotEligibleException(sprintf(
                'Number [%d] has not yet received a release notice.',
                (int) $number->id,
            ));
        }

        if ($this->portOutRequests->activeRequestFor($number) !== null) {
            throw new NumberReleaseNotEligibleException(sprintf(
                'Number [%d] has an active port-out request and must not be released out from under it.',
                (int) $number->id,
            ));
        }

        DB::transaction(function () use ($number, $actorUserId, $note): void {
            DB::table('business_messaging_numbers')->where('id', $number->id)->update([
                'status' => BusinessMessagingNumberStatus::Released->value,
                'released_at' => now(),
                'updated_at' => now(),
            ]);

            $this->recordEvent($number, NumberLifecycleEventType::Released, $actorUserId, $note);
        });
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
}
