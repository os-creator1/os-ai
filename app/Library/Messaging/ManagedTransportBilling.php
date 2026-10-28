<?php

namespace App\Library\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Messaging\Exceptions\MessagingInsufficientFundsException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Repositories\Contracts\UsageMeterRepository;

/**
 * The ONE place an outbound managed SMS/MMS is paid for.
 *
 * It rides the canonical usage architecture and adds no pricing of its own:
 * the per-segment price is whatever rate the platform activated for the
 * `messaging_transport` meter, the payer is whoever EffectivePayerResolver
 * says it is, and the admission order (paid-activity pause, debt, feature
 * limit, spend cap, balance, auto-recharge) is UsageWalletManager::reserve().
 *
 * WHILE THE METER IS UNMETERED OR UNPRICED, THIS DOES NOTHING. That is the
 * documented Slice 3 contract (a fresh install has no rate, T-MSG-36), and it
 * keeps every existing send byte-for-byte unchanged until the platform owner
 * activates a rate through the existing rate tooling. No price is invented here.
 *
 * Lifecycle, driven by ManagedMessageDispatcher around the provider call:
 *   reserve()  before the provider is contacted  (throws when denied)
 *   commit()   the provider confirmed acceptance
 *   release()  the provider rejected / the call failed
 * Every step is idempotent on the operation key, so a replayed send neither
 * double-charges nor leaves money held.
 */
class ManagedTransportBilling
{
    public function __construct(
        private readonly UsageWalletManager $wallet,
        private readonly UsageMeterRepository $meters,
    ) {
    }

    public const DENIAL_BILLING_NOT_CONFIGURED = 'messaging_billing_not_configured';

    public static function requiresBilling(): bool
    {
        return (bool) config('messaging.require_paid_billing', true);
    }

    public function isMetered(string $meterKey = MessageMeters::SMS_OUTBOUND): bool
    {
        $meter = $this->meters->findByMeterKey($meterKey);

        return $meter !== null && (bool) $meter->is_metered && $meter->active_rate_id !== null;
    }

    /**
     * Holds the retail base for the parts plus an ESTIMATE of the carrier
     * surcharge; both are settled to the final Telnyx figures afterwards by
     * MessageCostReconciler. Refuses when the wallet cannot cover both.
     *
     * @return TransportReservation|null null when sending is not billed
     *                                   (the outbound meter is unmetered / unpriced)
     *
     * @throws MessagingInsufficientFundsException the wallet refused; nothing has been sent
     */
    public function reserve(Business $business, string $operationKey, string $quantity, string $messageType = 'sms', ?string $numberType = null): ?TransportReservation
    {
        $meterKey = MessageMeters::for($messageType, 'outbound', $numberType);

        if (! $this->isMetered($meterKey) || (self::requiresBilling() && ! $this->isMetered(MessageMeters::SURCHARGE))) {
            if (self::requiresBilling()) {
                // Fail closed: real paid messaging is never sent for free.
                throw new MessagingInsufficientFundsException(self::DENIAL_BILLING_NOT_CONFIGURED);
            }

            return null;
        }

        $base = $this->wallet->reserve($business, $meterKey, self::reservationKey($business, $operationKey), $quantity);

        if (! $base->granted) {
            throw new MessagingInsufficientFundsException($base->denialReason);
        }

        $surchargeId = null;
        $estimate = bcmul($quantity, bcdiv((string) max(0, (int) config('messaging.estimated_carrier_surcharge_micro')), '1000000', 6), 6);

        if (bccomp($estimate, '0', 6) > 0 && $this->isMetered(MessageMeters::SURCHARGE)) {
            $surcharge = $this->wallet->reserve($business, MessageMeters::SURCHARGE, self::surchargeKey($business, $operationKey), $estimate);

            if (! $surcharge->granted) {
                $this->wallet->release($base->reservationId);

                throw new MessagingInsufficientFundsException($surcharge->denialReason);
            }

            $surchargeId = $surcharge->reservationId;
        }

        return new TransportReservation((int) $base->reservationId, $surchargeId, $meterKey);
    }

    /** The provider accepted the message: the estimates become charges (the reconciler corrects them to final). */
    public function commit(?TransportReservation $reservation, string $quantity): void
    {
        if ($reservation === null) {
            return;
        }

        $this->wallet->commit($reservation->baseReservationId, $quantity);

        if ($reservation->surchargeReservationId !== null) {
            $this->wallet->commit($reservation->surchargeReservationId);
        }
    }

    public function release(?TransportReservation $reservation): void
    {
        if ($reservation === null) {
            return;
        }

        $this->wallet->release($reservation->baseReservationId);

        if ($reservation->surchargeReservationId !== null) {
            $this->wallet->release($reservation->surchargeReservationId);
        }
    }

    public static function reservationKey(Business|int $business, string $operationKey): string
    {
        return 'msg_transport:' . ($business instanceof Business ? (int) $business->id : $business) . ':' . $operationKey;
    }

    public static function surchargeKey(Business|int $business, string $operationKey): string
    {
        return 'msg_surcharge:' . ($business instanceof Business ? (int) $business->id : $business) . ':' . $operationKey;
    }
}
