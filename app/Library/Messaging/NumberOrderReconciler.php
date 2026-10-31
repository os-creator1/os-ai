<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\NumberOrderCheckResult;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingNumberOrder;
use App\Models\BusinessMessagingProvisioningIncident;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finishes carrier number orders that were accepted but not completed (Telnyx
 * `pending`, or an outcome that was never seen), with READ-ONLY carrier
 * requests, and then finalizes the Business's existing messaging identity and
 * purchased number from the order's own durable record.
 *
 * What it never does: place an order, create a Messaging Profile, reserve or
 * commit anything in the wallet, or refund/credit. The customer was charged
 * exactly once when the order was accepted; the original quote (not a carrier
 * billing report, which can lag) is what gets recorded on the number.
 *
 * Concurrency: a check is first CLAIMED with an atomic conditional UPDATE, so
 * overlapping workers/pollers do not pile onto one order; finalization takes a
 * row lock and is idempotent, so a replay or a lost race changes nothing.
 */
class NumberOrderReconciler
{
    /** Incident stages, recorded at most once per order while unresolved. */
    public const INCIDENT_NEEDS_ATTENTION = 'number_order_needs_attention';

    public const INCIDENT_FAILED_AFTER_CHARGE = 'number_order_failed_after_charge';

    /** A claimed check is leased this long; a crashed worker's order is retried afterwards. */
    private const LEASE_SECONDS = 60;

    /** The status endpoint may nudge a check at most this often per order. */
    private const NUDGE_INTERVAL_SECONDS = 5;

    /** An order still PLACING this long after creation lost its process mid-request. */
    private const STALE_PLACING_MINUTES = 15;

    public function __construct(
        private readonly BusinessMessagingIdentityResolver $identities,
        private readonly ProvisioningIncidentRecorder $incidents,
    ) {
    }

    /** Seconds until the next check after $attempts unfinished checks. */
    public static function backoffSeconds(int $attempts): int
    {
        return [10, 15, 20, 30, 45, 60, 90, 120, 180, 300][$attempts] ?? 600;
    }

    /**
     * Scheduled entry point: check every order that is due. Bounded per run.
     *
     * @return int orders checked
     */
    public function reconcileDue(int $limit = 50): int
    {
        $this->escalateStalePlacing();

        $ids = BusinessMessagingNumberOrder::query()
            ->whereIn('status', [BusinessMessagingNumberOrder::PROCESSING, BusinessMessagingNumberOrder::NEEDS_ATTENTION])
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<=', now())
            ->orderBy('next_check_at')
            ->limit($limit)
            ->pluck('id');

        $checked = 0;

        foreach ($ids as $id) {
            try {
                if ($this->claim((int) $id, scheduled: true)) {
                    $this->reconcile((int) $id);
                    $checked++;
                }
            } catch (\Throwable $e) {
                // One order's failure never stops the sweep; it is retried on the lease.
                Log::warning('Number order reconcile failed.', ['order_id' => (int) $id, 'exception' => $e::class]);
            }
        }

        return $checked;
    }

    /**
     * Status-endpoint nudge: re-check this order now unless it was checked in the last few
     * seconds. Same claim as the scheduler, so it can never stack concurrent checks.
     */
    public function nudge(BusinessMessagingNumberOrder $order): BusinessMessagingNumberOrder
    {
        if (in_array($order->status, [BusinessMessagingNumberOrder::PROCESSING, BusinessMessagingNumberOrder::NEEDS_ATTENTION], true)
            && $this->claim((int) $order->id, scheduled: false)) {
            try {
                $this->reconcile((int) $order->id);
            } catch (\Throwable $e) {
                Log::warning('Number order nudge failed.', ['order_id' => (int) $order->id, 'exception' => $e::class]);
            }
        }

        return $order->fresh();
    }

