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

    public function isMetered(): bool
    {
        $meter = $this->meters->findByMeterKey(PlatformFeature::MessagingTransport->value);

        return $meter !== null && (bool) $meter->is_metered && $meter->active_rate_id !== null;
    }

    /**
     * @return int|null the reservation id to settle, or null when sending is
     *                  not billed (unmetered / unpriced)
     *
     * @throws MessagingInsufficientFundsException the wallet refused; nothing
     *                                             has been sent
     */
    public function reserve(Business $business, string $operationKey, string $quantity): ?int
    {
        if (! $this->isMetered()) {
            return null;
        }

        $result = $this->wallet->reserve(
            $business,
            PlatformFeature::MessagingTransport->value,
            self::reservationKey($business, $operationKey),
            $quantity,
        );

        if (! $result->granted) {
            throw new MessagingInsufficientFundsException($result->denialReason);
        }

        return $result->reservationId;
    }

    public function commit(?int $reservationId, string $quantity): void
    {
        if ($reservationId !== null) {
            $this->wallet->commit($reservationId, $quantity);
        }
    }

    public function release(?int $reservationId): void
    {
        if ($reservationId !== null) {
            $this->wallet->release($reservationId);
        }
    }

    private static function reservationKey(Business $business, string $operationKey): string
    {
        return 'msg_transport:' . (int) $business->id . ':' . $operationKey;
    }
}