    private function claim(int $orderId, bool $scheduled): bool
    {
        $query = DB::table('business_messaging_number_orders')
            ->where('id', $orderId)
            ->whereIn('status', [BusinessMessagingNumberOrder::PROCESSING, BusinessMessagingNumberOrder::NEEDS_ATTENTION]);

        if ($scheduled) {
            $query->whereNotNull('next_check_at')->where('next_check_at', '<=', now());
        } else {
            $query->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subSeconds(self::NUDGE_INTERVAL_SECONDS)));
        }

        return $query->update([
            'last_checked_at' => now(),
            'next_check_at' => now()->addSeconds(self::LEASE_SECONDS),
            'updated_at' => now(),
        ]) === 1;
    }

    /** Check one claimed order and move it forward. */
    public function reconcile(int $orderId): void
    {
        $order = BusinessMessagingNumberOrder::query()->find($orderId);

        if ($order === null || ! $order->isOpen() || $order->status === BusinessMessagingNumberOrder::PLACING) {
            return;
        }

        try {
            $adapter = app(MessagingProvisioningAdapter::class);
        } catch (MessagingProviderNotConfiguredException) {
            $this->scheduleNext($order);

            return;
        }

        $check = $adapter->checkNumberOrder((string) $order->phone_number, (string) $order->messaging_profile_id, $order->provider_order_id);

        match ($check->outcome) {
            NumberOrderCheckResult::SUCCESS => $this->finalize($orderId, $check),
            NumberOrderCheckResult::FAILED => $this->fail($orderId, (string) ($check->detail ?? 'order_failed')),
            default => $this->scheduleNext($order),
        };
    }

    /**
     * Success path. Idempotent: replays, concurrent workers and a number already attached
     * all end in the same completed state with no second row and no wallet activity.
     */
    public function finalize(int $orderId, NumberOrderCheckResult $check): void
    {
        DB::transaction(function () use ($orderId, $check): void {
            $order = BusinessMessagingNumberOrder::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order === null || $order->status === BusinessMessagingNumberOrder::COMPLETED) {
                return;
            }

            if ($order->status === BusinessMessagingNumberOrder::FAILED) {
                // A definitive failure was recorded earlier; a contradicting success is a
                // human decision, never an automatic attach.
                $this->raiseIncident($order, self::INCIDENT_NEEDS_ATTENTION, 'success reported after failure');

                return;
            }

            $resourceId = trim((string) $check->providerPhoneNumberId);
            $identity = BusinessMessagingIdentity::query()->whereKey($order->business_messaging_identity_id)->lockForUpdate()->first();

            if ($resourceId === '' || $identity === null || (int) $identity->business_id !== (int) $order->business_id
                || $identity->messaging_profile_id !== $order->messaging_profile_id
                || $order->monthly_cost_micro === null || (int) $order->monthly_cost_micro < 1) {
                // Missing the identifiers or the original quote: never invent a price.
                $order->forceFill(['status' => BusinessMessagingNumberOrder::NEEDS_ATTENTION, 'escalated_at' => $order->escalated_at ?? now(), 'failure_code' => 'cannot_finalize'])->save();
                $this->raiseIncident($order, self::INCIDENT_NEEDS_ATTENTION, 'carrier succeeded but the local order record is incomplete');

                return;
            }

            $purchasedAt = $this->purchasedAt($check->purchasedAt, $order);

            $existing = BusinessMessagingNumber::query()->where('phone_number', $order->phone_number)->lockForUpdate()->first();

            if ($existing !== null && (int) $existing->business_messaging_identity_id !== (int) $identity->id) {
                $order->forceFill(['status' => BusinessMessagingNumberOrder::NEEDS_ATTENTION, 'escalated_at' => $order->escalated_at ?? now(), 'failure_code' => 'number_collision'])->save();
                $this->raiseIncident($order, self::INCIDENT_NEEDS_ATTENTION, 'number already mapped to another identity');

                return;
            }

            $identity->forceFill([
                'status' => BusinessMessagingIdentityStatus::Active->value,
                'activated_at' => $identity->activated_at ?? $purchasedAt,
            ])->save();

            $number = $existing ?? $this->identities->attachNumber(
                $identity,
                (string) $order->phone_number,
                true,
                BusinessMessagingNumberStatus::Active,
                $resourceId,
                PhoneNumberType::from((string) $order->number_type),
            );

            // The original verified quote — what the customer confirmed and paid for the
            // first month — and the real purchase date. The first renewal is one month
            // after the purchase, exactly as an immediate purchase would have set.
            DB::table('business_messaging_numbers')->where('id', $number->id)->update([
                'provider_number_reference' => $resourceId,
                'activated_at' => $purchasedAt,
                'next_renewal_at' => $purchasedAt->copy()->addMonthNoOverflow(),
                'upfront_cost_micro' => $order->upfront_cost_micro,
                'monthly_cost_micro' => $order->monthly_cost_micro,
                'cost_currency' => $order->cost_currency,
                'updated_at' => now(),
            ]);

            $order->forceFill([
                'status' => BusinessMessagingNumberOrder::COMPLETED,
                'provider_phone_number_id' => $resourceId,
                'purchased_at' => $purchasedAt,
                'completed_at' => now(),
                'next_check_at' => null,
            ])->save();

            $this->resolveIncidents($order, 'Completed automatically: the carrier confirmed the order and the number is active.');
        });
    }

    /**
     * Definitive carrier failure of an ACCEPTED order. The customer was charged when the order
     * was accepted; no automatic credit is issued (a human verifies the billing consequences),
     * the unusable pending identity is removed so the customer can choose another number, and
     * operators get one incident.
     */
    private function fail(int $orderId, string $code): void
    {
        DB::transaction(function () use ($orderId, $code): void {
            $order = BusinessMessagingNumberOrder::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order === null || ! $order->isOpen()) {
                return;
            }

            $order->forceFill(['status' => BusinessMessagingNumberOrder::FAILED, 'failure_code' => mb_substr($code, 0, 64), 'next_check_at' => null])->save();

            $identity = BusinessMessagingIdentity::query()->whereKey($order->business_messaging_identity_id)->lockForUpdate()->first();

            if ($identity !== null && $identity->status === BusinessMessagingIdentityStatus::Pending
                && ! BusinessMessagingNumber::query()->where('business_messaging_identity_id', $identity->id)->exists()) {
                $identity->delete();
            }

            $this->raiseIncident($order, self::INCIDENT_FAILED_AFTER_CHARGE, 'carrier reported ' . $code . '; wallet reservation #' . (int) $order->usage_reservation_id . ' was committed at acceptance');
        });
    }

    private function scheduleNext(BusinessMessagingNumberOrder $order): void
    {
        $order = $order->fresh();

        if ($order === null || ! $order->isOpen()) {
            return;
        }

        $attempts = (int) $order->attempts + 1;
        $minutes = (int) config('messaging.number_order_escalate_minutes', 120);
        $giveUpDays = (int) config('messaging.number_order_give_up_days', 7);
        $age = $order->accepted_at?->diffInMinutes(now()) ?? 0;
        $update = ['attempts' => $attempts, 'updated_at' => now()];

        if ($age >= $minutes && $order->escalated_at === null) {
            $update += ['status' => BusinessMessagingNumberOrder::NEEDS_ATTENTION, 'escalated_at' => now()];
            $this->raiseIncident($order, self::INCIDENT_NEEDS_ATTENTION, 'still unresolved after ' . $minutes . ' minutes');
        }

        $update['next_check_at'] = $order->accepted_at !== null && $order->accepted_at->diffInDays(now()) >= $giveUpDays
            ? null
            : now()->addSeconds($order->escalated_at !== null || isset($update['escalated_at']) ? 1800 : self::backoffSeconds($attempts));

        DB::table('business_messaging_number_orders')->where('id', $order->id)->whereIn('status', BusinessMessagingNumberOrder::OPEN_STATUSES)->update($update);
    }

    /** An order that lost its process while PLACING cannot be verified (no profile id): operators decide. */
    private function escalateStalePlacing(): void
    {
        BusinessMessagingNumberOrder::query()
            ->where('status', BusinessMessagingNumberOrder::PLACING)
            ->where('created_at', '<=', now()->subMinutes(self::STALE_PLACING_MINUTES))
            ->each(function (BusinessMessagingNumberOrder $order): void {
                $order->forceFill(['status' => BusinessMessagingNumberOrder::NEEDS_ATTENTION, 'escalated_at' => now(), 'failure_code' => 'interrupted_while_placing'])->save();
                $this->raiseIncident($order, self::INCIDENT_NEEDS_ATTENTION, 'order was interrupted while being placed; the carrier outcome is unknown');
            });
    }

    private function purchasedAt(?string $reported, BusinessMessagingNumberOrder $order): Carbon
    {
        try {
            $at = $reported !== null && trim($reported) !== '' ? Carbon::parse($reported) : null;
        } catch (\Throwable) {
            $at = null;
        }

        $at ??= $order->accepted_at ?? now();

        return $at->isFuture() ? now() : $at->setTimezone((string) config('app.timezone'));
    }

    private function raiseIncident(BusinessMessagingNumberOrder $order, string $stage, string $detail): void
    {
        if (BusinessMessagingProvisioningIncident::query()
            ->where('business_id', $order->business_id)->where('stage', $stage)->where('phone_number', $order->phone_number)->whereNull('resolved_at')->exists()) {
            return;
        }

        $business = Business::query()->find($order->business_id);

        if ($business === null) {
            return;
        }

        $this->incidents->record(
            business: $business,
            stage: $stage,
            messagingProfileId: $order->messaging_profile_id,
            providerPhoneNumberId: $order->provider_phone_number_id,
            phoneNumber: $order->phone_number,
            numberType: $order->number_type,
            errorMessage: 'order #' . $order->id . ': ' . $detail,
        );
    }

    private function resolveIncidents(BusinessMessagingNumberOrder $order, string $note): void
    {
        BusinessMessagingProvisioningIncident::query()
            ->where('business_id', $order->business_id)
            ->where('phone_number', $order->phone_number)
            ->whereIn('stage', [self::INCIDENT_NEEDS_ATTENTION])
            ->whereNull('resolved_at')
            ->pluck('id')
            ->each(fn ($id) => $this->incidents->resolve((int) $id, null, '[number order #' . $order->id . '] ' . $note));
    }
}
